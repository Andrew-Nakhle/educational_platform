# Deploying the backend on Railway

A step-by-step guide for the Laravel API (`educational_platform`) on [Railway](https://railway.com).
It assumes the code of `feature/security-and-deploy-docs` (or `main` after it is merged).
Every value below is a placeholder: **never paste a real key, password or token into this file,
a commit, an issue or a chat.**

Contents: [1. Services](#1-services) · [2. Environment variables](#2-environment-variables) ·
[3. Build, ffmpeg and release](#3-build-ffmpeg-and-the-release-command) ·
[4. Queue worker](#4-queue-worker) · [5. Scheduler](#5-scheduler) · [6. Stripe webhook](#6-stripe-webhook) ·
[7. Database backup and migrations](#7-database-backup-and-migrations) ·
[8. Post-deploy checklist](#8-post-deploy-checklist) · [9. Security checklist](#9-security-checklist) ·
[10. Rollback](#10-rollback)

---

## 1. Services

Create one Railway **project** with:

| Service | Source | Start command | Notes |
|---|---|---|---|
| `api` | this GitHub repository | default (the PHP builder serves `public/`), or `php artisan serve --host=0.0.0.0 --port=$PORT` | Public domain, e.g. `api.example.test` |
| `worker` | the **same** repository | `php artisan queue:work --tries=3 --timeout=3600 --sleep=3` | No public domain. Converts lesson videos (section 4) |
| `scheduler` | the **same** repository | `php artisan schedule:work` | No public domain. Section 5 |
| `MySQL` (or `PostgreSQL`) | Railway database plugin | — | Its variables are referenced by the three services |

All three app services use the **same variables** (section 2). The easiest way is to define them once as
shared variables in the project and reference them from each service.

## 2. Environment variables

Set these in Railway → service → **Variables** (or as shared variables). Names only; the values are yours.

### Application

| Variable | Value |
|---|---|
| `APP_NAME` | e.g. `Fahhemni` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` (never `true` in production: it shows stack traces and settings) |
| `APP_KEY` | generate once locally with `php artisan key:generate --show` and paste it; keep it stable (changing it invalidates encrypted data) |
| `APP_URL` | the public URL of the `api` service, e.g. `https://api.example.test` |
| `FRONTEND_URL` | the frontend origin, e.g. `https://app.example.test` (Stripe return pages and the password-reset link use it) |
| `LOG_CHANNEL` | `stderr` (Railway collects stdout/stderr) |
| `LOG_LEVEL` | `info` (or `warning`) |

### Security

| Variable | Value |
|---|---|
| `CORS_ALLOWED_ORIGINS` | **Required in production.** The frontend origin(s), comma separated, no trailing slash, e.g. `https://app.example.test`. Empty means every origin (`*`), which is only acceptable locally |
| `SANCTUM_TOKEN_EXPIRATION_MINUTES` | e.g. `10080` (7 days). Empty means tokens never expire. An expired token gets `401 {"message":"Unauthenticated."}` and the frontend shows "Your session has expired" |

### Database

Reference the database plugin's variables (Railway shows them under the plugin → **Variables**):

| Variable | Value |
|---|---|
| `DB_CONNECTION` | `mysql` (or `pgsql`) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | references to the plugin's host, port, database, user and password variables |

### Sessions, cache and queue

| Variable | Value |
|---|---|
| `SESSION_DRIVER` | `database` |
| `CACHE_STORE` | `database` (the rate limits use the cache; with several `api` replicas the cache must be shared: `database` or `redis`, never `array`/`file`) |
| `QUEUE_CONNECTION` | `database` (the `jobs` table already exists) |
| `DB_QUEUE_RETRY_AFTER` | `3700`: must be **longer** than the worker's `--timeout` (3600), or a long video is started twice |

### Files (Backblaze B2, S3-compatible)

| Variable | Value |
|---|---|
| `UPLOAD_DISK` | `b2` |
| `AWS_ACCESS_KEY_ID` | the B2 application key **ID** |
| `AWS_SECRET_ACCESS_KEY` | the B2 application key |
| `AWS_DEFAULT_REGION` | the bucket's region (from its S3 endpoint) |
| `AWS_BUCKET` | the bucket name |
| `AWS_ENDPOINT` | the bucket's S3 endpoint URL |
| `SIGNED_URL_MINUTES` | `1000` (lifetime of the thumbnail, avatar and certificate links; lesson video links stay at 30 minutes) |
| `THUMBNAIL_MAX_KB` | `5120` (5 MB) |
| `VIDEO_MAX_KB` | `2097152` (2 GB). PHP's `upload_max_filesize` and `post_max_size` must be at least as large (section 3) |

Use a B2 key restricted to this one bucket.

### Video processing

| Variable | Value |
|---|---|
| `FFMPEG_PATH` | **Required.** The path of ffmpeg in the container, e.g. `/usr/bin/ffmpeg` (check with `which ffmpeg`). The built-in default is a Windows path and will not work on Railway |
| `FFPROBE_PATH` | e.g. `/usr/bin/ffprobe` |

### Stripe

| Variable | Value |
|---|---|
| `STRIPE_SECRET` | the secret key (`sk_live_…` in production, `sk_test_…` for a staging project). Empty: paid enrollments answer `503 Payments are temporarily unavailable`, free courses still work |
| `STRIPE_WEBHOOK_SECRET` | the signing secret of the webhook endpoint (section 6) |

### Mail (password reset)

| Variable | Value |
|---|---|
| `MAIL_MAILER` | `smtp` (or your provider's mailer) |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME` | your mail provider's settings |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | the sender |

### Never set in production

| Variable | Why |
|---|---|
| `DEMO_SEED_ALLOW` | It lets `demo:seed` run in production. Leave it unset |
| `DEMO_SEED_PASSWORD` | Only for local demo data |
| `ADMIN_NEW_PASSWORD` | Only for a one-off `admin:set-password` run (section 9); delete it right after |

## 3. Build, ffmpeg and the release command

1. **Builder.** Railway detects the Laravel app (`composer.json`) and runs `composer install --no-dev`.
2. **ffmpeg.** The worker needs `ffmpeg` and `ffprobe`. Add the system package to the build of the
   `worker` service (it doesn't hurt on the others):
   - Railpack builder: variable `RAILPACK_DEPLOY_APT_PACKAGES=ffmpeg`;
   - Nixpacks builder: variable `NIXPACKS_APT_PKGS=ffmpeg` (or `NIXPACKS_PKGS=ffmpeg`).

   The variable names belong to the builder and may change; check the builder's current documentation.
   After the deploy, open a shell on the worker (`railway ssh`, or `railway run` locally against it) and run
   `which ffmpeg ffprobe`, then set `FFMPEG_PATH` / `FFPROBE_PATH` to those paths.
3. **PHP upload limits.** For 2 GB videos set `upload_max_filesize = 2048M`, `post_max_size = 2048M`,
   `memory_limit` and `max_execution_time` high enough (a `php.ini`/`.user.ini` supported by the builder,
   or the builder's PHP settings). Otherwise PHP drops the upload before Laravel sees it.
4. **Release (pre-deploy) command** of the `api` service — Railway → Settings → Deploy → *Pre-deploy command*:

   ```sh
   php artisan migrate --force
   ```

   It runs once per deploy, before the new version receives traffic. **Make a database backup first** when the
   deploy adds migrations (section 7). Do not add it to the `worker` or `scheduler` services (one runner is enough).
5. **Caches** (optional, after `migrate`): `php artisan config:cache && php artisan route:cache`.
   With a config cache, `env()` is only read from real environment variables, which is how Railway provides them.

## 4. Queue worker

- Service `worker`, start command:

  ```sh
  php artisan queue:work --tries=3 --timeout=3600 --sleep=3
  ```

- `ProcessLessonVideo` converts an upload to HLS with ffmpeg and uploads the files to B2. It sets no timeout of its
  own, so the worker's `--timeout` applies: the default (60 s) would kill every video longer than a few minutes.
  Keep `--timeout` below `DB_QUEUE_RETRY_AFTER` (section 2).
- After each deploy the worker restarts with the new code (Railway replaces the container). If you run it some
  other way, run `php artisan queue:restart` after deploying.
- Failed jobs are in the `failed_jobs` table: `php artisan queue:failed`, retry with `php artisan queue:retry all`.
  Instructors can also retry a failed video (`POST /api/instructor/lessons/{id}/video/retry`).

## 5. Scheduler

`routes/console.php` schedules:

| Command | When | What |
|---|---|---|
| `payments:expire-unpaid` | every minute (no overlap) | Marks Stripe payments that were never paid as expired or failed, so the student can buy again |
| `sanctum:prune-expired --hours=24` | daily | Deletes tokens that expired more than 24 hours ago (nothing to do while `SANCTUM_TOKEN_EXPIRATION_MINUTES` is empty) |

Run **one** of:

- a `scheduler` service with the start command `php artisan schedule:work`, or
- a Railway **cron** service with the schedule `* * * * *` and the command `php artisan schedule:run`.

Only one scheduler must run; two would run each command twice.

## 6. Stripe webhook

1. Stripe Dashboard → Developers → **Webhooks** → *Add endpoint*.
2. Endpoint URL: `https://<api-domain>/api/payments/stripe/webhook`, e.g. `https://api.example.test/api/payments/stripe/webhook`.
3. Events: **`checkout.session.completed`** (required: it enrolls the student after a web payment). The controller
   also handles, and you should add: `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`,
   `checkout.session.expired`, and for the mobile flow `payment_intent.succeeded`, `payment_intent.payment_failed`,
   `payment_intent.canceled`.
4. Copy the endpoint's **signing secret** into `STRIPE_WEBHOOK_SECRET`. A wrong secret answers `400 Invalid Stripe webhook.`
5. Test: Stripe → the endpoint → *Send test event*, or a test-mode purchase; the payment becomes `paid` and the course
   appears in the student's My Courses. The webhook route has no CSRF and no auth; its security is the signature.

## 7. Database backup and migrations

> **Mandatory: back up the production database before every deploy that runs new migrations.**
> Railway → the database → **Backups** → create a backup (or `mysqldump` / `pg_dump` to a safe place outside the
> repository). Write down the backup's name and time. Restore it if a migration fails halfway.

Migrations added on top of upstream `main` (`170d889`), in the order they run:

| # | Migration | What it does | Reversible |
|---|---|---|---|
| 1 | `2026_10_26_100000_add_reject_reason_to_courses_and_instructor_profiles` | Adds nullable `reject_reason` to `courses` and `instructor_profiles` | Yes (drops the columns) |
| 2 | `2026_10_26_100100_create_contact_messages_table` | Creates `contact_messages` | Yes (drops the table) |
| 3 | `2026_10_27_100000_make_instructor_profiles_avatar_nullable` | `instructor_profiles.avatar` becomes nullable | Yes (`NULL` avatars become `''` first) |

Two upstream migrations were **made idempotent** (same file names, so a database that already ran them is unaffected):

- `2026_10_24_124020_create_password_reset_tokens_table` skips when the table exists (the default users migration
  already creates it; on a fresh database it failed), and its `down()` is a no-op.
- `2026_10_23_201648_create_lesson_progress_table` skips when the table exists.

Check what will run before deploying: `php artisan migrate:status` against the production database (or a copy).
Never run `migrate:fresh`, `migrate:reset`, `db:wipe` or `db:seed` on production.

## 8. Post-deploy checklist

- [ ] `GET https://<api-domain>/up` answers 200.
- [ ] `php artisan migrate:status` shows every migration as *Ran*.
- [ ] `GET /api/categories` from the browser on the frontend origin works, and the response has
      `Access-Control-Allow-Origin: <frontend origin>` (not `*`).
- [ ] Log in as a student, an instructor and the admin from the frontend; `GET /api/auth/profile` works.
- [ ] A wrong password eleven times in a minute answers `429 Too Many Attempts.` (rate limits active).
- [ ] Upload a short lesson video: the worker logs `ProcessLessonVideo started` / `completed` and the lesson plays.
- [ ] Thumbnails and avatars load (signed B2 links).
- [ ] A test-mode purchase reaches `paid` through the webhook; an abandoned checkout is cleaned up by
      `payments:expire-unpaid` (scheduler logs).
- [ ] "Forgot password" sends a mail whose link opens `<FRONTEND_URL>/reset-password?...`.
- [ ] Logs show no stack traces in responses (`APP_DEBUG=false`) and no keys or tokens.

## 9. Security checklist

Do these **once, right after the first production deploy**, and again whenever a secret may have leaked.

- [ ] **Revoke old tokens:** `php artisan tokens:revoke-all --force` (in a Railway shell; `--force` because there is no
      prompt there). Every user logs in again; tokens created during development or shared in files stop working.
- [ ] **Change the admin password:** `php artisan admin:set-password admin@example.test` (your admin's email). It asks
      for the password twice with a hidden prompt; without a terminal, set `ADMIN_NEW_PASSWORD` on the service, run the
      command, then **delete the variable**. It needs at least 8 characters, never prints the password and revokes that
      admin's tokens.
- [ ] **Rotate every secret that was ever committed, pasted or shared** (in a file, a Postman collection, a chat, a
      screenshot): `APP_KEY` only if it leaked (rotating it logs out encrypted sessions), the database password,
      `STRIPE_SECRET` (roll the key in Stripe), `STRIPE_WEBHOOK_SECRET` (roll the endpoint secret), the B2 application
      key, the mail password. Removing a secret from git history does not make it safe again; rotating it does.
- [ ] **Keep the repository private again** (GitHub → Settings → General → Danger Zone → Change visibility) if it was
      made public for review, and review who has access.
- [ ] **Never run `demo:seed` in production.** It refuses while `APP_ENV=production`; keep `DEMO_SEED_ALLOW` unset so it
      stays that way.
- [ ] `APP_DEBUG=false`, `CORS_ALLOWED_ORIGINS` set, `SANCTUM_TOKEN_EXPIRATION_MINUTES` set.
- [ ] Stripe in live mode only on the production project; test keys on staging.

## 10. Rollback

1. Railway → the `api` service → **Deployments** → the previous deployment → *Redeploy*. Do the same for `worker` and
   `scheduler` so all three run the same version.
2. The three new migrations only **add** a column, a table and a nullable change, so older code keeps working with them
   and **no rollback of the schema is needed** for a code rollback.
3. If a migration itself failed or damaged data: put the app in maintenance (`php artisan down`), restore the backup from
   section 7, redeploy the previous version, then `php artisan up`. `php artisan migrate:rollback --step=N` is possible
   for these three migrations but restoring the backup is the safer path.
4. The rate limits and token expiry are configuration: to relax them in an emergency, empty
   `SANCTUM_TOKEN_EXPIRATION_MINUTES` (tokens never expire again) or redeploy the previous version; CORS goes back to `*`
   by emptying `CORS_ALLOWED_ORIGINS` (temporary only).
