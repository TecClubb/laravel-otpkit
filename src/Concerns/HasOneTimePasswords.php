<?php

namespace TecClub\OtpKit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use TecClub\OtpKit\Facades\Otp;
use TecClub\OtpKit\Models\OneTimePassword;
use TecClub\OtpKit\PendingOtp;
use TecClub\OtpKit\PolicyResolver;

/**
 * @mixin Model
 */
trait HasOneTimePasswords
{
    public function otp(string $purpose = PolicyResolver::DEFAULT_PURPOSE): PendingOtp
    {
        return Otp::for($this)->purpose($purpose);
    }

    /**
     * Codes stored by the database driver for this model.
     *
     * @return MorphMany<OneTimePassword, $this>
     */
    public function oneTimePasswords(): MorphMany
    {
        return $this->morphMany(OneTimePassword::class, 'otpable');
    }
}
