# First Generation

A standalone, deterministic PHP/MariaDB simulation beginning with exactly two human founders, Adam and Eve. Independent of Emergent Civilization; no immigrants or other human populations are seeded.

## Implemented

- One tick is one simulated day; random state persists per world.
- Aging, parent references and ancestor traversal.
- Adult partnerships, pregnancies, births and descendant families.
- Age-based and childbirth mortality, death records, partnership closure on death and extinction detection.
- Family-tree, population and event-history reports. Mortality rates are illustrative, not historical estimates.

Genetics, terrain, wildlife, resource-based survival and a browser UI remain unimplemented. See [genealogy](docs/aging-genealogy.md), [reproduction](docs/reproduction.md), [relationships](docs/descendant-families.md) and [mortality](docs/mortality.md).

## Requirements and configuration

PHP 8.3+ with PDO MySQL, Composer, and MariaDB 11.x. Create an empty database and a user with privileges on it. Copy `.env.example` to `.env` and set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`.

The built-in `.env` reader is a simple literal `KEY=value` parser, not a shell or dotenv library:

- Do **not** surround values with single or double quotes: quotes become part of the value, including passwords.
- Blank lines and lines starting with `#` after trimming are ignored.
- Values may contain `=`, `#`, `$`, quotes and internal spaces literally. Inline comments, escapes, interpolation and `export` are unsupported.
- Leading/trailing whitespace is trimmed. A password requiring edge whitespace must be supplied through the process environment.
- Process environment variables override `.env`, including an explicitly empty value.

Keep real credentials out of Git. For example, `DB_PASSWORD=example#value=123` sets the literal value `example#value=123`. If setting a variable in a shell, follow that shell's quoting rules; shell syntax quotes differ from literal quotes in `.env`.

## Fresh installation

Apply all migrations before creating a world. Every new world starts with two living, 20-year-old founders:

```sh
composer install
for migration in database/migrations/00[1-6]_*.sql; do
    mariadb -u YOUR_USER -p first_generation < "$migration"
done
php bin/create-world.php "Genesis World" 1337
```

For an existing installation, apply only unapplied migrations in numeric order through 006; do not reapply migrations or recreate your existing world. Back up your database before upgrading. This code update needs no new migration and does not rewrite existing worlds.

## Run and inspect

Worlds start paused. To start world 1 deliberately:

```sql
UPDATE worlds SET status='running' WHERE id=1;
```

```sh
php bin/simulate.php 1 10000
php bin/population-report.php 1
php bin/family-tree.php 1
php bin/family-tree.php 1 23
```

The final command lists ancestors of person 23. Living rows show current age; deceased rows show age at death, death day and recorded cause. Infants dying before 365 days show exact days. Missing legacy death records show `unknown`. Parent references and ancestry calculations retain deceased people.

For the reported 10,000-day world, 22 births plus two founders gives 24 people: 22 living and two deceased. Person #3 died at 8 years and #20 at 75 days. This is an observed run, not a population guarantee for other worlds.

Time advances only when the CLI runs, with 1–10,000 days per invocation; extinction stops the run. There is no background scheduler.

## Simulation history

Read existing events without advancing time or changing the world:

```sh
php bin/world-history.php 1
php bin/world-history.php 1 --type=death
php bin/world-history.php 1 --from=9000 --to=10000
php bin/world-history.php 1 --type=birth --from=9000 --to=10000
php bin/world-history.php --help
```

Supported types are `founder`, `partnership`, `conception`, `birth`, `death` and `extinction`. Omit `--type` to show all types. Day bounds are inclusive, allow day zero, and can be used independently. Events appear in day order, with event ID breaking same-day ties. Each row shows the day, type, person name and ID (or `-` for events without a person), and recorded details, including death causes. Empty results print `No matching events.`

Invalid arguments exit with code 2; a missing world exits with code 1. Success, including an empty result, exits with code 0. This report uses the existing `world_events` table and needs no new migration.

## CSV exports for Python and spreadsheets

Export population history or individual lifespans without advancing time:

```sh
php bin/export-csv.php 1 > world-1-daily.csv
php bin/export-csv.php 1 --from=10000 --to=20000 > world-1-days-10000-20000.csv
php bin/export-csv.php 1 --report=people > world-1-people.csv
```

Daily exports contain one end-of-day row per day, including day zero. A world at day 20,000 produces 20,001 data rows plus the header. These are **reconstructed population figures**, not saved tick snapshots: the database stores people, dates, events and the current world clock. Past random states, health, hunger and energy values are not recoverable from this export. Reconstruction assumes the retained birth/death records have not been deleted or retroactively edited.

Daily columns are `world_id`, `day`, `year`, `day_of_year`, `total_people`, `living`, `births`, `deaths`, `cumulative_births`, `cumulative_deaths`, `natural_deaths`, `childbirth_deaths` and `other_deaths`. Birth counts exclude founders and follow the population report's mother-reference rule. Cause counts are daily; `other_deaths` includes missing or unrecognized causes. `total_people` includes founders and all people born by that day, including those who have died. The initial day zero uses year/day-of-year 0/0; later days use the simulation calendar.

Day bounds are inclusive, must not exceed the current day, and apply only to the daily report. Cumulative totals include earlier days even when exporting a later range. Each export reads a consistent database snapshot. Invalid arguments exit with code 2; a missing world exits with code 1. Data goes to standard output, errors to standard error; check the exit code before using a redirected file.

The people report includes person ID, name, sex, birth day, initial age in days, death day/cause, exact age in days, whole age in years and parent IDs. Ages for deceased people stop at death; living ages use the current world day. Blank death fields mean alive; missing recorded causes show `unknown`. Formula-like names are prefixed with an apostrophe to keep them literal in spreadsheet software. Use this report to analyze infant deaths and age-at-death distributions.

Python example:

```python
import csv

with open('world-1-daily.csv', newline='', encoding='utf-8') as source:
    rows = list(csv.DictReader(source))
print('Peak living population:', max(int(row['living']) for row in rows))
```

No new migration or per-tick storage is required.

## Tests

```sh
composer test
# With migrations 001–006 applied and a fixture world containing two living founders:
php tests/MortalityIntegrationTest.php
# With migrations 001–006 applied; creates and cleans up its own isolated world:
php tests/FamilyTreeIntegrationTest.php
# With migrations 001–006 applied; all fixture changes roll back:
php tests/MortalityPartnershipIntegrationTest.php
# With migrations 001–006 applied; creates and removes an isolated CLI world:
php tests/CreateWorldIntegrationTest.php
# With migrations 001–006 applied; creates and removes isolated history fixtures:
php tests/WorldHistoryIntegrationTest.php
# With migrations 001–006 applied; creates and removes isolated CSV fixtures:
php tests/CsvExportIntegrationTest.php
```

GitHub Actions runs PHP syntax checks, deterministic tests, mortality and family-tree regressions, and a 10,000-day multi-generation integration scenario on MariaDB. It runs on pull requests, pushes to main or feature/mortality-survival, and manual dispatch. Require green checks for the latest commit before considering a merge.
