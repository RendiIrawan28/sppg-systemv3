<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FcmHttpV1Client
{
    /** @return array<string, mixed> */
    public function send(string $registrationToken, array $message): array
    {
        $projectId = (string) config('mobile.firebase.project_id');
        if (! config('mobile.firebase.enabled') || blank($projectId)) {
            throw new RuntimeException('Firebase Cloud Messaging belum dikonfigurasi.');
        }

        $response = Http::acceptJson()
            ->withToken($this->accessToken())
            ->timeout(20)
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $registrationToken,
                    ...$message,
                ],
            ]);

        $response->throw();

        return $response->json() ?? [];
    }

    private function accessToken(): string
    {
        $projectId = (string) config('mobile.firebase.project_id');

        return Cache::remember("mobile:fcm:oauth-token:{$projectId}", now()->addMinutes(50), function (): string {
            $credentials = $this->credentials();
            $tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');
            $now = now()->timestamp;
            $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claims = $this->base64Url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));
            $unsigned = $header.'.'.$claims;
            $signature = '';

            if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Tidak dapat menandatangani kredensial Firebase.');
            }

            $assertion = $unsigned.'.'.$this->base64Url($signature);
            $response = Http::asForm()->timeout(20)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
            $response->throw();
            $accessToken = trim((string) $response->json('access_token'));
            if ($accessToken === '') {
                throw new RuntimeException('Google OAuth tidak mengembalikan access token Firebase.');
            }

            return $accessToken;
        });
    }

    /** @return array<string, mixed> */
    private function credentials(): array
    {
        $path = trim((string) config('mobile.firebase.credentials'));
        if ($path === '') {
            throw new RuntimeException('FIREBASE_CREDENTIALS belum diisi.');
        }
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            $path = base_path($path);
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('File kredensial Firebase tidak ditemukan atau tidak dapat dibaca.');
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        foreach (['client_email', 'private_key'] as $field) {
            if (blank($decoded[$field] ?? null)) {
                throw new RuntimeException("Kredensial Firebase tidak memiliki {$field}.");
            }
        }

        return $decoded;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
