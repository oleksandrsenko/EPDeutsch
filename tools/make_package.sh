#!/bin/bash
# Собирает пакет обновления для InfinityFree: ~/EPDeutsch/_infinityfree_upload/update-<имя>/ (дальше пользователь запускает deploy.sh)
set -e
NAME="${1:-$(date +%Y-%m-%d)}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$HOME/EPDeutsch/_infinityfree_upload/update-$NAME"
rm -rf "$OUT"; mkdir -p "$OUT/htdocs/app" "$OUT/htdocs/data/de"
cp "$ROOT"/app/*.php "$OUT/htdocs/app/"
cp -R "$ROOT/app/prompts" "$OUT/htdocs/app/prompts"
cp "$ROOT"/public/{de.html,de-core.js,de-tasks.js,schreiben.html,ep.js} "$OUT/htdocs/"
cp "$ROOT"/public/data/de/*.json "$OUT/htdocs/data/de/"
find "$OUT" -name .DS_Store -delete
echo "Пакет: $OUT"; find "$OUT" -type f | wc -l
