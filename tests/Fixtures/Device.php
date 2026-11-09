<?php

namespace TecClub\OtpKit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use TecClub\OtpKit\Concerns\HasOneTimePasswords;

/**
 * A model without Notifiable.
 */
class Device extends Model
{
    use HasOneTimePasswords;

    protected $guarded = [];
}
