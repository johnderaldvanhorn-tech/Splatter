#!/bin/zsh
set -e
cd "$(dirname "$0")"
if ! command -v php >/dev/null 2>&1; then
  echo "PHP is required for local testing. Production runs on GoDaddy cPanel PHP."
  exit 1
fi
PORT=4173
echo "Starting Splatter PHP edition at http://127.0.0.1:$PORT"
php -S 127.0.0.1:$PORT router.php
