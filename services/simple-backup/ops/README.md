# Simple backup deployment

Based on onymchat/onym-backup. Public endpoint: https://atlas.predhit.com/simple-backup
Manifest: https://atlas.predhit.com/simple-backup/manifest.json

Changes from upstream: authenticate the complete configured public URL path (required by the iOS client when mounted below a prefix), signed display name, truthful storage jurisdiction FI and Hetzner infrastructure disclosure. Original crypto, retention, export and erase implementation retained.

Deployment uses Ubuntu, systemd and the existing Atlas nginx TLS virtual host. Build with `cargo test --locked -j 2` and `cargo build --release --locked -j 2` inside `operator/`, then upload source to `/opt/simple-backup/source` and run `ops/install.sh` as root. The installer reserves an 8 GiB filesystem image and creates an isolated system user. Existing configuration and signing key are preserved on subsequent runs.

Private config: `/etc/simple-backup/service.env`, mode 0600, root only. Never commit this file. Data volume: `/var/lib/simple-backup`. Binary: `/opt/simple-backup/bin/onym-backup-operator`. No shell access for the service user, localhost-only port 8092, 384 MiB memory limit. TLS terminates on nginx. Per-holder limit: 5 snapshots, each 256 MiB. Mode: free. No billing broker or accounts.

Check `systemctl status simple-backup`, `journalctl -u simple-backup`, `df -h /var/lib/simple-backup`, and the public `/simple-backup/health` endpoint. The volume is finite; provision more storage before it fills. Logs omit per-user requests. The reference runs its reconciliation sweep hourly. The server has no geographically separate replica; no automated secondary archive copy is configured. Preserve the signing key securely when migrating, and move the SQLite database and blob tree as one consistent unit with the service stopped. Do not claim backups were erased if separately retained copies still exist.

The inherited signed terms include operational notice commitments (shutdown P90D, breach P3D, lawful-access notice when permitted). These require action by the operator; this software does not send such notices automatically. Hosting location was checked using Hetzner metadata: hel1-dc2. Tests validate the server protocol, not a complete phone restore.

Run `python3 ops/smoke.py` in an environment with Python cryptography to exercise public HTTPS. It creates a random temporary holder and encrypted test archive, verifies signatures, transfer integrity and isolation, and erases only its own test snapshot. No user credentials or archives are read.
