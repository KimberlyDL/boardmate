#!/bin/sh
# Uploads live in Supabase Storage on Render, but local disks still need their
# folders (e.g. a host with FILES_*_DISK left at public/local, or a mounted disk
# that starts empty). Stop with a clear message if storage/app is not writable.
set -e

APP_STORAGE="${APP_BASE_DIR:-/var/www/html}/storage/app"

mkdir -p "$APP_STORAGE/public" "$APP_STORAGE/private"

if ! touch "$APP_STORAGE/.write-test" 2>/dev/null; then
    echo "DormLink: $APP_STORAGE is not writable by $(id -un). Uploads would fail; check the disk mount." >&2
    exit 1
fi
rm -f "$APP_STORAGE/.write-test"
