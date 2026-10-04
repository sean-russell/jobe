#!/usr/bin/env bash
# Connect the running Jobe container to a running Moodle container so that
# Moodle/CodeRunner can reach Jobe as http://jobe (port 80).
#
# Usage:  ./connect-to-moodle.sh [moodle-container-name]
#
# With no argument, looks for a running container whose image or name contains
# "moodle" (ignoring database containers). Both containers are attached to a
# shared user-defined network, "jobe-net", in which Jobe has the alias "jobe".
# Re-run this after recreating either container.
#
# Works with the bash 3.2 that ships with macOS.

set -euo pipefail

JOBE_CONTAINER="${JOBE_CONTAINER:-jobe}"
NETWORK="${JOBE_NETWORK:-jobe-net}"
LANG_URL_PATH="/jobe/index.php/restapi/languages"

die() { echo "ERROR: $*" >&2; exit 1; }

command -v docker >/dev/null || die "docker not found on PATH"

if ! docker inspect -f '{{.State.Running}}' "$JOBE_CONTAINER" 2>/dev/null | grep -q true; then
    die "Jobe container '$JOBE_CONTAINER' isn't running. Start it with: docker compose up -d --build"
fi

# ---- Find the Moodle container ----------------------------------------------
MOODLE="${1:-}"
if [ -z "$MOODLE" ]; then
    candidates=$(docker ps --format '{{.Names}}|{{.Image}}' |
        grep -i 'moodle' |
        grep -v -i -E 'mariadb|mysql|postgres|pgsql|[-_]db[-_0-9]*\||selenium|mailpit|exttests|redis|memcache' |
        cut -d'|' -f1 || true)
    count=$(printf '%s\n' "$candidates" | grep -c . || true)
    if [ "$count" -eq 0 ]; then
        echo "Running containers:" >&2
        docker ps --format '  {{.Names}}  ({{.Image}})' >&2
        die "No running Moodle container found. Pass its name: $0 <container-name>"
    elif [ "$count" -gt 1 ]; then
        echo "Several possible Moodle containers:" >&2
        printf '  %s\n' $candidates >&2
        die "Pass the Moodle web server's container name: $0 <container-name>"
    fi
    MOODLE="$candidates"
fi
docker inspect "$MOODLE" >/dev/null 2>&1 || die "No container named '$MOODLE'"
echo "Moodle container: $MOODLE"

# ---- Shared network ------------------------------------------------------------
if ! docker network inspect "$NETWORK" >/dev/null 2>&1; then
    echo "Creating network $NETWORK"
    docker network create "$NETWORK" >/dev/null
fi

attached() {  # attached <container> -> true if on $NETWORK
    docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$1" |
        tr ' ' '\n' | grep -qx "$NETWORK"
}

if attached "$JOBE_CONTAINER"; then
    echo "Jobe already on $NETWORK"
else
    docker network connect --alias jobe "$NETWORK" "$JOBE_CONTAINER"
    echo "Connected Jobe to $NETWORK (alias 'jobe')"
fi

if attached "$MOODLE"; then
    echo "Moodle already on $NETWORK"
else
    docker network connect "$NETWORK" "$MOODLE"
    echo "Connected $MOODLE to $NETWORK"
fi

# ---- Check Moodle can see Jobe ---------------------------------------------------
echo
echo "Checking http://jobe$LANG_URL_PATH from inside $MOODLE ..."
result=$(docker exec "$MOODLE" sh -c "
    if command -v curl >/dev/null 2>&1; then curl -s -m 10 http://jobe$LANG_URL_PATH;
    elif command -v php >/dev/null 2>&1; then php -r 'echo @file_get_contents(\"http://jobe$LANG_URL_PATH\");';
    elif command -v wget >/dev/null 2>&1; then wget -q -O - -T 10 http://jobe$LANG_URL_PATH;
    else echo NO_HTTP_CLIENT; fi" 2>&1 || true)

case "$result" in
    *scala*)
        echo "OK - Jobe languages: $result" ;;
    NO_HTTP_CLIENT)
        echo "Couldn't test (no curl/php/wget in the Moodle container), but the network is set up." ;;
    *)
        echo "WARNING: unexpected response: $result" >&2 ;;
esac

cat <<EOF

Next, in Moodle (as admin):
  1. Site administration > Plugins > Question types > CodeRunner:
       Jobe server  =  jobe        (no http://, no port)
  2. Site administration > General > Security > HTTP security:
       cURL blocked hosts list: remove 172.16.0.0/12 (Docker's network range),
       or the range shown by: docker network inspect $NETWORK -f '{{(index .IPAM.Config 0).Subnet}}'
     (Port 80 is already in the default cURL allowed ports list.)

Alternatively, without this network, use Jobe server = host.docker.internal:4000
and add 4000 to the cURL allowed ports list (and unblock 192.168.0.0/16 on Docker Desktop).
EOF
