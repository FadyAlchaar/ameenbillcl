# AmeenBill — deployment notes for this revision

## Do these in order

### 1. Rotate the database passwords (before anything else)

`config.php` and `config copy.php` were committed, so `alameenbill_reader / P@ssw0rd@2026`
and `sa / P@ssw0rd` are in the git history of five separate commits. Changing the
file does not un-publish them.

    -- edit the password first, then run:
    sqlcmd -S localhost\SQLEXPRESS -i sql/02_reader_login.sql

Also change the `sa` password by hand. `sql/02_reader_login.sql` additionally
restricts the dashboard login to `SELECT` on the seven tables it actually reads
and denies write access, so a flaw in the dashboard cannot modify accounting data.

### 2. Purge the secrets from git history

`.gitignore` now excludes `config.php`, but the old commits still carry it:

    git rm --cached config.php
    git commit -m "Stop tracking config.php"

    # then rewrite history (git-filter-repo is the supported tool)
    git filter-repo --invert-paths --path config.php --path "config copy.php"
    git push --force

If the repo is public, make it private first. Rotation (step 1) is what actually
protects you; history rewriting is cleanup.

### 3. Create the indexes

    sqlcmd -S localhost\SQLEXPRESS -d AlbassaDB2026 -i sql/01_indexes.sql

This is the single biggest performance change. Do it during a quiet period —
they are indexes on a table Alameen writes to.

### 4. Set the timezone

`config.php` now has `APP_TZ`. It must match the timezone of the **SQL Server
host**, because Alameen stores `CreateDate` / `LastUpdateDate` as local wall
time with no offset. If it is wrong, live updates will be skipped or replayed.

### 5. Change the dashboard password

The `admin` hash that shipped in the repo must be treated as known.

    php tools/generate_hash.php 'a-new-strong-password'

Paste the output into `users.php`.

### 6. Deploy and check

Files to copy: everything except `sql/` and `tools/` (those are operator tools,
and `.htaccess` blocks them anyway). Make sure `cache/` is writable by the web
server user.

Then verify:

- Opening `index.php` while logged out redirects to `login.php`.
- `sse_bills.php`, `?ajax=1`, `?details=1` return `401` while logged out.
- `config.php`, `lib.php`, `users.php`, `auth.php` return `403` over HTTP.
- `api/app-config.php` reports `start_page: /index.php`.

---

## Apache worker capacity

Each open dashboard tab holds one PHP worker and one SQL connection for as long
as the stream lives. With `mod_php` + prefork this is the binding constraint.

Budget at least `2 × (expected concurrent tabs) + 20` workers, e.g. in
`httpd.conf`:

    <IfModule mpm_prefork_module>
        StartServers         10
        MinSpareServers      10
        MaxSpareServers      25
        MaxRequestWorkers   150
        MaxConnectionsPerChild 1000
    </IfModule>

Streams now recycle themselves every 30 minutes (`MAX_STREAM_SECONDS` in
`sse_bills.php`), so a leaked connection can never live longer than that.
`EventSource` reconnects on its own, so users see nothing.

If the tab count ever grows past ~30, move off `mod_php` to `php-fpm` with a
dedicated pool for `sse_bills.php`, or raise `POLL_INTERVAL_MS` from 2000.

---

## Known remaining items (not changed here)

- **`OFFSET … FETCH` pagination** still re-reads and discards every skipped row,
  so "Load More" gets slower the deeper you go. Keyset pagination
  (`WHERE CreateDate < :lastSeen`) would fix it but changes the client
  contract, so it is left alone. The new `CreateDate` index makes the current
  form acceptable up to a few thousand rows.
- **No DOM virtualisation.** Every loaded card stays in the DOM. The per-click
  `querySelectorAll` sweep is gone, so this is now comfortable to a few
  thousand cards, but not unbounded.
- **No totals row.** The dashboard lists bills but never sums them; that is a
  feature request, not a bug.
