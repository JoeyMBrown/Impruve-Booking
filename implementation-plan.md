# Impruve Booking: Implementation Plan

> **Source:** [`PRD.md`](./PRD.md) (v1, 2026-10-26). Technical detail comes from [`design.md`](./design.md) sections the PRD links (*D§n*).
> **Rule for this plan:** every step traces to PRD requirements. Nothing outside the PRD is added without explicit approval.
> **Process:** each step gets its own detailed plan, reviewed before any code is written. Docker, Sail and artisan commands are handed over to be run manually. Commits only with permission.

Steps are grouped by the PRD milestones (§12). Each step ships with the tests the PRD §10 assigns to it, so the suite grows with the code instead of being bolted on at the end.

---

## M1: Scaffold

| Step | Work | PRD refs |
|---|---|---|
| **1.1 Project scaffold** | Create the app from the official Laravel React starter kit using a Docker image that has PHP 8.5 + Composer (no local PHP). Merge it into the existing repo without losing `README.md`, `.gitignore` and the docs. Install Sail with **Postgres only**, PHP 8.5 runtime. Install `laravel/ai` and publish its conversation migrations. Confirm every Composer dependency installs and the starter kit's tests pass on 8.5; fall back to the latest 8.4 patch only if something blocks, and record why. | §9.1, §9.3.1, R3 |
| **1.2 Strip starter-kit auth** | Remove login, registration, password reset, email verification, 2FA, profile/settings pages, Fortify routes/views/config, and their tests. Keep the layout shell, shadcn, TS/ESLint/Prettier, Vite. Make `users.email` and `users.password` nullable. | §9.2, FR-AUTH-5 |
| **1.3 Config, enums, migrations, models** | `config/booking.php` (timezone `America/Chicago`, 30-min slot, 96 h max, 15-min grace, chat limits, proposal expiry; env-overridable where the PRD says configurable). Enums: `EquipmentCategory`, `EquipmentStatus`, `BookingSource`, `ProposalAction`, `ProposalStatus`. Migrations: `equipment`, `bookings` (CHECK constraints), `booking_items` (`btree_gist`, composite cascading FK, exclusion constraint, all as raw `DB::statement`), `booking_proposals`. Eloquent models, relationships and factories. | §7.2 FR-EQ-1/3, §7.3, Q1/Q2/Q4, D§2.2 |
| **1.4 Placeholder seeders** | `database/seeders/data/users.json` (14) and `equipment.json` (~10 placeholders: Sony FX3 #1/#2, Canon C70, Aputure Kit A/B, Godox Kit, Podcast Room, …). Seeders for a fresh install only. | §9.4, R7 |
| **1.5 Code quality + CI** | Add Larastan (moderate level) and confirm Pint config. GitHub Actions workflow with three jobs: **php-quality** (Pint `--test`, Larastan), **php-tests** (Postgres service, migrate, PHPUnit), **frontend** (`npm ci`, `npm run build`, `tsc --noEmit`, `vp check` lint + format). Runs on PRs and pushes to `main`. CI green. | §11, R12, R13 |

**M1 done when:** starter kit without auth runs in Sail on Postgres + PHP 8.5, migrations with constraints apply, seeders load, CI is green.

## M2: Identity & shell

| Step | Work | PRD refs |
|---|---|---|
| **2.1 `/who` + acting user** | `GET /who` (guests only) lists seeded users; picking one opens "Continue as X?"; `POST /who` logs in via the `web` guard (no password) and regenerates the session. Laravel's built-in `auth` middleware (guests sent to `/who`) on every route except `/who`. `GET /` redirects to `/calendar` or `/who`. `POST /switch-user` logs out, invalidates the session, returns to `/who`. A placeholder `/calendar` page so the redirect has a target until M6. | FR-AUTH-1, 2, 3 |
| **2.2 Basic Auth gate** | Global middleware: on only when both `BASIC_AUTH_USER` and `BASIC_AUTH_PASSWORD` are set; 401 without credentials. | FR-AUTH-6, R8 |
| **2.3 Shared layout & header** | Adapt the starter-kit shell: nav (Calendar, My bookings, Equipment) and "Acting as **Name** · Switch" on every page. Flash message display. | FR-AUTH-3, D§6 |

Tests: `/who` flow, middleware redirect, switch clears session, Basic Auth on/off.

## M3: Equipment

| Step | Work | PRD refs |
|---|---|---|
| **3.1 List, create, edit** | `/equipment` with category + status filters (default **Active** = available + out of service; Retired filter separate). Create/edit form with form request (name unique+required, category, description with the "assistant reads this" hint, asset tag unique). No delete. | FR-EQ-1, 2, 5 |
| **3.2 Detail page** | Fields, status + reason, this week and next week of bookings for the item, **Book this** link (to `/bookings/create?equipment[]=`, built in M5). | FR-EQ-7 |
| **3.3 Status change + impact preview** | `GET /api/equipment/{id}/status-impact?status=` returns upcoming and in-progress active bookings using the item. `PUT /equipment/{id}/status` saves status, optional reason, `status_changed_at`; any → any; never cancels bookings. Status-change modal shows the preview before saving when moving away from `available`. Booking model scopes for phase / active / at-risk introduced here. | FR-EQ-3, 4, 6, FR-BK-12, Q3, R1 |

Tests: equipment CRUD, filters, status change, impact preview JSON, Inertia props.

## M4: Rules engine

| Step | Work | PRD refs |
|---|---|---|
| **4.1 Domain types + time helpers** | `BookingDraft`, `CheckResult`, `BookingRuleViolation` (error codes), phase calculation, slot alignment, round-up-to-slot, all Carbon in `America/Chicago` and stored UTC. | FR-BK-2, 10, §8 Time |
| **4.2 `BookingService::check`** | Dry-run validation in order: `owner_only`, `immutable_phase`, `invalid_range`, `too_long`, `starts_in_past`, `no_items`, `item_unbookable` (with the in-progress-shorten exception), `item_conflict`. | FR-BK-1–10, 14 |
| **4.3 `create` / `update` / `cancel`** | Transactions; write `bookings`, insert `booking_items` once with copied range/active; items added/removed in the same transaction. Cancel upcoming → `cancelled_at` + `is_active=false`; cancel in-progress → end now rounded up to next 30-min boundary. SQLSTATE 23P01 → `BookingConflictException` listing conflicting items. Records `source`. `BookingPolicy` (view: anyone; update/cancel: owner). | FR-BK-6–13 |
| **4.4 `AvailabilityService`** | `forRange` (per non-retired item: free / busy with conflicts / unbookable with status + reason, optional ignore-booking) and `findOpenings` (next N windows where all listed items are free together). | FR-EQ-6, FR-FORM-2, FR-CHAT-5 |
| **4.5 Test suites** | **Unit:** one test per error code, phase rules, end-early rounding, 96 h vs 96.5 h, 15-min grace, slot alignment, DST weeks. **DB integration (Postgres):** exclusion constraint, back-to-back ranges allowed, **concurrent confirm** (two connections, exactly one 23P01), FK cascades on reschedule / end-early / cancel, reschedule into conflict fails, mismatched `booking_items` insert rejected, CHECK constraints. | §7.3 AC, §10 |

## M5: Manual booking

| Step | Work | PRD refs |
|---|---|---|
| **5.1 Availability endpoint** | `GET /api/availability?starts_at&ends_at&ignore_booking?` → one row per non-retired item. | FR-FORM-2, FR-EQ-6 |
| **5.2 Create form** | `/bookings/create`: time first (30-min pickers, end-before-start resets to start + 1 h, duration + 4-day limit shown), then items grouped by category with free / busy (owner, title, time) / unbookable (status, reason), debounced refetch, busy/unbookable disabled, already-selected-then-unavailable stays checked + red + reason. Title (≤120) and notes. Prefill from `starts_at`, `ends_at`, `equipment[]`. `BookingRequest` + `BookingService` errors shown as field errors. Success → calendar for the booking's week with flash. `source=manual`. | FR-FORM-1–4, 6, FR-BK-14 |
| **5.3 Edit, shorten, end now** | Same form in edit mode (owner only), ignoring the edited booking in availability. In-progress bookings: only End + **End now** enabled. `POST /bookings/{id}/cancel` (cancel or end early). | FR-FORM-5, FR-BK-9–11 |

Tests: booking CRUD + policies over HTTP, availability JSON shape, validation errors mapped from codes, prefill props.
*(Proposal prefill `?proposal=` is built in M8 once proposals exist.)*

## M6: Visibility

| Step | Work | PRD refs |
|---|---|---|
| **6.1 Week data** | `CalendarController` + `GET /api/calendar/week` with the same payload as the page props: ISO `week` param, category / item / mine filters, every booking overlapping the week (cancelled excluded), phase, at-risk, `out_now`, `unavailable`, my at-risk. | FR-CAL-1–3, 5, 8, D§4.2 |
| **6.2 Week grid** | Mon–Sun × 30-min grid, opens scrolled to 07:00–19:00, all 24 h reachable, red now line. Prev / Today / Next. Filters. Blocks: title, owner, items; colour from first item's category, striped if mixed; solid border for mine; ⚠ for at-risk. Side-by-side layout for overlaps. Multi-day segments per day + all-day strip. Empty-slot click → prefilled form (with item if single-item filter). | FR-CAL-1–6 |
| **6.3 Booking detail drawer** | `/bookings/{id}` page, also opened as a drawer from the calendar: details, items, phase, at-risk warnings, notes, Edit/Cancel for owner, confirm dialog ("Cancel this booking?" / "End this booking now?"). | FR-CAL-7 |
| **6.4 Side panel** | Out now, Unavailable, My at-risk; collapses into chips on narrow screens. | FR-CAL-8, R11 |
| **6.5 My bookings** | `/my-bookings` tabs with counts (Upcoming with at-risk first, In progress, Past, Cancelled), attention banner, "Edit to swap item", Edit/Cancel or shorten/End now per phase. | FR-MINE-1–3, US-13 |

Tests: calendar + week JSON shapes, filters, overlap-the-week inclusion, My bookings props.
*(Calendar refetch after chat confirm, FR-CAL-9, is wired in M8.)*

## M7: Streaming spike

| Step | Work | PRD refs |
|---|---|---|
| **7.1 laravel/ai API check** | Verify agent, tools, `RemembersConversations`, `fake()`, streaming API against current docs/source for the installed version. | §9.3.3, RK1 |
| **7.2 Streaming spike** | Throwaway experiment: can the stream reveal a finished tool step so a proposal card can be sent mid-stream? Decide mid-stream vs. cards-at-end (R6) and write the decision down. | §9.3.2, R6 |

## M8: Chat

| Step | Work | PRD refs |
|---|---|---|
| **8.1 Agent + system prompt** | `BookingAssistant` (Conversational, RemembersConversations, HasTools, `MaxSteps` from config, Anthropic, model from `AI_CHAT_MODEL`). Prompt rebuilt per request: now in Chicago, 14-day printed date reference, full live inventory with status + description, workflow, rules, other users' text labelled as data. | FR-CHAT-8–10, R4, D§5.1–5.2 |
| **8.2 Read tools + `ai` log channel** | `CheckAvailability`, `FindOpenings`, `ListMyBookings`. Acting user from session only; no `user_id` argument. Daily-rotated `ai` channel, JSON lines: tool, args, result/error code, user ID, conversation ID, timestamp. | FR-CHAT-5, 7, 17, R9 |
| **8.3 Proposals + propose tools** | `BookingProposal` model + policy, `ProposalCollector`. `ProposeBooking`, `ProposeChange`, `ProposeCancellation`: dry-run via `BookingService::check`, owner checks, store pending proposal with 15-min (configurable) expiry, card payload. | FR-CHAT-5–7, 11, 14 |
| **8.4 Chat endpoints** | `POST /chat/conversations` (new per page load). `POST /chat/conversations/{id}/messages` → SSE (`text`, `proposal`, `error`, `done`), conversation ownership checked before continuing, 20/min per-user rate limit, provider failure → `error` event. `POST /chat/proposals/{id}/confirm` (200 / 409 conflicts / 410 expired / 422 rule) and `/dismiss` (204), ownership enforced; confirm saves with `source=chat`. | FR-CHAT-2, 6, 12–16, R10 |
| **8.5 Chat widget** | Floating widget on every authenticated page; new conversation on page load and on user switch; 3–4 suggestion chips from real item names; streamed replies via `fetch` + `ReadableStream`; input disabled while streaming, Stop aborts, Enter sends. Proposal cards with all states (pending, confirming, booked + calendar link, taken, expired, dismissed); on 409 auto-send the system follow-up message. "Assistant unavailable" + Book manually link. Notify an open calendar to refetch after confirm. | FR-CHAT-1–4, 11–13, 15, FR-AUTH-4, FR-CAL-9 |
| **8.6 Edit in form** | `/bookings/create?proposal={uuid}` prefill (owner's proposals only) with the "From your chat request" banner. | FR-FORM-4 |
| **8.7 Prune + expiry command** | Scheduled command: delete conversations older than 30 days, mark expired pending proposals `expired`. Scheduler running in Sail. | FR-CHAT-18, Q6 |
| **8.8 Chat test suites** | Feature: `BookingAssistant::fake()` + `preventStrayPrompts()` for SSE envelope, conversation ownership, rate limit, confirm/dismiss/expire, 409 race, unavailable path. Unit: each tool directly (availability output, propose error codes, refusing others' bookings, no `user_id` arg). Conversations A–J covered via fakes + tool tests. | §7.7 AC, §10 |

## M9: UAT

| Step | Work | PRD refs |
|---|---|---|
| **9.1 Real-model check** | You run conversations A–J against real Haiku; I tune the prompt from the results. | §7.7 AC, M9 |
| **9.2 Timing check** | Testers time a typical booking by chat and by form (< 60 s). Manual, no instrumentation. | §2.1 |
| **9.3 Real seed data** | Replace placeholder users/equipment JSON with real names and gear (you supply them). | §9.4 |

---

## Decisions

1. **Branches:** one branch per milestone (e.g. `m1-scaffold`).
2. **Equipment list "Next booking" column** (D§6.6 wireframe): not built. Follow the PRD (FR-EQ-5).
3. **Docs** (`PRD.md`, `design.md`, `research.md`, `work-log.md`) are committed to the repo.
4. **Seeders** skip any table that already has rows (fresh install only). 10 placeholder equipment items: the PRD's 7 plus 3 more.
5. **Frontend lint/format** uses the starter kit's Vite+ (`vp check`: oxlint + oxfmt) instead of ESLint + Prettier. PRD §9.2 and §11 updated.
6. **Acting-user middleware** is Laravel's built-in `auth` (guests redirected to `/who`), not a custom `EnsureActingUser`. PRD §9.2 updated.
7. **Shell** uses the kit's top-header layout (matches D§6). Nav links are added in the milestone that builds each page.
8. **No dead links.** Links to pages that don't exist yet ("Book this" → M5, booking detail → M6) are added in the milestone that builds the target page.
9. **Equipment detail bookings** are a simple list grouped by day for M3; a mini calendar grid can replace it after M6.
10. **Re-saving the same equipment status** is allowed (to update the reason); it refreshes `status_changed_at`.
11. **96-hour cap** is measured in real elapsed time, matching the DB CHECK. Over a DST change, Fri 09:00 → Tue 09:00 can be 97 h and is rejected as `too_long`.
12. **End now** rounds to the next 30-minute boundary strictly after now (10:00:00 → 10:30), so the end is always after now and after the start.
13. **Title/notes validation** lives in the form request (M5) and tool schema (M8), not `BookingService`; the `string(120)` column is the backstop.
14. **Booking form times** are sent as company-local wall-clock strings and parsed in PHP (Carbon, America/Chicago); the browser does no timezone maths. Native date input + 30-minute time select (no new dependencies).
15. **Temporary "+ New booking" button** on the placeholder calendar page until M6's calendar header replaces it. Booking edit links arrive with M6's drawer and My bookings.
16. **Calendar "Unavailable" panel** lists out-of-service items only; retired items have left the inventory.
17. **Empty-slot click** prefills exactly that 30-minute slot.
18. **Cancel / End now** return to the page the user came from (calendar, detail page, My bookings), falling back to the booking's calendar week. Form saves still return to the calendar week (FR-FORM-6).
19. **Calendar filters** are single-select: one category, one item, Only mine.
20. **Booking drawer** opens without changing the URL and links to `/bookings/{id}` ("Open page") for sharing.

## Open questions (to resolve at the step they affect)

1. *(At 7.2)* Where to write the spike decision.
2. *(At 8.7)* How to run the scheduler under Sail (dedicated container vs. a manual `schedule:work`).
