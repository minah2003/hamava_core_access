<?php

namespace Hamava\CoreAccess\Tests;

use Hamava\CoreAccess\CoreAccessServiceProvider;
use Hamava\CoreAccess\Models\CoreAccessNode;
use Hamava\CoreAccess\Models\CoreModule;
use Hamava\CoreAccess\Models\CorePermission;
use Hamava\CoreAccess\Models\CoreRole;
use Hamava\CoreAccess\Models\CoreTeam;
use Hamava\CoreAccess\Models\CoreTeamMember;
use Hamava\CoreAccess\Models\CoreTeamMemberRole;
use Hamava\CoreAccess\Models\CoreTeamScope;
use Hamava\CoreAccess\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            CoreAccessServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.web', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => User::class,
        ]);

        $app['config']->set('permission.models.permission', CorePermission::class);
        $app['config']->set('permission.models.role', CoreRole::class);
        $app['config']->set('permission.table_names.permissions', 'permissions');
        $app['config']->set('permission.table_names.roles', 'roles');
        $app['config']->set('permission.table_names.model_has_permissions', 'model_has_permissions');
        $app['config']->set('permission.table_names.model_has_roles', 'model_has_roles');
        $app['config']->set('permission.table_names.role_has_permissions', 'role_has_permissions');
        $app['config']->set('permission.column_names.model_morph_key', 'model_id');
        $app['config']->set('permission.column_names.team_foreign_key', 'team_id');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function user(string $name = 'user', bool $active = true): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => "{$name}@example.test",
            'is_active' => $active,
        ]);
    }

    protected function module(string $code = 'inventory', bool $requiresScope = true): CoreModule
    {
        return CoreModule::query()->create([
            'code' => $code,
            'name' => ucfirst($code),
            'requires_scope' => $requiresScope,
            'is_enabled' => true,
            'sort_order' => 1,
        ]);
    }

    protected function node(CoreModule $module, string $code, ?CoreAccessNode $parent = null, string $type = 'page'): CoreAccessNode
    {
        return CoreAccessNode::query()->create([
            'module_id' => $module->id,
            'parent_id' => $parent?->id,
            'code' => $code,
            'label' => ucfirst(str($code)->afterLast('.')->toString()),
            'node_type' => $type,
            'is_visible_in_navigation' => true,
            'requires_scope' => false,
            'sort_order' => 1,
        ]);
    }

    protected function permission(string $name, CoreModule $module, bool $requiresScope = false, ?CoreAccessNode $node = null): CorePermission
    {
        return CorePermission::query()->create([
            'name' => $name,
            'guard_name' => 'web',
            'module_id' => $module->id,
            'access_node_id' => $node?->id,
            'requires_scope' => $requiresScope,
            'is_active' => true,
        ]);
    }

    protected function role(string $name, CoreModule $module, CorePermission ...$permissions): CoreRole
    {
        $role = CoreRole::query()->create([
            'name' => $name,
            'guard_name' => 'web',
            'module_id' => $module->id,
            'display_name' => ucfirst($name),
            'is_active' => true,
        ]);

        foreach ($permissions as $permission) {
            DB::table('role_has_permissions')->insert([
                'role_id' => $role->id,
                'permission_id' => $permission->id,
            ]);
        }

        return $role;
    }

    protected function team(string $code = 'OPS'): CoreTeam
    {
        return CoreTeam::query()->create([
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    protected function membership(User $user, CoreTeam $team, CoreRole $role, CoreModule $module): CoreTeamMember
    {
        $membership = CoreTeamMember::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'membership_role' => 'member',
        ]);

        CoreTeamMemberRole::query()->create([
            'team_member_id' => $membership->id,
            'role_id' => $role->id,
            'module_id' => $module->id,
        ]);

        return $membership;
    }

    protected function scope(CoreTeam $team, CoreModule $module, string $type, int|string|null $id, string $effect = 'allow', ?CoreAccessNode $accessNode = null): CoreTeamScope
    {
        return CoreTeamScope::query()->create([
            'team_id' => $team->id,
            'module_id' => $module->id,
            'access_node_id' => $accessNode?->id,
            'scope_type' => $type,
            'scope_id' => $id,
            'include_children' => true,
            'effect' => $effect,
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'core_resource_grants',
            'core_access_node_scope_rules',
            'core_scope_entity_providers',
            'regions',
            'assets',
            'core_team_scopes',
            'core_team_member_roles',
            'core_team_members',
            'core_teams',
            'role_has_permissions',
            'model_has_roles',
            'model_has_permissions',
            'roles',
            'permissions',
            'core_access_nodes',
            'core_modules',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('core_modules', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('name_fa')->nullable();
            $table->string('base_url')->nullable();
            $table->string('dashboard_url')->nullable();
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->boolean('requires_scope')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('core_access_nodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('module_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code')->unique();
            $table->string('label');
            $table->string('label_fa')->nullable();
            $table->string('node_type')->default('page');
            $table->string('route_name')->nullable();
            $table->string('url_path')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('requires_scope')->default(false);
            $table->boolean('is_visible_in_navigation')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->unsignedBigInteger('module_id')->nullable();
            $table->unsignedBigInteger('access_node_id')->nullable();
            $table->boolean('requires_scope')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->unsignedBigInteger('module_id')->nullable();
            $table->string('display_name')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('core_teams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedBigInteger('manager_user_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('core_team_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('user_id');
            $table->string('membership_role')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('core_team_member_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_member_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('module_id')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('core_team_scopes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('module_id');
            $table->unsignedBigInteger('access_node_id')->nullable();
            $table->string('scope_type');
            $table->string('scope_id')->nullable();
            $table->string('scope_code')->nullable();
            $table->string('asset_category')->nullable();
            $table->string('asset_type')->nullable();
            $table->boolean('include_children')->default(false);
            $table->string('effect')->default('allow');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('core_resource_grants', function (Blueprint $table): void {
            $table->id();
            $table->string('principal_type');
            $table->string('principal_id');
            $table->unsignedBigInteger('module_id');
            $table->unsignedBigInteger('capability_id')->nullable();
            $table->string('resource_type');
            $table->string('resource_id')->nullable();
            $table->string('resource_code')->nullable();
            $table->string('effect')->default('allow');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
