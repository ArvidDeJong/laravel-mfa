<?php

namespace Darvis\Mfa\Events;

use Darvis\Mfa\Models\Challenge;
use Illuminate\Contracts\Auth\Authenticatable;

class ChallengeSent
{
    public function __construct(
        public readonly Challenge $challenge,
        public readonly Authenticatable $user,
    ) {}
}
