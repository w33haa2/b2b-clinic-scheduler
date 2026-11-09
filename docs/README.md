# Clinic App — Developer Documentation

A multi-clinic (SaaS) booking and billing system: clinics manage patients,
book sessions with their staff, bill each patient and issue invoices. Laravel
13 + Inertia v3 + Vue 3, with a JSON API under `/api`, a public site at `/` and
a staff admin area at `/admin`. Development and production both run in Docker.

## Contents

| Document                           | What it covers                                                                    |
| ---------------------------------- | --------------------------------------------------------------------------------- |
| [architecture.md](architecture.md) | Stack, directory layout, routing (public vs admin), authentication, testing       |
| [domain-model.md](domain-model.md) | Tables and models, business rules, and how the original table spec was corrected  |
| [api.md](api.md)                   | API authentication, roles and permissions, every endpoint, Swagger                |
| [activity-log.md](activity-log.md) | What is logged, clinic vs platform level, filters, adding logging to new features |
| [frontend.md](frontend.md)         | Vue pages and data loading, schedule calendar, animations, shadcn-vue, Pinia      |
| [docker.md](docker.md)             | Local Docker workflow, services, Mailpit, production images, troubleshooting      |

## Quick start (Docker)

```bash
cp .env.example .env
# Uncomment the "Docker (compose.yaml)" block at the bottom of .env

docker compose run --rm app composer install         # builds the image on first run
docker compose run --rm app php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate --seed    # demo accounts below
```

| URL                       | What                                                   |
| ------------------------- | ------------------------------------------------------ |
| http://localhost          | Public landing page (theme toggle top right)           |
| http://localhost/admin    | App: clinic workspace or internal tool (after login)   |
| http://localhost:8025     | Mailpit (captured outgoing mail, e.g. password resets) |
| http://localhost/docs/api | API docs (Swagger), development only                   |

Seeded accounts (password `password` for all):

| Email                    | What                                                                                        |
| ------------------------ | ------------------------------------------------------------------------------------------- |
| `superadmin@example.com` | Super admin: internal tool (clinics, their users and roles, super admins)                   |
| `admin@example.com`      | Clinic Admin of "Demo Clinic": everything, including staff and roles                        |
| `worker@example.com`     | Clinic Worker of "Demo Clinic": schedule, daily sessions, patients, billing items, payments |

If port 80 or 3306 is taken on your machine, set `APP_PORT` / `FORWARD_DB_PORT`
in `.env` (see [docker.md](docker.md#ports)).

## Everyday commands

```bash
docker compose exec app php artisan test --compact   # tests (in-memory SQLite, never the dev DB)
docker compose exec app php artisan <command>        # any artisan command
docker compose exec app php artisan clinic:sync-permissions   # after adding clinic API routes (migrate also does it)
docker compose exec app php artisan clinic:schedule-daily-sessions   # schedule daily sessions' upcoming days (the scheduler runs it hourly)
docker compose exec app php artisan clinic:advance-daily-session-days   # start/finish today's daily-session days (the scheduler runs it every minute)
docker compose exec vite npm run check               # frontend lint + format check
docker compose exec vite npm run types:check         # vue-tsc
docker compose logs -f app vite queue                # follow logs
```
