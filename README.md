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

## Team Scope Semantics

Team scopes in `core_team_scopes` can be either module-wide or access-node
specific:

- `access_node_id = null` means the scope is module-wide. It can match any
  access node in the scope's module and preserves legacy scope behavior.
- `access_node_id != null` means the scope only matches checks for that exact
  access node. If a permission has no `access_node_id`, node-specific scopes do
  not match it.

Scope enforcement is still controlled by `permissions.requires_scope` or
`core_access_nodes.requires_scope`. Catalog rules do not enforce access by
themselves.

## Authorization Decision Precedence

For resource-scoped capabilities, authorization decisions use the following
precedence:

1. Explicit resource deny
2. Matching team deny scope
3. Operator-global capability
4. Explicit resource allow
5. Matching team allow scope
6. Deny when no allow rule matches

An explicit resource allow does not grant a capability by itself. The user
must first receive the capability through an active role assignment.

A matching team deny scope overrides an explicit resource allow.

An explicit resource deny overrides both matching allow scopes and an
operator-global capability.

## Scope Catalogs

Consuming applications own migrations for the dynamic scope catalog tables:

- `core_scope_entity_providers` defines where selectable scope entities come
  from for a `module_code` and `scope_type`. Table-backed providers use the
  persisted table and column metadata; request data never supplies table or
  column names directly.
- `core_access_node_scope_rules` defines the scope types and UI/catalog rules
  available for each access node, including entity requirements, child-scope
  support, and asset category/type modes.

`Hamava\CoreAccess\Services\ScopeCatalogService` and
`Hamava\CoreAccess\Services\ScopeEntityOptionProvider` both return empty
results gracefully when these tables have not been created yet.

## Main APIs

- `Hamava\CoreAccess\Services\CoreAccessResolver`
- `Hamava\CoreAccess\Services\CoreNavigationResolver`
- `Hamava\CoreAccess\Services\ScopeCatalogService`
- `Hamava\CoreAccess\Services\ScopeEntityOptionProvider`
- `Hamava\CoreAccess\Services\TeamScopeResolver`
- `Hamava\CoreAccess\Facades\CoreAccess`
- `Hamava\CoreAccess\Facades\CoreNavigation`
- `Hamava\CoreAccess\Middleware\CoreCan`

## Domain Resource Contract

Domain models that participate in resource-level authorization should
implement:

```php
Hamava\CoreAccess\Contracts\DescribesCoreResource
public function toCoreResourceDescriptor(): ResourceDescriptor
{
    return ResourceDescriptor::make(
        module_code: 'inventory',
        resource_type: 'record',
        resource_id: $this->getKey(),
        attributes: [
            'region_id' => $this->region_id,
        ],
    );
}

Arbitrary objects are not converted automatically. Resource checks accept
arrays, ResourceDescriptor instances, or objects implementing
DescribesCoreResource.
```

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
