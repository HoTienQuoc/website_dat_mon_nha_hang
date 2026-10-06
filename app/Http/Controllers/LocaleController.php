<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => 'required|in:vi,en']);
        $request->session()->put('locale', $data['locale']);
        $previous = url()->previous();
        $path = parse_url($previous, PHP_URL_PATH) ?: '/';

        return redirect()->to(url($path))->withCookie(cookie('restaurant_locale', $data['locale'], 525600, '/', null, $request->isSecure(), true, false, 'lax'));
    }
}
