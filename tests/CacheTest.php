<?php

namespace Maklad\Permission\Test;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Maklad\Permission\Models\Role;
use Maklad\Permission\Models\Permission;
use Maklad\Permission\Exceptions\PermissionDoesNotExist;
use Maklad\Permission\PermissionRegistrar;

class CacheTest extends TestCase
{
    protected $cache_init_count = 0;
    protected $cache_load_count = 0;
    protected $cache_run_count = 2; // roles lookup, permissions lookup
    protected $cache_relations_count = 1;

    protected $registrar;

    public function setUp(): void
    {
        parent::setUp();

        $this->registrar = app(PermissionRegistrar::class);

        $this->registrar->forgetCachedPermissions();

        DB::connection()->enableQueryLog();

        $cacheStore = $this->registrar->getCacheStore();

        switch (true) {
            case $cacheStore instanceof \Illuminate\Cache\DatabaseStore:
                $this->cache_init_count = 1;
                $this->cache_load_count = 1;
                // no break
            default:
        }
    }

    /** @test */
    public function it_can_cache_the_permissions()
    {
        $this->resetQueryCount();

        $this->getPermissions();

        $this->assertQueryCount($this->cache_init_count + $this->cache_load_count + $this->cache_run_count);
    }

    /** @test */
    public function it_flushes_the_cache_when_creating_a_permission()
    {
        app(Permission::class)->create(['name' => 'new']);

        $this->resetQueryCount();

        $this->getPermissions();

        $this->assertQueryCount($this->cache_init_count + $this->cache_load_count + $this->cache_run_count);
    }

    /** @test */
    public function it_flushes_the_cache_when_updating_a_permission()
    {
        $permission = app(Permission::class)->create(['name' => 'new']);

        $permission->name = 'other name';
        $permission->save();

        $this->resetQueryCount();

        $this->getPermissions();

        $this->assertQueryCount($this->cache_init_count + $this->cache_load_count + $this->cache_run_count);
    }

    /** @test */
    public function it_flushes_the_cache_when_creating_a_role()
    {
        app(Role::class)->create(['name' => 'new']);

        $this->resetQueryCount();

        $this->getPermissions();

        $this->assertQueryCount($this->cache_init_count + $this->cache_load_count + $this->cache_run_count);
    }

    /** @test */
    public function it_flushes_the_cache_when_updating_a_role()
    {
        $role = app(Role::class)->create(['name' => 'new']);

        $role->name = 'other name';
        $role->save();

        $this->resetQueryCount();

        $this->getPermissions();

        $this->assertQueryCount($this->cache_init_count + $this->cache_load_count + $this->cache_run_count);
    }

    /** @test */
    public function removing_a_permission_from_a_user_should_not_flush_the_cache()
    {
        $this->testUser->givePermissionTo('edit-articles');

        $this->getPermissions();

        $this->testUser->revokePermissionTo('edit-articles');

        $this->resetQueryCount();

        $this->getPermissions();

        $this->assertQueryCount(0);
    }

    /** @test */
    public function removing_a_role_from_a_user_should_not_flush_the_cache()
    {
        $this->testUser->assignRole('testRole');

        $this->getPermissions();

        $this->testUser->removeRole('testRole');

        $this->resetQueryCount();

        $this->getPermissions();

        $this->assertQueryCount(0);
    }

    /** @test */
    public function it_flushes_the_cache_when_removing_a_role_from_a_permission()
    {
        $this->testUserPermission->assignRole('testRole');

        $this->getPermissions();

        $this->testUserPermission->removeRole('testRole');

        $this->resetQueryCount();

        $this->getPermissions();

        $this->assertQueryCount($this->cache_init_count + $this->cache_load_count + $this->cache_run_count);
    }

    /** @test */
    public function it_flushes_the_cache_when_assign_a_permission_to_a_role()
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->resetQueryCount();

        $this->getPermissions();

        $this->assertQueryCount($this->cache_init_count + $this->cache_load_count + $this->cache_run_count);
    }

    /** @test */
    public function user_creation_should_not_flush_the_cache()
    {
        $this->getPermissions();

        User::create(['email' => 'new']);

        $this->resetQueryCount();

        $this->getPermissions();

        // should all be in memory, so no init/load required
        $this->assertQueryCount(0);
    }

    /** @test */
    public function it_flushes_the_cache_when_giving_a_permission_to_a_role()
    {
        $this->testUserRole->givePermissionTo($this->testUserPermission);

        $this->resetQueryCount();

        $this->getPermissions();

        $this->assertQueryCount($this->cache_init_count + $this->cache_load_count + $this->cache_run_count);
    }

    /** @test */
    public function has_permission_to_should_use_the_cache()
    {
        $this->testUserRole->givePermissionTo(['edit-articles', 'edit-news', 'Edit News']);
        $this->testUser->assignRole('testRole');

        $this->resetQueryCount();
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertQueryCount(4);

        $this->resetQueryCount();
        $this->assertTrue($this->testUser->hasPermissionTo('edit-news'));
        $this->assertQueryCount(0);

        $this->resetQueryCount();
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertQueryCount(0);

        $this->resetQueryCount();
        $this->assertTrue($this->testUser->hasPermissionTo('Edit News'));
        $this->assertQueryCount(0);
    }

    /** @test */
    public function the_cache_should_differentiate_by_guard_name()
    {
        $this->expectException(PermissionDoesNotExist::class);

        $this->testUserRole->givePermissionTo(['edit-articles', 'web']);
        $this->testUser->assignRole('testRole');

        $this->resetQueryCount();
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles', 'web'));
        $this->assertQueryCount($this->cache_init_count + $this->cache_load_count + $this->cache_run_count + $this->cache_relations_count);

        $this->resetQueryCount();
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles', 'admin'));
        $this->assertQueryCount(1); // 1 for first lookup of this permission with this guard
    }

    /** @test */
    public function get_all_permissions_should_not_over_hydrate_roles()
    {
        $this->testUserRole->givePermissionTo(['edit-articles', 'edit-news']);
        $permissions = $this->getPermissions();
        $roles = $permissions->flatMap->roles;

        // Should have same object reference
        $this->assertSame($roles[0], $roles[1]);
    }

    /** @test */
    public function it_can_reset_the_cache_with_artisan_command()
    {
        Artisan::call('permission:create-permission', ['name' => 'new-permission']);
        $this->assertCount(1, Permission::where('name', 'new-permission')->get());

        $this->resetQueryCount();
        // retrieve permissions, and assert that the cache had to be loaded
        $this->getPermissions();
        $this->assertQueryCount($this->cache_init_count + $this->cache_load_count + $this->cache_run_count);

        // reset the cache
        Artisan::call('permission:cache-reset');

        $this->resetQueryCount();
        $this->getPermissions();
        // assert that the cache had to be reloaded
        $this->assertQueryCount($this->cache_init_count + $this->cache_load_count + $this->cache_run_count);
    }

    protected function assertQueryCount(int $expected)
    {
        $this->assertCount($expected, DB::getQueryLog());
    }

    protected function resetQueryCount()
    {
        DB::flushQueryLog();
    }

    protected function getPermissions()
    {
        return app(PermissionRegistrar::class)->getPermissions();
    }
}
