<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\BookingDraft;
use App\Domain\Booking\BookingService;
use App\Enums\BookingSource;
use App\Models\Booking;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Two confirms race for the same item and time in separate transactions.
 * The second one passes the service's own check (the first isn't committed
 * yet), so only the exclusion constraint can stop it: exactly one wins.
 *
 * Uses truncation rather than RefreshDatabase because the other process
 * must see committed rows.
 */
class ConcurrentConfirmTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * This test commits real rows, so remove them for the tests that follow
     * (they use RefreshDatabase and would otherwise see them).
     */
    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::statement('TRUNCATE booking_items, booking_proposals, bookings, equipment, users RESTART IDENTITY CASCADE');

        parent::tearDown();
    }

    public function test_exactly_one_of_two_concurrent_confirms_succeeds()
    {
        $ana = User::factory()->create();
        $sam = User::factory()->create();
        $camera = Equipment::factory()->create();
        $startsAt = now()->addDay()->startOfHour();
        $endsAt = $startsAt->addHours(3);

        // First confirm: saved but not yet committed.
        DB::beginTransaction();
        app(BookingService::class)->create(
            new BookingDraft([$camera->id], $startsAt, $endsAt, 'First confirm'),
            $ana,
            BookingSource::Chat,
        );

        // Second confirm, in another process: its insert waits on our lock.
        $second = new Process(
            [PHP_BINARY, base_path('tests/Support/concurrent-create.php'), (string) $sam->id, (string) $camera->id, $startsAt->toIso8601String(), $endsAt->toIso8601String()],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => config('database.default'),
                'DB_DATABASE' => config('database.connections.pgsql.database'),
            ],
        );
        $second->setTimeout(30);
        $second->start();

        $this->waitUntilAnotherSessionIsBlocked($second);

        DB::commit();
        $second->wait();

        $this->assertSame('conflict:1', trim($second->getOutput()), $second->getErrorOutput());
        $this->assertSame(1, Booking::count());
        $this->assertSame($ana->id, Booking::sole()->user_id);
    }

    private function waitUntilAnotherSessionIsBlocked(Process $process): void
    {
        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            $blocked = DB::selectOne(
                "select count(*) as waiting from pg_stat_activity
                 where datname = current_database() and wait_event_type = 'Lock' and pid <> pg_backend_pid()",
            );

            if ($blocked->waiting > 0) {
                return;
            }

            if (! $process->isRunning()) {
                DB::rollBack();
                $this->fail('The second confirm finished without waiting: '.$process->getOutput().$process->getErrorOutput());
            }

            usleep(50_000);
        }

        DB::rollBack();
        $process->stop();
        $this->fail('The second confirm never blocked on the first one.');
    }
}
