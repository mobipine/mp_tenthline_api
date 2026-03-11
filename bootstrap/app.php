<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
            $message = $e instanceof HttpException ? $e->getMessage() : 'Server Error';
            if ($e instanceof NotFoundHttpException) {
                $message = 'Not found';
            }

            $response = response()->json(['message' => $message], $status);

            $origin = $request->header('Origin');
            $allowed = config('cors.allowed_origins', []);
            if ($origin && (in_array('*', $allowed) || in_array($origin, $allowed))) {
                $response->header('Access-Control-Allow-Origin', $origin);
            } elseif (! empty($allowed) && $allowed[0] !== '*') {
                $response->header('Access-Control-Allow-Origin', $allowed[0]);
            }
            if (config('cors.supports_credentials', false)) {
                $response->header('Access-Control-Allow-Credentials', 'true');
            }
            $methods = config('cors.allowed_methods', ['*']);
            $response->header('Access-Control-Allow-Methods', is_array($methods) ? implode(', ', $methods) : '*');
            $headers = config('cors.allowed_headers', ['*']);
            $response->header('Access-Control-Allow-Headers', is_array($headers) ? implode(', ', $headers) : '*');

            return $response;
        });
    })->create();
