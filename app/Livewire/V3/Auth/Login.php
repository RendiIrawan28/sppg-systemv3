<?php

namespace App\Livewire\V3\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Login extends Component
{
    public string $login = '';

    public string $password = '';

    public bool $remember = false;

    public function mount(): void
    {
        if (Auth::check()) {
            $this->redirectRoute('v3.entry', navigate: true);
        }
    }

    public function authenticate(): void
    {
        $credentials = $this->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'login.required' => 'Email atau nomor pegawai wajib diisi.',
            'password.required' => 'Kata sandi wajib diisi.',
        ]);

        $this->ensureIsNotRateLimited();

        $field = filter_var($credentials['login'], FILTER_VALIDATE_EMAIL)
            ? 'email'
            : 'employee_number';

        if (! Auth::attempt([
            $field => $credentials['login'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => 'Kredensial tidak cocok atau akun sudah dinonaktifkan.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        request()->session()->regenerate();

        $this->redirectIntended(default: route('v3.entry'), navigate: true);
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => "Terlalu banyak percobaan. Coba kembali dalam {$seconds} detik.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->login).'|'.request()->ip());
    }

    public function render()
    {
        return view('livewire.v3.auth.login')
            ->layout('layouts.v3-auth', ['title' => 'Masuk ke SPPG']);
    }
}
