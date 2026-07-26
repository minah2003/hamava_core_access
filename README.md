# Hamava Access Control

Reusable Laravel access control services for Hamava applications.

This repository is a Composer package only. It does not contain a Laravel
application, database dumps, environment files, or application-specific
credentials.

The Composer package name is `hamava/access-control`. The PHP namespace remains
`Hamava\CoreAccess` throughout the backward-compatible `0.2.x` line. That legacy
namespace does not mean this package owns Core Runtime. Renaming the namespace,
configuration keys, middleware aliases, container aliases, facade aliases, or
publish tags requires a future major-version migration.

This package owns Authorization only. OAuth and PKCE flows, the Core HTTP
client, sessions, and Localization Runtime belong to a future
`hamava/core-runtime` package. FTTH- and ITSM-specific scope-to-column SQL
mapping remains in those consumer applications.

## Installation

When the package is not available through Packagist, register its public VCS
repository in the consuming Laravel application:

```bash
composer config repositories.hamava-access-control vcs https://github.com/minah2003/hamava-access-control.git
composer require hamava/access-control:^0.2.1
```

The 0.2 release supports PHP 8.3, 8.4, and 8.5 with Laravel / Illuminate 13,
according to `composer.json`.

Laravel package auto-discovery registers:

```php
Hamava\CoreAccess\CoreAccessServiceProvider::class
```

Publish the package configuration when customization is needed:

```bash
php artisan vendor:publish --tag=hamava-core-access-config
```


The provider registers two route middleware aliases:

- `core.can` performs a capability check without a resource descriptor.
- `core.node` checks whether the user may enter an access node or page.

See [Choosing an Authorization Entry Point](#choosing-an-authorization-entry-point)
before selecting middleware for a route.


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

1. An inactive or invalid user, permission, module, role, or assignment denies.
2. An explicit matching resource deny wins.
3. A matching team deny scope wins.
4. A valid base capability or operator-global assignment must exist.
5. Operator-global access may bypass missing allow scopes only when no
   applicable deny exists.
6. An explicit resource allow may allow the resource.
7. A matching team allow scope may allow the resource.
8. Otherwise, access is denied.

An explicit resource allow does not grant a capability by itself. The user
must first receive the capability through an active role assignment.

A matching team deny scope overrides an explicit resource allow.

An explicit resource deny overrides both matching allow scopes and an
operator-global capability.

## Deprecated Row-Scanning Query Scope

`Hamava\CoreAccess\Traits\ScopedByCoreTeam` and its
`scopeVisibleToCore()` query scope are deprecated since version `0.2.0` and
are scheduled for removal in version `1.0.0`.

Do not add this trait to new domain models.

The trait reads the entire model table and performs authorization checks for
individual records in PHP. This causes excessive memory usage, repeated
database queries, and incorrect pagination behavior on large tables.

List authorization must be implemented as a domain-specific SQL query in the
consuming application. The package cannot safely provide a generic query
scope because it does not know how scope types map to domain columns.

For example, after an FTTH-specific query service has resolved the complete
allow and deny scope sets, it can translate them into SQL predicates:

```php
$query
    ->whereIn('province_id', $allowedProvinceIds)
    ->whereNotIn('province_id', $deniedProvinceIds);
```

The example is illustrative. The domain query service must also handle
module-wide scopes, child scopes, access-node-specific scopes, explicit
resource grants, and deny precedence.

Existing uses may remain temporarily for backward compatibility, but they
must be migrated before version `1.0.0`.

`ScopedByCoreTeam` remains deprecated and must not be used by new domain code.

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
- `Hamava\CoreAccess\Services\CoreQueryAuthorizationResolver`
- `Hamava\CoreAccess\Data\QueryAuthorizationContext`
- `Hamava\CoreAccess\Data\ResourceDescriptor`
- `Hamava\CoreAccess\Contracts\DescribesCoreResource`
- `Hamava\CoreAccess\Services\ScopeCatalogService`
- `Hamava\CoreAccess\Services\ScopeEntityOptionProvider`
- `Hamava\CoreAccess\Services\TeamScopeResolver`
- `Hamava\CoreAccess\Facades\CoreAccess`
- `Hamava\CoreAccess\Facades\CoreNavigation`
- `Hamava\CoreAccess\Middleware\CoreCan`
- `Hamava\CoreAccess\Middleware\CoreNodeCan`
- `Hamava\CoreAccess\Services\CoreAccessContext`

## Spatie Permission Integration

Roles and permissions use `spatie/laravel-permission`. Consumer applications
must configure Spatie's permission and role models to use
`Hamava\CoreAccess\Models\CorePermission` and
`Hamava\CoreAccess\Models\CoreRole`, as demonstrated by the package test
environment. This package then combines those roles and permissions with
Hamava team memberships, assignments, scopes, and resource grants.

## Query Authorization Context

Consuming applications should inject `CoreQueryAuthorizationResolver` when they
need to build an index, search, export, or pagination query from the user's
authorization state:

```php
use Hamava\CoreAccess\Services\CoreQueryAuthorizationResolver;

$context = $resolver->resolve(
    $request->user(),
    'ftth.projects.view',
    'ftth',
);
```

`resolve()` returns a `QueryAuthorizationContext` with these fields:

- `capabilityGranted` means at least one active assignment grants the requested
  capability.
- `operatorGlobal` means at least one active assignment grants
  operator-global access for the module.
- `requiresScope` mirrors the permission or access-node scope requirement.
- `allowScopes` contains active allow scopes restricted to teams whose
  assignments grant the requested capability.
- `denyScopes` contains active deny scopes from all effective assignments,
  including assignments that grant operator-global access.
- `allowResourceGrants` contains explicit resource allow grants that apply to
  capability-granting principals.
- `denyResourceGrants` contains explicit resource deny grants that apply to any
  effective principal, including operator-global principals.
- `denialReason` explains fail-closed setup failures such as inactive users,
  undefined permissions, disabled modules, or module mismatches.
- `hasBaseGrant()` is true only when the user has either the requested
  capability or operator-global access.
- `hasAnyAllowPath()` is false without a base grant and is also false when an
  applicable unconditional `scope_type = all` deny scope blocks the whole
  query plan.

Only an explicit, valid `scope_type = all` is an intentional wildcard. Empty
or malformed scopes are ignored by authorization decision paths and never
become wildcards. Whitespace-only identifiers and asset values are empty;
numeric or string zero remains a valid scope value. A narrowed all-scope with
asset-category or asset-type constraints is row-specific, not an unconditional
deny of the complete query.

Explicit resource grants are separated into allow and deny collections.
Consuming applications must apply deny predicates before allow predicates.
Entity-specific deny scopes and explicit resource denies do not necessarily
mean that every row in a collection query is hidden; they are part of the plan
the application translates into SQL.

The package intentionally does not map domain scope types to SQL columns. Each
application must implement that mapping in its own domain query service.
`resolve()` is for collection and query planning. It does not replace
`CoreAccess::check()` for authorizing one concrete resource.

The domain query service must apply the returned plan in SQL before pagination,
export, count, or aggregation.

## Navigation Payloads

Navigation filtering is an Authorization concern, but navigation presentation
is owned by consumers. The package returns route names, icons, `label`,
`label_fa`, translation keys, and related presentation fields as compatibility
payloads. It does not resolve translations or implement Localization Runtime.

## Request-scoped Authorization Context

`CoreAccessContext` caches active memberships, modules, permissions, enabled
modules, active team scopes, and resource grants for the lifetime of one
application request.

`CoreAccessContext`, `TeamScopeResolver`, `CoreAccessResolver`, and
`CoreNavigationResolver` are registered as scoped container services.
`CoreQueryAuthorizationResolver` is scoped as well, while the stateless
assignment resolver is a singleton. Cached authorization state must not be
stored in static properties or shared application-wide caches.

The context represents a request-local authorization snapshot. When the
application modifies memberships, role assignments, roles, permissions,
modules, team scopes, or resource grants and needs to authorize again during
the same request, it must clear the snapshot:

```php
use Hamava\CoreAccess\Services\CoreAccessContext;

app(CoreAccessContext::class)->flush();

```
Normal read-only authorization requests do not need to call `flush()`.

## Domain Resource Contract

Domain models that participate in resource-level authorization should
implement `Hamava\CoreAccess\Contracts\DescribesCoreResource`.

```php
use Hamava\CoreAccess\Contracts\DescribesCoreResource;
use Hamava\CoreAccess\Data\ResourceDescriptor;
use Illuminate\Database\Eloquent\Model;

final class InventoryRecord extends Model implements DescribesCoreResource
{
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
}
```

Arbitrary objects are not converted automatically. Resource checks accept
arrays, `ResourceDescriptor` instances, or objects implementing
`DescribesCoreResource`.


## Choosing an Authorization Entry Point

The package exposes different authorization entry points for different
purposes. They are not interchangeable.

| Entry point | Use it for | Important limitation |
| --- | --- | --- |
| `core.can` | Route-level checks for capabilities that do not require a resource scope | No resource descriptor is passed to the resolver |
| `core.node` | Deciding whether a user may enter a page or access node | Page entry does not authorize every resource shown or modified on the page |
| `CoreAccess::check()` | Authorizing an operation on one concrete resource | The resource descriptor must contain the attributes required for scope matching |
| Domain query service | Restricting index and search queries at SQL level | The consuming application must implement the domain-to-scope mapping |

### Unscoped route capability: `core.can`

Use `core.can` only when the permission and its attached access node do not
require resource scope evaluation.

```php
Route::middleware('core.can:ftth.reports.export')
    ->get('/reports/export', ReportExportController::class);
```

The example assumes that `ftth.reports.export` is configured with
`requires_scope = false`.

`core.can` does not receive a resource descriptor. Therefore, it must not be
used as the authorization guarantee for viewing, updating, or deleting a
specific scoped resource.

For example, this is not appropriate for a scope-required permission:

```php
Route::middleware('core.can:ftth.projects.update')
    ->patch('/projects/{project}', ProjectUpdateController::class);
```

For a normal scoped role assignment, the resolver denies this check because
the capability requires a resource scope but no resource was provided.
Operator-global authorization remains an intentional exception according to
the package decision precedence.

### Page entry: `core.node`

Use `core.node` when the route represents entry into a page whose records will
later be restricted by a domain query service.

```php
Route::middleware('core.node:ftth.projects.view')
    ->get('/projects', ProjectIndexController::class);
```

For a scope-required permission, `core.node` checks whether the user has an
active role assignment and at least one applicable allow scope for the access
node or the module.

This only means that the user may enter the page. It does not mean that the
user may view every project returned by an unrestricted query.

Entity-specific deny scopes must be applied when building the list query or
checking an individual resource. A matching `all` deny scope may block page
entry itself.

### Concrete resource operation: `CoreAccess::check()`

Use `CoreAccess::check()` before viewing, updating, deleting, or executing
another operation on a concrete domain resource.

The domain model should implement `DescribesCoreResource` as described above.

```php
use Hamava\CoreAccess\Facades\CoreAccess;

$descriptor = $project->toCoreResourceDescriptor();

$decision = CoreAccess::check(
    $request->user(),
    'ftth.projects.update',
    $descriptor,
);

abort_unless($decision->allowed, 403);
```

The descriptor must include the resource identity and every domain attribute
needed for scope matching, such as province, city, service area, site, or
another supported scope dimension.

The returned `AccessDecision` also contains `reason` and `matched` data for
diagnostics and auditing. Application responses should normally expose only
the HTTP authorization result, not internal authorization details.

### Collection and index queries: Domain Query Service

List, search, export, and pagination endpoints must restrict records in SQL
through a domain-specific query service owned by the consuming application.

For example, an FTTH application may provide an application-level service such
as:

```php
$projects = app(ProjectVisibilityQuery::class)
    ->forUser(
        $request->user(),
        'ftth.projects.view',
    )
    ->paginate();
```

`ProjectVisibilityQuery` is an illustrative application service; it is not
provided by this package.

The query service must translate the applicable authorization state into SQL
predicates, including:

- module-wide and access-node-specific scopes;
- allow and deny scopes;
- supported parent and child scope relationships;
- explicit resource grants;
- operator-global access;
- authorization deny precedence.

Do not load all records and then call `CoreAccess::check()` for each row in
PHP. That approach causes repeated queries, excessive memory usage, and
incorrect pagination. See
[Deprecated Row-Scanning Query Scope](#deprecated-row-scanning-query-scope).


## Development

Expected local checks:

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
composer audit --no-interaction
vendor/bin/phpunit
vendor/bin/pint --test
git diff --check
```

GitHub Actions runs these checks for pull requests targeting `main`, pushes to
`main`, and version tags. Each trigger runs against PHP 8.3, 8.4, and 8.5.

A release tag must only be created from a green, merged `main` commit.

The test suite uses an in-memory database fixture and does not require package
migrations.
