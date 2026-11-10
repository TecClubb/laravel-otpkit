<?php

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TecClub\OtpKit\Contracts\TokenIssuer;
use TecClub\OtpKit\Enums\OtpStatus;
use TecClub\OtpKit\Exceptions\InvalidConfigException;
use TecClub\OtpKit\Facades\OtpLogin;
use TecClub\OtpKit\Login\LoginService;
use TecClub\OtpKit\OtpKit;
use TecClub\OtpKit\Tests\Fixtures\Admin;
use TecClub\OtpKit\Tests\Fixtures\Device;
use TecClub\OtpKit\Tests\Fixtures\LoginForm;
use TecClub\OtpKit\Tests\Fixtures\User;

function enableLogin(): void
{
    config()->set('otpkit.login.enabled', true);
    config()->set('otpkit.login.guards', [
        'web' => ['model' => User::class, 'identifier' => 'email'],
        'admin' => ['model' => Admin::class, 'identifier' => 'phone'],
        'sanctum' => ['model' => User::class, 'identifier' => 'email'],
    ]);
}

/**
 * Register the API routes as routes/api.php would: Laravel adds the "api" prefix.
 */
function apiLoginRoutesInApiFile(): void
{
    Route::prefix('api')->group(fn () => OtpKit::apiLoginRoutes(guards: ['sanctum']));
}

function refreshRoutes(): void
{
    app('router')->getRoutes()->refreshNameLookups();
    app('router')->getRoutes()->refreshActionLookups();
}

function lastMailedCode(): string
{
    return test()->codeFromMail(test()->sentMails()->last());
}

describe('switching on', function () {
    it('is disabled by default', function () {
        expect(config('otpkit.login.enabled'))->toBeFalse();

        OtpKit::webLoginRoutes();
        OtpKit::apiLoginRoutes();
        refreshRoutes();

        expect(Route::has('otpkit.login.send'))->toBeFalse()
            ->and(Route::has('otpkit.login.api.send'))->toBeFalse()
            ->and(fn () => OtpLogin::send('jane@example.com'))->toThrow(LogicException::class, 'login is disabled');
    });

    it('registers nothing until the route methods are called', function () {
        enableLogin();
        refreshRoutes();

        expect(Route::has('otpkit.login.send'))->toBeFalse()
            ->and(Route::has('otpkit.login.api.send'))->toBeFalse();

        OtpKit::webLoginRoutes();
        apiLoginRoutesInApiFile();
        refreshRoutes();

        expect(route('otpkit.login.send', absolute: false))->toBe('/otp-login/send')
            ->and(route('otpkit.login.verify', absolute: false))->toBe('/otp-login/verify')
            ->and(route('otpkit.login.api.send', absolute: false))->toBe('/api/otp-login/send')
            ->and(route('otpkit.login.api.verify', absolute: false))->toBe('/api/otp-login/verify');
    });

    it('accepts a custom prefix', function () {
        enableLogin();

        OtpKit::webLoginRoutes('auth/code');
        refreshRoutes();

        expect(route('otpkit.login.send', absolute: false))->toBe('/auth/code/send');
    });

    it('validates the login config at boot', function (array $guards, string $message) {
        config()->set('otpkit.login.guards', $guards);

        expect(fn () => app(LoginService::class)->validateConfig())->toThrow(InvalidConfigException::class, $message);
    })->with([
        'no guards' => [[], 'at least one guard'],
        'unknown auth guard' => [['nope' => ['model' => User::class, 'identifier' => 'email']], 'defined in config/auth.php'],
        'not authenticatable' => [['web' => ['model' => Device::class, 'identifier' => 'email']], 'Authenticatable'],
        'no identifier' => [['web' => ['model' => User::class, 'identifier' => '']], 'exactly one column'],
    ]);
});

describe('send endpoint', function () {
    beforeEach(function () {
        enableLogin();
        OtpKit::webLoginRoutes();
        refreshRoutes();
    });

    it('responds identically for existing and missing accounts', function () {
        User::make('jane@example.com');

        $existing = $this->postJson('/otp-login/send', ['identifier' => 'jane@example.com']);
        $missing = $this->postJson('/otp-login/send', ['identifier' => 'nobody@example.com']);
        $limited = $this->postJson('/otp-login/send', ['identifier' => 'jane@example.com']); // cooldown

        foreach ([$existing, $missing, $limited] as $response) {
            $response->assertStatus(202)->assertExactJson(['message' => 'If an account matches, we sent a login code.']);
        }

        expect($this->sentMails())->toHaveCount(1)
            ->and($this->sentMails()->sole()->getTo()[0]->getAddress())->toBe('jane@example.com');
    });

    it('uses the login purpose, so its config and rate limits apply', function () {
        config()->set('otpkit.purposes.login', ['length' => 8]);
        $user = User::make();

        $this->postJson('/otp-login/send', ['identifier' => $user->email]);

        expect(lastMailedCode())->toMatch('/^\d{8}$/')
            ->and($user->otp('login')->pending())->toBeTrue();
    });

    it('works with strict purposes without listing login', function () {
        config()->set('otpkit.strict_purposes', true);
        User::make();

        $this->postJson('/otp-login/send', ['identifier' => 'jane@example.com'])->assertStatus(202);

        expect($this->sentMails())->toHaveCount(1);
    });

    it('rejects guards that are not configured', function () {
        $this->postJson('/otp-login/send', ['identifier' => 'jane@example.com', 'guard' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('guard');
    });
});

describe('web login', function () {
    beforeEach(function () {
        enableLogin();
        OtpKit::webLoginRoutes();
        refreshRoutes();
    });

    it('logs in, regenerates the session and fires Laravel\'s Login event', function () {
        Event::fake([Login::class]);
        $user = User::make();
        $this->postJson('/otp-login/send', ['identifier' => $user->email]);

        $this->startSession();
        $sessionId = session()->getId();

        $this->post('/otp-login/verify', ['identifier' => $user->email, 'code' => lastMailedCode()])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user, 'web');
        expect(session()->getId())->not->toBe($sessionId);
        Event::assertDispatched(Login::class, fn (Login $event) => $event->guard === 'web' && $event->user->is($user) && $event->remember === false);
    });

    it('redirects to the intended URL and honours remember', function () {
        $user = User::make();
        $this->postJson('/otp-login/send', ['identifier' => $user->email]);

        $this->withSession(['url.intended' => '/dashboard'])
            ->post('/otp-login/verify', ['identifier' => $user->email, 'code' => lastMailedCode(), 'remember' => '1'])
            ->assertRedirect('/dashboard');

        expect($user->fresh()->remember_token)->not->toBeNull();
    });

    it('returns the redirect as JSON for JSON requests', function () {
        $user = User::make();
        $this->postJson('/otp-login/send', ['identifier' => $user->email]);

        $this->postJson('/otp-login/verify', ['identifier' => $user->email, 'code' => lastMailedCode()])
            ->assertOk()
            ->assertJson(['redirect' => url('/')]);

        $this->assertAuthenticatedAs($user, 'web');
    });

    it('redirects back with a generic error on a wrong code', function () {
        $user = User::make();
        $this->postJson('/otp-login/send', ['identifier' => $user->email]);
        $code = lastMailedCode();

        $this->from('/login')
            ->post('/otp-login/verify', ['identifier' => $user->email, 'code' => wrongCode($code)])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['code' => 'The code is invalid or has expired.'])
            ->assertSessionHasInput('identifier', $user->email);

        $this->assertGuest('web');
    });

    it('fails the same way for a missing account', function () {
        $this->postJson('/otp-login/verify', ['identifier' => 'nobody@example.com', 'code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'The code is invalid or has expired.']);
    });

    it('counts attempts on missing accounts against the per-IP verify limit', function () {
        config()->set('otpkit.rate_limits.verify.per_ip_per_minute', 2);

        foreach (range(1, 2) as $attempt) {
            expect(OtpLogin::attempt('nobody@example.com', '123456')->status)->toBe(OtpStatus::NotFound);
        }

        $limited = OtpLogin::attempt('nobody@example.com', '123456');

        expect($limited->status)->toBe(OtpStatus::RateLimited)
            ->and($limited->message())->toStartWith('Too many attempts. Try again in');
    });

    it('refuses guards that cannot keep a session, before using the code', function () {
        $user = User::make();
        $code = $user->otp('login')->generate()->code;

        expect(fn () => OtpLogin::attempt($user->email, $code, guard: 'sanctum'))->toThrow(LogicException::class, 'attemptForToken')
            ->and($user->otp('login')->pending())->toBeTrue();
    });
});

describe('API login', function () {
    beforeEach(function () {
        enableLogin();
        apiLoginRoutesInApiFile();
        Route::middleware(['api', 'auth:sanctum'])->get('/api/me', fn (Request $request) => $request->user()->email);
        refreshRoutes();
    });

    it('returns a working Sanctum token', function () {
        $user = User::make();
        $this->postJson('/api/otp-login/send', ['identifier' => $user->email])->assertStatus(202);

        $response = $this->postJson('/api/otp-login/verify', [
            'identifier' => $user->email,
            'code' => lastMailedCode(),
            'device_name' => 'iPhone',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'token_type'])->assertJson(['token_type' => 'Bearer']);

        expect(DB::table('personal_access_tokens')->value('name'))->toBe('iPhone');

        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('token'))->getJson('/api/me')->assertOk()->assertSee('jane@example.com');
    });

    it('names the token from config when no device name is sent', function () {
        $user = User::make();
        $this->postJson('/api/otp-login/send', ['identifier' => $user->email]);

        $this->postJson('/api/otp-login/verify', ['identifier' => $user->email, 'code' => lastMailedCode()])->assertOk();

        expect(DB::table('personal_access_tokens')->value('name'))->toBe('otp-login');
    });

    it('fails with a 422 on a wrong code and issues no token', function () {
        $user = User::make();
        $this->postJson('/api/otp-login/send', ['identifier' => $user->email]);

        $this->postJson('/api/otp-login/verify', ['identifier' => $user->email, 'code' => wrongCode(lastMailedCode())])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'The code is invalid or has expired.']);

        expect(DB::table('personal_access_tokens')->count())->toBe(0);
    });

    it('only accepts the guards the routes were registered for', function () {
        $this->postJson('/api/otp-login/send', ['identifier' => '555-0100', 'guard' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('guard');
    });

    it('can be replaced with a custom TokenIssuer', function () {
        $this->app->bind(TokenIssuer::class, fn () => new class implements TokenIssuer
        {
            public function issue(Authenticatable $user, string $name): string
            {
                return "custom-{$user->getAuthIdentifier()}-{$name}";
            }

            public function tokenType(): string
            {
                return 'Custom';
            }
        });

        $user = User::make();
        $this->postJson('/api/otp-login/send', ['identifier' => $user->email]);

        $this->postJson('/api/otp-login/verify', ['identifier' => $user->email, 'code' => lastMailedCode(), 'device_name' => 'cli'])
            ->assertOk()
            ->assertExactJson(['token' => "custom-{$user->id}-cli", 'token_type' => 'Custom']);

        expect(DB::table('personal_access_tokens')->count())->toBe(0);
    });

    it('throws when routes are registered for a model without HasApiTokens', function () {
        expect(fn () => OtpKit::apiLoginRoutes(guards: ['admin']))
            ->toThrow(InvalidConfigException::class, 'does not use Laravel\Sanctum\HasApiTokens');
    });
});

describe('guards', function () {
    beforeEach(function () {
        enableLogin();
        OtpKit::webLoginRoutes();
        refreshRoutes();
    });

    it('logs in two guards with different models and identifiers', function () {
        $user = User::make('jane@example.com');
        $admin = Admin::create(['phone' => '555-0100', 'email' => 'ops@example.com']);

        $this->postJson('/otp-login/send', ['identifier' => '555-0100', 'guard' => 'admin']);
        expect($this->sentMails()->last()->getTo()[0]->getAddress())->toBe('ops@example.com');

        $this->postJson('/otp-login/verify', ['identifier' => '555-0100', 'code' => lastMailedCode(), 'guard' => 'admin'])->assertOk();

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');

        $this->postJson('/otp-login/send', ['identifier' => 'jane@example.com']);
        $this->postJson('/otp-login/verify', ['identifier' => 'jane@example.com', 'code' => lastMailedCode()])->assertOk();

        $this->assertAuthenticatedAs($user, 'web');
    });

    it('does not mix up identifiers between guards', function () {
        User::make('jane@example.com');
        Admin::create(['phone' => '555-0100', 'email' => 'ops@example.com']);

        $this->postJson('/otp-login/send', ['identifier' => 'jane@example.com', 'guard' => 'admin']);
        $this->postJson('/otp-login/send', ['identifier' => '555-0100', 'guard' => 'web']);

        expect($this->sentMails())->toBeEmpty();
    });
});

describe('authorizeLoginUsing()', function () {
    beforeEach(function () {
        enableLogin();
        OtpKit::webLoginRoutes();
        refreshRoutes();
        OtpKit::authorizeLoginUsing(fn (User $user) => $user->email !== 'banned@example.com');
    });

    it('blocks an account with the generic failure and sends it nothing', function () {
        $banned = User::make('banned@example.com');

        $this->postJson('/otp-login/send', ['identifier' => 'banned@example.com'])
            ->assertStatus(202)
            ->assertExactJson(['message' => 'If an account matches, we sent a login code.']);

        expect($this->sentMails())->toBeEmpty();

        // Even holding a valid code, the account can't log in, and the code isn't used up.
        $code = $banned->otp('login')->generate()->code;

        $this->postJson('/otp-login/verify', ['identifier' => 'banned@example.com', 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'The code is invalid or has expired.']);

        $result = OtpLogin::attempt('banned@example.com', $code);

        expect($result->ok())->toBeFalse()
            ->and($result->blocked)->toBeTrue()
            ->and($result->user)->toBeNull()
            ->and($banned->otp('login')->pending())->toBeTrue();

        $this->assertGuest('web');
    });

    it('lets other accounts through', function () {
        $user = User::make('jane@example.com');
        $this->postJson('/otp-login/send', ['identifier' => $user->email]);

        expect(OtpLogin::attempt($user->email, lastMailedCode())->ok())->toBeTrue();
    });
});

describe('Livewire', function () {
    it('logs in through OtpLogin with no HTTP calls to the package endpoints', function () {
        enableLogin();
        $user = User::make();

        // No login routes are registered in this test at all.
        refreshRoutes();
        expect(Route::has('otpkit.login.send'))->toBeFalse();

        $component = Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->call('sendCode')
            ->assertSet('codeSent', true);

        $component->set('code', wrongCode(lastMailedCode()))
            ->call('login')
            ->assertHasErrors(['code' => 'The code is invalid or has expired.']);

        $component->set('code', lastMailedCode())
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user, 'web');
    });
});
