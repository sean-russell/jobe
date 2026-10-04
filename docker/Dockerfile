# Jobe server (this working copy, including Scala 3) in a container, for
# testing alongside a Moodle/CodeRunner container.
#
# Based on JobeInABox (https://github.com/trampgeek/jobeinabox), but builds from
# the local source tree rather than cloning trampgeek/jobe, so local changes
# (e.g. the scala3 branch) are what gets tested.
#
# Build from the repository root:
#     docker build -f docker/Dockerfile -t jobe-local .
# or use docker/docker-compose.yml. See docker/README.md.

FROM ubuntu:24.04

ARG TZ=Europe/Dublin
# Scala 3 release to install (the 3.3.x line is the long-term-support series).
ARG SCALA_VERSION=3.3.6
# Octave adds ~1 GB to the image; set to true if you need it.
ARG INSTALL_OCTAVE=false

ENV APACHE_RUN_USER=www-data \
    APACHE_RUN_GROUP=www-data \
    APACHE_LOG_DIR=/var/log/apache2 \
    APACHE_LOCK_DIR=/var/lock/apache2 \
    APACHE_PID_FILE=/var/run/apache2.pid \
    LANG=C.UTF-8 \
    DEBIAN_FRONTEND=noninteractive

# ---- OS packages, Apache and PHP --------------------------------------------
RUN ln -snf /usr/share/zoneinfo/"$TZ" /etc/localtime && \
    echo "$TZ" > /etc/timezone && \
    apt-get update && \
    apt-get --no-install-recommends install -yq \
        acl \
        apache2 \
        build-essential \
        ca-certificates \
        curl \
        default-jdk-headless \
        fp-compiler \
        libapache2-mod-php \
        nodejs \
        php \
        php-cli \
        php-intl \
        php-mbstring \
        procps \
        pylint \
        python3 \
        python3-pip \
        sqlite3 \
        sudo \
        tzdata && \
    if [ "$INSTALL_OCTAVE" = "true" ]; then \
        apt-get --no-install-recommends install -yq octave ; \
    fi && \
    pylint --reports=no --score=n --generate-rcfile > /etc/pylintrc && \
    apt-get -y clean && rm -rf /var/lib/apt/lists/*

# ---- Scala 3 ------------------------------------------------------------------
# Unpacked to /usr/local/scala3, the default scala_home in app/Config/Jobe.php.
ADD https://github.com/scala/scala3/releases/download/${SCALA_VERSION}/scala3-${SCALA_VERSION}.tar.gz /tmp/scala3.tar.gz
RUN tar -xzf /tmp/scala3.tar.gz -C /usr/local --no-same-owner && \
    ln -sfn /usr/local/scala3-${SCALA_VERSION} /usr/local/scala3 && \
    chmod -R a+rX /usr/local/scala3-${SCALA_VERSION} && \
    rm /tmp/scala3.tar.gz

# ---- Apache configuration -----------------------------------------------------
COPY docker/000-jobe.conf /etc/apache2/sites-enabled/000-jobe.conf
RUN ln -sf /proc/self/fd/1 /var/log/apache2/access.log && \
    ln -sf /proc/self/fd/1 /var/log/apache2/error.log && \
    sed -i "s/^export LANG=.*/export LANG=$LANG/" /etc/apache2/envvars && \
    sed -i '1 i ServerName localhost' /etc/apache2/apache2.conf && \
    sed -i 's/ServerTokens OS/ServerTokens Prod/' /etc/apache2/conf-enabled/security.conf && \
    sed -i 's/ServerSignature On/ServerSignature Off/' /etc/apache2/conf-enabled/security.conf && \
    rm -f /etc/apache2/sites-enabled/000-default.conf && \
    mkdir -p /var/crash && chmod 777 /var/crash && \
    echo '<!DOCTYPE html><html lang="en"><title>Jobe</title><h1>Jobe</h1></html>' > /var/www/html/index.html

# ---- Jobe -----------------------------------------------------------------------
COPY . /var/www/html/jobe

# The installer finds the web-server user from the running Apache processes, so
# Apache must be running. --max_uid keeps the jobe users' UIDs low so they are
# unlikely to collide with real users on the Docker host (RLIMIT_NPROC is per UID).
# Then build the CDS archive that speeds up Scala compilation and enable it.
RUN apache2ctl start && \
    cd /var/www/html/jobe && \
    /usr/bin/python3 install --max_uid=500 && \
    chown root:root runguard/runguard runguard/rmjobedir && \
    chmod 700 runguard/runguard runguard/rmjobedir && \
    php spark jobe:scalacds /usr/local/scala3/jobe-scalac.jsa && \
    sed -i "s|public string \$scala_cds_archive = '';|public string \$scala_cds_archive = '/usr/local/scala3/jobe-scalac.jsa';|" \
        app/Config/Jobe.php && \
    grep -q "scala_cds_archive = '/usr/local/scala3/jobe-scalac.jsa'" app/Config/Jobe.php && \
    { apache2ctl stop || true ; } && \
    rm -f /tmp/jobe_language_cache_file

EXPOSE 80

HEALTHCHECK --start-period=30s --interval=1m --timeout=5s \
    CMD /usr/bin/python3 /var/www/html/jobe/minimaltest.py || exit 1

CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
