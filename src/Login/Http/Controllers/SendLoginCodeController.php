<?php

namespace TecClub\OtpKit\Login\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TecClub\OtpKit\Login\LoginService;

class SendLoginCodeController
{
    use ResolvesLoginGuard;

    /**
     * Always 202 with the same body, whether or not the account exists or a
     * rate limit applied: anything else would reveal which accounts exist.
     */
    public function __invoke(Request $request, LoginService $login): JsonResponse
    {
        $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'guard' => ['nullable', 'string'],
        ]);

        $login->send($request->string('identifier')->toString(), $this->resolveGuard($request, $login));

        return new JsonResponse(['message' => __('otpkit::messages.login.sent')], 202);
    }
}
