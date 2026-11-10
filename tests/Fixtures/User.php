<?php

namespace TecClub\OtpKit\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use TecClub\OtpKit\Concerns\HasOneTimePasswords;

class User extends Authenticatable
{
    use HasApiTokens, HasOneTimePasswords, Notifiable;

    protected $guarded = [];

    public static function make(string $email = 'jane@example.com'): self
    {
        return self::create(['email' => $email]);
    }
}
