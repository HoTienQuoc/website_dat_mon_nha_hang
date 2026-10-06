<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless($request->user()?->is_active && in_array($request->user()->role, $roles, true), 403, __('Bạn không có quyền thực hiện thao tác này.'));

        return $next($request);
    }
}
