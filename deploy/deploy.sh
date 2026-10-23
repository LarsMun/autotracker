#!/usr/bin/env bash
# Deploys the checked-out commit in /srv/auto. Run by the GitHub Actions runner on this machine
# (.github/workflows/deploy.yml) after it pulled main; by hand: deploy/deploy.sh
#
#   1. tests, in composer:2 against the checkout (as in the README); a failure stops here
#   2. a new image, tagged with the commit; auto:latest is untouched until it is ready
#   3. wait until the queue worker is idle, so a running scrape is not cut off
#   4. a database dump when migrations are pending, then migrate with the new image
#   5. auto:latest becomes the new image, the containers are recreated, /up must answer
#      (when it does not, the previous image is put back; migrations are not undone)

set -euo pipefail

cd "$(dirname "$0")/.."

compose="docker compose --env-file .env -f deploy/compose.prod.yml"
sha=$(git rev-parse --short HEAD)
image="auto:$sha"
# Runs the new image against the production database and storage, outside compose.
run_new="docker run --rm --network auto --env-file .env -v auto-storage:/app/storage $image"

dumps="${AUTO_PREDEPLOY_DIR:-$HOME/backups/auto-predeploy}"
idle_wait="${AUTO_IDLE_WAIT:-3600}"

log() { printf '\n==> %s\n' "$*"; }

log "Tests ($sha)"
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/w -w /w composer:2 \
    sh -c 'composer install --no-interaction --no-progress --quiet && php artisan test'

log "Image $image"
docker build -t "$image" .

log "Waiting for the queue worker to be idle (at most ${idle_wait}s)"
waited=0
while true; do
    status=0
    $run_new php artisan auto:idle || status=$?
    [ "$status" -eq 0 ] && break
    # 2 is busy; anything else is an error (no database, a broken image) and stops the deploy.
    [ "$status" -eq 2 ] || exit "$status"
    if [ "$waited" -ge "$idle_wait" ]; then
        echo "The worker is still busy after ${idle_wait}s; not deploying. Run the workflow again later." >&2
        exit 1
    fi
    sleep 30
    waited=$((waited + 30))
done

if $run_new php artisan migrate:status --pending --no-ansi | grep -q Pending; then
    log "Pending migrations: database dump first"
    mkdir -p "$dumps"
    dump="$dumps/auto_$(date +%Y%m%d_%H%M%S)_$sha.sql.gz"
    # The root password stays inside the container's environment.
    $compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --single-transaction --no-tablespaces -u root "$MYSQL_DATABASE"' | gzip > "$dump"
    echo "$dump"
    # Keep the last 10.
    ls -1t "$dumps"/auto_*.sql.gz | tail -n +11 | xargs -r rm --

    log "Migrate"
    $run_new php artisan migrate --force
fi

log "Release"
previous=$(docker image inspect auto:latest --format '{{.Id}}' 2>/dev/null || true)
docker tag "$image" auto:latest
$compose up -d --no-build

log "Waiting for /up"
for _ in $(seq 1 30); do
    if [ "$(docker inspect --format '{{.State.Health.Status}}' "$($compose ps -q app)")" = healthy ]; then
        log "Deployed $sha"
        # Commit-tagged images older than this one and the one before it.
        docker images auto --format '{{.Tag}}' | grep -vE "^(latest|$sha)$" | tail -n +2 | sed 's/^/auto:/' | xargs -r docker rmi >/dev/null || true
        exit 0
    fi
    sleep 5
done

echo "The app did not become healthy; putting the previous image back." >&2
if [ -n "$previous" ]; then
    docker tag "$previous" auto:latest
    $compose up -d --no-build
fi
exit 1
