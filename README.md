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

## Current Scope

This scaffold reserves lightweight namespaces for the future importer architecture:

- Playground package validation and reading
- Playground source-data access
- Import planning and transformation
- WordPress destination writing

The eventual importer should treat a Playground SQLite database as a source datastore. It must not assume the destination WordPress site uses SQLite.

The following are intentionally out of scope for this scaffold:

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

## References

- [WordPress requirements](https://wordpress.org/about/requirements/)
- [`wp-env` documentation](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
- [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards)
