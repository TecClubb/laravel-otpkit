<?php

namespace TecClub\OtpKit\Enums;

enum SendStatus: string
{
    case Sent = 'sent';
    case RateLimited = 'rate_limited';
}
