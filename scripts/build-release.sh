#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dist="$root/dist"
name="anore-whmcs-module"
source_date_epoch="${SOURCE_DATE_EPOCH:-$(git -C "$root" log -1 --format=%ct)}"

rm -rf "$dist"
mkdir -p "$dist"

tar \
  --sort=name \
  --mtime="@$source_date_epoch" \
  --owner=0 --group=0 --numeric-owner \
  --format=ustar \
  -C "$root" \
  -czf "$dist/$name.tar.gz" \
  modules README.md

(
  cd "$dist"
  sha256sum "$name.tar.gz" > SHA256SUMS
)
