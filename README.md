# mis-backend

Laravel 12 API for the MIS project. Receives RFID scans from the Arduino scanner firmware ([arduino-rfid-scanner](https://github.com/AdonisJr/arduino-rfid-scanner)).

## Requirements

- PHP 8.2+ with `pdo_pgsql` enabled (XAMPP: uncomment `extension=pdo_pgsql` in `C:\xampp\php\php.ini`)
- Composer 2
- PostgreSQL

## Setup

```bash
composer install
cp .env.example .env        # then set DB_* for your PostgreSQL
php artisan key:generate
php artisan migrate
```

## Commands

| Task | Command |
|---|---|
| Dev server (port the firmware expects) | `php artisan serve --host=0.0.0.0 --port=8000` |
| Tests | `php artisan test` |
| Lint / format | `./vendor/bin/pint` |

`--host=0.0.0.0` is needed so the Arduino on the same Wi-Fi can reach the server.

## Endpoints the firmware calls (not implemented yet)

| Mode (yellow LED) | Request | Body |
|---|---|---|
| ON — attendance scan (time in / time out) | `POST /api/scan` | JSON `{"rfid":"AA:BB:CC:DD"}` |
| OFF — forward UID for card assignment | `POST /api/rfid/scan` | form-encoded `rfid=AA:BB:CC:DD` |

No authentication yet — planned for later.
