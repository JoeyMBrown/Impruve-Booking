<?php

/*
 * Helper for ConcurrentConfirmTest: runs BookingService::create in its own
 * process (and so its own database connection and transaction) and prints
 * "created", "conflict" or "rule:<codes>".
 *
 * Usage: php concurrent-create.php <user_id> <equipment_id> <starts_at> <ends_at>
 */

use App\Domain\Booking\BookingConflictException;
use App\Domain\Booking\BookingDraft;
use App\Domain\Booking\BookingRuleException;
use App\Domain\Booking\BookingRuleViolation;
use App\Domain\Booking\BookingService;
use App\Enums\BookingSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $equipmentId, $startsAt, $endsAt] = $argv;

$draft = new BookingDraft([(int) $equipmentId], CarbonImmutable::parse($startsAt), CarbonImmutable::parse($endsAt), 'Racing confirm');

try {
    app(BookingService::class)->create($draft, User::findOrFail((int) $userId), BookingSource::Chat);
    echo 'created';
} catch (BookingConflictException $exception) {
    echo 'conflict:'.count($exception->conflicts);
} catch (BookingRuleException $exception) {
    echo 'rule:'.implode(',', array_map(fn (BookingRuleViolation $violation) => $violation->code->value, $exception->result->violations));
}
