# Impruve-Booking

An internal web app for booking the company's camera equipment, lighting kits and spaces (like the podcast room). Employees book either through a **chat assistant** ("I need the FX3 and a light kit Wednesday 9 to 5") or a **manual booking form**, and a **weekly calendar** shows what is booked, by whom and when.

- **Stack:** Laravel 13 (PHP 8.5), Inertia + React + TypeScript, Tailwind / shadcn, PostgreSQL, Laravel AI SDK with Anthropic Claude.
- **Docs:** [`PRD.md`](./PRD.md) (requirements) · [`design.md`](./design.md) (technical design) · [`implementation-plan.md`](./implementation-plan.md) · [`spike-streaming.md`](./spike-streaming.md)

Everything except the chat assistant works without an API key. See [Connecting the chat assistant](#connecting-the-chat-assistant-anthropic-api-key).

---

## Option A: Run with Docker (Laravel Sail), recommended

Sail runs PHP 8.5 and PostgreSQL in containers, so you don't need PHP, Composer or Postgres installed.

### Requirements

- Docker with Docker Compose (Docker Desktop, or Docker Engine on Linux / WSL 2)
- Git
- On Windows: clone and run the project **inside WSL 2** (e.g. `~/projects`), not under `/mnt/c`

### First-time setup

```bash
git clone https://github.com/JoeyMBrown/Impruve-Booking.git
cd Impruve-Booking

# 1. Install PHP dependencies (Sail itself lives in vendor/). This uses a
#    throwaway Composer container, so no local PHP is needed.
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd)":/app -w /app \
  -e COMPOSER_HOME=/tmp/composer composer:2 \
  composer install --ignore-platform-reqs

# 2. Create your environment file
cp .env.example .env

# 3. Build and start the containers (the first build takes a few minutes)
./vendor/bin/sail up -d

# 4. App key, database and placeholder data (14 users, 10 pieces of equipment)
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed

# 5. Frontend
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

Open **http://localhost** and pick a name on the "Who's booking?" screen. There are no passwords.

> Tip: add `alias sail='./vendor/bin/sail'` to your shell profile so you can type `sail artisan …`.

### Day to day

```bash
./vendor/bin/sail up -d          # start
./vendor/bin/sail npm run dev    # optional: Vite with hot reload while editing the frontend
./vendor/bin/sail down           # stop
```

If you don't run `npm run dev`, run `./vendor/bin/sail npm run build` after pulling frontend changes.

### Ports

The app is served on port **80** and Postgres on **5432**. If either is taken, set `APP_PORT=8080` and/or `FORWARD_DB_PORT=5433` in `.env`, then run `sail up -d` again.

---

## Option B: Run without Docker

### Requirements

- **PHP 8.5** with the `pdo_pgsql`, `mbstring`, `intl`, `bcmath` and `pcntl` extensions
- **Composer 2**
- **Node.js 24** (LTS) and npm
- **PostgreSQL 16 or newer**, with the `btree_gist` extension available. It ships with Postgres's standard `contrib` package; on Debian/Ubuntu that's included in `postgresql`. The migrations enable it, so the database user must be allowed to `CREATE EXTENSION` (simplest: a superuser for local development).

### First-time setup

```bash
git clone https://github.com/JoeyMBrown/Impruve-Booking.git
cd Impruve-Booking

composer install
cp .env.example .env
php artisan key:generate
```

Create two databases: one for the app and one for the test suite (`phpunit.xml` uses a database named `testing`).

```bash
createdb impruve_booking
createdb testing
```

Point `.env` at your local Postgres. `.env.example` is set up for Sail, so change the host and credentials:

```dotenv
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=impruve_booking
DB_USERNAME=your_postgres_user
DB_PASSWORD=your_postgres_password
```

Then migrate, seed and build:

```bash
php artisan migrate --seed
npm install
npm run build
```

### Running it

```bash
php artisan serve        # http://localhost:8000
```

While working on the frontend, also run `npm run dev` in a second terminal for hot reload.

---

## Connecting the chat assistant (Anthropic API key)

The floating 💬 assistant uses Anthropic's Claude via the Laravel AI SDK. Without a key, the rest of the app works normally and the assistant shows "The assistant is unavailable right now", with a **Book manually** link.

1. **Get a key.** Sign in at [console.anthropic.com](https://console.anthropic.com/), open **API Keys**, and create one. It starts with `sk-ant-`.
2. **Add it to `.env`.** Never put it in `.env.example`, which is committed to git.

   ```dotenv
   ANTHROPIC_API_KEY=sk-ant-...
   AI_CHAT_MODEL=claude-haiku-5-5
   ```

   `AI_CHAT_MODEL` is optional (that's the default). Change it to try a different Claude model without a code change.
3. **Clear cached config** so Laravel picks up the new values:

   ```bash
   ./vendor/bin/sail artisan config:clear   # Docker
   php artisan config:clear                 # without Docker
   ```

4. **Try it.** Open the 💬 button at the bottom right of any page and click one of the suggestions. A proposal card should appear; nothing is saved until you press **Confirm**.

**To replace or rotate a key,** change `ANTHROPIC_API_KEY` in `.env` and run `config:clear` again.

**Cost:** every chat message is a real API call. With the default Haiku model a typical message costs about a cent or less. Usage is capped at 20 messages per minute per user, and 8 tool steps per message.

**Debugging the assistant:** every tool call the assistant makes is written as JSON to `storage/logs/ai-YYYY-MM-DD.log`. Chat conversations are stored in the database for 30 days.

---

## Optional settings (`.env`)

| Setting | What it does |
|---|---|
| `BASIC_AUTH_USER`, `BASIC_AUTH_PASSWORD` | Put the whole site behind HTTP Basic Auth. It's on only when **both** are set; leave them empty locally. Use this (or a VPN) for any shared deployment, because the "pick who you are" sign-in has no passwords. |
| `CHAT_RATE_LIMIT` | Chat messages per minute per user (default 20) |
| `CHAT_MAX_STEPS` | Maximum assistant tool steps per message (default 8) |
| `CHAT_PROPOSAL_EXPIRY` | Minutes before an unconfirmed chat proposal expires (default 15) |

Run `config:clear` after changing any of them.

### Scheduled cleanup

`chat:prune` deletes chat conversations idle for 30 days and expires stale proposals. It's scheduled hourly, but in local development the scheduler only runs while you keep it running:

```bash
./vendor/bin/sail artisan schedule:work   # Docker
php artisan schedule:work                 # without Docker
```

You can also run it once with `artisan chat:prune`.

---

## Tests and code quality

The test suite runs against **real PostgreSQL** (the `testing` database) and never calls the Anthropic API; the assistant is faked in tests. With Docker, prefix each command with `./vendor/bin/sail`.

```bash
php artisan test            # PHPUnit
composer types:check        # Larastan / PHPStan
composer lint:check         # Pint (run `composer lint` to fix)
npm run build               # also generates the typed route helpers used below
npm run types:check         # TypeScript
npm run check               # oxlint + oxfmt (run `npm run check:fix` to fix)
```

GitHub Actions runs the same checks on every pull request.

---

## Placeholder data

The seeders load **placeholder** people and equipment from `database/seeders/data/users.json` and `equipment.json`, on a fresh database only. Seeding again skips tables that already have rows. Replace the placeholders with real names and gear before launch.

To start over locally:

```bash
./vendor/bin/sail artisan migrate:fresh --seed   # Docker
php artisan migrate:fresh --seed                 # without Docker
```

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| Chat says "The assistant is unavailable right now" | Check that `ANTHROPIC_API_KEY` is set in **`.env`** (not `.env.example`), then run `config:clear`. `storage/logs/laravel.log` has the error (a `401` means the key is missing or wrong). |
| `Vite manifest not found` / blank page | Run `npm run build` (or keep `npm run dev` running). |
| A browser login prompt appears | `BASIC_AUTH_USER` and `BASIC_AUTH_PASSWORD` are both set in `.env`. Clear them, then run `config:clear`. |
| `sail up` fails because a port is in use | Set `APP_PORT` / `FORWARD_DB_PORT` in `.env` (see [Ports](#ports)). |
| Migration error about `btree_gist` | Without Docker: install your Postgres's `contrib` package, and make sure the DB user may create extensions. |
| Tests fail with database errors (without Docker) | Create the `testing` database and make sure `.env`'s DB credentials can reach it. |
