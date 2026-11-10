<?php

namespace TecClub\OtpKit\Login\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TecClub\OtpKit\Login\LoginService;

class ApiVerifyController
{
    use ResolvesLoginGuard;

    /**
     * Return { token, token_type } from the token issuer. A failure is a 422
     * validation error on "code".
     */
    public function __invoke(Request $request, LoginService $login): JsonResponse
    {
        $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'guard' => ['nullable', 'string'],
        ]);

        $result = $login->attemptForToken(
            $request->string('identifier')->toString(),
            $request->string('code')->toString(),
            $this->resolveGuard($request, $login),
            $request->input('device_name'),
        )->throw('code');

        return new JsonResponse([
            'token' => $result->token,
            'token_type' => $result->tokenType,
        ]);
    }
}
