# Impruve Booking: Product Requirements Document (MVP)

> **Status:** v1, 2026-10-26
> **Owner:** Joey Brown
> **Companion docs:** [`design.md`](./design.md) (technical design: schema, SQL, routes, payloads, wireframes) · [`research.md`](./research.md) (Laravel AI SDK research)
> This PRD says **what** the MVP must do and **how we'll know it's done**. For **how** it's built, it links to `design.md` sections (*D§n*) and doesn't repeat them.

---

## 1. Summary

Impruve Booking is an internal web app where the company's **14 employees** book about **10 unique pieces of equipment** (cameras, lighting kits and the podcast room). Users can book in two ways:

1. **AI chat assistant**: a floating widget on every page that takes natural-language requests ("I need the FX3 and a light kit Wednesday 9 to 5"), checks availability and proposes a booking that the user confirms with one click.
2. **Manual booking form**: pick a time range first, then choose from the items that are available in that range, with live availability.

A **weekly calendar** (hours × days) is the home screen. It shows what is booked, by whom and when, what is out right now, and what is unavailable.

## 2. Problem & goals

**Problem.** Equipment booking currently has no single source of truth. People can't easily see what is free, double bookings are possible, and when gear goes into maintenance, nobody tells the people who booked it.

**Goals**

| # | Goal |
|---|---|
| G1 | **No double bookings.** The database makes it impossible for one item to be in two active, overlapping bookings. |
| G2 | **Fast booking.** A typical booking takes under 60 s, whether made by chat or by form. |
| G3 | **Clear visibility.** Anyone can see the week's bookings, what is out now and what is unavailable, and why. |
| G4 | **A trustworthy assistant.** The bot never saves anything by itself, never picks an item or time for the user, and never acts on someone else's booking. |
| G5 | **Room to grow.** Placeholder auth, the single timezone and the fixed categories can each be replaced later without rewriting the booking rules. |

### 2.1 Success metrics

| Metric | Target | How it's measured |
|---|---|---|
| Double bookings | **0**, ever | Postgres exclusion constraint (D§2.2). Concurrent-confirm tests in CI must pass. |
| Time to book | **< 60 s** for a typical single-shoot booking, by chat **and** by form | **Qualitative UAT check**: testers are timed by hand. No instrumentation in the MVP. |

## 3. Users & personas

- **Employee (all 14 users).** Books gear for shoots, checks what is free, manages their own bookings, and may update inventory. There are no roles in the MVP: everyone has the same permissions, except that **only the owner can edit or cancel a booking**.

## 4. Scope

### 4.1 In scope (MVP)

- Placeholder "pick who you are" auth (seeded users only)
- Equipment inventory: create, edit, change status (available / out of service / retired), with an impact preview
- Bookings: create, edit, cancel, end early, with one time range and one or more items per booking
- Weekly calendar with filters, an "out now" panel, an "unavailable" panel and a "my at-risk bookings" panel
- My bookings page
- AI chat widget: availability questions, create, list mine, modify or cancel mine, all through confirm cards
- Suggestion chips in the chat widget
- Optional site-wide HTTP Basic Auth gate
- CI on GitHub Actions

### 4.2 Out of scope (MVP)

Real authentication or SSO, roles and permissions, in-app user management, email or Slack notifications, recurring bookings, check-in/check-out tracking, dated maintenance blocks, kits and bundles, quantity-based items, multiple timezones, saved or browsable chat history, business-hours rules, buffers between bookings, un-cancelling, per-item time ranges within a booking, failover to a second AI provider, live AI eval suite, frontend/JS and E2E test suites, automated deployment (CD), a fully polished mobile layout.

### 4.3 Post-MVP backlog (designed, deliberately deferred)

- Drag across calendar slots to prefill a booking (clicking a single slot **is** in the MVP)
- Chat keyboard shortcuts (`/`, `Ctrl+K`, `Esc`)
- Context-aware chat prefill ("About: Sony FX3 #1" when opened from an equipment page or a selected slot)
- Live eval suite: the §8.4 conversations run against the real model
- Continuous deployment, once a hosting target is chosen (§13)

## 5. Resolved decisions

These close the open questions in `design.md` and the stack decisions made during PRD review.

| # | Topic | Decision |
|---|---|---|
| Q1 | Slot step | **30 minutes**, for start and end times, calendar rows and the form pickers |
| Q2 | Max length | **Hard 96 hours** (Fri 09:00 → Tue 09:00 allowed). Enforced by a DB CHECK constraint. |
| Q3 | Un-retire | **Allowed.** Any status can change to any other status. |
| Q4 | Past-start grace | **15 minutes.** A booking may start at 10:00 until 10:15. |
| Q5 | Date but no time | **Bot always asks** for a start and end time. It never fills in a default. |
| Q6 | Chat retention | **30 days**, then a scheduled prune |
| R1 | Retiring equipment | **Status only.** No `SoftDeletes` and no `deleted_at`. Retired items are hidden from booking screens and kept for history. |
| R2 | Starter kit | **Official Laravel React starter kit, with its auth removed** (see §9.2) |
| R3 | PHP | **8.5, latest patch release** (includes the latest security fixes). Fall back to 8.4 only if a dependency blocks 8.5. |
| R4 | Chat model | **`claude-haiku-5-5`**, set by env var (`AI_CHAT_MODEL`) so it can be changed without a code change |
| R5 | AI failover | **None.** If Anthropic fails, the widget shows "Assistant unavailable" with a **Book manually** link. |
| R6 | Streaming fallback | If the streaming spike shows proposal cards can't be sent mid-stream, **stream the text and send cards at the end of the stream** |
| R7 | Seeders | **Fresh install only.** Users and equipment are seeded into an empty DB. After launch, changing users is a manual DB task. |
| R8 | Outer gate | **Optional HTTP Basic Auth** for the whole site, turned on by env vars |
| R9 | Tool-call logs | **Dedicated `ai` log channel** with structured JSON lines, rotated daily. No DB table. |
| R10 | Chat limits | **20 messages/min per user**, `MaxSteps(8)`, **15-min** proposal expiry, all configurable |
| R11 | Devices | **Desktop first, usable on mobile** |
| R12 | Testing | **PHPUnit only**, run against real Postgres. No JS or E2E tests in the MVP. |
| R13 | CD | **CI only.** Deployment is deferred until a hosting target is chosen. |

The design decisions D1–D21 in `design.md` §0 still apply, with the changes above.

---

## 6. User stories

| ID | As an employee, I want to… | So that… |
|---|---|---|
| US-1 | pick my name from a list and confirm | my bookings are in my name, without a password |
| US-2 | see this week's bookings on a calendar | I know what is booked, by whom and when |
| US-3 | filter the calendar by category, by item or to my own bookings | I can answer "when is FX3 #1 free?" at a glance |
| US-4 | book one or more items for a time range through a form that shows live availability | I only pick items that are actually free |
| US-5 | ask the chat assistant in plain English for gear | I can book without leaving the page I'm on |
| US-6 | be asked which item I mean when my request is vague | the bot never books the wrong camera |
| US-7 | review a card with the exact dates before anything is saved | mistakes like a wrong "next Tuesday" are caught before I confirm |
| US-8 | ask the bot whether something is free | I get an answer without starting a booking |
| US-9 | list, change or cancel my bookings, by chat or in the UI | plans can change easily |
| US-10 | end an in-progress booking early | gear is freed as soon as I'm done |
| US-11 | add equipment and change its status, with a reason | the inventory reflects reality |
| US-12 | see which bookings a status change would affect before I save it | I understand the impact |
| US-13 | see a warning when gear in my booking becomes unavailable | I can swap it or cancel in time |
| US-14 | switch to a different user | a shared machine can be used by several people |

---

## 7. Functional requirements

Each requirement has acceptance criteria (AC) that can be tested. "Now" and all displayed times are in **America/Chicago**. Times are stored in UTC (`timestamptz`).

### 7.1 Identity & access (D§3)

- **FR-AUTH-1** Every route except `/who` (and its POST) requires an acting user. Without one, the user is redirected to `/who`.
- **FR-AUTH-2** `/who` lists all seeded users. Choosing a name opens a confirmation ("Continue as X?"). Confirming logs the user in through Laravel's standard `web` session guard (no password) and regenerates the session.
- **FR-AUTH-3** The header on every page shows "Acting as **Name** · Switch". Switch logs out, invalidates the session and returns to `/who`.
- **FR-AUTH-4** Switching user starts a new chat conversation (FR-CHAT-2).
- **FR-AUTH-5** There are no registration, password, password reset, 2FA or profile/settings screens. The starter kit's versions are removed.
- **FR-AUTH-6** If both `BASIC_AUTH_USER` and `BASIC_AUTH_PASSWORD` are set, HTTP Basic Auth protects every route. If either is unset (the local Sail default), the gate is off.

**AC:** Visiting `/calendar` as a guest redirects to `/who`. After confirming "Ana", `auth()->user()` is Ana. Switching clears the session. With the Basic Auth env vars set, a request without credentials gets a 401. Without them, there is no prompt.

### 7.2 Equipment (D§2.2, D§2.5, D§6.6–6.7)

- **FR-EQ-1** Fields: **name** (required, unique), **category** (required: `camera`, `lighting` or `space`, fixed enum), **description** (optional; the form hint says the assistant reads it), **asset tag** (optional, unique).
- **FR-EQ-2** Anyone can create or edit equipment. There is no hard delete and no soft delete.
- **FR-EQ-3** Status is `available`, `out_of_service` or `retired`. Any status can change to any other (Q3). Each change takes an optional **reason** and records `status_changed_at`.
- **FR-EQ-4** Before a status change away from `available` is saved, the UI shows an **impact preview**: the upcoming and in-progress active bookings that use the item, with links. Saving **never cancels bookings**.
- **FR-EQ-5** The equipment list can be filtered by category and status. The default "Active" filter shows available and out-of-service items. Retired items have their own filter.
- **FR-EQ-6** Retired items can't be chosen in the booking form or by the bot, and don't appear in availability results. Their past bookings stay visible.
- **FR-EQ-7** The equipment detail page shows the item's fields, its status and reason, and this week and next week of bookings for that item, with a **Book this** action.

**AC:** Setting Kit B to out of service with 2 future bookings shows those 2 bookings in the preview. After saving, both are flagged at risk and neither is cancelled. A retired item is missing from `/api/availability`. Un-retiring makes it bookable again.

### 7.3 Booking rules (D§2.4)

The same rules engine (`BookingService`) is used by every save path: the form, the confirm endpoint, and the chat tools (as a dry run).

- **FR-BK-1** A booking is **one time range**, **one or more items**, an **owner**, a **title** (required, max 120 characters) and optional **notes**.
- **FR-BK-2** The range is half-open `[start, end)`. Start and end are on **30-minute boundaries** and end is after start (`invalid_range`).
- **FR-BK-3** Length is **at most 96 hours** (`too_long`).
- **FR-BK-4** The start is **no earlier than now minus 15 minutes** when creating, or when an update changes the start (`starts_in_past`).
- **FR-BK-5** There is at least one item (`no_items`).
- **FR-BK-6** Every item has status `available` (`item_unbookable`). Exception: shortening an in-progress booking is always allowed.
- **FR-BK-7** No item is in two active bookings whose ranges overlap (`item_conflict`). This is checked in the service and **enforced by a Postgres exclusion constraint**. A constraint violation (SQLSTATE 23P01) becomes a conflict error that lists the conflicting items.
- **FR-BK-8** Bookings may overlap in time as long as they don't share an item.
- **FR-BK-9** Only the owner can edit or cancel a booking (`owner_only`). Everyone can view all bookings.
- **FR-BK-10** Phase rules (`immutable_phase`):
  - **Upcoming**: fully editable, and can be cancelled.
  - **In progress**: the only allowed changes are moving the end to a later-than-now slot that is still before the current end, or **ending it now**. Ending now sets the end to now rounded **up** to the next 30-minute boundary.
  - **Ended**: no changes at all.
- **FR-BK-11** Cancelling sets `cancelled_at` and `is_active = false`. Cancelled bookings stay in history (My bookings → Cancelled tab) and are hidden from the calendar.
- **FR-BK-12** A booking is **at risk** when it is active, hasn't ended, and any of its items has a status other than `available`. At-risk bookings show a ⚠ on the calendar, the booking detail, My bookings and the side panel.
- **FR-BK-13** Each booking records its `source` (`manual` or `chat`).
- **FR-BK-14** Every rule failure returns its error code, which the form shows as a field error and the bot explains in plain language.

**AC:** One automated test per error code. Two concurrent confirms for the same item and range: exactly one succeeds, and the other returns 409 with the conflict details. 09:00–12:00 and 12:00–15:00 on the same item both succeed. A 96-hour booking succeeds and a 96.5-hour booking fails. Rescheduling, ending early and cancelling update every `booking_items` row through the cascading foreign key.

### 7.4 Manual booking form (D§6.3)

- **FR-FORM-1** **Time first, then items.** Start and end pickers move in 30-minute steps. If the end is set before the start, it resets to start + 1 h. The form shows the duration and the 4-day limit.
- **FR-FORM-2** Each time-range change calls the availability endpoint (debounced). Every non-retired item is listed by category with **free**, **busy** (owner, title and time of the conflicting booking) or **unbookable** (status and reason). Busy and unbookable items are disabled.
- **FR-FORM-3** If an item that is already selected becomes unavailable, it stays checked, turns red, and shows why it can't be saved.
- **FR-FORM-4** The form can be prefilled from query parameters: `starts_at`, `ends_at`, `equipment[]` (from a calendar slot click or "Book this"), or `proposal={uuid}` ("Edit in form" on a chat card). A proposal prefill shows a banner: "From your chat request: review and book".
- **FR-FORM-5** Edit mode uses the same form and leaves the booking being edited out of the availability check. For an **in-progress** booking, only the End field and an **End now** button are enabled.
- **FR-FORM-6** On success, the user returns to the calendar for that booking's week, with a flash message.

### 7.5 Weekly calendar (D§6.2)

- **FR-CAL-1** `/calendar` is the home screen. It shows a **Mon–Sun × 30-minute** grid that opens scrolled to 07:00–19:00, with all 24 hours reachable, and a red "now" line.
- **FR-CAL-2** Navigation: previous week, Today, next week. The week is in the URL (`?week=2026-W44`).
- **FR-CAL-3** Filters: category (All, Cameras, Lighting, Space), item picker, and **Only mine**. Filtering to a single item gives that item's week.
- **FR-CAL-4** Booking blocks show title, owner and item names. Colour comes from the first item's category, striped when categories are mixed. The user's own bookings get a solid border. At-risk bookings show ⚠. Cancelled bookings are hidden.
- **FR-CAL-5** Bookings that overlap in time sit side by side. Multi-day bookings appear as a segment in each day's column **and** in an all-day strip. Any booking that overlaps the visible week is included.
- **FR-CAL-6** **Clicking an empty slot** opens the booking form prefilled with that slot (and the item, if the calendar is filtered to one). Drag-to-select is post-MVP.
- **FR-CAL-7** Clicking a block opens the booking detail drawer: details, items, phase, at-risk warnings, notes, and Edit / Cancel for the owner. Cancel asks for confirmation ("Cancel this booking?", or "End this booking now?" for an in-progress booking).
- **FR-CAL-8** The side panel lists **Out now** (item, owner, until), **Unavailable** (item, status, reason) and **My at-risk bookings**. On narrow screens it collapses into chips above the grid.
- **FR-CAL-9** After a chat confirm, an open calendar refetches its week data and shows the new block without a full page reload.

### 7.6 My bookings (D§6.5)

- **FR-MINE-1** Tabs: **Upcoming** (at-risk bookings first), **In progress**, **Past**, **Cancelled**, each with a count.
- **FR-MINE-2** A banner shows how many bookings need attention. Each at-risk booking has an "Edit to swap item" action.
- **FR-MINE-3** Each row has Edit and Cancel when the phase allows them. For in-progress bookings these become shorten and End now.

### 7.7 AI chat assistant (D§4.5, D§5)

**Placement & lifecycle**

- **FR-CHAT-1** A floating chat widget appears on every authenticated page.
- **FR-CHAT-2** **A new conversation starts on every page load and every user switch.** Earlier conversations can't be opened from the UI. Conversations are stored server-side through laravel/ai's tables.
- **FR-CHAT-3** When the widget opens, it shows **3–4 suggestion chips** built from real item names (e.g. "Book the Podcast Room tomorrow 2–3pm", "What's free this weekend?", "My bookings this week").
- **FR-CHAT-4** Replies **stream** as text. The input is disabled while a reply streams, and a **Stop** button aborts it. `Enter` sends.

**Abilities (tools)**

- **FR-CHAT-5** The bot can answer availability questions (`CheckAvailability`, `FindOpenings`), list the user's own bookings (`ListMyBookings`), and propose a create, change or cancel (`ProposeBooking`, `ProposeChange`, `ProposeCancellation`).
- **FR-CHAT-6** **The model never saves anything.** Propose tools run the rules engine as a dry run and store a pending proposal. A booking is saved only when the user clicks **Confirm**, which calls an ordinary authorized HTTP endpoint without involving the model.
- **FR-CHAT-7** Identity comes **only from the session**. No tool accepts a user ID. Change and cancel tools refuse bookings the user doesn't own.
- **FR-CHAT-8** The system prompt is rebuilt on every request and contains: the current time in America/Chicago, a **14-day printed date reference** (so the model never works out weekdays itself), the full live inventory with status and description, the workflow (identify items → resolve exact times → check availability → propose), and the rules.
- **FR-CHAT-9** **Never guess.** If several items fit ("a light kit"), the bot lists the available ones and asks which one. If the date or time is missing or vague ("Tuesday", "afternoon"), the bot asks for specific start and end times (Q5).
- **FR-CHAT-10** Booking titles and notes written by other users are passed to the model as labelled data, not as instructions.

**Confirm cards**

- **FR-CHAT-11** Each proposal appears as a card with the action (create, change or cancel), items, **absolute weekday dates and times**, duration, title, an availability check result, and for changes the old and new values side by side. Buttons: **Confirm**, **Edit in form**, **✕** (dismiss).
- **FR-CHAT-12** Card states: pending → confirming… → **✓ Booked · View on calendar**, or **✕ Taken** (with details), **Expired: ask again** (after 15 min), or **Dismissed**.
- **FR-CHAT-13** **Race on confirm:** if an item was taken after the proposal was made, nothing is saved. The card shows who has it now, and the widget automatically sends a system message so the bot follows up with alternatives (`FindOpenings` / `CheckAvailability`).
- **FR-CHAT-14** Only the user who owns a proposal can confirm or dismiss it. Only the owner of a conversation can continue it. Both are checked in our code, because the SDK doesn't check them.

**Reliability & limits**

- **FR-CHAT-15** If the model or API is unavailable, the widget shows "The assistant is unavailable right now" with a **Book manually** link. There is no provider failover (R5).
- **FR-CHAT-16** Limits: **20 messages/min per user**, **`MaxSteps(8)`** per message, **15-min** proposal expiry, all configurable.
- **FR-CHAT-17** Every tool call is logged to the `ai` log channel as structured JSON: tool name, arguments, result or error code, user ID, conversation ID and timestamp.
- **FR-CHAT-18** A scheduled command deletes conversations older than **30 days** and marks pending proposals past their expiry as `expired`.

**AC:** Conversations A–J in `design.md` §5.4 are the behavioural acceptance criteria. They are tested with `BookingAssistant::fake()` plus direct tool unit tests (§10), and checked by hand against the real model during UAT.

---

## 8. Non-functional requirements

| Area | Requirement |
|---|---|
| **Integrity** | Overlap prevention is enforced in the DB (exclusion constraint with `btree_gist`). The `booking_items` copy of each booking's range and active flag is kept in sync by a cascading composite foreign key (D§2.2). |
| **Security** | Session auth and CSRF on all routes. Policies for booking, proposal and conversation ownership. The LLM has read/propose tools only (OWASP LLM06). Optional Basic Auth gate. The app **must** be deployed behind the company network/VPN **or** with the Basic Auth gate on. Secrets (e.g. `ANTHROPIC_API_KEY`) are kept only in `.env`. |
| **Time** | Single company timezone `America/Chicago`. Times stored in UTC `timestamptz`. All parsing and validation happens in PHP (Carbon), never trusting the model's arithmetic. DST transitions are covered by tests. |
| **Performance** | Calendar and availability responses in < 300 ms at MVP data volume. The first streamed chat token typically arrives in < 3 s. |
| **Devices** | Desktop first (latest Chrome, Firefox, Safari, Edge). Usable on phones: responsive layout, the side panel collapses, the chat widget works, and the calendar may scroll horizontally. |
| **Accessibility** | shadcn/Radix primitives. All actions keyboard-reachable. Status is never shown by colour alone (⚠/⛔ icons plus text). |
| **Observability** | `bookings.source`, the stored chat conversations (30 days) and the `ai` log channel give enough information to debug bot behaviour. |
| **Cost** | Haiku-class model. The rate limit and `MaxSteps` cap runaway usage. Cost is expected to be tiny for 14 users, so there is no hard budget cap in the MVP. |

---

## 9. Technical stack & constraints

### 9.1 Stack

| Layer | Choice |
|---|---|
| Language | **PHP 8.5** (latest patch release) |
| Framework | **Laravel 13.x** |
| Frontend | **Inertia + React + TypeScript**, Vite, Tailwind, **shadcn/ui**, using the versions the starter kit ships |
| AI | **Laravel AI SDK (`laravel/ai`)** with the **Anthropic** provider and model **`claude-haiku-5-5`** (`AI_CHAT_MODEL` env var) |
| Database | **PostgreSQL** (Sail service), with the `btree_gist` extension |
| Local dev | **Laravel Sail** (Docker): app, Postgres, plus scheduler/queue as needed |
| Tests | **PHPUnit**, run against real Postgres |
| CI | **GitHub Actions** |

### 9.2 Starter kit adaptation

Start from the **official Laravel React starter kit** and keep its layout shell, shadcn setup, TypeScript and lint/format config (Vite+: oxlint + oxfmt) and Vite wiring. **Remove** login, registration, password reset, email verification, 2FA, the profile/settings pages, and the Fortify routes and views, plus the tests for all of these. Replace them with `/who`, `EnsureActingUser` and switch-user (FR-AUTH). Keep the `users.password` column (nullable) so real auth can be added later.

### 9.3 Scaffold-time checks

1. Confirm that the Sail PHP 8.5 runtime and **every Composer dependency** (especially `laravel/ai`) install and pass tests on 8.5. If anything blocks 8.5, pin to the latest 8.4 patch release and record why.
2. Run the **streaming spike** (about half a day): can laravel/ai's stream show when a tool step finishes, so a proposal card can be sent mid-stream? If not, **stream the text and send the cards at the end** (R6).
3. Check the laravel/ai API (agent, tools, `RemembersConversations`, `fake()`) against the 13.x docs. The SDK was beta in Feb 2026, so APIs may have changed.

### 9.4 Seed data

- `database/seeders/data/users.json` (14 users) and `equipment.json` (about 10 items) hold **placeholder** entries, e.g. Sony FX3 #1, Sony FX3 #2, Canon C70, Aputure Kit A, Aputure Kit B, Godox Kit, Podcast Room.
- Seeders run on a **fresh install only**. Replacing the placeholders with real names and gear is a **pre-launch checklist item**. After launch, users change through a manual DB task and equipment is managed in the app.

---

## 10. Testing strategy (PHPUnit)

| Suite | Covers |
|---|---|
| **Unit: rules engine** | Every `BookingService` error code, phase rules, end-early rounding, the 96 h boundary, the 15-min grace, slot alignment, DST weeks |
| **DB integration (Postgres)** | Exclusion constraint. **Concurrent confirm** (two transactions, exactly one fails with 23P01 → 409). Composite foreign key cascades on reschedule, end-early and cancel. Rescheduling into a conflict fails. A mismatched `booking_items` insert is rejected. CHECK constraints. |
| **Feature: HTTP** | `/who` flow and middleware, Basic Auth gate on and off, booking CRUD and policies, equipment CRUD, status change and impact preview, calendar and availability JSON shapes, Inertia props (`assertInertia`) |
| **Feature: chat** | `BookingAssistant::fake()` + `preventStrayPrompts()`: SSE event envelope (`text`, `proposal`, `error`, `done`), conversation ownership, rate limit, proposal confirm/dismiss/expire, 409 race path, "assistant unavailable" path |
| **Unit: tools** | Each tool called directly: availability output, propose error codes, refusing other people's bookings, no `user_id` argument |

There are no JS/E2E or live-model tests in the MVP (R12; §4.3).

## 11. CI (GitHub Actions)

Runs on every pull request and on every push to `main`. All jobs must pass before merging.

| Job | Steps |
|---|---|
| **php-quality** | PHP 8.5 setup, `composer install`, **Pint** (`--test`), **Larastan/PHPStan** (starting at a moderate level and raising it over time) |
| **php-tests** | Postgres service container, `migrate`, **PHPUnit** (the full suite, including the DB concurrency tests) |
| **frontend** | Node setup, `npm ci`, **`npm run build`**, **`tsc --noEmit`**, **lint + format check** (`vp check`: oxlint + oxfmt, the starter kit's Vite+ tooling, in place of ESLint/Prettier; decided 2026-10-26) |

There is no deployment job (R13). CI never calls the Anthropic API, and agent tests use fakes.

---

## 12. Milestones

| # | Milestone | Done when |
|---|---|---|
| M1 | **Scaffold** | Starter kit with its auth removed, Sail + Postgres, PHP 8.5 check, enums, migrations with the constraints, placeholder seeders, CI green |
| M2 | **Identity & shell** | `/who`, switch user, Basic Auth gate, shared layout and header |
| M3 | **Equipment** | CRUD, status changes with impact preview, list and detail pages |
| M4 | **Rules engine** | `BookingService`/`AvailabilityService` with the full unit and DB integration suites |
| M5 | **Manual booking** | Form with live availability, edit/shorten/end now, prefill |
| M6 | **Visibility** | Weekly calendar, filters, drawer, side panel, My bookings |
| M7 | **Streaming spike** | §9.3 item 2 decided and written down |
| M8 | **Chat** | Agent, tools, proposals, SSE, widget, chips, confirm/race/expiry/unavailable flows, logging, prune |
| M9 | **UAT** | Conversations A–J checked against real Haiku, prompt tuned, < 60 s booking target met by testers, real seed data loaded |

## 13. Risks & open questions

| # | Item | Notes |
|---|---|---|
| OQ1 | **Hosting / deployment target** | Not chosen. It blocks CD and decides whether a VPN or the Basic Auth gate protects production. |
| OQ2 | **Real auth** | Talk to the client about SSO or an existing identity system, to avoid creating a new set of accounts for them to manage. |
| OQ3 | **Client validation** of the 30-min slots, the 4-day cap and the single timezone | Accepted for the MVP. Confirm with the client after launch. |
| OQ4 | **Return / handoff lag** | Should there be buffers between bookings? Out of scope for now; ask the client. |
| OQ5 | **Accessories and partial kits** | The MVP treats each item as a whole. Ask the client. |
| RK1 | **laravel/ai is young** (beta Feb 2026) | APIs may change. Covered by the scaffold checks and the streaming spike. |
| RK2 | **Model misreads dates or items** | Mitigated by the printed date reference, absolute dates on cards, always asking when something is ambiguous, user confirmation, and PHP-side validation. |
| RK3 | **Placeholder auth is spoofable** | This is acceptable only for an internal deployment with an outer gate (FR-AUTH-6, §8). |
