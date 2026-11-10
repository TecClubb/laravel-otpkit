# Changelog

All notable changes to `tecclub/otpkit` will be documented in this file.

## v1.0.0 - 2026-11-10

First release.

### Core

- One-time codes for any Eloquent model (`HasOneTimePasswords`) or any address (`Otp::to()`), scoped by purpose, with one active code per subject and purpose.
- `send()`, `generate()`, `verify()`, `check()`, `pending()`, `invalidate()`, `sendTo()` and per-call overrides on one fluent builder.
- Policies resolved from package defaults, config defaults, purpose config and call overrides, snapshotted onto each code; config validated at boot.
- Numeric and alphanumeric codes (4 to 12 characters) from `random_int()`, custom formats through `CodeGenerator`.
- Codes stored only as HMAC-SHA256 keyed by `APP_KEY`, subject and purpose; addresses, IPs and user agents only as hashes.
- Eight verification statuses with collapsed, translated messages (`messages.verbose` for one per status).
- Database store (ULIDs, row locks) and cache store (`Cache::lock()`, lock support checked at boot); custom stores through `OtpStore` and `OtpKit::extend()`.
- Queued mail notification with a Markdown template, dispatched after commit, dropping codes that were replaced, used or expired before the worker ran.
- `otpkit:install`, `otpkit:prune` (scheduled daily automatically), publishable config, migration, translations and view.

### Hardening

- Five rate limits: attempts per code, escalating resend cooldown (30, 60, 120, 300 seconds), sends per subject per hour, sends per IP per hour, verifies per IP per minute.
- Destination, IP and user agent bindings, off by default, enabled globally or per purpose.
- `ValidOtp` validation rule: checks by default, `->consume()` to verify, works in Form Requests and Livewire.
- Six events without the plaintext code: `OtpGenerated`, `OtpSent`, `OtpVerified`, `OtpFailed`, `OtpLocked`, `OtpRateLimited`.
- `Otp::fake()` with `assertSent`, `assertNotSent`, `assertVerified`, `assertNotVerified` and `lastCode`.

### Login

- Opt-in login by code: `OtpLogin::send()`, `attempt()` (session guards) and `attemptForToken()` (API tokens).
- `OtpKit::webLoginRoutes()` and `OtpKit::apiLoginRoutes()`; never registered automatically.
- Send endpoint that never reveals whether an account exists.
- `TokenIssuer` contract with a Sanctum default; `OtpKit::authorizeLoginUsing()` to block accounts.

### Support

- PHP 8.2+ and Laravel 12 and 13.
