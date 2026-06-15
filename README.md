# Hamava Core Access

Reusable Laravel access control services for Hamava applications.

This repository is a Composer package only. It does not contain a Laravel
application, database dumps, environment files, or application-specific
credentials.

## Installation

Register the private VCS repository in the consuming Laravel application:

```bash
composer config repositories.hamava-core-access vcs https://github.com/minah2003/hamava_core_access.git
composer require hamava/core-access:^0.1
```

Laravel package auto-discovery registers:

```php
Hamava\CoreAccess\CoreAccessServiceProvider::class
```

Publish the package configuration when customization is needed:

```bash
php artisan vendor:publish --tag=hamava-core-access-config
```

The provider also registers the route middleware alias:

```php
Route::middleware('core.can:inventory.records.view')->group(function () {
    // Protected routes...
});
```

## Database Ownership

This package does not register or publish migrations. The consuming Laravel
application owns the database schema for the core access tables and should keep
those migrations in the application repository.

Default table names are configured in `config/core-access.php`.

## Main APIs

- `Hamava\CoreAccess\Services\CoreAccessResolver`
- `Hamava\CoreAccess\Services\CoreNavigationResolver`
- `Hamava\CoreAccess\Services\TeamScopeResolver`
- `Hamava\CoreAccess\Facades\CoreAccess`
- `Hamava\CoreAccess\Facades\CoreNavigation`
- `Hamava\CoreAccess\Middleware\CoreCan`

## Development

Expected local checks:

```bash
composer validate
composer install
vendor/bin/phpunit
vendor/bin/pint --test
```

The test suite uses an in-memory database fixture and does not require package
migrations.
