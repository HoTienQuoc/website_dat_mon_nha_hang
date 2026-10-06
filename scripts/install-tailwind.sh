#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
case "$(uname -s)-$(uname -m)" in
 Darwin-arm64) platform=macos-arm64 ;;
 Darwin-x86_64) platform=macos-x64 ;;
 Linux-aarch64|Linux-arm64) platform=linux-arm64 ;;
 Linux-x86_64) platform=linux-x64 ;;
 *) echo "Nền tảng chưa hỗ trợ" >&2; exit 1 ;;
esac
mkdir -p tools
curl -fL "https://github.com/tailwindlabs/tailwindcss/releases/download/v4.3.3/tailwindcss-$platform" -o tools/tailwindcss.download
chmod +x tools/tailwindcss.download
mv tools/tailwindcss.download tools/tailwindcss
