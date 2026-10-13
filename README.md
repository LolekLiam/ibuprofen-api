# ibuprofen API

A self-hosted PHP API that caches public eAsistent timetables for the [ibuprofen Android app](https://github.com/LolekLiam/ibuprofen). 
It supports class, teacher and room schedules, background synchronization, and a separate administrator account.

## Install and run

Requires PHP 8.1+, Composer, and the PDO SQLite, DOM and mbstring extensions. Run these commands from `ibuprofen-api/`:

```sh
composer install --no-dev
php bin/admin.php create owner
IBUPROFEN_ALLOW_HTTP=1 php -S 0.0.0.0:8080 -t public public/index.php
```

The admin command prompts for a password twice. There is no default password. Start the synchronization worker in a second terminal:

```sh
php bin/worker.php
```

In the app, open **Settings → Advanced settings → Proxy connection**, enable the proxy, enter your server URL (for example `http://192.168.1.27:8080/`), and save. 
**Server administration** then appears. Sign in with your separate admin account, register a school with its public timetable key, and queue a synchronization.

## Docker

Alternatively, from this directory:

```sh
docker compose up -d --build
docker compose exec api php bin/admin.php create owner
```

Docker runs both the API and worker, exposes port 8080 on your network, and stores data in a persistent volume. 
Set `IBUPROFEN_BIND_ADDRESS=127.0.0.1` to bind only to localhost.

## Documentation

See [API documentation](docs/README.md) for endpoints, configuration, HTTPS hosting, backups, administrator management, migration and tests. 
For public hosting, use HTTPS and serve only the `public/` directory. Native HTTP hosting requires `IBUPROFEN_ALLOW_HTTP=1`, as shown above.

## License

[MIT](LICENSE).
