# HAProxy queue in front of the Jobe container(s). See haproxy.cfg.
FROM ubuntu:24.04
RUN apt-get update && \
    apt-get --no-install-recommends install -yq haproxy curl ca-certificates && \
    apt-get -y clean && rm -rf /var/lib/apt/lists/*
COPY haproxy.cfg overload.json overload.http /etc/haproxy/
ENV JOBE_SLOTS=16 QUEUE_TIMEOUT=60s QUEUE_MAX=500
EXPOSE 80 8404
HEALTHCHECK --interval=30s --timeout=5s \
    CMD curl -fs http://localhost/jobe/index.php/restapi/languages >/dev/null || exit 1
CMD ["haproxy", "-W", "-db", "-f", "/etc/haproxy/haproxy.cfg"]
