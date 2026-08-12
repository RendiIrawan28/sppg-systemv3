<?php

use App\Models\SppgUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    SppgUnit::query()->create([
        'code' => 'SPPG-NAV',
        'name' => 'SPPG Navigation',
        'slug' => 'sppg-navigation',
        'is_active' => true,
    ]);
});

it('renders the complete sidebar as collapsible modules for a super admin', function (): void {
    $user = User::query()->create([
        'name' => 'Super Admin Navigation',
        'email' => 'super-nav@example.test',
        'password' => 'password',
        'is_active' => true,
        'is_super_admin' => true,
    ]);

    $this->actingAs($user)->get('/v3/dashboard')
        ->assertOk()
        ->assertSee('Modul kerja')
        ->assertSee('Ahli Gizi')
        ->assertSee('Kebutuhan bahan')
        ->assertSee('Asisten Lapangan')
        ->assertSee('Rencana distribusi')
        ->assertSee('Administrasi Sistem')
        ->assertDontSee('Kebutuhan &amp; pengadaan', false)
        ->assertDontSee('Rencana lapangan');
});

it('only renders modules containing menus allowed for the user', function (): void {
    $user = User::query()->create([
        'name' => 'Petugas Keamanan Navigation',
        'email' => 'security-nav@example.test',
        'password' => 'password',
        'is_active' => true,
    ]);
    $user->givePermissionTo([
        Permission::findOrCreate('dashboard.view', 'web'),
        Permission::findOrCreate('security.view', 'web'),
    ]);

    $this->actingAs($user)->get('/v3/dashboard')
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Keamanan')
        ->assertSee('Laporan keamanan')
        ->assertDontSee('Ahli Gizi')
        ->assertDontSee('Gudang')
        ->assertDontSee('Administrasi Sistem');
});
