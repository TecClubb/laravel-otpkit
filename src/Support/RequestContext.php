<?php

namespace TecClub\OtpKit\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * The current HTTP request's IP and user agent; null outside HTTP (queues, console).
 *
 * @internal
 */
class RequestContext
{
    public function __construct(private readonly Application $app) {}

    public function ip(): ?string
    {
        return $this->request()?->ip();
    }

    public function userAgent(): ?string
    {
        return $this->request()?->userAgent();
    }

    private function request(): ?Request
    {
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return null;
        }

        return $this->app->bound('request') ? $this->app->make('request') : null;
    }
}
