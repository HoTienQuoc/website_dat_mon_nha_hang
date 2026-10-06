#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
if [ ! -x tools/tailwindcss ]; then
    echo "Chạy: sh scripts/install-tailwind.sh" >&2
    exit 1
fi
mkdir -p public/css
exec ./tools/tailwindcss -i resources/css/app.css -o public/css/app.css --minify "$@"
