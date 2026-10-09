# AzuraCast with the Tracker plugin baked in (for CI, Kubernetes or immutable hosts).
#   docker build -t my-azuracast:0.23.8-tracker --build-arg AZURACAST_VERSION=0.23.8 .
# Then use it as the image of the "web" service in AzuraCast's docker-compose.yml.
ARG AZURACAST_VERSION=0.23.8
FROM ghcr.io/azuracast/azuracast:${AZURACAST_VERSION}
COPY --chown=azuracast:azuracast . /var/azuracast/www/plugins/branch-tracker
