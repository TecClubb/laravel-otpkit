<?php

namespace TecClub\OtpKit\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use TecClub\OtpKit\Concerns\HasOneTimePasswords;

/**
 * A second login model: identified by phone, codes mailed to its email, no API tokens.
 */
class Admin extends Authenticatable
{
    use HasOneTimePasswords, Notifiable;

    protected $guarded = [];
}
