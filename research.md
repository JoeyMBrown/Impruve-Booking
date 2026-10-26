# Research: AI Chatbot for Camera Equipment Booking (Laravel AI SDK)

> **Context:** A small internal app for 14 people to reserve about 10 camera assets, using natural-language booking through a chatbot.
> **Method:** Deep-research workflow run on 2026-10-26: 5 search angles, 21 sources fetched, 90 claims extracted, 25 checked by 3 separate reviewers each (23 confirmed, 2 refuted).
> **Confidence key:** **High** means confirmed 3–0 from primary sources. **Medium** means confirmed, but resting on one study or with caveats.

---

## TL;DR

- **Laravel AI SDK (`laravel/ai`) fits this app well.** It is Laravel's official package. It gives you agent classes, tool calling, structured output, conversation history stored in the database, streaming over server-sent events (SSE), queued runs, provider failover, and test fakes.
- **Suggested design:** *read* tools (checking availability) run freely. *Write* tools (creating or cancelling a booking) must be **confirmed by the user** first. **Every tool call is authorized in your own code** with Laravel policies or gates. Never let the model decide what a user may do (OWASP LLM06:2025).
- **Write the booking workflow into the agent's instructions** (identify the equipment, then the dates, then check availability, then confirm, then book), and **keep the toolset small**.
- **Gaps:** the research did not produce verified findings on natural-language date parsing and timezones, Prism, other PHP SDKs, Laravel MCP, or cost. See [Gaps & next research](#gaps--next-research).

---

## 1. Laravel AI SDK: core features

### 1.1 What it is and which providers it supports (High)
- It is the official AI package for Laravel. One API covers agents, images, audio, transcription, embeddings, reranking, vector stores and files.
- At launch it supported text generation from **OpenAI, Anthropic, Gemini, Groq and xAI**. The current docs also list Azure, DeepSeek, Mistral and Ollama.
- Defaults live in `config/ai.php`, and you can switch provider on any request.
- Not every feature works with every provider; images, for example, work only with OpenAI, Gemini and xAI.
- It is built on top of **Prism**, so you don't need to use Prism directly.
- It was announced as **beta in Feb 2026**, and API details may change. Check them against the 13.x docs before building.

### 1.2 Agents (High)
- `php artisan make:agent` creates a class that holds the **system instructions, tools, output schema and conversation context**. You can reuse it in controllers, queued jobs and event listeners.
- Laravel's own comparison of its AI SDK, Boost and MCP packages recommends the **AI SDK for an in-app chat interface**.

### 1.3 Tools / function calling (High)
- A tool is a PHP class implementing `Laravel\Ai\Contracts\Tool`, with `description()`, `schema(JsonSchema)` and `handle(Request)`. The agent's `tools()` method returns them (the agent implements `HasTools`), and `php artisan make:tool` creates new ones.
- The **model decides when to call a tool, and the SDK runs it and sends the result back automatically**, up to the limit set by `#[MaxSteps(10)]`. Tools can also have middleware that runs on each step, and there is a `RepairToolCalls` attribute.
- Tools this app could use: `CheckAvailability`, `ListEquipment`, `CreateReservation` (proposal only, see §2), `CancelReservation`, `MyReservations`.

### 1.4 Structured output (High)
- The agent implements `HasStructuredOutput` and defines `schema(JsonSchema $schema): array`. You can create one with `make:agent --structured`.
- The response is a `StructuredAgentResponse` that you read like an array, for example `$response['equipment_id']`. It can pull `equipment`, `start` and `end` out of what the user typed.

### 1.5 Conversation memory (High)
- Implement `Conversational` and add the `RemembersConversations` trait. History is stored in the `agent_conversations` and `agent_conversation_messages` tables, which come from migrations you publish with `vendor:publish`.
- Start a conversation with `->forUser($user)->prompt(...)` and resume it with `->continue($conversationId, as: $user)`. Since v0.10, `forParticipant()` lets any Eloquent model own a conversation.
- ⚠️ **`continue()` / `continueOrStart()` do not check that the participant owns the conversation.** Your app must check that the conversation ID belongs to the logged-in user.
- If you define your own `messages()` method, it overrides the trait.

### 1.6 Streaming, queues, failover (High)
- `->stream()` sends responses to the browser over **SSE**, and the docs say both **Livewire and Inertia have hooks that can consume the stream**. Responses can also be broadcast through Reverb/Echo.
- `->queue()` runs the generation as a queue job with completion and failure callbacks.
- If you pass an **array of providers**, the SDK fails over automatically when one is rate-limited or down.
- ❌ *Refuted (0–3):* the claim that it uses the Vercel AI data protocol and needs no custom wiring. Plan to write some frontend glue yourself.

### 1.7 Testing (High)
- `MyAgent::fake()` returns a fixed response. You can also pass an array of queued responses or a closure that receives `AgentPrompt`. For structured-output agents, the fake generates data that matches the schema.
- Assertions: `assertPrompted`, `assertNotPrompted`, `assertNeverPrompted`, `assertQueued`. `fake()->preventStrayPrompts()` throws if a prompt has no fake.
- The docs also mention `AgentResponse::fakeWithPendingApprovals`, which hints at some approval feature. That was **not verified** (see §2).

---

## 2. Booking patterns: confirm before writing (High)

- **Ask for confirmation only on actions with real consequences**, such as creating, changing or cancelling a booking. Availability checks are read-only and can run freely.
- **Show the user every argument** of the pending action ("Book *Sony FX3 #2* from **Tue Oct 28 09:00** to **Wed Oct 29 17:00**?").
- Other frameworks use the same pattern:
  - **Haystack HITL:** pauses tool calls before they run, and the user can confirm, reject or edit each one.
  - **Cloudflare Agents:** `requiresApproval` records the pending method and its arguments.
- ❌ *Refuted (0–3):* "laravel/ai v0.10 has native tool approval through `Decisions` and an `approval_state` column." **Plan to build the confirmation step yourself**, for example:
  1. `CreateReservation` tool → checks the policy, checks for overlaps → saves a **pending proposal** (or returns a signed token) → the model shows it to the user.
  2. The user clicks **Confirm** in the UI. This is a normal controller or Livewire action, not something the model triggers. That action re-checks availability inside a DB transaction or lock, then saves the booking.
- *Unverified lead (Nylas blog, not voted on):* check the slot again right before writing, because availability can change between suggesting a slot and booking it. Offer alternatives when it is taken.

### Prompt design (Medium)
- **FlowBench** (EMNLP Findings 2024) found that writing the workflow into the agent's context, as text, code or a flowchart, helped agents plan across multiple turns in booking scenarios. GPT-4o's success rate went from 35.1 to 41.7–43.2. Without that guidance, agents made more errors in step order. Giving them more tools at once raised tool-call errors.
- Caveats: absolute success stayed around 40%, the models tested are from 2024, and an LLM did the judging.

---

## 3. Security & guardrails (High)

- **OWASP LLM06:2025 Excessive Agency:** an LLM-driven system can take damaging actions because of ambiguous or manipulated output, including prompt injection. The three root causes are **too much functionality, too many permissions, and too much autonomy**.
- OWASP says to *"implement authorization in downstream systems rather than relying on an LLM to decide if an action is allowed."*
- What that means for this app (our own reading, not OWASP's wording):
  - Every tool's `handle()` calls `Gate::authorize()` or a policy **against the logged-in user**. Never trust a `user_id` that the model passes in.
  - Keep each tool narrow. Don't build a generic "run query" or "update any record" tool.
  - Writes go through the confirmation step in §2.
  - Check that the user owns the conversation before calling `continue()` (§1.5).

---

## 4. UI options

### Livewire `wire:stream` (High)
- `wire:stream` together with `$this->stream(to:, content:, replace:)` sends partial responses to the browser before the request finishes. Appending is the default, and `replace: true` swaps out the existing content. The docs include a ChatGPT-style example.
- ⚠️ **It does not work under Laravel Octane.** The HTTP request also stays open for the whole stream.

### Inertia
- The SDK docs say Inertia has hooks for consuming streams. The research did **not verify which hooks**.

---

## Gaps & next research

These areas produced **no verified findings**. Look into them before building:

| Topic | Open question | Where to start (not verified) |
|---|---|---|
| Natural-language dates and timezones | Should the LLM output ISO-8601 through structured output, given the current datetime and company timezone in its instructions? Or should PHP parse the dates with Carbon? | One vendor thread (Vapi) suggests working out relative dates *outside* the model. Put `now()` and the timezone in the agent's instructions, and validate in PHP. |
| Conflict detection | Overlapping-interval query (`start < :end AND end > :start`) plus locking / transaction | Laravel DB docs (`lockForUpdate`) and your database's docs on exclusion constraints |
| Native approval in `laravel/ai` | Does a supported tool-approval mechanism exist (`fakeWithPendingApprovals`)? | laravel.com/docs/13.x/ai-sdk, GitHub releases for `laravel/ai` |
| Alternatives | How do Prism PHP, `openai-php/client`, the Anthropic PHP SDK and Laravel MCP compare? Is an MCP server worth it for 10 assets? | prismphp.com, Laravel MCP docs, the "AI SDK, Boost, or MCP" blog post below |
| Cost | Monthly token cost for about 14 users on a small model (Haiku, GPT mini, Gemini Flash) | Provider pricing pages. Even a rough estimate would help. |

---

## Reading list

### Primary: Laravel
- [Laravel AI SDK docs (13.x)](https://laravel.com/docs/13.x/ai-sdk): current reference
- [Laravel AI SDK docs (12.x)](https://laravel.com/docs/12.x/ai-sdk): includes the testing section
- [Introducing the Laravel AI SDK](https://laravel.com/blog/introducing-the-laravel-ai-sdk): launch post
- [Building AI Agents with Laravel: No Python Required](https://laravel.com/blog/building-ai-agents-with-laravel-no-python-required)
- [Laravel AI SDK, Boost, or MCP: Which Tool Do You Need?](https://laravel.com/blog/laravel-ai-sdk-boost-or-mcp-which-tool-do-you-need)
- [Livewire `wire:stream`](https://livewire.laravel.com/docs/wire-stream)
- [laravel/ai repo docs (Algolia DocSearch index)](https://docsearch.algolia.com/mcp/docs/repo/laravel/ai): v0.9–0.10 upgrade notes
- [laravel/ai agent skill (skills.sh)](https://skills.sh/laravel/ai/ai-sdk-development)

### Secondary / tutorials
- [Laravel News: official AI SDK announcement](https://laravel-news.com/laravel-announces-official-ai-sdk-for-building-ai-powered-apps)
- [Laravel News: Real-time streaming chat UI with Livewire](https://laravel-news.com/index.php/ship-ai-with-laravel-real-time-streaming-chat-ui-with-livewire)
- [skills.cat: Developing with the AI SDK](https://skills.cat/skills/laravel/ai/developing-with-ai-sdk)
- [Vizra: Testing AI agents in Laravel beyond fakes](https://vizra.ai/blog/how-to-test-ai-agents-in-laravel-beyond-fakes)

### Booking / agent patterns
- [Cloudflare Agents: Human-in-the-loop](https://developers.cloudflare.com/agents/concepts/human-in-the-loop/)
- [Haystack: Human-in-the-loop](https://docs.haystack.deepset.ai/docs/2.27/human-in-the-loop)
- [Nylas: Calendar conflict resolution agent](https://cli.nylas.com/ai-answers/calendar-conflict-resolution-agent.md) (blog)
- [Unified.to: Building an AI reservation agent](https://unified.to/blog/how_to_build_an_ai_reservation_agent_using_a_unified_commerce_api) (blog)
- [FlowBench (arXiv 2406.14884)](https://arxiv.org/pdf/2406.14884): workflow-guided agent planning

### Security
- [OWASP LLM06:2025 Excessive Agency](https://genai.owasp.org/llmrisk/llm062025-excessive-agency/)

---

## Refuted claims (do not rely on these)
1. *"The SDK streams in the Vercel AI data protocol, so it works with Livewire/Inertia without custom wiring."* (0–3)
2. *"v0.10 added native human-in-the-loop tool approval through `Decisions` keyed by tool-call ID, with an `approval_state` column."* (0–3)
