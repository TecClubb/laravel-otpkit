<?php

it('registers the otpkit config', function () {
    expect(config('otpkit.store'))->toBe('database');
});
