<?php

namespace TecClub\OtpKit\Login;

use Illuminate\Contracts\Auth\Authenticatable;
use TecClub\OtpKit\Contracts\TokenIssuer;
use TecClub\OtpKit\Exceptions\InvalidConfigException;

class SanctumTokenIssuer implements TokenIssuer
{
    public const HAS_API_TOKENS = 'Laravel\Sanctum\HasApiTokens';

    public function issue(Authenticatable $user, string $name): string
    {
        self::assertUsableFor($user::class);

        if (! method_exists($user, 'createToken')) {
            throw InvalidConfigException::invalid('login.token_issuer', 'is SanctumTokenIssuer but ['.$user::class.'] has no createToken() method');
        }

        return $user->createToken($name)->plainTextToken;
    }

    public function tokenType(): string
    {
        return 'Bearer';
    }

    /**
     * Sanctum must be installed and the model must use HasApiTokens.
     *
     * @param  class-string  $model
     *
     * @throws InvalidConfigException
     */
    public static function assertUsableFor(string $model): void
    {
        if (! trait_exists(self::HAS_API_TOKENS)) {
            throw InvalidConfigException::invalid(
                'login.token_issuer',
                'is SanctumTokenIssuer but laravel/sanctum is not installed; install it or bind your own TokenIssuer'
            );
        }

        if (! in_array(self::HAS_API_TOKENS, class_uses_recursive($model), true)) {
            throw InvalidConfigException::invalid(
                'login.token_issuer',
                "is SanctumTokenIssuer but [{$model}] does not use Laravel\\Sanctum\\HasApiTokens"
            );
        }
    }
}
