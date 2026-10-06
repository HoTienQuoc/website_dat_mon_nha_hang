<?php

use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetLocale::class, AuthenticateSession::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('tai-khoan*') ? route('customer.login') : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()?->role === 'customer' ? route('customer.account') : route('staff.orders.index'));
        $middleware->validateCsrfTokens(except: ['stripe/webhook']);
        $middleware->alias(['role' => RequireRole::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['card_number', 'cvc', 'expiry', 'cardholder', 'billing_address', 'postal_code']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
