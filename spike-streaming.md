# Impruve Booking: Streaming Spike (M7)

> **Date:** 2026-10-26 · **Package:** `laravel/ai` **v1.3.0** (installed) · **Model:** `claude-haiku-5-5`
> **Answers:** PRD §9.3 items 2 and 3, R6, RK1. Referenced from [`design.md`](./design.md) §4.5.
> The spike code (a throwaway agent, tool and artisan command) was deleted after the runs and is not in git history.

## Decision

**Proposal cards are sent mid-stream.** The R6 fallback (cards at the end of the stream) is **not needed**.

The stream yields a typed `ToolResult` event the moment a tool finishes, carrying the tool name and its result. In the live run it arrived about **1.5 s before** the model's closing sentence, so the card can be shown while the bot is still typing. The M8 controller sends the `proposal` SSE event when it sees the `ToolResult` of a `Propose*` tool.

---

## 7.2 Spike evidence

The throwaway agent had one tool (`ProposeSomething`, standing in for `ProposeBooking`). It was prompted once to book an item, and every stream event was printed with its time since the request started.

### Live run (real Haiku, one authorised call)

| # | Time | Event | Notes |
|---|---|---|---|
| 1–7 | +1581 ms | `StreamStart`, `TextStart`, `TextDelta`… | The model writes a sentence first: "Setting up a booking proposal…" |
| 8 | +1941 ms | `ToolCall` | Tool name and parsed arguments |
| 9 | +1943 ms | `TextEnd` | |
| — | +1944 ms | *tool `handle()` runs* | Synchronously, inside our PHP request |
| 10 | +1944 ms | **`ToolResult`** | Full JSON returned by our tool. **Send the card here.** |
| 11–20 | +3411 ms | `StreamStart`, `TextDelta`…, `TextEnd` | Second model step: "Please review the booking card…" |
| 21 | +3533 ms | `StreamEnd` | `reason: stop`, usage 1,203 input / 177 output tokens |

- The conversation was stored for `App\Models\User` #1 with one `user` and one `assistant` message; the tool steps are kept inside the assistant message.
- The title was the truncated prompt, because the spike set `ai.conversations.generate_title=false` (see API check §8).

### Fake run (`SpikeAgent::fake([new ToolCall(...), 'text'])`)

The same event order as live: `StreamStart` → `ToolCall` → **our real tool ran** → `ToolResult` → `StreamStart` → text deltas → `TextEnd` → `StreamEnd`. The conversation and messages were stored exactly as in the live run.

**Consequence for M8 tests:** the SSE controller, the real `Propose*` tools and the proposal flow can all be tested end to end with fakes, with no API calls (R12, §10).

---

## 7.1 API check against the design

| # | Design assumption | Installed v1.3.0 | Impact for M8 |
|---|---|---|---|
| 1 | Agent = `Conversational` + `RemembersConversations` + `HasTools` | ✅ `implements Agent, Conversational, HasTools` with `use Promptable, RemembersConversations` | As designed |
| 2 | Anthropic, model from `AI_CHAT_MODEL` | ⚠ The package's default provider is **`openai`** (`config/ai.php` `default`). The agent's `provider()` and `model()` **methods** override the attributes (`Promptable.php` `getProvidersAndModels`) | `BookingAssistant::provider()` returns `'anthropic'`; `model()` reads `AI_CHAT_MODEL` via config |
| 3 | `#[MaxSteps(8)]`, configurable (R10) | ✅ A `maxSteps()` method overrides the attribute (`TextGenerationOptions::resolve`) | `maxSteps()` reads `booking.chat.max_steps` |
| 4 | Tools are narrow classes; no `user_id` argument | ✅ `Tool`: `description()`, `handle(Request)`, `schema(JsonSchema)`. `tools()` returns **instances**, so the acting user is injected through the constructor. The tool's name is its class basename (`ToolNameResolver`). `Request::validate()` exists | Tools get the `User` (and the per-request proposal collector) in their constructor |
| 5 | `POST /chat/conversations` returns an ID before the first message | ⚠ There's no "create empty conversation" call. `forUser()` creates the row **lazily on the first turn**. `continue($id, as:)` assumes the row already exists and never creates it | `chat.start` creates the row itself via `ConversationStore::storeConversation($type, $userId, $title)`, which returns the ID. Later messages `continue()` it |
| 6 | Ownership checked in our code (FR-CHAT-14) | ✅ The SDK **does not** check. The docs warn that `continue()` doesn't verify the participant. The store offers `conversationBelongsTo($id, $type, $userId)` but never calls it itself | Call `conversationBelongsTo()` before every `continue()`, and return 403/404 otherwise |
| 7 | Stream wrapped in our own SSE envelope (`text`, `proposal`, `error`, `done`) | ✅ `stream()` returns `StreamableAgentResponse`, an iterable of typed events: `TextDelta`, `ToolCall`, `ToolResult`, `StreamStart`, `StreamEnd`, `Error`, and others. The default `toResponse()` uses the SDK's own format; Vercel and AG-UI protocols are also built in | The controller iterates events and writes the design's envelope. The built-in protocols aren't used |
| 8 | (not in design) | ⚠ By default **every new conversation triggers an extra model call to generate a title** (`RememberConversation::generateTitle`). We start a conversation on every page load | Set `ai.conversations.generate_title=false`. Verified by the spike |
| 9 | Tests use `Agent::fake()` + `preventStrayPrompts()` | ✅ Both exist, and the fake accepts `Laravel\Ai\Responses\Data\ToolCall` responses that execute the real tool (see the fake run) | End-to-end SSE tests through fakes |
| 10 | Each model step can emit events | ✅ Every step sends its own `StreamStart`, but there's **one `StreamEnd`** for the whole run | Send `done` after `StreamEnd` or the end of the loop, never at a step boundary |
| 11 | "Assistant unavailable" (FR-CHAT-15, R5) | ✅ Provider errors are **thrown while iterating** (e.g. `Illuminate\Http\Client\RequestException` on the 401 we saw). There's no failover, because we configure one provider | Wrap the loop in `try/catch` and send `error` with `fallback_url` |
| 12 | Stop button aborts the request | ℹ *Inferred from source, not run:* the turn is only stored by the `then()` callbacks after the stream is **fully consumed** (`StreamableAgentResponse::getIterator`). A client abort that stops PHP mid-stream would leave that turn unstored | Decide in M8: accept it (the user stopped it), or let PHP finish after the client disconnects (`ignore_user_abort(true)`) |
| 13 | Confirmation built ourselves (`booking_proposals`) | ℹ v1.3.0 now has **native tool approvals** (`Approvals`, `ToolApprovalRequest`). The research marked this as not available in v0.10 | **Not used.** The PRD keeps confirmation outside the model (FR-CHAT-6): Confirm calls an ordinary endpoint |

## How M8 builds on this

1. The `Propose*` tools run the dry-run rules check, save a `booking_proposals` row, add its card payload to the request's `ProposalCollector`, and return short JSON to the model.
2. The controller loop:
   - `TextDelta` → `event: text`
   - `ToolResult` from a `Propose*` tool → drain the collector → `event: proposal`
   - `StreamEnd` or loop end → `event: done`
   - any `Throwable` → `event: error`
3. `chat.start` creates the conversation row itself (API check §5). Every message checks ownership with `conversationBelongsTo()` (§6).
4. Config: `ai.conversations.generate_title=false`, provider `anthropic`, model from `AI_CHAT_MODEL`, and `maxSteps()` from `booking.chat.max_steps`.

**Cost observed:** about 1.2k input and 0.2k output tokens for a two-step turn with a tiny prompt. The real system prompt (live inventory plus a 14-day date reference) will raise the input, but it stays small for a Haiku-class model.
