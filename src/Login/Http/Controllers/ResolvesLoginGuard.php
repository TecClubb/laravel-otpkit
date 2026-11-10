<?php

namespace TecClub\OtpKit\Login\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use TecClub\OtpKit\Login\LoginService;

/**
 * @internal
 */
trait ResolvesLoginGuard
{
    /**
     * The requested guard, limited to the guards the route was registered for.
     *
     * @throws ValidationException
     */
    protected function resolveGuard(Request $request, LoginService $login): string
    {
        /** @var list<string>|null $allowed */
        $allowed = $request->route()?->defaults['otpkit_guards'] ?? null;
        $guard = $request->input('guard');

        try {
            return $login->guardName(is_string($guard) && $guard !== '' ? $guard : null, $allowed);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['guard' => __('validation.in', ['attribute' => 'guard'])]);
        }
    }
}
