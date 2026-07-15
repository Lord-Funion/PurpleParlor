# Local Development

Follow Parts B or C of [SETUP_EVERYTHING.md](SETUP_EVERYTHING.md). The short loop is:

```text
copy .env.example → .env
generate APP_KEY
create local database
php bin/migrate.php
php bin/seed.php
php -S 127.0.0.1:8080 -t public bin/dev-router.php
php bin/check-routes.php
php bin/test.php
php bin/simulate-games.php --mode=fast --seed=20260713
```

Use demo payments only. Development seed accounts are refused when `APP_ENV=production`; production administrators are created by the protected installer with unique credentials. Never copy local secrets or data into a deployment ZIP.

Logs are under `storage/logs`, disposable cache under `storage/cache`, and test artifacts under `storage/temporary`. No Node process, Docker, WebSocket server, Redis, or background daemon is required.
