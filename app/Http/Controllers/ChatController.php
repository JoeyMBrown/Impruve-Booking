<?php

namespace App\Http\Controllers;

use App\Ai\Agents\BookingAssistant;
use App\Ai\ChatSuggestions;
use App\Ai\ProposalCollector;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Streaming\Events\Error as StreamError;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolResult;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The chat widget's endpoints. Messages stream back as SSE in our own
 * envelope: `text`, `proposal` (mid-stream, when a propose tool finishes),
 * `error` and `done` (D§4.5, spike-streaming.md).
 */
class ChatController extends Controller
{
    public function __construct(private readonly ConversationStore $conversations) {}

    /**
     * Start a conversation (when the widget first opens on a page load).
     * The SDK only creates conversations lazily, so the row is created here.
     */
    public function start(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        $conversationId = $this->conversations->storeConversation(
            Conversation::participantType($user),
            $user->id,
            'Booking chat',
        );

        return response()->json([
            'conversation_id' => $conversationId,
            'suggestions' => ChatSuggestions::make(),
        ], 201);
    }

    /**
     * Send a message and stream the reply. Only the conversation's owner may
     * continue it; the SDK doesn't check this (FR-CHAT-14).
     */
    public function message(Request $request, string $conversation): StreamedResponse|JsonResponse
    {
        $user = $this->actor($request);
        $validated = $request->validate(['message' => ['required', 'string', 'max:2000']]);

        if (! $this->ownsConversation($conversation, $user)) {
            return response()->json(['message' => 'This conversation does not belong to you.'], 403);
        }

        return response()->stream(function () use ($user, $conversation, $validated) {
            $proposals = new ProposalCollector;

            try {
                $stream = (new BookingAssistant($user, $proposals))
                    ->continue($conversation, as: $user)
                    ->stream($validated['message']);

                foreach ($stream as $event) {
                    if ($event instanceof TextDelta) {
                        $this->send('text', ['delta' => $event->delta]);
                    } elseif ($event instanceof ToolResult) {
                        // A propose tool just finished: show its card now.
                        foreach ($proposals->drain() as $card) {
                            $this->send('proposal', $card);
                        }
                    } elseif ($event instanceof StreamError) {
                        throw new RuntimeException($event->message);
                    }

                    // Stop: the client went away, so drop the rest of this turn.
                    if (connection_aborted() === 1) {
                        return;
                    }
                }

                foreach ($proposals->drain() as $card) {
                    $this->send('proposal', $card);
                }

                $this->send('done', (object) []);
            } catch (Throwable $exception) {
                report($exception);

                $this->send('error', [
                    'message' => 'The assistant is unavailable right now.',
                    'fallback_url' => route('bookings.create'),
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>|object  $data
     */
    private function send(string $event, array|object $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        // Push each event out now. Tests capture the stream in an output
        // buffer, which flushing would empty past the capture.
        if (! app()->runningUnitTests()) {
            if (ob_get_level() > 0) {
                ob_flush();
            }

            flush();
        }
    }

    private function ownsConversation(string $conversationId, User $user): bool
    {
        return $this->conversations instanceof VerifiesConversationOwnership
            && $this->conversations->conversationBelongsTo($conversationId, Conversation::participantType($user), $user->id);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
