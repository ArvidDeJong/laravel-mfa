<?php

namespace Darvis\Mfa\Concerns;

use Darvis\Mfa\Models\Factor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Optional helper for an Eloquent user model. Add `implements MfaUser` next
 * to it and override a method when an attribute has another name.
 *
 * @phpstan-require-extends Model
 */
trait HasMfa
{
    /**
     * @return MorphMany<Factor, $this>
     */
    public function mfaFactors(): MorphMany
    {
        return $this->morphMany(Factor::class, 'authenticatable');
    }

    public function mfaEmail(): ?string
    {
        $email = $this->getAttribute('email');

        return is_string($email) ? $email : null;
    }

    public function mfaPhone(): ?string
    {
        $phone = $this->getAttribute('phone');

        return is_string($phone) ? $phone : null;
    }

    public function mfaActive(): bool
    {
        $flag = $this->getAttribute('is_active');

        return $flag === null || (bool) $flag;
    }
}
