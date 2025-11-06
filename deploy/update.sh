#!/bin/bash -e
readonly USER="gil"
readonly REPO_BRANCH="master"

readonly REPO_PATH="$(cd -P "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly IAM="$(whoami)"

if [[ "${IAM}" != "${USER}" ]]; then
    echo "Run this script as ${USER}"
    exit 1
fi

usage() {
	echo "Usage: $0 -f"
	echo "  -f Ensures the script is only run when intended. "
	exit 1
}

if [[ $# -eq 0 ]] ; then
	usage
fi

if [[ "$1" != "-f" ]]; then
	echo "Update flag -f was not included. Nothing was run."
	usage
fi

timer_total=${SECONDS}

echo "Deploying site"
printf "==================\n"

cd ${REPO_PATH}

timer_git=${SECONDS}
git fetch origin ${REPO_BRANCH}
git checkout -f ${REPO_BRANCH}
git clean -fd
git pull
chmod +x deploy/update.sh
readonly timer_git=$((${SECONDS} - ${timer_git}))

timer_docker=${SECONDS}
yes | cp -f compose.override.yml{,.bak}
yes | cp -f compose.{prod,override}.yml
docker compose pull
DOCKER_BUILDKIT=1 docker compose build --pull --no-cache
docker compose up --remove-orphans -d
readonly timer_docker=$((${SECONDS} - ${timer_docker}))

timer_maintenance=${SECONDS}
docker compose exec php php bin/console doctrine:migrations:migrate --allow-no-migration --no-interaction
readonly timer_maintenance=$((${SECONDS} - ${timer_maintenance}))

readonly timer_total=$((${SECONDS} - ${timer_total}))

printf "==================\n"
printf "Git: %4ss\n" ${timer_git}
printf "Docker: %4ss\n" ${timer_docker}
printf "Maintenance: %4ss\n" ${timer_maintenance}
printf "      Total: %4ss\n" ${timer_total}
