<?php

namespace App\Domain\Booking;

/**
 * Why a booking change was refused. The form shows these as field errors
 * and the assistant explains them in plain language.
 */
enum RuleCode: string
{
    case OwnerOnly = 'owner_only';
    case ImmutablePhase = 'immutable_phase';
    case InvalidRange = 'invalid_range';
    case TooLong = 'too_long';
    case StartsInPast = 'starts_in_past';
    case NoItems = 'no_items';
    case ItemUnbookable = 'item_unbookable';
    case ItemConflict = 'item_conflict';
}
