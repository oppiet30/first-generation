# Mortality and extinction

Run `database/migrations/006_mortality.sql` after migrations 001–005.

Daily age-dependent mortality and 0.2% maternal mortality at delivery are **illustrative gameplay assumptions**, not historical or clinical estimates. Deaths set `people.death_day`, record a cause in `deaths`, append an event, and close any active partnerships. A pregnancy whose mother dies before delivery closes without a birth. If the last living person dies, the world becomes `completed`, an extinction event is recorded, and the simulation CLI stops.

Run `php bin/population-report.php 1` for births, living population, deaths, and causes. RNG remains deterministic for a given seed and version.
