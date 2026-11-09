<?php

namespace TecClub\OtpKit\Contracts;

use Closure;
use TecClub\OtpKit\Stores\OtpRecord;

interface OtpStore
{
    /**
     * Store a new code, replacing any active code for the same subject and purpose.
     */
    public function put(OtpRecord $record): void;

    /**
     * The latest code for the subject and purpose, consumed or not.
     */
    public function find(string $subjectKey, string $purpose): ?OtpRecord;

    /**
     * Run the callback while holding an exclusive lock on the subject and purpose.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function atomically(string $subjectKey, string $purpose, Closure $callback): mixed;

    /**
     * Add one failed attempt and return the new attempt count.
     */
    public function incrementAttempts(OtpRecord $record): int;

    /**
     * Mark the code used. Returns true only for the first caller.
     */
    public function consume(OtpRecord $record): bool;

    /**
     * Whether the code is still the active, usable one for its subject and purpose.
     */
    public function isCurrent(string $id): bool;

    /**
     * Remove the active code for the subject and purpose.
     */
    public function forget(string $subjectKey, string $purpose): void;
}
