# Dashboard on Dell: Apache and PHP-FPM

Dell has Fedora Apache and PHP-FPM running, with the PHP handler configured in `/etc/httpd/conf.d/php.conf` and its socket at `/run/php-fpm/www.sock`. No additional PHP packages are required. The template in `deploy/apache/first-generation.conf` adds an independent HTTP virtual host named `first-generation.local`; it does not replace the existing sites. Requests are restricted to loopback and `10.1.10.0/24`.

These are deployment instructions, not changes applied by merging. Do not enable the site until the dashboard code is merged and the application copy and database credentials are ready.

## Prepare a web-server checkout

Use a dedicated checkout at `/var/www/first-generation`, with only its `public/` subdirectory exposed. Keep the CLI checkout at `/home/oppie/first-generation` separate; no broad home-directory permission changes are needed. Check that the destination does not already contain an application before creating or using it. Check out the reviewed merged commit and install its Composer dependencies.

The Apache/PHP-FPM user `apache` needs read/traverse access to the application, `vendor/`, and the private `.env`. It does not need write access to the application or database for this dashboard. Do not make the application tree or credentials world-writable. Keep `.env` outside `public/`, readable only by its owner and the intended service account/group. This project's `.env` values are literal and must not be shell-quoted; see the README.

Point the dashboard at the existing database. A separate MariaDB account with SELECT privileges on that database is sufficient for this web checkout and keeps the simulation's write credentials in the CLI checkout. Configure that web account in `/var/www/first-generation/.env`. Do not create, recreate, migrate, or advance the existing world to deploy this code.

If SELinux is enforcing, use the existing site's approved labeling approach for `/var/www` and the database connection. Keep SELinux enabled; do not disable it or recursively relabel the user's home. On the inspected Dell configuration SELinux was permissive, so no SELinux change was required for the syntax check.

## Enable the independent virtual host

After preparing the checkout, review and install the template as `/etc/httpd/conf.d/first-generation.conf`. Preserve any existing file rather than overwriting it without inspection. The existing Fedora PHP configuration handles `api.php` through PHP-FPM; no second FPM pool or PHP development server is needed.

Before reloading Apache:

```sh
sudo apachectl configtest
sudo httpd -S
```

Confirm `Syntax OK`, the new hostname maps to the intended public directory, and the other hosts remain present. Then use a graceful reload:

```sh
sudo systemctl reload httpd
```

Verify locally without changing DNS first:

```sh
curl --fail --header 'Host: first-generation.local' http://127.0.0.1/
curl --fail --header 'Host: first-generation.local' 'http://127.0.0.1/api.php?world=1'
```

Check that the page, JSON API and CSV downloads succeed, and that `/.env`, `/vendor/autoload.php` and `/database/migrations/001_initial.sql` return 403 or 404. Compare world 1's population report before and after deployment; loading the page must leave its day, random state and population unchanged. Also check an existing local site after the reload.

## Open from another LAN device

Resolve `first-generation.local` to `10.1.10.138` using local DNS or that device's hosts file. For a hosts-file entry:

```text
10.1.10.138 first-generation.local
```

Then visit `http://first-generation.local/`. Using the raw IP will select Dell's existing default virtual host, not this named site. This configuration does not add HTTPS, modify certificates, change firewall rules or expose a new port. It provides a trusted-LAN, unauthenticated statistics page; do not publish it on the public internet.

## Page loads but statistics return HTTP 503

If the dashboard loads but reports HTTP 503, inspect `/api.php?world=1`
directly. An HTML **Service Unavailable** response is an Apache/backend failure,
not a JSON statistics report. A running Apache can still serve static HTML while
PHP-FPM is stopped, restarting, overloaded, or inaccessible through its socket.

On Dell, inspect the services and logs before changing configuration:

```sh
systemctl status httpd php-fpm --no-pager
sudo journalctl -u php-fpm -u httpd --since '1 hour ago' --no-pager
sudo tail -n 80 /var/log/httpd/first-generation_error.log
sudo ls -l /run/php-fpm/www.sock
```

Compare socket errors with the handler in `/etc/httpd/conf.d/php.conf` and
PHP-FPM's configured listen address. If PHP-FPM is stopped, resolve the logged
cause before starting it with `sudo systemctl start php-fpm`. If it is running,
investigate the logged capacity or connection error rather than restarting
services blindly. Do not disable SELinux or make the socket world-writable.

Repeat the API check above and confirm it returns JSON, then reload the dashboard.
The browser error-handling change does not restore an unavailable PHP service.

## Large populations and PHP memory

The open dashboard refreshes statistics every five minutes while the page is
visible. Leave **To day** blank to follow the latest saved simulation day, or
enter an end day to keep a historical range. Automatic refresh waits for current
requests and pauses while filters have unapplied edits. Full history returns to
day zero with no fixed end day.

Statistics read population rows with an unbuffered PDO cursor into a private
temporary file. Compact parent IDs support genealogy calculations without keeping
every person's full database row in memory. The operating system's temporary
directory must have enough free disk space; PHP removes the temporary files when
they close or the request ends. Failure to create or write a spool returns the
normal logged API error instead of an incomplete report.

The dashboard sends at most 2,000 daily observations and 200 individual death
records, and omits the unused per-person export. The page labels sampled graphs
and truncated tables. Summary counts, yearly counts, age distributions and
generation totals still include all applicable records. Both range endpoints
are shown. CSV downloads keep every day, person and death, using disk-backed
iterables rather than collecting all export rows in PHP arrays.

Regression tests cover 420,000 people and 100,000 days under a 128 MiB PHP limit.
Memory still grows with the compact ancestry index and the number of distinct
event days; this is a reduction in memory use, not unlimited population capacity.

## Rollback

Disable only the newly installed First Generation virtual-host configuration, validate Apache's configuration, and gracefully reload. Leave the other virtual hosts and the simulation database alone. The dedicated application checkout can remain for later inspection; no database rollback is necessary.
