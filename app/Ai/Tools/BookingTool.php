<?php

namespace App\Ai\Tools;

use App\Domain\Booking\SlotClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Base for the assistant's tools: validates arguments, returns short JSON,
 * and logs every call to the `ai` channel (FR-CHAT-17). Tools never take a
 * user id; the acting user comes from the ToolContext (FR-CHAT-7).
 */
abstract class BookingTool implements Tool
{
    public function __construct(protected readonly ToolContext $context) {}

    /**
     * The tool's work. Return a JSON-serialisable result.
     *
     * @return array<string, mixed>
     */
    abstract protected function run(Request $request): array;

    public function handle(Request $request): Stringable|string
    {
        try {
            $result = $this->run($request);
        } catch (ValidationException $exception) {
            $result = ['ok' => false, 'error' => 'invalid_arguments', 'messages' => $exception->errors()];
        } catch (Throwable $exception) {
            $this->log($request, ['ok' => false, 'error' => 'exception', 'message' => $exception->getMessage()]);

            throw $exception;
        }

        $this->log($request, $result);

        return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function log(Request $request, array $result): void
    {
        Log::channel('ai')->info('tool_call', [
            'tool' => class_basename($this),
            'arguments' => $request->all(),
            'ok' => $result['ok'] ?? true,
            'error' => $result['error'] ?? null,
            'result' => $result,
            'user_id' => $this->context->user->id,
            'conversation_id' => $this->context->conversationId,
            'at' => now()->toIso8601String(),
        ]);
    }

    /**
     * A tool time argument: local "YYYY-MM-DDTHH:mm" in the company timezone.
     */
    protected function time(Request $request, string $key): CarbonImmutable
    {
        return SlotClock::parse((string) $request[$key]);
    }

    /**
     * Times for the model: local wall-clock plus a readable label.
     *
     * @return array{local: string, display: string}
     */
    protected static function when(CarbonInterface $time): array
    {
        $local = SlotClock::local($time);

        return [
            'local' => $local->format('Y-m-d\TH:i'),
            'display' => $local->format('D M j, g:i A'),
        ];
    }

    /**
     * Validation rules shared by tools for a local time argument.
     *
     * @return list<string>
     */
    protected static function timeRules(): array
    {
        return ['required', 'date_format:Y-m-d\TH:i'];
    }
}
