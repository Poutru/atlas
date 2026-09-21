#!/usr/bin/env bash
set -euo pipefail
# Run as root on the existing nginx/PHP 8.3 host, after uploading a reviewed release.
release=${1:?Usage: install.sh /opt/atlas/releases/RELEASE}
case "$release" in /opt/atlas/releases/*) ;; *) echo 'Unexpected release path' >&2; exit 1;; esac
getent passwd atlas >/dev/null || useradd --system --home /var/lib/atlas --shell /usr/sbin/nologin atlas
install -d -m 0700 -o atlas -g atlas /var/lib/atlas /var/lib/atlas/sessions
install -d -m 0755 /var/www/atlas-acme
chmod -R a+rX "$release"
ln -sfn "$release" /opt/atlas/current
install -m 0644 "$release/ops/atlas.pool.conf" /etc/php/8.3/fpm/pool.d/atlas.conf
install -d -m 0750 -o atlas -g atlas /var/log/atlas
install -m 0644 "$release/ops/atlas.logrotate" /etc/logrotate.d/atlas
touch /var/log/atlas/php-error.log
chown atlas:atlas /var/log/atlas/php-error.log
# Dedicated Atlas logs have their own bounded retention.
install -m 0644 "$release/ops/atlas-refresh.service" /etc/systemd/system/
install -m 0644 "$release/ops/atlas-refresh.timer" /etc/systemd/system/
if [ ! -f /var/lib/atlas/config.json ]; then runuser -u atlas -- php "$release/web/bin/console.php" init; fi
if [ ! -f /etc/nginx/sites-available/atlas.predhit.com ]; then
 install -m 0644 "$release/ops/atlas.nginx" /etc/nginx/sites-available/atlas.predhit.com
 ln -s /etc/nginx/sites-available/atlas.predhit.com /etc/nginx/sites-enabled/atlas.predhit.com
fi
php-fpm8.3 -t
nginx -t
systemctl reload php8.3-fpm
systemctl reload nginx
systemctl daemon-reload
systemctl enable --now atlas-refresh.timer
printf 'Atlas installed. Issue TLS only after DNS resolves, then verify HTTPS.\n'
