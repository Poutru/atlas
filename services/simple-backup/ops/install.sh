#!/bin/bash
set -euo pipefail
src=/opt/simple-backup/source
getent passwd simple-backup >/dev/null || useradd --system --home /var/lib/simple-backup --shell /usr/sbin/nologin simple-backup
install -d -m 700 /etc/simple-backup
install -d -m 755 /opt/simple-backup/bin /opt/simple-backup/public /var/lib/simple-backup
# A dedicated bounded filesystem prevents public uploads consuming the host disk.
if [ ! -f /opt/simple-backup/storage.ext4 ]; then
    fallocate -l 8G /opt/simple-backup/storage.ext4
    chmod 600 /opt/simple-backup/storage.ext4
    mkfs.ext4 -q -m 1 /opt/simple-backup/storage.ext4
fi
if ! grep -q '^/opt/simple-backup/storage.ext4 ' /etc/fstab; then
    printf '\n/opt/simple-backup/storage.ext4 /var/lib/simple-backup ext4 loop,nodev,nosuid,noexec 0 0\n' >> /etc/fstab
fi
mountpoint -q /var/lib/simple-backup || mount /var/lib/simple-backup
chown simple-backup:simple-backup /var/lib/simple-backup
chmod 700 /var/lib/simple-backup
if [ ! -f /etc/simple-backup/service.env ]; then
    python3 - <<'PY'
import secrets, pathlib
p=pathlib.Path('/etc/simple-backup/service.env')
p.write_text('BACKUP_BIND=127.0.0.1:8092\nBACKUP_COMPONENT_ID=onym:component:simple-backup\nBACKUP_PUBLIC_URL=https://atlas.predhit.com/simple-backup\nBACKUP_SIGNING_SEED='+secrets.token_hex(32)+'\nBACKUP_STORE_PATH=/var/lib/simple-backup/backup.sqlite\nBACKUP_BLOB_ROOT=/var/lib/simple-backup/blobs\nBACKUP_MAX_SNAPSHOT_BYTES=268435456\nBACKUP_MAX_SNAPSHOTS=5\nBACKUP_CHUNK_BYTES=8388608\nBACKUP_ENTITLEMENT_ISSUERS=\nRUST_LOG=warn\n')
p.chmod(0o600)
PY
fi
install -m 755 "$src/operator/target/release/onym-backup-operator" /opt/simple-backup/bin/onym-backup-operator.new
mv /opt/simple-backup/bin/onym-backup-operator.new /opt/simple-backup/bin/onym-backup-operator
install -m 644 "$src/ops/index.html" /opt/simple-backup/public/index.html
install -m 644 "$src/ops/nginx-locations.conf" /etc/nginx/snippets/simple-backup.conf
install -m 644 "$src/ops/simple-backup.service" /etc/systemd/system/simple-backup.service
python3 - <<'PY'
from pathlib import Path
p=Path('/etc/nginx/sites-available/atlas.predhit.com');s=p.read_text()
include='    include /etc/nginx/snippets/simple-backup.conf;\n'
if include not in s:
    Path('/etc/nginx/sites-available/atlas.predhit.com.before-simple-backup').write_text(s)
    s=s.replace('    server_name atlas.predhit.com;\n','    server_name atlas.predhit.com;\n'+include,1);p.write_text(s)
PY
systemctl daemon-reload
systemctl enable --now simple-backup
systemctl restart simple-backup
nginx -t
systemctl reload nginx
