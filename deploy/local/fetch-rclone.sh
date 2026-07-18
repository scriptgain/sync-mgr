#!/usr/bin/env bash
# Fetch the pinned rclone static binary into bin/. Idempotent.
# SyncMGR's sync engine shells out to this binary (bundled like BackupMGR's kopia).
set -euo pipefail

RCLONE_VERSION="1.68.2"
ARCH="linux-amd64" # adjust for other targets: linux-arm64, osx-amd64, ...

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
dest="$here/../../bin"
mkdir -p "$dest"

if [ -x "$dest/rclone" ] && "$dest/rclone" version 2>/dev/null | grep -q "$RCLONE_VERSION"; then
  echo "rclone $RCLONE_VERSION already present"
  exit 0
fi

url="https://downloads.rclone.org/v${RCLONE_VERSION}/rclone-v${RCLONE_VERSION}-${ARCH}.zip"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

echo "downloading rclone $RCLONE_VERSION ($ARCH)..."
curl -fsSL -o "$tmp/rclone.zip" "$url"
unzip -q "$tmp/rclone.zip" -d "$tmp"
cp "$tmp/rclone-v${RCLONE_VERSION}-${ARCH}/rclone" "$dest/rclone"
chmod +x "$dest/rclone"
"$dest/rclone" version | head -2
