<?php

use App\Enums\UserRole;
use App\Models\SppgUnit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccessControlSeeder::class);
});

it('allows an active mobile user to login with email', function (): void {
    $user = User::factory()->create([
        'email' => 'lapangan@sppg.test',
        'password' => 'rahasia123',
        'is_active' => true,
    ]);
    $user->syncRoles([UserRole::AsistenLapangan->value]);

    $response = $this->postJson('/api/mobile/login', [
        'login' => 'lapangan@sppg.test',
        'password' => 'rahasia123',
        'device_name' => 'Android Test',
    ]);

    $response->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.name', $user->name)
        ->assertJsonPath('user.primary_role', UserRole::AsistenLapangan->value)
        ->assertJsonPath('user.unit.code', SppgUnit::query()->firstOrFail()->code)
        ->assertJsonStructure(['access_token', 'user' => ['roles', 'permissions', 'unit']]);
});

it('allows login with an employee number', function (): void {
    $user = User::factory()->create([
        'employee_number' => 'GDG-001',
        'password' => 'rahasia123',
        'is_active' => true,
    ]);
    $user->syncRoles([UserRole::StafGudang->value]);

    $this->postJson('/api/mobile/login', [
        'login' => 'GDG-001',
        'password' => 'rahasia123',
        'device_name' => 'Android Gudang',
    ])->assertOk()
        ->assertJsonPath('user.primary_role', UserRole::StafGudang->value);
});

it('rejects invalid credentials and inactive accounts', function (): void {
    $user = User::factory()->create([
        'email' => 'nonaktif@sppg.test',
        'password' => 'rahasia123',
        'is_active' => false,
    ]);

    $this->postJson('/api/mobile/login', [
        'login' => $user->email,
        'password' => 'rahasia123',
        'device_name' => 'Android Test',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Kredensial tidak cocok atau akun sudah dinonaktifkan.');
});

it('returns the authenticated user and revokes the current token on logout', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $token = $user->createToken('Android Test', ['mobile'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/mobile/user')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);

    $this->withToken($token)
        ->postJson('/api/mobile/logout')
        ->assertOk();

    $tokenHash = hash('sha256', explode('|', $token, 2)[1]);
    $this->assertDatabaseMissing('personal_access_tokens', ['token' => $tokenHash]);
});
