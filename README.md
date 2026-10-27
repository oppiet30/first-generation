# First Generation

A standalone, deterministic PHP/MariaDB civilization simulation beginning with exactly two human founders: Adam and Eve. No other human populations are seeded.

## MVP 1: foundation

- PHP 8.3+ and MariaDB 11.x
- Composer PSR-4 autoloading
- Transactional world creation with two adult founders
- Individual people and parent references for future genealogy
- One tick = one simulated day; world starts paused
- Deterministic random-number generator with persisted world state
- GitHub Actions migration and 1,000-day integration test

**Not yet implemented:** births, reproduction, aging effects, survival decisions, death, genetics, terrain, wildlife, or browser UI. Founders are conceptually adults; explicit age-at-start fields will be added alongside aging rules.

## Install

Create an empty MariaDB database and user with privileges on that database. Copy `.env.example` to `.env` and configure credentials.

```sh
composer install
mariadb -u YOUR_USER -p first_generation < database/migrations/001_initial.sql
php bin/create-world.php "Genesis World" 1337
```

Worlds start paused. Start one deliberately:

```sql
UPDATE worlds SET status='running' WHERE id=1;
```

Advance time and inspect results:

```sh
php bin/simulate.php 1 1000
composer test
```

No real-time background scheduler is installed yet; simulation advances only when the CLI is invoked. This repository is independent of Emergent Civilization.
