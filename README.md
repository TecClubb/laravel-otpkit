# OtpKit for Laravel

[![Tests](https://github.com/TecClubb/laravel-otpkit/actions/workflows/run-tests.yml/badge.svg)](https://github.com/TecClubb/laravel-otpkit/actions/workflows/run-tests.yml)
[![PHPStan](https://github.com/TecClubb/laravel-otpkit/actions/workflows/phpstan.yml/badge.svg)](https://github.com/TecClubb/laravel-otpkit/actions/workflows/phpstan.yml)

A headless Laravel package that issues, delivers, verifies and expires one-time codes for any model or any email address, scoped by purpose, with opt-in passwordless login for web and API.

Use it anywhere an app needs "prove you own this account or address": confirming a data export, verifying an email on the spot, re-confirming a sensitive action, guest checkout codes, or logging in by code.

```php
$customer->otp('data-export')->send();

$result = $customer->otp('data-export')->verify($request->input('code'));

if (! $result->ok()) {
    return back()->withErrors(['code' => $result->message()]);
}
```

- **Purpose scoping.** A code for one action never works for another.
- **Any subject.** Any Eloquent model, or a bare address when no account exists yet.
- **A separate destination.** Send to a new email while the code belongs to the user.
- **Database or cache storage**, or your own store.
- **Per-use code format.** Length, numeric or alphanumeric, lifetime and attempts per purpose or per call.
- **Headless.** No Blade forms or Livewire components: PHP APIs, a validation rule, controllers and JSON.
- **Expected outcomes are results, not exceptions.** A wrong code or a rate limit comes back as a status.
- **Opt-in login** by code for session guards and API tokens (Sanctum or your own issuer).

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Concepts](#concepts)
- [Usage](#usage)
- [Results and statuses](#results-and-statuses)
- [Validation rule](#validation-rule)
- [Configuration reference](#configuration-reference)
- [Code formats](#code-formats)
- [Storage](#storage)
- [Delivery and customization](#delivery-and-customization)
- [Rate limiting](#rate-limiting)
- [Bindings](#bindings)
- [Login by code](#login-by-code)
- [Events](#events)
- [Testing your app](#testing-your-app)
- [Pruning](#pruning)
- [Security notes](#security-notes)
- [Testing the package](#testing-the-package)

## Requirements

- PHP 8.2+ (8.3+ on Laravel 13)
- Laravel 12 or 13
- `laravel/sanctum` only if you use API login with the default token issuer

## Installation

```bash
composer require tecclub/otpkit
```

```bash
php artisan otpkit:install
```

The install command publishes `config/otpkit.php` and the migration, then offers to run it. The migration is published with the current timestamp and is never run automatically: your app owns its schema. If you only use the cache store, you don't need the table.

That's all the setup needed. Codes are mailed with the package's template through your app's normal mail settings, on your app's default queue.

> If your models use UUID or ULID keys, change `nullableMorphs('otpable')` in the published migration to `nullableUuidMorphs` or `nullableUlidMorphs` before running it.

Optional publishes:

```bash
php artisan vendor:publish --tag=otpkit-translations
php artisan vendor:publish --tag=otpkit-views
```

## Concepts

Every code belongs to one **subject** and one **purpose**. Everything else is resolved from config.

| Concept | Meaning | Example |
| --- | --- | --- |
| Subject | Who the code belongs to: any Eloquent model, or an address when no account exists | `$customer`, `mail:guest@example.com` |
| Destination | Where the code is delivered. Defaults to the subject; can be overridden and optionally bound into the code | A new address during an email change |
| Purpose | What the code unlocks. Free-form; codes never work across purposes. Omitted means `default` | `data-export`, `delete-account`, `login` |
| Policy | The rules for one code: length, charset, lifetime, attempts, bindings, rate limits, notification | 8 characters, 5 minutes, 3 attempts |
| Store | Where hashed codes live | `database` (default), `cache` |

There is only ever **one active code per subject and purpose**: issuing a new one replaces the old one.

**Policy resolution:** package defaults, then `defaults` in config, then the purpose's entry in `purposes`, then per-call overrides. Later wins. The resolved policy is snapshotted onto each code when it's issued, so changing config never affects codes already sent.

## Usage

### Models

Add the trait. `Notifiable` is only needed for the built-in mail delivery.

```php
use Illuminate\Notifications\Notifiable;
use TecClub\OtpKit\Concerns\HasOneTimePasswords;

class Customer extends Model
{
    use HasOneTimePasswords, Notifiable;
}
```

```php
$customer->otp('data-export')->send();
$result = $customer->otp('data-export')->verify($code);

$customer->otp()->send();                 // no purpose = 'default'

$customer->otp('data-export')->pending(); // bool: an active, usable code exists
$customer->otp('data-export')->invalidate(); // kill the active code

$customer->oneTimePasswords;              // stored codes (database store)
```

### Addresses without a model

```php
use TecClub\OtpKit\Facades\Otp;

Otp::to('mail', 'guest@example.com')->purpose('guest-checkout')->send();

Otp::to('mail', $request->email)->purpose('guest-checkout')->verify($request->code);
```

Addresses are matched case-insensitively and are only ever stored as a hash.

### Sending to another address

```php
$user->otp('verify-email')->sendTo('mail', $newEmail)->send();

$user->otp('verify-email')->verify($code, destination: $newEmail);
```

Turn on the `destination` binding for any purpose that uses `sendTo()`, such as changing an email address. Without it, a code sent to one address could be used to confirm a different one:

```php
'purposes' => [
    'verify-email' => ['bind_to' => ['destination']],
],
```

### Per-call overrides

```php
Otp::for($customer)->purpose('confirm-transfer')
    ->length(8)
    ->alphanumeric()     // or ->numeric(), ->charset('my-format')
    ->ttl(300)           // seconds
    ->maxAttempts(3)
    ->bindTo(['ip'])
    ->notification(TransferCodeNotification::class)
    ->send();
```

### Delivering the code yourself

`generate()` stores a code and returns it without sending anything, for WhatsApp, push, or any channel your app handles itself:

```php
$issued = $customer->otp('pair-device')->generate();

$issued->code;        // plaintext, only available here
$issued->id;          // ULID
$issued->expiresAt;   // CarbonImmutable
```

`generate()` does not apply the send rate limits, because it has no way to report them. If you deliver codes yourself, apply your own limits.

### Check vs verify

- `verify($code)` runs every check and **consumes** the code on success. Use it where the protected action happens.
- `check($code)` runs the same checks and counts failed attempts, but **leaves the code active**. Use it to validate in one step and act in a later one.

```php
$customer->otp('delete-account')->check($code);   // code stays active
$customer->otp('delete-account')->verify($code);  // code consumed on success
```

Input is normalized before comparing: trimmed, uppercased, spaces and hyphens removed, so `ab3-k9x` matches `AB3K9X`.

## Results and statuses

`send()` returns a `SendResult`:

```php
$send->sent();        // bool
$send->status;        // SendStatus::Sent | SendStatus::RateLimited
$send->retryAfter;    // ?int seconds, when rate limited
$send->expiresAt;     // ?CarbonImmutable, when sent
```

`verify()` and `check()` return a `VerificationResult`:

```php
$result->ok();                 // bool
$result->status;               // OtpStatus enum
$result->attemptsRemaining;    // ?int
$result->retryAfter;           // ?int seconds
$result->message();            // safe, translated message; null when ok
$result->throw('code');        // ValidationException on that field when not ok
```

| Status | When | Default message | Code afterwards |
| --- | --- | --- | --- |
| `Verified` | Code matches | none | Consumed (`verify`) or active (`check`) |
| `Invalid` | Wrong code, attempts left | The code is invalid or has expired. | Active, attempts +1 |
| `Expired` | Past its lifetime | The code is invalid or has expired. | Dead |
| `NotFound` | No code for this subject and purpose | The code is invalid or has expired. | None |
| `AlreadyUsed` | Already consumed, including by a parallel request | The code is invalid or has expired. | Consumed |
| `ContextMismatch` | An enabled binding failed | The code is invalid or has expired. | Active, attempts +1 |
| `TooManyAttempts` | Attempt limit reached | Too many attempts. Request a new code. | Burned |
| `RateLimited` | Per-IP verify limit hit | Too many attempts. Try again in :seconds seconds. | Unchanged |

**Why the messages collapse:** telling a user "no code exists for this address" versus "wrong code" helps an attacker. The status stays precise for your logs, events and code; only `message()` is generic. Set `messages.verbose` to `true` for a distinct message per status. All messages are in the publishable translations.

A wrong code that uses up the last attempt returns `TooManyAttempts` immediately.

## Validation rule

```php
use TecClub\OtpKit\Rules\ValidOtp;

// check only: a passed validation followed by a failed action doesn't waste the code
'code' => ['required', 'string', new ValidOtp($customer, 'delete-account')],

// check and consume, for one-step forms
'code' => ['required', 'string', (new ValidOtp($customer, 'delete-account'))->consume()],

// address subjects, and the destination binding
'code' => ['required', 'string', new ValidOtp(Otp::to('mail', $email)->purpose('guest-checkout'))],
'code' => ['required', 'string', (new ValidOtp($user, 'verify-email'))->destination($newEmail)],
```

`->result()` returns the last `VerificationResult` when you need the exact status.

**Failed checks count as attempts in both modes.** Validating on every keystroke (Livewire `wire:model.live`) would burn the code, so validate the code field on submit only.

## Configuration reference

Every key has a working default. The file is validated at boot (for example, length must be 4 to 12), so mistakes fail early.

| Key | Default | Meaning |
| --- | --- | --- |
| `store` | `database` (`OTPKIT_STORE`) | `database`, `cache`, or a custom driver name |
| `cache_store` | `null` (`OTPKIT_CACHE_STORE`) | A store from `config/cache.php` for codes and rate limits; `null` = app default |
| `defaults.length` | `6` | 4 to 12 |
| `defaults.charset` | `numeric` | `numeric`, `alphanumeric`, or a registered generator |
| `defaults.ttl` | `120` | Seconds a code lives |
| `defaults.max_attempts` | `5` | Attempts per code, then it's burned (1 to 255) |
| `defaults.bind_to` | `[]` | Any of `destination`, `ip`, `user_agent` |
| `purposes` | `[]` | Per-purpose overrides of `length`, `charset`, `ttl`, `max_attempts`, `bind_to`, `rate_limits`, `notification` |
| `strict_purposes` | `false` | `true` = purposes not listed in `purposes` throw `UnknownPurposeException` (`default` and `login` are always allowed) |
| `notification` | `OneTimePasswordNotification` | The notification class used to deliver codes |
| `queue.enabled` | `true` | `false` sends with `notifyNow()`, inline |
| `queue.connection` | `null` (`OTPKIT_QUEUE_CONNECTION`) | `null` = app default |
| `queue.name` | `null` (`OTPKIT_QUEUE`) | `null` = default queue |
| `rate_limits.send.cooldown` | `[30, 60, 120, 300]` | Seconds before each resend; a list escalates, an int is fixed |
| `rate_limits.send.per_subject_per_hour` | `5` | Sends per subject and purpose per hour |
| `rate_limits.send.per_ip_per_hour` | `20` | Sends per IP per hour |
| `rate_limits.verify.per_ip_per_minute` | `10` | Verify attempts per IP per minute |
| `messages.verbose` | `false` | A distinct message per status |
| `database.connection` | `null` | Connection for the codes table; `null` = default |
| `database.table` | `one_time_passwords` | Table name |
| `cache.prefix` | `otpkit` | Key prefix for the cache store |
| `cache.grace` | `60` | Seconds a cached code is kept after expiry, so verify can report `Expired` |
| `prune.schedule` | `true` | Register `otpkit:prune` daily automatically |
| `prune.keep_for_hours` | `24` | Keep expired and used rows this long |
| `login.enabled` | `false` (`OTPKIT_LOGIN_ENABLED`) | Turn on login by code |
| `login.guards` | `web` → `App\Models\User` by `email` | Each guard names a model and exactly one identifier column |
| `login.redirect_to` | `/` | Where web login redirects when there's no intended URL |
| `login.token_issuer` | `SanctumTokenIssuer` | Class issuing API tokens |
| `login.token_name` | `otp-login` | Token name when the request sends no `device_name` |

Setting any single rate limit to `null` disables it. A purpose's `rate_limits` merge per section over the global ones:

```php
'purposes' => [
    'delete-account' => ['length' => 8, 'max_attempts' => 3, 'ttl' => 300],
    'login' => ['rate_limits' => ['send' => ['cooldown' => [15, 60]]]],
],
```

## Code formats

Every character comes from `random_int()`.

| Format | Possible codes | Chance one code is guessed in 5 attempts |
| --- | --- | --- |
| 6 numeric (default) | 1,000,000 | 1 in 200,000 |
| 8 numeric | 100,000,000 | 1 in 20,000,000 |
| 6 alphanumeric | about 887 million | 1 in about 177 million |
| 8 alphanumeric | about 853 billion | 1 in about 170 billion |

`alphanumeric` uses uppercase `23456789ABCDEFGHJKMNPQRSTUVWXYZ`, without 0, O, 1, I or L, which people misread.

Custom formats implement `CodeGenerator` and are registered by name:

```php
use TecClub\OtpKit\Contracts\CodeGenerator;
use TecClub\OtpKit\OtpKit;

OtpKit::generator('hex', fn () => new class implements CodeGenerator {
    public function generate(int $length): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= '0123456789ABCDEF'[random_int(0, 15)];
        }

        return $code;
    }
});

// then: 'charset' => 'hex', or ->charset('hex')
```

## Storage

| | Database (default) | Cache |
| --- | --- | --- |
| Setup | Publish and run the migration | A lock-capable cache store |
| History for audits | Kept until pruned | None |
| Survives `cache:clear` | Yes | No |
| Redis eviction can drop codes | No | Yes, with an LRU eviction policy |
| Expiry cleanup | `otpkit:prune` (scheduled for you) | Native TTL |

Both stores run verification under a lock (a transaction with `lockForUpdate()`, or `Cache::lock()`), so two parallel requests can never both succeed or skip the attempt counter.

**Cache store:** set `OTPKIT_STORE=cache`. The cache store must support atomic locks (Redis, Memcached, database, file, array); the app fails at boot with a clear message if it doesn't. A dedicated store avoids the `cache:clear` and eviction caveats: define a separate Redis store in `config/cache.php` (for example, its own Redis database) and name it in `OTPKIT_CACHE_STORE`. Rate limits use the same store.

**Custom store:** implement `TecClub\OtpKit\Contracts\OtpStore` and register it:

```php
OtpKit::extend('dynamo', fn ($app) => new DynamoOtpStore(...));
// OTPKIT_STORE=dynamo
```

## Delivery and customization

Codes are delivered by a queued mail notification with a Markdown template: subject "Your :app verification code", the code in a large panel, its lifetime, and "If you didn't request this, you can ignore this email." It uses your app's `mail.from`, mailer and mail theme.

The notification is dispatched after the database transaction commits, and a queued code that was consumed, expired or replaced before the worker ran is dropped instead of sent.

**Customization, smallest change first:**

1. **Text.** Publish the translations (`otpkit-translations`). Add a purpose-specific subject with `mail.subjects.{purpose}`, for example `'data-export' => 'Confirm your data export'`.
2. **Look.** Publish the view to `resources/views/vendor/otpkit/mail/code.blade.php` (`otpkit-views`).
3. **Everything.** Set `notification` (globally or per purpose) to your own class, usually extending the default. This is how you add SMS channels, custom Mailables or sender rules. Your class receives the code as its `$otp` constructor argument (an `IssuedOtp`).

```php
use Illuminate\Notifications\Messages\VonageMessage;
use TecClub\OtpKit\Notifications\OneTimePasswordNotification;

class SmsCode extends OneTimePasswordNotification
{
    public function via(object $notifiable): array
    {
        return ['vonage'];
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        return (new VonageMessage)->content("Your code is {$this->otp->code}");
    }
}
```

**Routing:** a model with `Notifiable` gets `$model->notify()`. `Otp::to()` and `sendTo()` use `Notification::route($channel, $address)`. A model without `Notifiable` and no `sendTo()` throws `MissingDestinationException`.

**Queue:** `queue.connection` and `queue.name` default to your app's. `queue.enabled = false` sends inline. The plaintext code sits in the queued job payload; to encrypt it there, add `ShouldBeEncrypted` to your own notification class (it uses your `APP_KEY`).

## Rate limiting

Five limits, each against a different attack. All are on by default and can be overridden per purpose or disabled with `null`.

| Limit | Keyed by | Default | Defends against | Result |
| --- | --- | --- | --- | --- |
| Attempts per code | The code | 5 | Guessing one code | `TooManyAttempts`, code burned |
| Send cooldown | Subject + purpose | 30 s, 60 s, 120 s, then 300 s | Resend-button spam | `SendStatus::RateLimited` + `retryAfter` |
| Sends per subject | Subject + purpose | 5 per hour | Flooding one person's inbox | `SendStatus::RateLimited` |
| Sends per IP | IP | 20 per hour | Spraying many addresses, SMS pumping fraud | `SendStatus::RateLimited` |
| Verifies per IP | IP | 10 per minute | Guessing across many subjects | `OtpStatus::RateLimited` |

**The cooldown escalates** so a real person whose email was slow can resend quickly, while each further resend waits longer. The step is the number of sends already made this hour; it resets once a code is verified or the hour passes. An integer means a fixed cooldown.

A rate-limited send creates no code and sends nothing. Limits use Laravel's `RateLimiter` with hashed keys, on the OTP cache store.

**Behind a proxy or load balancer, configure [trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies).** IP limits use `request()->ip()`; without trusted proxies every visitor shares the proxy's IP. Outside an HTTP request (queues, console), IP limits are skipped and subject limits still apply.

## Bindings

Bindings tie a code to where it was requested. All are **off by default**; turn them on globally (`defaults.bind_to`) or per purpose.

| Binding | Checks |
| --- | --- |
| `destination` | `verify($code, destination: $address)` matches the address the code was sent to with `sendTo()` |
| `ip` | The verifying request comes from the same IP |
| `user_agent` | The verifying request has the same user agent |

A failed binding returns `ContextMismatch` and uses up an attempt. IP and user agent are stored only as hashes. With no bindings enabled, the `destination:` argument is ignored.

## Login by code

Login is a thin layer over the core using the `login` purpose. It's **off by default**, and its routes are **never registered automatically**.

### Enabling it

```env
OTPKIT_LOGIN_ENABLED=true
```

```php
'login' => [
    'enabled' => env('OTPKIT_LOGIN_ENABLED', false),
    'guards' => [
        'web' => ['model' => App\Models\User::class, 'identifier' => 'email'],
        'admin' => ['model' => App\Models\Admin::class, 'identifier' => 'phone'],
        'sanctum' => ['model' => App\Models\User::class, 'identifier' => 'email'],
    ],
],
```

Each guard is a guard from `config/auth.php` and names its model and exactly one identifier column, so admins and customers can log in with different models. The identifier must match the stored value exactly, as with Laravel's own login: store emails in lowercase if users may type them in mixed case.

### Routes

```php
// routes/web.php
use TecClub\OtpKit\OtpKit;

OtpKit::webLoginRoutes();                                  // /otp-login/send, /otp-login/verify
OtpKit::webLoginRoutes('admin/login', guards: ['admin']);  // custom prefix, admin guard only

// routes/api.php
OtpKit::apiLoginRoutes(guards: ['sanctum']);               // /api/otp-login/send, /api/otp-login/verify
```

Each method takes a prefix (default `otp-login`; in `routes/api.php` Laravel adds the `api/` part), the login guards that route group accepts (the first is the default; `null` means all configured guards), and middleware (`web` or `api` by default). They register nothing while login is disabled, so the calls can stay in your routes file. You can also point your own routes at the controllers in `TecClub\OtpKit\Login\Http\Controllers`.

| Endpoint | Input | Response |
| --- | --- | --- |
| `POST {prefix}/send` | `identifier`, `guard` (optional) | Always `202` with the same body, whether or not the account exists |
| `POST {prefix}/verify` (web) | `identifier`, `code`, `remember` (optional), `guard` (optional) | Logs in through the guard, regenerates the session, redirects to the intended URL (or `{ "redirect": … }` for JSON requests) |
| `POST {prefix}/verify` (API) | `identifier`, `code`, `device_name` (optional), `guard` (optional) | `{ "token": …, "token_type": "Bearer" }` |

A failed verify is a validation error on `code` with the generic message: a 422 for JSON requests, or a redirect back with errors for forms.

### From Livewire or any PHP code

The endpoints are thin wrappers over the `OtpLogin` service. Livewire components, Inertia controllers and custom routes call it directly, without HTTP calls to the package endpoints. The package has no Livewire dependency.

```php
use TecClub\OtpKit\Facades\OtpLogin;

public function sendCode(): void
{
    OtpLogin::send($this->email, guard: 'web'); // silent for unknown accounts
    $this->codeSent = true;
}

public function login()
{
    // Errors added with addError() persist across Livewire requests.
    $this->resetErrorBag('code');

    $result = OtpLogin::attempt($this->email, $this->code, guard: 'web', remember: $this->remember);

    if (! $result->ok()) {
        $this->addError('code', $result->message());

        return;
    }

    return redirect()->intended('/dashboard');
}
```

```php
// API guards
$result = OtpLogin::attemptForToken($identifier, $code, guard: 'sanctum', deviceName: 'iPhone');
$result->token;
$result->tokenType;
```

`attempt()` logs in through the guard (so Laravel's `Login` event fires) and regenerates the session. `attemptForToken()` returns a token from the token issuer. Both return a `LoginResult` with `ok()`, `user`, `status`, `token`, `message()` and `throw()`.

### API tokens

The default `SanctumTokenIssuer` needs `laravel/sanctum` and a model using `HasApiTokens`; otherwise `apiLoginRoutes()` throws when the routes are registered. To use Passport, JWT or anything else, implement `TecClub\OtpKit\Contracts\TokenIssuer` and bind it (a container binding wins over the config value):

```php
use TecClub\OtpKit\Contracts\TokenIssuer;

$this->app->bind(TokenIssuer::class, JwtTokenIssuer::class);
```

### Blocking accounts

```php
OtpKit::authorizeLoginUsing(fn ($user) => ! $user->is_banned);
```

A blocked account is sent nothing and gets the same generic failure as a wrong code. `LoginResult->blocked` tells your code that this happened.

### Login security

- The send endpoint never reveals whether an account exists: the response is identical, even when a rate limit applies.
- Attempts on missing or blocked accounts count against the per-IP verify limit, so the limit can't reveal which accounts exist either.
- Every rate limit applies to login; configure them under `purposes.login`.
- The send endpoint does a little more work (store a code, queue a mail) for an existing account than for a missing one, so response times differ slightly. If that matters for you, wrap the endpoint so every response takes the same minimum time.

## Events

None of them carry the plaintext code.

| Event | Fired when | Payload |
| --- | --- | --- |
| `OtpGenerated` | A code is stored | `subjectKey`, `purpose`, `id`, `expiresAt` |
| `OtpSent` | The notification was handed to Laravel | `subjectKey`, `purpose`, `id` |
| `OtpVerified` | `verify()` returned `Verified` | `subjectKey`, `purpose`, `id` |
| `OtpFailed` | Any other verify or check status | `subjectKey`, `purpose`, `status` |
| `OtpLocked` | A code hits its attempt limit | `subjectKey`, `purpose`, `id` |
| `OtpRateLimited` | A send or verify is rate limited | `subjectKey`, `purpose`, `limit`, `ip` |

All live in `TecClub\OtpKit\Events`. The subject key is `model:{morphClass}:{key}` for models and a SHA-256 hash for addresses.

## Testing your app

```php
use TecClub\OtpKit\Facades\Otp;

Otp::fake();                    // no real store or mail; every code is recorded
Otp::fake(code: '123456');      // a fixed code, for end-to-end tests

Otp::assertSent($user, 'data-export');
Otp::assertSent('guest@example.com', 'guest-checkout', times: 1);
Otp::assertNotSent($user, 'delete-account');
Otp::assertVerified($user, 'data-export');
Otp::assertNotVerified($user, 'data-export');

$code = Otp::lastCode($user, 'data-export');
```

The fake still applies the real verification rules (attempts, expiry, purposes).

## Pruning

Expired codes are never accepted, whether or not they've been cleaned up; pruning only keeps the table small.

`otpkit:prune` deletes rows whose `expires_at` or `consumed_at` is older than `prune.keep_for_hours`, in chunks. It's scheduled daily for you. To schedule it yourself, set `prune.schedule` to `false`:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('otpkit:prune')->daily();
```

The cache store needs no pruning: each key expires after `ttl + grace`.

## Security notes

- **Hashing.** Codes are stored only as `HMAC-SHA256("{subject}|{purpose}|{code}")` keyed with your decoded `APP_KEY`, and compared with `hash_equals()`. A stolen hash is useless for any other subject or purpose. The attempt limit, not hash cost, is what stops guessing, so there's no bcrypt latency.
- **Rotating `APP_KEY` invalidates pending codes.** That's acceptable for codes that live minutes.
- **Codes aren't unique**, and don't need to be: a code is always looked up by subject and purpose.
- **Addresses, IPs and user agents are stored only as hashes.**
- **Trusted proxies** must be configured for IP limits and bindings to work behind a proxy.
- **Bind `destination`** for any purpose that uses `sendTo()`.

## Testing the package

```bash
composer test
composer analyse
composer format
```

The suite runs core behaviour against the database store, the array cache store and Redis. Redis and the multi-process concurrency tests (MySQL, PostgreSQL, Redis) only run when these are set, as they are in CI:

```bash
OTPKIT_TEST_REDIS_HOST=127.0.0.1   # uses Redis database 15 (OTPKIT_TEST_REDIS_DB)
OTPKIT_TEST_MYSQL_HOST=127.0.0.1
OTPKIT_TEST_PGSQL_HOST=127.0.0.1
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## License

The MIT License (MIT). See [License File](LICENSE.md) for more information.
