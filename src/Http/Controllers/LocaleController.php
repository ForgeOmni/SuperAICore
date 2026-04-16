<?php

namespace SuperAICore\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Sets the locale cookie the host app reads.
 * Configurable via config('super-ai-core.locales') + 'locale_cookie'.
 */
class LocaleController extends Controller
{
    public function switch(Request $request, string $code)
    {
        $allowed = array_keys(config('super-ai-core.locales', []));
        if (!in_array($code, $allowed, true)) {
            abort(404);
        }

        $cookie = config('super-ai-core.locale_cookie', 'locale');
        $back = $request->headers->get('referer') ?: route('super-ai-core.providers.index');

        return redirect($back)->withCookie(cookie($cookie, $code, 60 * 24 * 365));
    }
}
