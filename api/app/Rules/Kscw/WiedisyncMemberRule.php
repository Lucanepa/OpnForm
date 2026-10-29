<?php

namespace App\Rules\Kscw;

use App\Service\Kscw\WiedisyncMemberLookup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

/**
 * KSCW fork: the email must belong to exactly one current club member (see
 * WiedisyncMemberLookup). Wiedisync down → the submission is refused with a "try again"
 * message rather than accepted unchecked; the option not configured at all → accepted.
 */
class WiedisyncMemberRule implements ValidationRule
{
    public function __construct(private array $property, private array $data)
    {
    }

    /** The rule for an email property, or nothing when its Wiedisync check is off. */
    public static function for(array $property, array $data): array
    {
        return empty($property['wiedisync_lookup']) ? [] : [new self($property, $data)];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            return; // required-ness is the field's own rule
        }
        if (!WiedisyncMemberLookup::isConfigured()) {
            Log::warning('Wiedisync check is on for a form field but WIEDISYNC_API_URL / WIEDISYNC_SERVICE_TOKEN are unset');

            return;
        }

        $result = app(WiedisyncMemberLookup::class)
            ->lookup($value, WiedisyncMemberLookup::nameValue($this->property, $this->data));
        $message = WiedisyncMemberLookup::message($result['status'], $this->property);
        if ($message !== null) {
            $fail($message);
        }
    }
}
