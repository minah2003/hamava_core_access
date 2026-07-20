<?php

namespace Hamava\CoreAccess\Services;

use Hamava\CoreAccess\Models\CoreModule;
use Hamava\CoreAccess\Models\CorePermission;
use Hamava\CoreAccess\Models\CoreTeamMember;
use Hamava\CoreAccess\Models\CoreTeamScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class CoreAccessContext
{
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

        $this->permissions[$name] = $permission;

        /*
         * Permission already loaded its module. Put that module in the
         * module cache so CoreAccessResolver does not query it again.
         */
        if ($permission?->module) {
            $moduleCode = (string) $permission->module->code;

            if (! array_key_exists($moduleCode, $this->modules)) {
                $this->modules[$moduleCode] = $permission->module;
            }
        }

        return $permission;
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
     * Clear the request-local authorization snapshot.
     *
     * Call this after modifying memberships, assignments, roles,
     * permissions, modules or scopes during the same request.
     */
    public function flush(): void
    {
        $this->memberships = [];
        $this->modules = [];
        $this->permissions = [];
        $this->scopes = [];
        $this->enabledModules = null;
    }
}
