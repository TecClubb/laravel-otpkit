<?php

namespace TecClub\OtpKit\Login\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use TecClub\OtpKit\Login\LoginService;

class WebVerifyController
{
    use ResolvesLoginGuard;

    /**
     * Log in through the guard and regenerate the session, then redirect to the
     * intended URL, or return it as JSON. A failure is a validation error on "code".
     */
    public function __invoke(Request $request, LoginService $login): RedirectResponse|JsonResponse
    {
        $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'remember' => ['nullable', 'boolean'],
            'guard' => ['nullable', 'string'],
        ]);

        $login->attempt(
            $request->string('identifier')->toString(),
            $request->string('code')->toString(),
            $this->resolveGuard($request, $login),
            $request->boolean('remember'),
        )->throw('code');

        $fallback = (string) config('otpkit.login.redirect_to', '/');

        if ($request->expectsJson()) {
            return new JsonResponse(['redirect' => $request->session()->pull('url.intended', url($fallback))]);
        }

        return redirect()->intended($fallback);
    }
}
