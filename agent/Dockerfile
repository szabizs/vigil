# The Vigil relay agent as a standalone container (sidecar) for teams that do not run `php artisan vigil:agent`.
#
# Build from the package root:
#   docker build -f agent/Dockerfile --build-arg VIGIL_AGENT_VERSION=1.2.3 -t vigil-agent .
# Run:
#   docker run -d --name vigil-agent -p 127.0.0.1:2407:2407 -v vigil-spool:/vigil-agent/spool \
#     -e VIGIL_TOKEN=... -e VIGIL_BASE_URL=https://vigil.example.internal vigil-agent
FROM php:8.2-cli-alpine

RUN docker-php-ext-install pcntl

ARG VIGIL_AGENT_VERSION=unknown

WORKDIR /vigil-agent

COPY agent/docker/entrypoint.sh agent/vigil-status ./
COPY agent/build ./agent/build/

ENV VIGIL_INGEST_URI=0.0.0.0:2407 \
    VIGIL_AGENT_SPOOL_PATH=/vigil-agent/spool \
    VIGIL_AGENT_VERSION=${VIGIL_AGENT_VERSION}

VOLUME ["/vigil-agent/spool"]

EXPOSE 2407

STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 CMD ["php", "vigil-status"]

ENTRYPOINT ["./entrypoint.sh"]
