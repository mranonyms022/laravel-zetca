# SQL Server setup

The package supports SQL Server 2019 and newer. PHP talks to SQL Server through two pieces you install
yourself:

1. **Microsoft ODBC Driver 18 for SQL Server**: a system driver, installed once per machine.
2. **The PHP extensions `sqlsrv` and `pdo_sqlsrv`**: from *Microsoft Drivers for PHP for SQL Server*.
   Laravel uses `pdo_sqlsrv`.

If either is missing, every query fails with:

```text
SQLSTATE[IMSSP]: This extension requires the Microsoft ODBC Driver for SQL Server to communicate with SQL Server.
```

That message means step 1 is missing. `could not find driver` means step 2 is missing.

::: warning ⚠ Test status
The package's automated test suite has not yet completed a run on SQL Server (the CI machine lacked the
ODBC driver). MySQL, MariaDB and PostgreSQL pass the full suite. If you use SQL Server, run the package's
tests against your server once (see [the end of this page](#verify-with-the-package-tests)) and report any
failure.
:::

## Windows

### 1. Install the ODBC driver

Download and run **Microsoft ODBC Driver 18 for SQL Server (x64)** from Microsoft:
<https://learn.microsoft.com/sql/connect/odbc/download-odbc-driver-for-sql-server>

### 2. Find out which DLLs you need

The DLL file names depend on your PHP version and on whether PHP is thread-safe (TS) or not (NTS):

```powershell
php -v                                # e.g. PHP 8.3.x → "83"
php -i | findstr "Thread Safety"      # enabled → ts, disabled → nts
php -i | findstr "Architecture"       # must be x64
```

| Your PHP | DLLs to use |
|---|---|
| 8.3, thread-safe (WAMP, XAMPP, Apache module) | `php_sqlsrv_83_ts_x64.dll`, `php_pdo_sqlsrv_83_ts_x64.dll` |
| 8.3, non-thread-safe (IIS / FastCGI, Laragon with NTS) | `php_sqlsrv_83_nts_x64.dll`, `php_pdo_sqlsrv_83_nts_x64.dll` |
| Other versions | Same pattern: `php_pdo_sqlsrv_<81|82|83|84>_<ts|nts>_x64.dll` |

### 3. Download and copy the DLLs

1. Download **Microsoft Drivers for PHP for SQL Server** (the newest release that lists your PHP version):
   <https://learn.microsoft.com/sql/connect/php/download-drivers-php-sql-server>
2. Run the download. It extracts the DLLs into a folder you choose.
3. Copy the two matching DLLs into your PHP `ext` folder. To find it:

   ```powershell
   php -i | findstr "extension_dir"
   ```

   For WAMP this is, for example, `C:\wamp64\bin\php\php8.3.x\ext`.

### 4. Enable them in php.ini

Find the `php.ini` that is actually loaded (WAMP has separate files for the CLI and Apache):

```powershell
php --ini
```

Add these lines, using your file names but without `.dll`:

```ini
extension=php_sqlsrv_83_ts_x64
extension=php_pdo_sqlsrv_83_ts_x64
```

Restart Apache, IIS or PHP-FPM, then check:

```powershell
php -m | findstr sqlsrv     # must list pdo_sqlsrv and sqlsrv
```

On WAMP, also enable the extensions for Apache: left-click the tray icon → PHP → PHP extensions, or edit the
`php.ini` in the Apache folder.

## Linux (Ubuntu / Debian)

```bash
# 1. ODBC driver 18 (Ubuntu 24.04 shown; use your release number in the URL)
curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | sudo gpg --batch --yes --dearmor -o /usr/share/keyrings/microsoft-prod.gpg
curl -fsSL https://packages.microsoft.com/config/ubuntu/24.04/prod.list | sudo tee /etc/apt/sources.list.d/mssql-release.list
sudo apt-get update
sudo ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev

# 2. PHP extensions (needs php-dev / php-pear for your PHP version)
sudo pecl install sqlsrv pdo_sqlsrv
echo "extension=sqlsrv.so"     | sudo tee /etc/php/8.3/mods-available/sqlsrv.ini
echo "extension=pdo_sqlsrv.so" | sudo tee /etc/php/8.3/mods-available/pdo_sqlsrv.ini
sudo phpenmod sqlsrv pdo_sqlsrv
sudo systemctl restart php8.3-fpm     # or apache2

php -m | grep sqlsrv
```

For other distributions (RHEL, Alpine, SUSE) Microsoft lists the commands on the ODBC download page above.

**Docker** (official `php` images):

```dockerfile
RUN apt-get update \
 && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --batch --yes --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
 && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list > /etc/apt/sources.list.d/mssql-release.list \
 && apt-get update && ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev \
 && pecl install sqlsrv pdo_sqlsrv && docker-php-ext-enable sqlsrv pdo_sqlsrv
```

## macOS

```bash
brew tap microsoft/mssql-release https://github.com/Microsoft/homebrew-mssql-release
HOMEBREW_ACCEPT_EULA=Y brew install msodbcsql18 mssql-tools18
pecl install sqlsrv pdo_sqlsrv
php -m | grep sqlsrv
```

## Laravel connection

```dotenv
DB_CONNECTION=sqlsrv
DB_HOST=127.0.0.1
DB_PORT=1433
DB_DATABASE=your_database
DB_USERNAME=your_user
DB_PASSWORD=your_password
```

ODBC Driver 18 **encrypts connections by default** and checks the server certificate. With a server that
uses a self-signed certificate (local development, Docker) the connection fails with
`SSL Provider: certificate verify failed`. Then either install a trusted certificate on the server
(production) or, for development only, trust the server certificate:

```php
// config/database.php → connections.sqlsrv
'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', false),
```

```dotenv
DB_TRUST_SERVER_CERTIFICATE=true   # development only
```

Then run the migrations as usual:

```bash
php artisan migrate
```

Notes:

- The database must exist before migrating (`CREATE DATABASE your_database`).
- The package creates its own indexes and avoids unique indexes on nullable columns, because SQL Server
  allows only one `NULL` in a unique index. Uniqueness on those columns is checked in code.
- Use a case-insensitive collation (the SQL Server default, e.g. `SQL_Latin1_General_CP1_CI_AS`) or a
  `_UTF8` collation. Arabic names are stored in `nvarchar` columns either way.

## Verify with the package tests

From a clone of the package, with the extensions installed and an empty database:

```bash
composer install
ZATCA_TEST_DB=sqlsrv DB_HOST=127.0.0.1 DB_DATABASE=zatca_test DB_USERNAME=sa DB_PASSWORD='YourPassw0rd' vendor/bin/phpunit
```

PowerShell:

```powershell
$env:ZATCA_TEST_DB="sqlsrv"; $env:DB_DATABASE="zatca_test"; $env:DB_USERNAME="sa"; $env:DB_PASSWORD="YourPassw0rd"
vendor/bin/phpunit
```

The test connection trusts the server certificate automatically. All tests should pass. If not, please
open an issue with the output.
