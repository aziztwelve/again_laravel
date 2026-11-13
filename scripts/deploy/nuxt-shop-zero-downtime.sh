#!/usr/bin/env bash
#
# Выкладка витрины (nuxt-shop) БЕЗ простоя + хранение файлов прошлых сборок.
# Запускать НА СЕРВЕРЕ:  bash /var/www/html/laravel/scripts/deploy/nuxt-shop-zero-downtime.sh
#
# Что делает:
#   1. git pull --ff-only в /var/www/html/nuxt-shop (дерево должно быть чистым);
#   2. собирает витрину в ОТДЕЛЬНОЙ копии (/var/www/html/nuxt-shop-build) — работающий
#      сайт не трогается, MySQL не конкурирует за память (nice -n 10);
#   3. кладёт файлы сборки (public/_nuxt/*) в архив /var/www/html/nuxt-shop-chunks и
#      дописывает в НОВУЮ сборку файлы прошлых версий моложе KEEP_DAYS (по умолчанию 7),
#      чтобы вкладки, открытые до выкладки, не ловили ошибку «Страницы не существует»;
#   4. проверяет, что все файлы прошлой сборки на месте; иначе останавливается ДО замены;
#   5. меняет .output и делает pm2 reload (2 процесса по очереди), чистит кэш nginx;
#   6. проверяет сайт и удаляет временные папки.
#
# Откат: см. docs/deploy-runbook.md (git revert + повторный запуск скрипта).
set -euo pipefail

SHOP=/var/www/html/nuxt-shop
BUILD=/var/www/html/nuxt-shop-build
ARCHIVE=/var/www/html/nuxt-shop-chunks
KEEP_DAYS="${KEEP_DAYS:-7}"
LOG=/root/nuxt-build.log
SITE="${SITE:-https://again8.ru}"

fail() { echo "ОШИБКА: $*" >&2; exit 1; }

cd "$SHOP"
[ -z "$(git status --porcelain --untracked-files=no)" ] || fail "в $SHOP есть незакоммиченные изменения"

OLD_HEAD=$(git rev-parse HEAD)
git pull --ff-only
if ! git diff --quiet "$OLD_HEAD" HEAD -- package-lock.json; then
  fail "package-lock.json изменился: сначала выполните npm ci по runbook (раздел 5), затем запустите скрипт ещё раз"
fi
echo "== nuxt-shop на $(git rev-parse --short HEAD)"

echo "== копия для сборки"
rm -rf "$BUILD"; mkdir "$BUILD"
tar --exclude=./.output --exclude=./.nuxt --exclude=./node_modules --exclude=./.git -cf - . | tar -xf - -C "$BUILD"
cp -al node_modules "$BUILD/node_modules"

echo "== сборка (несколько минут, сайт работает на старой версии)"
( cd "$BUILD" && NODE_OPTIONS=--max-old-space-size=2048 nice -n 10 npm run build ) >"$LOG" 2>&1 \
  || { tail -20 "$LOG"; fail "сборка упала, сайт не тронут (лог $LOG)"; }

LIVE_NUXT="$SHOP/.output/public/_nuxt"
NEW_NUXT="$BUILD/.output/public/_nuxt"
[ -f "$BUILD/.output/server/index.mjs" ] || fail "в сборке нет server/index.mjs"
[ -d "$NEW_NUXT" ] || fail "в сборке нет public/_nuxt"

echo "== архив файлов прошлых версий (хранение $KEEP_DAYS дн.)"
mkdir -p "$ARCHIVE"
find "$ARCHIVE" -type f -mtime +"$KEEP_DAYS" -delete
# текущая боевая версия — в архив (при первом запуске это начало истории)
[ -d "$LIVE_NUXT" ] && rsync -a --ignore-existing "$LIVE_NUXT/" "$ARCHIVE/"
# новая сборка — в архив (обновляет дату, значит нужные файлы не удалятся)
rsync -a "$NEW_NUXT/" "$ARCHIVE/"
# старые файлы — в новую сборку (существующие не перезаписываются)
rsync -a --ignore-existing "$ARCHIVE/" "$NEW_NUXT/"

if [ -d "$LIVE_NUXT" ]; then
  MISSING=$(comm -23 <(cd "$LIVE_NUXT" && find . -type f -mtime -"$KEEP_DAYS" | sort) <(cd "$NEW_NUXT" && find . -type f | sort) | wc -l)
  [ "$MISSING" -eq 0 ] || fail "в новой сборке потерялось $MISSING файлов прошлой версии — замена отменена, сайт не тронут"
fi
echo "   файлов в новой сборке: $(find "$NEW_NUXT" -type f | wc -l), в архиве: $(find "$ARCHIVE" -type f | wc -l)"

echo "== замена и перезагрузка"
rm -rf "$SHOP/.output.new" "$SHOP/.output.old"
cp -a "$BUILD/.output" "$SHOP/.output.new"
mv "$SHOP/.output" "$SHOP/.output.old"
mv "$SHOP/.output.new" "$SHOP/.output"
pm2 reload nuxt-shop >/dev/null
sleep 8
rm -rf /var/cache/nginx/nuxt/*

echo "== проверка"
CODE=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:3000/)
[ "$CODE" = "200" ] || fail "витрина локально отвечает $CODE (откат: mv $SHOP/.output.old $SHOP/.output && pm2 reload nuxt-shop)"
BAD=0
for page in / /catalog; do
  for f in $(curl -s "$SITE$page" | grep -o '_nuxt/[A-Za-z0-9_.-]*\.js' | sort -u | sed 's#_nuxt/##'); do
    [ -f "$SHOP/.output/public/_nuxt/$f" ] || { echo "   нет файла $f (страница $page)"; BAD=1; }
  done
done
[ "$BAD" -eq 0 ] || fail "страницы ссылаются на несуществующие файлы"

rm -rf "$BUILD" "$SHOP/.output.old" "$LOG"
echo "ГОТОВО: nuxt-shop $(git -C "$SHOP" rev-parse --short HEAD), сайт отвечает 200, файлы прошлых версий сохранены"
