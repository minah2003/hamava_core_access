<?php

namespace Hamava\CoreAccess\Services;

use Hamava\CoreAccess\Models\CoreModule;
use Hamava\CoreAccess\Models\CorePermission;
use Hamava\CoreAccess\Models\CoreTeamMember;
use Hamava\CoreAccess\Models\CoreTeamScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Hamava\CoreAccess\Models\CoreResourceGrant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class CoreAccessContext
{
    public function __construct(
    private readonly CoreCapabilityAssignmentResolver $assignments,
) {}


    /**
     * @var array<string, EloquentCollection<int, CoreTeamMember>>
     */
    private array $memberships = [];

    /**
     * @var array<string, CoreModule|null>
     */
    private array $modules = [];

    /**
     * @var array<string, CorePermission|null>
     */
    private array $permissions = [];

    /**
     * @var array<string, EloquentCollection<int, CoreTeamScope>>
     */
    private array $scopes = [];

    /**
 * @var array<string, EloquentCollection<int, CoreResourceGrant>>
 */
private array $resourceGrants = [];

    /**
     * @var EloquentCollection<int, CoreModule>|null
     */
    private ?EloquentCollection $enabledModules = null;

    /**
     * @return EloquentCollection<int, CoreTeamMember>
     */
    public function memberships(
        Authenticatable $user,
    ): EloquentCollection {
        $key = $user::class.':'.(string) $user->getAuthIdentifier();

        return $this->memberships[$key] ??= CoreTeamMember::query()
            ->with([
                'team',
                'team.teamRoles.role.permissions',
                'team.teamRoles.module',
                'roles.role.permissions',
                'roles.module',
            ])
            ->where(
                'user_id',
                $user->getAuthIdentifier(),
            )
            ->active()
            ->whereHas(
                'team',
                fn ($query) => $query->where(
                    'is_active',
                    true,
                ),
            )
            ->get();
    }



    public function module(string $code): ?CoreModule
    {
        if (array_key_exists($code, $this->modules)) {
            return $this->modules[$code];
        }

        return $this->modules[$code] = CoreModule::query()
            ->where('code', $code)
            ->first();
    }

    public function permission(string $name): ?CorePermission
    {
        if (array_key_exists($name, $this->permissions)) {
            return $this->permissions[$name];
        }

        $permission = CorePermission::query()
            ->with([
                'module',
                'accessNode',
            ])
            ->where('name', $name)
            ->first();

        if (! $permission) {
            $this->permissions[$name] = null;

            return null;
        }

        $this->primePermissions([$permission]);

        return $permission;
    }

    /**
     * Prime the request-local permission cache with models loaded in batch.
     *
     * Relations required by subsequent authorization checks, especially
     * accessNode, must be eager-loaded by the caller.
     *
     * @param  iterable<array-key, CorePermission>  $permissions
     */
    public function primePermissions(iterable $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->permissions[(string) $permission->name] = $permission;

            if (! $permission->relationLoaded('module')) {
                continue;
            }

            $module = $permission->getRelation('module');

            if (! $module instanceof CoreModule) {
                continue;
            }

            $moduleCode = (string) $module->code;

            if (! array_key_exists($moduleCode, $this->modules)) {
                $this->modules[$moduleCode] = $module;
            }
        }
    }

    /**
     * @return EloquentCollection<int, CoreModule>
     */
    public function enabledModules(): EloquentCollection
    {
        if ($this->enabledModules !== null) {
            return $this->enabledModules;
        }

        $modules = CoreModule::query()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($modules as $module) {
            $moduleCode = (string) $module->code;

            if (! array_key_exists($moduleCode, $this->modules)) {
                $this->modules[$moduleCode] = $module;
            }
        }

        return $this->enabledModules = $modules;
    }

    /**
     * @param  array<int|string>  $teamIds
     * @return EloquentCollection<int, CoreTeamScope>
     */
    public function scopes(
        array $teamIds,
        ?string $moduleCode = null,
    ): EloquentCollection {
        $teamIds = array_values(
            array_unique(
                $teamIds,
                SORT_REGULAR,
            ),
        );

        sort($teamIds);

        $key = implode(
            ',',
            array_map('strval', $teamIds),
        ).'|'.($moduleCode ?? '*');

        if (array_key_exists($key, $this->scopes)) {
            return $this->scopes[$key];
        }

        if ($teamIds === []) {
            return $this->scopes[$key] = new EloquentCollection;
        }

        $query = CoreTeamScope::query()
            ->with([
                'team',
                'module',
            ])
            ->whereIn('team_id', $teamIds)
            ->active();

        if ($moduleCode !== null) {
            $module = $this->module($moduleCode);

            /*
             * Important:
             * The old implementation returned scopes from every module
             * when an unknown module code was passed.
             */
            if (! $module) {
                return $this->scopes[$key] = new EloquentCollection;
            }

            $query->where(
                'module_id',
                $module->getKey(),
            );
        }

        return $this->scopes[$key] = $query->get();
    }

        /**
 * @param  Collection<int, array<string, mixed>>  $assignments
 * @return EloquentCollection<int, CoreResourceGrant>
 */
public function resourceGrants(
    Authenticatable $user,
    Collection $assignments,
    int|string $moduleId,
    int|string $capabilityId,
): EloquentCollection {
    $principals = $this->assignments
        ->principals($user, $assignments);

    $principalKey = $principals
        ->map(
            fn (array $principal): string => implode(':', [
                $principal['type'],
                (string) $principal['id'],
            ])
        )
        ->sort()
        ->implode(',');

    $key = implode('|', [
        (string) $moduleId,
        (string) $capabilityId,
        $principalKey,
    ]);

    if (array_key_exists($key, $this->resourceGrants)) {
        return $this->resourceGrants[$key];
    }

    $grants = CoreResourceGrant::query()
        ->where('module_id', $moduleId)
        ->where(
            fn (Builder $query): Builder => $query
                ->whereNull('capability_id')
                ->orWhere('capability_id', $capabilityId)
        )
        ->where(function (Builder $query) use ($principals): void {
            foreach ($principals as $principal) {
                $query->orWhere(
                    function (Builder $principalQuery) use ($principal): void {
                        $principalQuery
                            ->where(
                                'principal_type',
                                $principal['type'],
                            )
                            ->where(
                                'principal_id',
                                (string) $principal['id'],
                            );
                    }
                );
            }
        })
        ->active()
        ->get()
        ->filter(
            fn (CoreResourceGrant $grant): bool => filled($grant->resource_id)
                || filled($grant->resource_code)
        )
        ->values();

    return $this->resourceGrants[$key] = $grants;
}

    /**
     * Clear the request-local authorization snapshot.
     *
     * Call this after modifying memberships, assignments, roles,
     * permissions, modules, scopes or resource grants during the
     * same request.
     */
    public function flush(): void
    {
        $this->memberships = [];
        $this->modules = [];
        $this->permissions = [];
        $this->scopes = [];
        $this->resourceGrants = [];
        $this->enabledModules = null;
    }
}
