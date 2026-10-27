#!/bin/bash
# Copy the plugin to the sites that use it, after a lint.
#   ./deploy.sh           deploy
#   ./deploy.sh --check   only show what differs
set -u
cd "$(dirname "$(readlink -f "$0")")" || exit 1
PHP=/opt/plesk/php/8.5/bin/php
CHECK=0; [ "${1:-}" = "--check" ] && CHECK=1
# name | plugins dir | owner
SITES=(
	"mxb|/var/www/vhosts/mxb-mods.com/httpdocs/wp-content/plugins|mxb-mods"
	"gpb|/var/www/vhosts/gpb-mods.com/httpdocs/wp-content/plugins|gpb-mods"
	"oem|/var/www/vhosts/mxb-mods.com/oem.mxb-mods.com/wp-content/plugins|mxb-mods"
	"wiki|/var/www/vhosts/mxb-mods.com/wiki.mxb-mods.com/wp-content/plugins|mxb-mods"
)
for f in $(find . -name '*.php' -not -path './.git/*'); do "$PHP" -l "$f" >/dev/null || { echo "LINT FAILED: $f"; exit 1; }; done
for entry in "${SITES[@]}"; do
	IFS='|' read -r name dir owner <<< "$entry"
	dest="$dir/dmkrtz-remove-unapproved-users"
	echo "== $name"
	if [ $CHECK -eq 1 ]; then
		rsync -rcn --delete --out-format='   would update: %n' --exclude=.git --exclude=deploy.sh --exclude=README.md --exclude=.gitignore --exclude=purge.log ./ "$dest/"
	else
		rsync -rc --delete --out-format='   update: %n' --exclude=.git --exclude=deploy.sh --exclude=README.md --exclude=.gitignore --exclude=purge.log --chown="$owner:psacln" --chmod=D755,F644 ./ "$dest/"
	fi
done
