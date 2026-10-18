#!/usr/bin/env sh
#
# Build the Vigil relay agent PHAR: agent/build/agent.phar + agent/build/signature.txt (sha512 of the PHAR).
#
#   agent/build.sh
#
# Requires PHP >= 8.2 and Composer. The built PHAR runs on PHP 8.2+ (Composer's platform is pinned to 8.2).
# Commit both build artifacts: `php artisan vigil:agent` requires the PHAR straight from the installed package,
# and the running relay restarts itself when signature.txt changes after a deploy.
set -eu

cd "$(dirname "$0")"

composer install --no-interaction --no-progress --prefer-dist
rm -f build/agent.phar build/signature.txt
php -d phar.readonly=0 vendor/bin/box compile --no-interaction

php -r '$h = hash_file("sha512", "build/agent.phar"); file_put_contents("build/signature.txt", $h);'

echo "Built build/agent.phar ($(wc -c < build/agent.phar | tr -d ' ') bytes), signature $(cut -c1-16 build/signature.txt)..."
