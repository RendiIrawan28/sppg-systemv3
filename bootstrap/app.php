<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );
    })
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->respond(function (Response $response, \Throwable $exception, Request $request): Response {
            if ($response->getStatusCode() < 500) {
                return $response;
            }

            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Data belum dapat diproses karena server sedang mengalami kendala. Coba lagi; jika tetap gagal, hubungi administrator.',
                ], $response->getStatusCode());
            }

            if ($request->is('livewire/*')) {
                return response('Proses belum berhasil. Coba lagi; jika tetap gagal, hubungi administrator.', $response->getStatusCode());
            }

            if ($request->is('v3/*')) {
                return response()->view('errors.sppg', [], $response->getStatusCode());
            }

            return $response;
        });
    })->create();
