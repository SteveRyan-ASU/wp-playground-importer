# WP Playground Importer

A WordPress plugin scaffold for importing a WordPress Playground exported ZIP into an existing conventional WordPress installation backed by MySQL/MariaDB.

This repository currently contains development and testing infrastructure only. Importer behavior is intentionally not implemented yet.

## Baseline

- WordPress: current stable WordPress through `wp-env`
- PHP: 8.3+
- Database: the MySQL/MariaDB service provided by `wp-env`
- Plugin slug: `wp-playground-importer`
- PHP namespace: `WP_Playground_Importer`
- License: GPL-2.0-or-later

## Prerequisites

- Docker
- Node.js and npm
- Composer
- PHP 8.3+

## Setup

Install PHP and JavaScript dependencies:

```bash
composer install
npm install
```

Start WordPress:

```bash
npm run env:start
```

Activate the plugin:

```bash
npm run env:activate
```

The local WordPress site is available at the URL reported by `wp-env`. This project uses `http://localhost:8890` for development and `http://localhost:8891` for the test environment, with username `admin` and password `password`.

## Local Development Commands

```bash
npm run env:start      # Start the development and test environments.
npm run env:stop       # Stop wp-env containers.
npm run env:destroy    # Destroy wp-env containers and data.
npm run env:reset      # Destroy, recreate, and activate the plugin.
npm run env:activate   # Activate this plugin in the development site.
npm run env:cli -- ... # Run WP-CLI against the development site.
```

Useful WP-CLI inspection examples:

```bash
npm run env:cli -- plugin list
npm run env:cli -- option get siteurl
npm run env:cli -- post list
```

## Playground Package Inspection

The plugin can now perform read-only inspection of a WordPress Playground export ZIP. Inspection currently:

- recognizes a candidate ZIP by checking for `playground-export.json`, `wp-content/`, and `wp-content/database/.ht.sqlite`
- reads and decodes `playground-export.json`
- extracts only `.ht.sqlite` to a temporary file for inspection
- opens the SQLite database with `SQLite3` in read-only mode
- detects the WordPress table prefix from source database tables instead of hard-coding `wp_`
- verifies expected WordPress tables are present
- reports source `home`, `siteurl`, table prefix, table list, content counts by post type/status, active theme options, and active plugin paths

Inspection does not import, copy, activate, migrate, or persist source package data into the destination WordPress site.

### SQLite Support

In the current `wp-env` runtime, PHP 8.3.33 provides:

- `SQLite3`
- `PDO`
- `pdo_sqlite`
- `ZipArchive`

The implementation uses `ZipArchive` for archive access and `SQLite3` with `SQLITE3_OPEN_READONLY` for source database inspection.

### Manual Inspection

Real Playground exports should not be committed unless they have been deliberately reviewed and sanitized. For local manual testing, place or copy exports under the ignored directory:

```text
local-playground-exports/
```

Then inspect a local export with:

```bash
npm run env:cli -- playground-importer inspect wp-content/plugins/wp-playground-importer/local-playground-exports/example.zip
```

The command prints a JSON inspection result. It is a developer inspection surface only; it is not a production importer command.

## Migration Planning

The plugin can also generate a read-only migration plan. The planner compares an inspected Playground source package with the current destination WordPress installation and returns a structured description of what a future import would need to do.

```bash
npm run env:cli -- playground-importer plan wp-content/plugins/wp-playground-importer/local-playground-exports/example.zip
npm run env:cli -- playground-importer plan wp-content/plugins/wp-playground-importer/local-playground-exports/example.zip --format=json
```

Planning currently includes:

- destination site inspection through normal WordPress APIs
- destination freshness classification as `fresh_or_nearly_fresh` or `populated`
- source content classification by post type and status
- source author mapping proposals to an existing destination administrator
- option classification for migrate, remap, preserve-destination, and review behavior
- source/destination theme and plugin comparison
- upload-file inventory from package entries without copying files
- relationship summaries for future ID remapping
- source-to-destination URL transformation requirements
- review warnings for unknown post types, unavailable themes/plugins, additional source tables, and populated destinations

The plan uses these action classifications:

- `migrate`: source data should eventually be reproduced on the destination
- `remap`: source data is meaningful but requires ID or URL transformation
- `preserve_destination`: destination value should not be overwritten by source data
- `review`: behavior is uncertain and requires a future decision
- `unsupported`: reserved for explicitly unsupported future cases

The CLI is only a developer planning surface. Planning does not create content, copy uploads, install themes/plugins, activate anything, migrate options, rewrite URLs, allocate IDs, or modify either database.

## Checks and Tests

```bash
composer lint   # PHP syntax checks.
composer phpcs  # WordPress Coding Standards.
composer phpcbf # Auto-fix coding-standard issues where possible.
npm test        # PHPUnit integration tests inside wp-env.
composer check  # Local PHP lint, coding standards, and PHPUnit.
npm run check   # Same combined PHP checks through npm.
```

`npm test` runs PHPUnit inside the `wp-env` test container so tests execute against a bootstrapped WordPress installation and database.

The coding-standards configuration uses WordPress Coding Standards with one project-specific exception: the filename sniff is disabled because this scaffold uses Composer PSR-4 autoloading for namespaced classes.

## Fixture Convention

Future Playground ZIP integration fixtures belong in:

```text
tests/fixtures/playground-zips/
```

Do not add ad hoc Playground exports. Fixtures should be purpose-built or deliberately selected, then reviewed for credentials, user data, environment-specific values, unnecessary media, and repository size before committing.

Automated tests currently generate the smallest synthetic ZIP and SQLite fixtures needed at runtime. CI does not depend on a private or local real-world Playground export.

## Current Scope

This project reserves lightweight namespaces for the importer architecture:

- Playground package validation and reading
- Playground source-data access
- Import planning and transformation
- WordPress destination writing

The eventual importer should treat a Playground SQLite database as a source datastore. It must not assume the destination WordPress site uses SQLite.

The following remain intentionally out of scope:

- Playground ZIP validation
- ZIP extraction
- SQLite parsing
- SQLite to MySQL/MariaDB migration
- WordPress content migration
- media migration
- URL rewriting
- user mapping
- theme/plugin migration
- admin UI
- production WP-CLI importer commands
- actual importer behavior

Current inspection limitations:

- WordPress product version is reported as unavailable unless it can be derived reliably later; the source database schema `db_version` is exposed separately.
- Multisite packages are not supported.
- No destination writes are performed, including options, posts, users, themes, plugins, uploads, or package state.

Current planning limitations:

- Author mapping is proposed to an existing destination administrator; no user creation or credential migration is attempted.
- Unknown/custom post types are surfaced for review rather than assumed safe.
- Plugin-specific tables are inventoried and marked for review; plugin-data migration is not implemented.
- Theme/plugin availability is compared only against what is already installed on the destination.
- URL and ID remapping requirements are identified, but no transformation engine exists yet.

## References

- [WordPress requirements](https://wordpress.org/about/requirements/)
- [`wp-env` documentation](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
- [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards)
