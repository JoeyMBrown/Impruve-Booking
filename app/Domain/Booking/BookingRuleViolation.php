<?php

namespace App\Domain\Booking;

/**
 * One broken rule: its code, a plain-language message, the form field it
 * belongs to, and details (e.g. the conflicting bookings).
 */
final readonly class BookingRuleViolation
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public RuleCode $code,
        public string $message,
        public string $field,
        public array $details = [],
    ) {}

    /**
     * @return array{code: string, message: string, field: string, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'message' => $this->message,
            'field' => $this->field,
            'details' => $this->details,
        ];
    }
}
