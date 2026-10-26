<?php

namespace App\Domain\Booking;

/**
 * The outcome of a dry-run check: ok, or every rule that failed.
 */
final readonly class CheckResult
{
    /**
     * @param  list<BookingRuleViolation>  $violations
     */
    public function __construct(public array $violations = []) {}

    public static function pass(): self
    {
        return new self;
    }

    public static function fail(BookingRuleViolation $violation): self
    {
        return new self([$violation]);
    }

    public function ok(): bool
    {
        return $this->violations === [];
    }

    /**
     * @return list<RuleCode>
     */
    public function codes(): array
    {
        return array_map(fn (BookingRuleViolation $violation) => $violation->code, $this->violations);
    }

    public function has(RuleCode $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    public function first(RuleCode $code): ?BookingRuleViolation
    {
        foreach ($this->violations as $violation) {
            if ($violation->code === $code) {
                return $violation;
            }
        }

        return null;
    }
}
