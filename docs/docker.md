# Docker

The app runs as a set of single-purpose containers. Development and production
are built from one multi-stage `Dockerfile`:

| File                            | Purpose                                                                |
| ------------------------------- | ---------------------------------------------------------------------- |
| `Dockerfile`                    | Image targets `development`, `production`, `web`                       |
| `compose.yaml`                  | Local development stack                                                |
| `compose.prod.yaml`             | Production stack                                                       |
| `docker/nginx/default.conf`     | Nginx site config (shared by dev and prod)                             |
| `docker/php/conf.d/app.ini`     | PHP settings for all environments                                      |
| `docker/php/conf.d/opcache.ini` | OPcache settings (production only)                                     |
| `docker/php/entrypoint.sh`      | Production entrypoint: optional migrations + `artisan optimize`        |
| `docker/vite/dev.sh`            | Starts the Vite dev server and cleans up `public/hot` on stop          |
| `.env.production.example`       | Template for production environment variables                          |
| `.dockerignore`                 | Keeps secrets, `vendor`, `node_modules` and build output out of images |

## Services

```
            ┌──────────── browser ────────────┐
            │                                 │ (dev only: JS/CSS + HMR)
            ▼                                 ▼
   web (nginx :80) ──fastcgi──► app (php-fpm)   vite (:5173)
                                  │   ▲             ▲
          queue (worker) ─────────┤   └── SSR ──────┘ (dev) / ssr (:13714, prod)
          scheduler ──────────────┤
                                  ▼
                       mysql (MySQL 8)    redis
```

| Service     | Dev                                     | Prod                                       | Role                                                                                                                   |
| ----------- | --------------------------------------- | ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------- |
| `web`       | `nginx:1.28-alpine` + bind mount        | `web` target (public/ baked in)            | Serves static files, proxies PHP to `app:9000`                                                                         |
| `app`       | `development` target                    | `production` target                        | PHP-FPM running Laravel                                                                                                |
| `vite`      | `development` target                    | —                                          | Vite dev server with HMR and Inertia SSR                                                                               |
| `ssr`       | — (Vite does SSR in dev)                | `ssr` target (`node bootstrap/ssr/app.js`) | Renders each first page load to HTML for crawlers and fast first paint                                                 |
| `queue`     | `queue:listen` (reloads on code change) | `queue:work`                               | Processes queued jobs (Redis)                                                                                          |
| `scheduler` | `schedule:work`                         | `schedule:work`                            | Runs the Laravel scheduler (activity log pruning, daily-session days: scheduled hourly, started/finished every minute) |
| `mysql`     | `mysql:8.4`                             | `mysql:8.4`                                | Database                                                                                                               |
| `redis`     | `redis:7-alpine`                        | `redis:7-alpine` (password protected)      | Cache, queue, sessions (prod)                                                                                          |
| `mailpit`   | `axllent/mailpit`                       | —                                          | Catches outgoing mail, UI on :8025 ([checking email](#checking-email-mailpit))                                         |

## Image targets

```
base         php:8.5-fpm-trixie + bcmath intl pcntl pdo_mysql redis zip (+ built-in OPcache)
 └ tooling   + Composer + Node 22
    ├ development   php.ini-development, www-data remapped to your UID/GID
    └ vendor        composer install --no-dev + app source
       └ assets     npm ci && npm run build:ssr → public/build + bootstrap/ssr
 └ production       base + php.ini-production + OPcache + vendor + public/build + bootstrap/ssr
web          nginx:1.28-alpine + public/ from production
ssr          node:22 + production node_modules + bootstrap/ssr
```

Node and PHP always live in the same image for builds. The Wayfinder Vite plugin
runs `php artisan wayfinder:generate`, and its output (`resources/js/routes`,
`resources/js/actions`) is gitignored, so Vite cannot run without PHP.

---

## Local development

### First run

```bash
cp .env.example .env
```

Uncomment the **Docker (compose.yaml)** block at the bottom of `.env`. Those
values point Laravel at the `mysql`, `redis` and `mailpit` containers. They come
after the SQLite defaults, so they override them. Then:

```bash
docker compose run --rm app composer install        # first run builds the image
docker compose run --rm app php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate --seed   # demo accounts, see docs/README.md
```

Install Composer dependencies **before** `up`. The `vite` service runs
`php artisan wayfinder:generate` as soon as it starts, and that needs `vendor/`.

Open http://localhost (or `http://localhost:$APP_PORT`). The API docs
(Swagger) are at http://localhost/docs/api; they only exist in development.

On the first run, Compose prints `pull access denied for clinic-app` before it
builds the image. That warning is expected.

### Day to day

```bash
docker compose up -d                  # start
docker compose down                   # stop (keeps database volumes)
docker compose down -v                # stop and DELETE database, redis and node_modules volumes
docker compose logs -f vite           # follow a service's logs
docker compose exec app bash          # shell inside the PHP container
docker compose exec app php artisan migrate
docker compose exec app php artisan test --compact
docker compose exec app composer require vendor/package
docker compose exec vite npm install some-package
docker compose build app              # rebuild after changing the Dockerfile
```

Code changes on your machine show up immediately because the project directory
is bind-mounted. The `vite` service hot-reloads Vue/CSS, and `queue:listen`
reloads on every job.

### How the pieces fit in development

- **Source**: the project directory is mounted at `/var/www/html` in `app`,
  `vite`, `queue`, `scheduler` and (read-only) `web`.
- **`node_modules`** is a named Docker volume, not your host folder. Native
  binaries (Rollup, Tailwind Oxide, Lightning CSS) must be built for Linux, so
  the container keeps its own copy. `vendor/` is shared with the host because
  it contains only PHP.
- **File ownership**: the dev image runs as `www-data` remapped to UID/GID
  `1000`. On Linux, if your user is not 1000, set `UID` / `GID` in `.env` and
  run `docker compose build app`. On macOS this does not matter.
- **Vite / HMR**: the `vite` service listens on `0.0.0.0:5173` and writes
  `public/hot` = `http://localhost:5173`, so your browser loads assets straight
  from the dev server. This is enabled by `VITE_HMR_HOST` in `vite.config.ts`.
  Without that variable (running Vite on the host), the config behaves as
  before.
- **SSR in dev**: Inertia v3 server-renders through the Vite dev server. PHP
  reaches it at `http://vite:5173` (`INERTIA_SSR_HOT_URL`), while the browser
  uses `localhost:5173`.
- **Stopping Vite**: `docker/vite/dev.sh` removes `public/hot` when the
  container stops. A leftover `public/hot` makes Laravel load assets from a dev
  server that no longer exists. If you ever see a blank page with failed requests to
  `:5173`, delete `public/hot`.

### Why database settings live in `.env`, not `compose.yaml`

It is tempting to put `DB_HOST=mysql` and similar settings under `environment:`
in `compose.yaml`. **Don't.** Real environment variables end up in `$_SERVER`,
Laravel's `env()` reads `$_SERVER` first, and PHPUnit's `<env>` settings in
`phpunit.xml` never overwrite `$_SERVER`, even with `force="true"`. The test
suite would then run `RefreshDatabase` against the **dev MySQL database and
wipe it**.

Values in `.env` are loaded by dotenv, which does respect what `phpunit.xml`
sets. With connection settings in `.env`, tests always run on in-memory
SQLite. `compose.yaml` only sets Docker plumbing (`INERTIA_SSR_HOT_URL`,
`VITE_HMR_*`) that cannot affect tests.

### MySQL notes

- The `mysql` container creates the database and user from `DB_DATABASE`,
  `DB_USERNAME` and `DB_PASSWORD` **only the first time it starts with an empty
  volume**. If you change those values later, recreate the volume with
  `docker compose down -v`, which deletes all data.
- `DB_USERNAME` must not be `root`, because the MySQL image refuses to create a user named root.
- To open a SQL shell, run `docker compose exec mysql mysql -uclinic -psecret clinic`.

### Checking email (Mailpit)

The `mailpit` container catches every email the app sends in development.
Nothing reaches real inboxes. Open **http://localhost:8025** to read them.

Emails are sent from the queue, so the `queue` container must be running. For
example, to check the password-reset flow:

1. Open http://localhost/admin/forgot-password and enter a seeded email (e.g.
   `worker@example.com`).
2. The page answers immediately. The request only pushes
   `App\Notifications\QueuedResetPassword` onto the Redis queue.
3. `docker compose logs -f queue` shows the job `RUNNING` → `DONE`.
4. The "Reset your password" email appears in Mailpit within a few seconds.
   Its link opens `http://localhost/admin/reset-password/{token}`.

If no email arrives, check that `.env` has `MAIL_MAILER=smtp`,
`MAIL_HOST=mailpit`, `MAIL_PORT=1025` and `QUEUE_CONNECTION=redis` (the Docker
block), and that `docker compose ps queue` is up. After changing `.env`, run
`docker compose restart queue`: the worker reads configuration when it starts.

Production has no Mailpit. Set real SMTP (or another mailer) in `.env.production`.
Password-reset emails are still sent by the `queue` service.

### Ports

| Variable (in `.env`)             | Default | What                                                                                            |
| -------------------------------- | ------- | ----------------------------------------------------------------------------------------------- |
| `APP_PORT`                       | 80      | Web app. Also update `APP_URL` to match.                                                        |
| `VITE_PORT`                      | 5173    | Vite dev server                                                                                 |
| `FORWARD_DB_PORT`                | 3306    | MySQL, for GUI clients (user `clinic`, password `secret`; `root` uses the same password in dev) |
| `FORWARD_REDIS_PORT`             | 6379    | Redis                                                                                           |
| `FORWARD_MAILPIT_PORT`           | 1025    | Mailpit SMTP                                                                                    |
| `FORWARD_MAILPIT_DASHBOARD_PORT` | 8025    | Mailpit web UI                                                                                  |

### Running without Docker

Still supported. Keep the Docker block in `.env` commented out (SQLite), then
run `composer setup` once and `composer run dev`.

---

## Production

### What is different from development

- Code, `vendor/` (no dev packages) and compiled assets are **baked into the
  images**. Nothing is bind-mounted except the `storage` volume.
- Application code is owned by root and read-only to PHP. Only `storage/` and
  `bootstrap/cache/` are writable by `www-data`. Containers run as `www-data`.
- `php.ini-production`, with OPcache and JIT enabled and timestamp validation
  off (a code change means a new image).
- On start, the entrypoint runs `php artisan optimize` (config, route, event and
  view caches) using the container's real environment. The `app` service also
  runs `php artisan migrate --force` (`RUN_MIGRATIONS=true`), which also syncs
  the clinic permissions with the deployed API routes.
- No API docs: `dedoc/scramble` is a dev dependency, so `/docs/api` does not
  exist in the production image.
- No `.env` file in the image. Configuration comes from `.env.production` through
  `env_file`, and that file is gitignored.
- **Server-side rendering:** the `ssr` service runs Inertia's SSR server.
  Laravel posts each first page load to `INERTIA_SSR_URL`
  (`http://ssr:13714`) and returns fully rendered HTML, so search engines and
  link previews see the real content. The app image also contains the bundle;
  Laravel only calls the SSR server when it finds it. If the `ssr` service is
  down, pages still work: they fall back to client-side rendering.
- Logs go to stderr (`LOG_CHANNEL=stderr`). Read them with
  `docker compose logs`.
- Nginx serves `/build/*` with `Cache-Control: public, immutable` and gzip, and
  denies dotfiles.
- Sessions, cache and queue use Redis, which requires a password.

### Deploying

```bash
cp .env.production.example .env.production
# Fill in APP_KEY (php artisan key:generate --show), APP_URL, DB_PASSWORD, DB_ROOT_PASSWORD,
# REDIS_PASSWORD and the mail settings.

docker compose -f compose.prod.yaml --env-file .env.production up -d --build
```

`--env-file` matters. Compose uses that file both to fill `${...}` in
`compose.prod.yaml` (for example the MySQL passwords) and to pass variables
to the containers. Without it, Compose would read your development `.env`.

To update the app, pull the new code and run the same command. The images are
rebuilt, and the containers are recreated, migrated and re-optimized.

Useful commands (alias the prefix in your shell):

```bash
alias dcp='docker compose -f compose.prod.yaml --env-file .env.production'
dcp ps
dcp logs -f app queue
dcp exec app php artisan tinker
dcp exec app php artisan migrate:status
```

### Build-time vs runtime variables

`VITE_*` variables are compiled into the JavaScript bundle, so they are
**build arguments** (`VITE_APP_NAME` is passed through `compose.prod.yaml`).
Changing them requires a rebuild. Every other variable is read at runtime.
After changing one, restart the containers so `optimize` re-caches the config.

### Storage and uploads

`storage/` is a named volume (`storage`) shared by `app`, `queue` and
`scheduler`, and mounted read-only into `web`. `public/storage` is a relative
symlink to `../storage/app/public`, so files on the `public` disk are served by
Nginx directly.

### Pushing images to a registry

`compose.prod.yaml` tags images as `${APP_IMAGE:-clinic-app}:${APP_TAG:-latest}`
and `${APP_IMAGE}-web:${APP_TAG}`. To build in CI and deploy elsewhere:

```bash
APP_IMAGE=registry.example.com/clinic-app APP_TAG=$(git rev-parse --short HEAD) \
  docker compose -f compose.prod.yaml --env-file .env.production build
docker push registry.example.com/clinic-app:<tag>
docker push registry.example.com/clinic-app-web:<tag>
```

On the server, set the same `APP_IMAGE` / `APP_TAG` and run `up -d` without
`--build`.

### Not covered yet

- **TLS**: the `web` container listens on plain HTTP :80. Put it behind a
  TLS-terminating proxy or load balancer (Caddy, Traefik, a cloud LB). Keep
  `SESSION_SECURE_COOKIE=true` and set `APP_URL` to the `https://` URL.
  Proxies are already trusted (`$middleware->trustProxies(at: '*')` in
  `bootstrap/app.php`), so Laravel reads the real client IP and scheme.
- **Backups** of the `mysql-data` volume (e.g. a scheduled `mysqldump`).

---

## Deploying to Render

Render runs one image per service, so the Nginx/PHP-FPM split of
`compose.prod.yaml` does not translate. The **`render`** image target runs both
under supervisord and listens on `$PORT`. It is the last stage in the
`Dockerfile`, so Render builds it without being told which target to use.

`render.yaml` is a Blueprint describing three services, all from that one
image:

| Service            | Type   | Command                       |
| ------------------ | ------ | ----------------------------- |
| `clinic-app`       | web    | supervisord (Nginx + PHP-FPM) |
| `clinic-queue`     | worker | `php artisan queue:work`      |
| `clinic-scheduler` | worker | `php artisan schedule:work`   |

To start on a single service, delete the two workers and set `RUN_QUEUE=true`
and `RUN_SCHEDULER=true` on the web service; supervisord will run all four
processes. Give the scheduler its own service before scaling the web service
past one instance, or every scheduled command runs once per instance.

### PostgreSQL instead of MySQL

The hosted database (Neon, Render Postgres, Supabase) is PostgreSQL, so
`DB_CONNECTION=pgsql`. Both PDO drivers are in the image. Two differences bit
the application code and are now handled, but they are worth knowing when
writing new queries:

- **`LIKE` is case-sensitive in PostgreSQL** and case-insensitive in MySQL.
  Every search in the app uses `whereLike()` / `orWhereLike()`, which Laravel
  compiles to `ILIKE` on PostgreSQL and `LIKE` on MySQL. Do not write
  `where(..., 'like', ...)` by hand.
- **NULLs sort first in MySQL and last in PostgreSQL.** Ordering by a nullable
  column therefore groups rows differently on each. Where the NULL group has
  to come first, the order is written out: `orderByRaw('settled_at is null
  desc')`. The same trick is used for `clinic_user_id` on discounts and
  `start_time` on daily sessions.

The test suite runs on SQLite by default. Run it against PostgreSQL before
deploying a schema or query change:

```bash
docker run -d --name pg --network clinic-app_default \
    -e POSTGRES_PASSWORD=secret -e POSTGRES_USER=clinic -e POSTGRES_DB=clinic postgres:17-alpine

docker compose exec -e DB_CONNECTION=pgsql -e DB_HOST=pg -e DB_PORT=5432 \
    -e DB_DATABASE=clinic -e DB_USERNAME=clinic -e DB_PASSWORD=secret \
    app vendor/bin/pest --compact
```

### Neon

- Use the **pooled** host (the one containing `-pooler`). Each PHP-FPM worker
  holds its own connection, and the pooler is what keeps that inside Neon's
  connection limit.
- `DB_SSLMODE=require`; Neon refuses plaintext.
- A Neon instance that has scaled to zero takes a few seconds to wake, so the
  first request after an idle period is slow. Paid plans can disable that.

### What is different from the Compose production stack

- **No Redis.** Cache, sessions and the queue run on PostgreSQL
  (`CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` = `database`). Add a
  Render Key Value instance and switch them to `redis` when the queue warrants
  it.
- **No SSR service.** `INERTIA_SSR_ENABLED=false`, so pages render in the
  browser. The landing page's SEO tags are unaffected — they are rendered by
  the server from `PublicSiteController::home()`, not by `<Head>`. Deploy the
  `ssr` target as a private service and set `INERTIA_SSR_ENABLED=true` with
  `INERTIA_SSR_URL=http://<service>:13714` to turn it back on.
- **No persistent disk.** Nothing durable is written to `storage/`: uploads to
  the patient form importer are parsed in memory and discarded, PDFs are
  generated per request, and logs go to stderr. Sessions and cache are in the
  database. A restart loses nothing.
- **Migrations run on deploy**, from the web service's entrypoint
  (`RUN_MIGRATIONS=true`). It uses `migrate --force --isolated`, which takes a
  cache lock so concurrent deploys and multiple instances cannot race. That
  lock needs `CACHE_STORE=database` or `redis` — not `array` or `file`. The
  very first deploy migrates without the lock, because with the database cache
  store the lock lives in a table the migrations have not created yet.
- **The first super admin is created for you.** Registration is disabled and
  every other account is made from inside the app, so a new installation has
  nobody who can sign in; the demo seeder cannot fill that gap because it
  needs Faker, a dev dependency. Set `SUPER_ADMIN_EMAIL` and
  `SUPER_ADMIN_PASSWORD` (and optionally `SUPER_ADMIN_NAME`), and the account
  is created by whichever of these happens first:

  1. the entrypoint, which runs `clinic:create-super-admin --if-missing` when
     `RUN_MIGRATIONS=true`; or
  2. the **first visit to the landing page**, handled by
     `App\Actions\Auth\ProvisionInitialAdmin`.

  The second exists for hosts where the deploy log is the only thing you can
  see and the entrypoint's attempt is easy to miss. Both stop as soon as one
  super admin exists, so they cannot produce two accounts, and a lock means
  simultaneous first visits cannot either. Change the password from
  Settings → Security afterwards and remove the variables. On a host with
  shell access, run the command by hand and leave the variables unset.

  Two things that make this look broken when it is not:

  - **It will not replace an existing super admin.** If *any* super admin
     exists the account you configured is skipped, and the deploy log names
     both: `A super admin already exists (someone@example.com)` followed by
     `you@example.com was NOT created`.
  - **`SUPER_ADMIN_PASSWORD` must satisfy the production policy**, which
     `AppServiceProvider` sets to 12+ characters with mixed case, numbers,
     symbols, and not present in a known breach. A password that fails is
     reported to the log — never to the visitor — and retried at most once
     every five minutes, so a misconfigured deployment cannot turn its own
     home page into an outbound request amplifier.

---

## Troubleshooting

| Symptom                                                              | Fix                                                                                                                                                             |
| -------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Bind for 0.0.0.0:80 failed: port is already allocated`              | Another service uses the port. Set `APP_PORT` (and `APP_URL`) in `.env`. Same for `FORWARD_DB_PORT`, etc.                                                       |
| Blank page, browser requests to `localhost:5173` fail                | Vite is not running but `public/hot` exists. Run `docker compose up -d vite` or delete `public/hot`.                                                            |
| `Unable to locate file in Vite manifest`                             | Vite is not running and there is no build. Start the `vite` service, or run `docker compose exec vite npm run build`.                                           |
| `npm` errors with `EACCES` in `node_modules`                         | The `node_modules` volume was created with the wrong owner. Run `docker compose down`, `docker volume rm clinic-app_node_modules`, then `docker compose up -d`. |
| HMR does not pick up changes                                         | Make sure you edit files inside the project directory, which is the one that is bind-mounted. Restart the service with `docker compose restart vite`.           |
| Permission denied writing `storage/` (Linux hosts)                   | Set `UID`/`GID` in `.env` to your `id -u` / `id -g`, then run `docker compose build app && docker compose up -d`.                                               |
| `vite` exits right after starting, with an artisan / Wayfinder error | `vendor/` is missing. Run `docker compose exec app composer install`, then `docker compose up -d vite`.                                                         |
| Tests touching the dev database                                      | Check that nobody added `DB_*` keys to `environment:` in `compose.yaml` (see above).                                                                            |
