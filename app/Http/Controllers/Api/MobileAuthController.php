<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\MobileDeviceToken;
use App\Support\V3\SystemUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class MobileAuthController extends Controller
{
    public function login(Request $request, SystemUnit $systemUnit): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'device_id' => ['required', 'uuid'],
        ], [
            'login.required' => 'Email atau nomor pegawai wajib diisi.',
            'password.required' => 'Kata sandi wajib diisi.',
            'device_name.required' => 'Nama perangkat wajib diisi.',
            'device_id.required' => 'Identitas instalasi aplikasi wajib tersedia.',
            'device_id.uuid' => 'Identitas instalasi aplikasi tidak valid.',
        ]);

        $throttleKey = $this->throttleKey($credentials['login'], $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'Terlalu banyak percobaan masuk. Silakan coba kembali.',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], 429);
        }

        $field = filter_var($credentials['login'], FILTER_VALIDATE_EMAIL)
            ? 'email'
            : 'employee_number';
        $user = User::query()->where($field, $credentials['login'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            return response()->json([
                'message' => 'Kredensial tidak cocok atau akun sudah dinonaktifkan.',
                'errors' => ['login' => ['Kredensial tidak cocok atau akun sudah dinonaktifkan.']],
            ], 422);
        }

        RateLimiter::clear($throttleKey);

        $tokenPrefix = 'android:'.$credentials['device_id'].':';
        $user->tokens()
            ->where('name', 'like', $tokenPrefix.'%')
            ->delete();

        $expiresAt = now()->addDays((int) config('mobile.token_expiration_days', 30));
        $tokenName = $tokenPrefix.Str::limit($credentials['device_name'], 60, '');
        $accessToken = $user->createToken($tokenName, ['mobile'], $expiresAt);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $accessToken->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $this->userPayload($user, $systemUnit),
        ]);
    }

    public function user(Request $request, SystemUnit $systemUnit): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user(), $systemUnit),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $name = (string) ($token?->name ?? '');
        if (preg_match('/^android:([0-9a-f-]{36}):/i', $name, $matches)) {
            MobileDeviceToken::query()
                ->where('user_id', $request->user()->getKey())
                ->where('installation_id', $matches[1])
                ->update(['is_active' => false]);
        }
        $token?->delete();

        return response()->json(['message' => 'Sesi berhasil diakhiri.']);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user, SystemUnit $systemUnit): array
    {
        $roles = $user->getRoleNames()->values();
        $primaryRole = $roles->first();
        $unit = $systemUnit->get();

        return [
            'id' => $user->getKey(),
            'employee_number' => $user->employee_number,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'primary_role' => $primaryRole,
            'primary_role_label' => UserRole::labelFor($primaryRole),
            'roles' => $roles,
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values(),
            'unit' => $unit ? [
                'id' => $unit->getKey(),
                'code' => $unit->code,
                'name' => $unit->name,
                'address' => $unit->address,
            ] : null,
        ];
    }

    private function throttleKey(string $login, ?string $ip): string
    {
        return 'mobile-login|'.Str::transliterate(Str::lower($login)).'|'.$ip;
    }
}
