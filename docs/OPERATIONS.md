# Эксплуатация Atlas

## Сервер

Ubuntu 24.04, nginx, PHP 8.3 (curl, mbstring, sqlite3, sodium), SQLite. Приложение работает от отдельного системного пользователя `atlas`.

- Код: `/opt/atlas/releases/<release>`, текущий symlink `/opt/atlas/current`.
- Rust CLI: `/opt/atlas/bin/onym-discovery`.
- Приватные данные: `/var/lib/atlas` (режим 0700).
- Логи: `/var/log/atlas`, ротация 7 дней.
- Nginx: `/etc/nginx/sites-available/atlas.predhit.com`.
- PHP pool: `/etc/php/8.3/fpm/pool.d/atlas.conf`.

## Релиз

1. Запустить CI и собрать `cargo build --release --locked`.
2. Передать проверенный код в новый release directory, бинарник — в `/opt/atlas/bin`.
3. От root выполнить `bash <release>/ops/install.sh <release>`. Скрипт сохраняет существующую конфигурацию TLS nginx и приватные данные.
4. При первом запуске направить DNS A на сервер, выпустить TLS через `certbot --nginx -d atlas.predhit.com`.
5. Проверить HTTPS `/health`, `/api/catalog`, `/manifest.json`, `/catalogs/public-services.json`; проверить подписи через CLI.
6. Убедиться, что `systemctl is-active atlas-refresh.timer` возвращает active.

TLS автоматически продлевает certbot. Таймер проверяет сервисы каждый час. Посмотреть ошибки: `journalctl -u atlas-refresh.service` и `/var/log/atlas/php-error.log`.

## Доступ владельца

Адрес `/admin`. Начальный пароль — `/var/lib/atlas/initial-admin-password.txt`, доступен только системному пользователю atlas/root. Пароль можно изменить через `runuser -u atlas -- php /opt/atlas/current/web/bin/console.php password`, передав новый пароль через stdin. Не помещайте пароль в командную строку или историю shell.

## Резервные копии и восстановление

Почасовой refresh создаёт одну SQLite-копию в сутки в `/var/lib/atlas/backups`, хранит 14 дней. Это локальная копия, она не защищает от потери сервера. Для внешней резервной копии отдельно и конфиденциально сохраняйте весь каталог данных, включая `operator.seed`, `config.json`, опубликованные снимки и базу. Ключ определяет идентичность каталога: не генерируйте новый при обычном восстановлении.

При восстановлении остановите timer и pool, восстановите согласованную копию данных с владельцем atlas и правами 0700/0600, затем запустите pool, проверьте подписи и включите timer. Для отката кода переключите `/opt/atlas/current` на предыдущий release и перезагрузите PHP-FPM; не откатывайте sequence опубликованного каталога.

## Границы первой версии

Техническая валидация включает подпись и базовые поля, но не все схемы seat/profile. Загрузка внешних URL поддерживает публичные IPv4-адреса; частные адреса, IP literals, нестандартные порты, oversized responses и небезопасные redirects запрещены. Лимиты защищают от простого спама, но не заменяют распределённую защиту от атак. Администратор один, с парольной сессией; OAuth и роли не реализованы. Совместимость проверяется оригинальным CLI; подключение в конкретной версии мобильного приложения следует дополнительно проверить вручную.
