<?php

namespace TecClub\OtpKit\Login;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use TecClub\OtpKit\Contracts\TokenIssuer;
use TecClub\OtpKit\Enums\OtpStatus;
use TecClub\OtpKit\Exceptions\InvalidConfigException;
use TecClub\OtpKit\Facades\Otp;
use TecClub\OtpKit\PolicyResolver;
use TecClub\OtpKit\Support\Limiter;
use TecClub\OtpKit\Support\RequestContext;

/**
 * Login by code, behind the OtpLogin facade. The HTTP endpoints are thin
 * wrappers over this, so Livewire components, Inertia controllers or custom
 * routes call it directly.
 */
class LoginService
{
    public const PURPOSE = 'login';

    /** @var (Closure(Authenticatable): bool)|null */
    private ?Closure $authorizeUsing = null;

    /**
     * @internal Use the OtpLogin facade or resolve it from the container.
     */
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly AuthFactory $auth,
        private readonly PolicyResolver $policies,
        private readonly Limiter $limiter,
        private readonly RequestContext $request,
    ) {}

    /**
     * Send a login code. Silent for unknown or blocked accounts, so callers
     * can't tell whether an account exists.
     */
    public function send(string $identifier, ?string $guard = null): void
    {
        $guard = $this->guardName($guard);
        $user = $this->findUser($guard, $identifier);

        if ($user === null || ! $this->authorized($user)) {
            return;
        }

        Otp::for($user)->purpose(self::PURPOSE)->send();
    }

    /**
     * Verify the code, then log in through the guard and regenerate the session.
     */
    public function attempt(string $identifier, string $code, ?string $guard = null, bool $remember = false): LoginResult
    {
        $guard = $this->guardName($guard);
        $statefulGuard = $this->auth->guard($guard);

        if (! $statefulGuard instanceof StatefulGuard) {
            throw new LogicException("OtpKit cannot log in through guard [{$guard}]: it does not keep a session. Use attemptForToken() for API guards.");
        }

        $result = $this->verify($guard, $identifier, $code);

        if (! $result->ok()) {
            return $result;
        }

        /** @var Authenticatable $user */
        $user = $result->user;
        $statefulGuard->login($user, $remember);

        $request = $this->container->make('request');

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $result;
    }

    /**
     * Verify the code, then return a token from the token issuer.
     */
    public function attemptForToken(string $identifier, string $code, ?string $guard = null, ?string $deviceName = null): LoginResult
    {
        $guard = $this->guardName($guard);
        $issuer = $this->tokenIssuer();

        // Fail on a misconfigured issuer before a valid code is used up.
        if ($issuer instanceof SanctumTokenIssuer) {
            SanctumTokenIssuer::assertUsableFor($this->guardConfig($guard)['model']);
        }

        $result = $this->verify($guard, $identifier, $code);

        if (! $result->ok()) {
            return $result;
        }

        /** @var Authenticatable $user */
        $user = $result->user;
        $name = $deviceName !== null && trim($deviceName) !== '' ? trim($deviceName) : (string) $this->config->get('otpkit.login.token_name', 'otp-login');

        return new LoginResult(
            OtpStatus::Verified,
            user: $user,
            token: $issuer->issue($user, $name),
            tokenType: $issuer->tokenType(),
            attemptsRemaining: $result->attemptsRemaining,
        );
    }

    /**
     * Block logins (banned, unverified, suspended). A blocked account gets the same generic failure.
     *
     * @internal Use OtpKit::authorizeLoginUsing().
     *
     * @param  (Closure(Authenticatable): bool)|null  $callback
     */
    public function authorizeUsing(?Closure $callback): void
    {
        $this->authorizeUsing = $callback;
    }

    /**
     * The configured guard to use: the given one, or the first in otpkit.login.guards.
     *
     * @internal
     *
     * @param  list<string>|null  $allowed  restrict to these guards (from the route definition)
     */
    public function guardName(?string $guard = null, ?array $allowed = null): string
    {
        $this->ensureEnabled();

        $configured = array_keys($this->guards());
        $allowed = $allowed === null ? $configured : array_values(array_intersect($allowed, $configured));
        $guard ??= $allowed[0] ?? null;

        if ($guard === null || ! in_array($guard, $allowed, true)) {
            throw new InvalidArgumentException('OtpKit login guard ['.($guard ?? 'none').'] is not configured in otpkit.login.guards'.($allowed !== $configured ? ' or not allowed on this route' : '').'.');
        }

        return $guard;
    }

    /**
     * @internal
     */
    public function tokenIssuer(): TokenIssuer
    {
        return $this->container->make(TokenIssuer::class);
    }

    /**
     * @internal
     *
     * @return array{model: class-string<Model&Authenticatable>, identifier: string}
     */
    public function guardConfig(string $guard): array
    {
        return $this->guards()[$guard] ?? throw new InvalidArgumentException("OtpKit login guard [{$guard}] is not configured in otpkit.login.guards.");
    }

    /**
     * Fail early on a broken login config. Runs at boot when login is enabled.
     *
     * @internal
     *
     * @throws InvalidConfigException
     */
    public function validateConfig(): void
    {
        $guards = $this->config->get('otpkit.login.guards');

        if (! is_array($guards) || $guards === []) {
            throw InvalidConfigException::invalid('login.guards', 'must list at least one guard when login is enabled');
        }

        foreach ($guards as $guard => $settings) {
            $model = $settings['model'] ?? null;
            $identifier = $settings['identifier'] ?? null;

            if (! is_string($guard) || $this->config->get("auth.guards.{$guard}") === null) {
                throw InvalidConfigException::invalid("login.guards.{$guard}", 'must name a guard defined in config/auth.php');
            }

            if (! is_string($model) || ! is_a($model, Model::class, true) || ! is_a($model, Authenticatable::class, true)) {
                throw InvalidConfigException::invalid("login.guards.{$guard}.model", 'must be an Eloquent model implementing '.Authenticatable::class);
            }

            if (! is_string($identifier) || trim($identifier) === '') {
                throw InvalidConfigException::invalid("login.guards.{$guard}.identifier", 'must be exactly one column name, such as "email" or "phone"');
            }
        }

        try {
            $issuer = $this->container->make(TokenIssuer::class);
        } catch (BindingResolutionException) {
            $issuer = null;
        }

        if (! $issuer instanceof TokenIssuer) {
            throw InvalidConfigException::invalid('login.token_issuer', 'must be a class implementing '.TokenIssuer::class);
        }
    }

    private function verify(string $guard, string $identifier, string $code): LoginResult
    {
        $user = $this->findUser($guard, $identifier);

        if ($user === null || ! $this->authorized($user)) {
            // Count the attempt against the IP exactly like a real verify, so the
            // verify limit behaves the same whether or not the account exists.
            $retryAfter = $this->limiter->hitVerify($this->policies->resolve(self::PURPOSE), $this->request->ip());

            return new LoginResult(
                $retryAfter !== null ? OtpStatus::RateLimited : OtpStatus::NotFound,
                retryAfter: $retryAfter,
                blocked: $user !== null,
            );
        }

        $verification = Otp::for($user)->purpose(self::PURPOSE)->verify($code);

        if (! $verification->ok()) {
            return LoginResult::fromVerification($verification);
        }

        return new LoginResult(OtpStatus::Verified, user: $user, attemptsRemaining: $verification->attemptsRemaining);
    }

    private function findUser(string $guard, string $identifier): (Model&Authenticatable)|null
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        ['model' => $model, 'identifier' => $column] = $this->guardConfig($guard);

        /** @var (Model&Authenticatable)|null */
        return $model::query()->where($column, $identifier)->first();
    }

    private function authorized(Authenticatable $user): bool
    {
        return $this->authorizeUsing === null || (bool) ($this->authorizeUsing)($user);
    }

    private function ensureEnabled(): void
    {
        if (! $this->config->get('otpkit.login.enabled', false)) {
            throw new LogicException('OtpKit login is disabled. Set otpkit.login.enabled to true.');
        }
    }

    /**
     * @return array<string, array{model: class-string<Model&Authenticatable>, identifier: string}>
     */
    private function guards(): array
    {
        return $this->config->get('otpkit.login.guards', []) ?? [];
    }
}
