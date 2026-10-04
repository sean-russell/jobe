# Running this Jobe in Docker (for testing with Moodle/CodeRunner)

This directory builds a container from **this working copy** of Jobe (so the
`scala3` branch, or any other local change, is what runs), modelled on
[JobeInABox](https://github.com/trampgeek/jobeinabox). It includes C, C++,
Python 3 (+pylint), Java, Scala 3 (with the CDS speed-up enabled), PHP,
Node.js and Pascal. Octave is off by default (it adds ~1 GB); set
`INSTALL_OCTAVE: "true"` in `docker-compose.yml` to include it.

## 1. Build and start Jobe

    cd docker
    docker compose up -d --build

The first build takes a few minutes. Check it:

    curl http://localhost:4000/jobe/index.php/restapi/languages

which should list `scala` among the languages. `docker ps` shows the container
as `healthy` after about 30 s.

Run the full Jobe test suite against it (Octave tests fail unless you
enabled Octave):

    python3 ../testsubmit.py --port=4000
    python3 ../testsubmit.py --port=4000 scala      # just Scala

## 2. Connect it to the Moodle container

    ./connect-to-moodle.sh                 # finds the Moodle container itself
    ./connect-to-moodle.sh <container>     # or name it explicitly

This attaches Jobe (alias `jobe`) and the Moodle web container to a shared
Docker network, `jobe-net`, then checks from inside Moodle that
`http://jobe/jobe/index.php/restapi/languages` answers. Re-run it if you
recreate either container.

## 3. Configure Moodle

As a Moodle admin:

1. **Site administration > Plugins > Question types > CodeRunner**:
   set *Jobe server* to `jobe` (no `http://`, no port).
2. **Site administration > General > Security > HTTP security**:
   remove `172.16.0.0/12` from the *cURL blocked hosts list* (Docker's
   private address range; Moodle blocks it by default, which makes every
   CodeRunner run fail with a Jobe connection error). Port 80 is already
   allowed.

Alternative without the shared network: set *Jobe server* to
`host.docker.internal:4000`, add `4000` to *cURL allowed ports*, and unblock
the address `host.docker.internal` resolves to (on Docker Desktop for Mac,
`192.168.65.x`, inside `192.168.0.0/16`).

These security changes are fine for a local test instance; don't make them
on a production Moodle.

## Using Scala from CodeRunner

The built-in CodeRunner question types (python3, c, java, …) work straight
away. CodeRunner has no built-in Scala question type, so to try Scala create
a prototype question with *Sandbox language* `scala` (customise an existing
Java-style prototype: set the language to `scala` and write the template in
Scala), then base Scala questions on it.

## Rebuilding after code changes

    docker compose up -d --build

then re-run `./connect-to-moodle.sh` (the container is recreated). To see
Jobe/Apache logs: `docker logs -f jobe`. To debug a run, set
`$debugging = true` in `app/Config/Jobe.php`, rebuild, and look in
`/home/jobe/runs` inside the container (`docker exec -it jobe bash`).

## Stopping

    docker compose down            # stop and remove the Jobe container
    docker network rm jobe-net     # optional, after disconnecting Moodle
