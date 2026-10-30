# Graphical family tree

Open `family-tree.html` from the statistics dashboard. The link preserves the loaded world. Direct links accept `?world=1&person=123`.

The page shows the selected person, their two recorded parents, and up to 20 children. Selecting a relative centers that person's family; Back returns to the previous selection. Next children and First children navigate larger families. Search accepts a person ID (with an optional #) or the beginning of a name. Results are limited to 20; narrow the prefix when more match. Zoom and the scrollable diagram help with larger families. Sex and living/deceased status are written on every box, independent of color. Simulation days, initial founder age, and age at death/current age are displayed.

This is a current-records view, not the dashboard's historical day range. It does not attempt to draw an entire world or label a co-parent as a current partner. Both parent IDs are listed on each child's box. Each request uses a consistent database snapshot and executes only SELECT statements, aside from transaction setup. No external chart libraries are used.

## Deployment

Apply `database/migrations/007_family_search.sql` once with an account allowed to create indexes, before using name search. It adds `(world_id,name,id)` for prefix name search and `(world_id,id)` for selecting a world's first person without sorting its entire population. Building indexes on a large database may take time and consume disk space; apply during a quiet period. No simulation data is rewritten by the application.

Deploy `app/Simulation/FamilyTreeView.php`, `public/family-api.php`, `public/family-tree.html`, the new `public/assets/family-tree.js` and `.css`, and the updated dashboard HTML/JS. Keep the site's existing environment file. The updated development router allows these new public paths; Apache serves them directly from the existing public document root. No change to the CLI family-tree command is required.

## Validation

`tests/FamilyTreeViewIntegrationTest.php` checks 25-child pagination, both parents' child lookups, founder defaults, ages, literal wildcard handling and bounded search. `tests/StatisticsIntegrationTest.php` exercises the HTTP API, input validation, cross-world isolation, read-only parity and the separate page. Existing dashboard tests still run.
