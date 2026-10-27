# Aging and genealogy

Each tick is one day, and a year is 365 days. Founders begin at age 20 (7,300 days), while future children begin at age zero. Age is computed from the birth day, initial age, and current day (or death day).

Apply migration 002 after migration 001:

```sh
mariadb -u YOUR_USER -p first_generation < database/migrations/002_aging_genealogy.sql
php bin/family-tree.php 1
php bin/family-tree.php 1 1
```

The family-tree command lists inhabitants with their parent IDs or traces an individual's ancestors. This milestone does not introduce births, mortality, or outsiders.
