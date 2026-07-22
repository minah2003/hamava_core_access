<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Models\CoreModule;
use Hamava\CoreAccess\Services\CoreAccessContext;
use Hamava\CoreAccess\Services\CoreAccessResolver;
use Hamava\CoreAccess\Tests\Fixtures\User;
use Hamava\CoreAccess\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class CoreAccessContextTest extends TestCase
{
    public function test_memberships_are_cached_by_user_class_and_identifier(): void
    {
        $user = $this->user('context-user');
        $module = $this->module(
            'inventory',
            requiresScope: false,
        );
        $permission = $this->permission(
            'inventory.records.view',
            $module,
        );
        $role = $this->role(
            'viewer',
            $module,
            $permission,
        );
        $team = $this->team('CONTEXT');

        $this->membership(
            $user,
            $team,
            $role,
            $module,
        );

        $context = app(CoreAccessContext::class);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $first = $context->memberships($user);
        $queryCountAfterFirstCall = count(DB::getQueryLog());

        $second = $context->memberships($user);

        $this->assertGreaterThan(
            0,
            $queryCountAfterFirstCall,
        );

        $this->assertSame(
            $queryCountAfterFirstCall,
            count(DB::getQueryLog()),
        );

        $this->assertSame($first, $second);

        $membership = $first->first();

        $this->assertNotNull($membership);
        $this->assertTrue($membership->relationLoaded('team'));
        $this->assertTrue($membership->relationLoaded('roles'));
        $this->assertTrue(
            $membership->team->relationLoaded('teamRoles'),
        );

        $assignment = $membership->roles->first();

        $this->assertNotNull($assignment);
        $this->assertTrue($assignment->relationLoaded('role'));
        $this->assertTrue($assignment->relationLoaded('module'));
        $this->assertTrue(
            $assignment->role->relationLoaded('permissions'),
        );

        /*
         * Same identifier but another Authenticatable class must use
         * another cache entry.
         */
        $anotherUserClass = new class extends User {};

        $anotherUserClass->forceFill([
            'id' => $user->getAuthIdentifier(),
        ]);

        $otherClassMemberships = $context->memberships(
            $anotherUserClass,
        );

        $this->assertNotSame(
            $first,
            $otherClassMemberships,
        );
    }

    public function test_module_and_permission_lookups_are_cached(): void
    {
        $module = $this->module(
            'inventory',
            requiresScope: false,
        );
        $node = $this->node(
            $module,
            'inventory.records',
        );
        $permission = $this->permission(
            'inventory.records.view',
            $module,
            node: $node,
        );

        $context = app(CoreAccessContext::class);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $resolvedPermission = $context->permission(
            $permission->name,
        );

        $resolvedModule = $context->module(
            $module->code,
        );

        $queryCountAfterFirstCalls = count(DB::getQueryLog());

        $this->assertSame(
            $resolvedPermission,
            $context->permission($permission->name),
        );

        $this->assertSame(
            $resolvedModule,
            $context->module($module->code),
        );

        $this->assertSame(
            $queryCountAfterFirstCalls,
            count(DB::getQueryLog()),
        );

        $this->assertNotNull($resolvedPermission);
        $this->assertTrue(
            $resolvedPermission->relationLoaded('module'),
        );
        $this->assertTrue(
            $resolvedPermission->relationLoaded('accessNode'),
        );

        $this->assertSame(
            $resolvedPermission->module,
            $resolvedModule,
        );

        /*
         * Null results must be cached too.
         */
        $this->assertNull(
            $context->module('missing-module'),
        );

        $this->assertNull(
            $context->permission('missing.permission'),
        );

        $queryCountAfterMissingCalls = count(DB::getQueryLog());

        $this->assertNull(
            $context->module('missing-module'),
        );

        $this->assertNull(
            $context->permission('missing.permission'),
        );

        $this->assertSame(
            $queryCountAfterMissingCalls,
            count(DB::getQueryLog()),
        );
    }

    public function test_scope_cache_normalizes_team_identifiers(): void
    {
        $module = $this->module('inventory');
        $firstTeam = $this->team('FIRST');
        $secondTeam = $this->team('SECOND');

        $firstScope = $this->scope(
            $firstTeam,
            $module,
            'region',
            10,
        );

        $secondScope = $this->scope(
            $secondTeam,
            $module,
            'region',
            20,
        );

        $context = app(CoreAccessContext::class);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $first = $context->scopes(
            [
                $secondTeam->id,
                $firstTeam->id,
                $secondTeam->id,
            ],
            $module->code,
        );

        $queryCountAfterFirstCall = count(DB::getQueryLog());

        $second = $context->scopes(
            [
                $firstTeam->id,
                $secondTeam->id,
            ],
            $module->code,
        );

        $this->assertSame($first, $second);

        $this->assertSame(
            $queryCountAfterFirstCall,
            count(DB::getQueryLog()),
        );

        $this->assertEqualsCanonicalizing(
            [
                $firstScope->id,
                $secondScope->id,
            ],
            $first->pluck('id')->all(),
        );
    }

    public function test_repeated_access_check_reuses_request_context(): void
    {
        $user = $this->user('repeated-check');
        $module = $this->module(
            'inventory',
            requiresScope: false,
        );
        $permission = $this->permission(
            'inventory.records.view',
            $module,
        );
        $role = $this->role(
            'viewer',
            $module,
            $permission,
        );
        $team = $this->team('REPEATED');

        $this->membership(
            $user,
            $team,
            $role,
            $module,
        );

        $resolver = app(CoreAccessResolver::class);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $first = $resolver->check(
            $user,
            $permission->name,
        );

        $queryCountAfterFirstCheck = count(DB::getQueryLog());

        $second = $resolver->check(
            $user,
            $permission->name,
        );

        $this->assertTrue($first->allowed);
        $this->assertTrue($second->allowed);

        $this->assertGreaterThan(
            0,
            $queryCountAfterFirstCheck,
        );

        $this->assertSame(
            $queryCountAfterFirstCheck,
            count(DB::getQueryLog()),
        );
    }

    public function test_flush_reloads_authorization_data(): void
    {
        $module = $this->module(
            'inventory',
            requiresScope: false,
        );

        $context = app(CoreAccessContext::class);

        $cachedModule = $context->module(
            $module->code,
        );

        $this->assertNotNull($cachedModule);
        $this->assertTrue($cachedModule->is_enabled);

        CoreModule::query()
            ->whereKey($module->id)
            ->update([
                'is_enabled' => false,
            ]);

        /*
         * Context represents one request-local snapshot.
         */
        $this->assertTrue(
            $context->module($module->code)->is_enabled,
        );

        $context->flush();

        $this->assertFalse(
            $context->module($module->code)->is_enabled,
        );
    }
}
