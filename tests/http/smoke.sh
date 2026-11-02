#!/usr/bin/env bash
# =====================================================================
# GrazeX — post-deploy smoke test. Run from your own computer after every deploy:
#   bash tests/http/smoke.sh https://grazex.co.za
# Checks that pages load, HTTP goes to HTTPS, and private folders/files are never served.
# =====================================================================
set -u
BASE="${1:?Usage: $0 https://your-domain}"
BASE="${BASE%/}"
HOST="${BASE#*://}"
HOST="${HOST%%/*}"
pass=0
fail=0

# expect <label> "<allowed codes>" <url> [extra curl args]
expect() {
  local label=$1 want=$2 url=$3
  shift 3
  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$@" "$url")
  if [[ " $want " == *" $code "* ]]; then
    pass=$((pass + 1)); printf '  \033[32m✓\033[0m %-55s %s\n' "$label" "$code"
  else
    fail=$((fail + 1)); printf '  \033[31m✗\033[0m %-55s got %s, want %s\n' "$label" "$code" "$want"
  fi
}

echo "Pages"
expect "home"                  "200" "$BASE/"
expect "feeds"                 "200" "$BASE/feeds/"
expect "feeds shop"            "200" "$BASE/feeds/shop.php"
expect "ranch"                 "200" "$BASE/ranch/"
expect "ranch livestock"       "200" "$BASE/ranch/livestock.php"
expect "contact"               "200" "$BASE/pages/contact.php"
expect "robots.txt"            "200" "$BASE/robots.txt"
expect "sitemap.xml"           "200" "$BASE/sitemap.xml"
expect "unknown page is 404"   "404" "$BASE/this-page-does-not-exist-$$"

echo "HTTPS"
expect "http → https (home)"   "301 302" "http://$HOST/"
expect "http → https (feeds)"  "301 302" "http://$HOST/feeds/shop.php"
expect "http → https (ranch)"  "301 302" "http://$HOST/ranch/livestock.php"

echo "Never served"
for path in /database/install.sql /database/migrations/README.txt /storage/ /storage/logs/ /vendor/autoload.php \
            /tests/run.php /config/env.php /config/app.php /includes/db.php /templates/pdf/document.php /cron/run.php \
            /.env /.env.php /composer.json /composer.lock /README.md /.git/config /uploads/test.php; do
  expect "$path" "403 404" "$BASE$path"
done

echo "APIs"
expect "webhook refuses GET"   "405" "$BASE/payments/api/yoco-webhook.php"
expect "webhook refuses unsigned POST" "403" "$BASE/payments/api/yoco-webhook.php" -X POST -H 'Content-Type: application/json' --data '{}'

echo
if [ "$fail" -eq 0 ]; then printf '\033[32m%d passed\033[0m\n' "$pass"; else printf '\033[31m%d failed\033[0m, %d passed\n' "$fail" "$pass"; fi
[ "$fail" -eq 0 ]
