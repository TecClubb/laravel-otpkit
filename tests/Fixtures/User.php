<?php

namespace TecClub\OtpKit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use TecClub\OtpKit\Concerns\HasOneTimePasswords;

class User extends Model
{
    use HasOneTimePasswords, Notifiable;

    protected $guarded = [];

    public static function make(string $email = 'jane@example.com'): self
    {
        return self::create(['email' => $email]);
    }
}
