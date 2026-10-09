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

For an existing database, import `database/upgrade.sql` after the main schema.

Optional integration variables:

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

After setting environment variables on Windows, restart Laragon completely so
Apache inherits them. Verify from the application environment with:

```powershell
composer health
```

The browser application and the CLI must both report PII encryption as
`configured`; otherwise Apache cannot decrypt protected contact numbers.

After starting MySQL, run `composer encrypt-contacts` to replace the legacy
plaintext contact values in `members.contact_number` with encrypted ciphertext.

Run the checks after setup:

```powershell
composer smoke
composer health
```

The health check should report the live PHP and database versions. Maya will
remain `not configured` until merchant credentials and a public webhook endpoint
are supplied. Do not use the default mobile API secret outside local development.

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
