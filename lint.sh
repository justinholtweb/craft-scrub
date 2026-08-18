#!/bin/bash
# Lints every PHP file in src/ and tests/ inside the plugin-testing container (no local PHP on this box).
cd /Users/jholt/Sites/plugin-testing || exit 1

for _ in $(seq 1 30); do
    ddev exec true >/dev/null 2>&1 && break
    ddev start >/dev/null 2>&1
    sleep 2
done

ddev exec bash -c 'find /var/www/craft-scrub/src /var/www/craft-scrub/tests -name "*.php" -print0 2>/dev/null | xargs -0 -n1 php -l' </dev/null 2>&1 | grep -v "^No syntax errors" | grep -v "^$"
echo "lint done"
