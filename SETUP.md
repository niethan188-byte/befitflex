# Setup

## Requirements

XAMPP 8.0 or newer (PHP 8.0+, MySQL 5.7 / MariaDB 10.4+). Nothing else.

## 1. Place the files

Copy the whole folder so the path reads:

```
C:\\xampp\\htdocs\\befitflex\\index.php
```

On macOS that is `/Applications/XAMPP/htdocs/befitflex/`,
on Linux `/opt/lampp/htdocs/befitflex/`.

## 2. Import the database

Open <http://localhost/phpmyadmin>, click **Import**, choose
`database/befitflex_gym.sql`, click **Go**. The file drops and recreates
`befitflex_gym`, so re-importing is always safe — it resets you to a clean
demo state.

Command line alternatives:

```bash
mysql -u root -p < database/befitflex_gym.sql
```

```bash
php tools/rebuild-database.php
```

```bash
composer db:reset
```

## 3. Check the connection

`config/db.php` ships with the XAMPP defaults:

```php
'host' => '127.0.0.1',
'name' => 'befitflex_gym',
'user' => 'root',
'pass' => '',          // XAMPP's root has no password by default
```

If you set a MySQL root password, put it in `pass`. If Apache runs on a
different port, nothing changes — the app uses relative paths throughout.

## 4. Install PHP dependencies

From the project directory, run:

```powershell
composer install
```

This installs Dompdf for downloadable payment invoices.

For an existing database, import `database/upgrade.sql` once after the main
schema. This non-destructive upgrade adds the `day_passes.member_id` link needed
to create a day pass for an existing member and show that pass QR on their
member card. Use the upgrade script rather than re-importing the fresh schema,
which resets the demo database.
The member `contact_number` column must be `TEXT` because the application stores
the encrypted contact value there for compatibility. Existing deployments
using the original `VARCHAR(20)` schema must widen it before registration:

```sql
ALTER TABLE members MODIFY contact_number TEXT NOT NULL;
```

Configure `BEFITFLEX_ENCRYPTION_KEY` before creating accounts or saving member
contact details. The key is required by the web application. The other variables
below are optional integrations:

```powershell
[Environment]::SetEnvironmentVariable('MAYA_PUBLIC_KEY', 'your_public_key', 'User')
[Environment]::SetEnvironmentVariable('MAYA_SECRET_KEY', 'your_secret_key', 'User')
[Environment]::SetEnvironmentVariable('MAYA_WEBHOOK_SECRET', 'your_webhook_secret', 'User')
[Environment]::SetEnvironmentVariable('BEFITFLEX_API_SECRET', 'generate-a-long-random-secret', 'User')
[Environment]::SetEnvironmentVariable('BEFITFLEX_ENCRYPTION_KEY', 'base64-encoded-32-byte-key', 'User')
```

Generate the encryption key instead of typing one manually:

```powershell
[Convert]::ToBase64String((1..32 | ForEach-Object { Get-Random -Maximum 256 }))
```

Keep both secrets outside the project and back them up securely. Existing member
contact numbers are backfilled into `members.contact_number_encrypted` when the
upgrade is applied; the legacy column remains temporarily for compatibility.

After setting environment variables on Windows, fully exit the XAMPP Control
Panel (including its notification-area process), then reopen it and start
Apache. Restarting Apache from an already-open control panel may leave Apache
with the old environment. Verify from the application environment with:

```powershell
composer health
```

The browser application and the CLI must both report PII encryption as
`configured`; otherwise Apache cannot decrypt protected contact numbers.
`composer smoke` also checks that the member contact column can hold the
encrypted value.

After starting MySQL, run `composer encrypt-contacts` to replace the legacy
plaintext contact values in `members.contact_number` with encrypted ciphertext.

Run the checks after setup:

```powershell
composer smoke
composer health
```

The health check should report the live PHP and database versions. Maya checkout
requires the public API key; return-page status verification also requires the
secret API key. Webhook notifications additionally require the webhook secret
and a publicly reachable HTTPS webhook endpoint. Apply `database/upgrade.sql`
to add the Maya checkout reference column before testing online payments. Do not
use the default mobile API secret outside local development.

## Deploy to Railway

The repository includes a Dockerfile for PHP 8.3 with Apache and the required
PHP extensions. Connect the GitHub repository to Railway and deploy the project
root; Railway will build the root `Dockerfile`. The container listens on
Railway's `PORT` and serves the app over Railway's public HTTPS domain. There is
no framework or frontend build step.

1. Add a Railway MySQL service and connect it to the web service. In the web
   service's **Variables**, add references to the database service variables
   (replace `MySQL` with that service's name):

   ```text
   MYSQLHOST=${{MySQL.MYSQLHOST}}
   MYSQLPORT=${{MySQL.MYSQLPORT}}
   MYSQLDATABASE=befitflex_gym
   MYSQLUSER=${{MySQL.MYSQLUSER}}
   MYSQLPASSWORD=${{MySQL.MYSQLPASSWORD}}
   ```

   `befitflex_gym` is the database name created by the seed SQL. Ensure the
   Railway MySQL user can create and access it; if the service restricts the
   user to its pre-created database, use a compatible database/user or adapt
   the schema import for that database before deployment.

   The app also accepts `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and
   `DB_PASSWORD`. Local XAMPP defaults are retained when none are set.

2. Initialize the Railway database with the schema in
   `database/befitflex_gym.sql`. **This is a destructive demo seed**: it drops
   and recreates `befitflex_gym`, including seeded demo accounts and data.
   Import it only into a new, disposable database after confirming the
   database credentials have permission to create and select that database.
   Never run it against a database containing data you need to keep. For an
   existing BeFitFlex database, back it up first and apply
   `database/upgrade.sql` to the database configured by `MYSQLDATABASE` (or
   `DB_NAME`). This upgrade adds schema required by current features, including
   the day-pass/member link. Ensure the script's `USE` statement targets that
   same database. Do not rerun the destructive seed to fix a schema mismatch.

   To initialize a fresh database and then add the 100 dummy members and
   payments from `tools/seed-dummy-members.php`, open the web service's Railway
   shell and run this once:

   ```sh
   composer railway:init
   ```

   This runs `tools/rebuild-database.php` followed by
   `tools/seed-dummy-members.php`, using the service's configured `MYSQL*`
   variables. It drops and recreates the configured database (for Railway, this
   is commonly named `railway`); **do not configure it as a build, start, or
   pre-deploy command**, and never run it on a database containing data you
   need. The dummy-member seeder also intentionally exits with an error if
   dummy accounts already exist. To load only the schema and built-in demo
   records, run `composer db:reset` instead.

3. Add persistent storage for announcement media. Create a Railway volume for
   the web service and mount it at `/var/www/html/assets/uploads`. Without a
   volume, uploaded files can disappear when the service is redeployed. Large
   video uploads are configured up to 1 GB in PHP, but actual upload limits
   may depend on Railway's platform and service plan.

4. Add required secrets in the web service's **Variables**. Use strong unique
   values, and retain the encryption key securely—losing it makes encrypted
   member contact numbers unreadable:

   - `BEFITFLEX_ENCRYPTION_KEY`: base64-encoded 32-byte key
   - `BEFITFLEX_API_SECRET`: long random API secret
   - `BEFITFLEX_APP_URL`: `https://befitflex.up.railway.app`
   - `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY`: keys for the Cloudflare
     Turnstile widget. Create a Managed widget in the Cloudflare dashboard and
     allow `befitflex.up.railway.app` in its hostname settings (hostname only,
     without `https://`). Put the site key and secret in the Railway web
     service's Variables; never put the secret in source code. Both keys and
     PHP cURL are required, and sign-in and registration fail closed if
     verification is not configured or Cloudflare cannot verify a token.

   For Maya sandbox checkout, also set `MAYA_PUBLIC_KEY` and `MAYA_SECRET_KEY`
   to sandbox credentials; the sandbox endpoint is the default. Set
   `MAYA_WEBHOOK_SECRET` if using the webhook, and configure that webhook in
   Maya to `https://befitflex.up.railway.app/api/maya-webhook.php`.

   Configure the Mailtrap Sandbox SMTP variables `MAIL_MAILER=smtp`,
   `MAIL_HOST=sandbox.smtp.mailtrap.io`, `MAIL_PORT=2525`,
   `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION=tls`,
   `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME` if email delivery is needed.
   Use the SMTP credentials shown for the Mailtrap Sandbox inbox, not an API
   token. The verification link uses `BEFITFLEX_APP_URL` when configured.

5. Deploy and inspect the Railway deployment logs. From the Railway service
   shell, run the health check to verify its live database connection, PHP
   extensions, and configured secrets:

   ```sh
   php tools/health-check.php
   ```

Turnstile works with this Railway deployment without routing the site through
Cloudflare. Cloudflare currently offers Turnstile on its Free plan with
unlimited challenges and verification requests. For local development, use
Cloudflare's documented [dummy test keys](https://developers.cloudflare.com/turnstile/troubleshooting/testing/):
site key `1x00000000000000000000AA` and secret key
`1x0000000000000000000000000000000AA`. They work on localhost, always pass,
and are for testing only; never use them in Railway production variables.

The seeded administrator and member passwords in `README.md` are demo
credentials; replace or remove demo accounts before opening the deployment to
the public. Railway's container filesystem is otherwise ephemeral. User
sessions may be lost on redeploy, and this setup is intended for a single web
replica; multiple replicas require shared session storage.

## 5. Open it

<http://localhost/befitflex/>

## 6. Add the brand images

| File | Used on |
|---|---|
| `assets/img/logo.png` | sidebar, sign-in card, browser tab |
| `assets/img/banner.png` | sign-in page hero |

Both are optional — an inline SVG mark stands in until you add them.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "Database connection failed" | MySQL is not running, or `pass` is wrong in `config/db.php` |
| Blank white page | Turn on display_errors, or read `C:\\xampp\\apache\\logs\\error.log` |
| "Unknown database befitflex_gym" | The import did not run — repeat step 2 |
| Styling looks flat, no blur | Your browser is old; `backdrop-filter` needs Chrome 76+, Safari 9+, Firefox 103+ |
| Logged out immediately | PHP sessions cannot write — check that `C:\\xampp\\tmp` exists and is writable |
| Peso sign shows as `?` | The page is being served as latin1; confirm the import kept utf8mb4 |

## Resetting the demo

Re-import `database/befitflex_gym.sql`. It begins with
`DROP DATABASE IF EXISTS befitflex_gym`, so everything returns to the seeded
state — four members, two branches, and five payments. The application has
admin and member accounts.
