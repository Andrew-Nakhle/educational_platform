<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Local development on Windows

Requirements: PHP 8.3+ (with the `pdo_sqlite`, `fileinfo` and `gd` extensions enabled in `php.ini`) and Composer. Run the commands below in PowerShell from the project root.

1. Install dependencies:

    ```powershell
    composer install
    ```

2. Create the environment file and application key:

    ```powershell
    Copy-Item .env.example .env
    php artisan key:generate
    ```

    The example is set up for local development: SQLite, `UPLOAD_DISK=local_uploads` (files are stored in `storage/app/private/uploads` instead of Backblaze B2) and `QUEUE_CONNECTION=sync`.

3. Create the SQLite database, run the migrations and seed roles and demo users:

    ```powershell
    New-Item database\database.sqlite -ItemType File
    php artisan migrate
    php artisan db:seed
    ```

4. Start the API at `http://localhost:8000` (all endpoints are under `/api`):

    ```powershell
    php artisan serve
    ```

5. Optional: payment expiry. Unpaid Stripe payments are expired by a scheduled command; run the scheduler in another terminal:

    ```powershell
    php artisan schedule:work
    ```

### Lesson videos (optional)

Uploaded lesson videos are converted to HLS with ffmpeg by the `ProcessLessonVideo` job.

1. Download an ffmpeg build for Windows (for example from https://www.gyan.dev/ffmpeg/builds/), extract it, and point `.env` at the binaries using forward slashes:

    ```dotenv
    FFMPEG_PATH=C:/ffmpeg/bin/ffmpeg.exe
    FFPROBE_PATH=C:/ffmpeg/bin/ffprobe.exe
    ```

2. With `QUEUE_CONNECTION=sync` the video is processed during the upload request. To process in the background instead, set `QUEUE_CONNECTION=database` and run a worker:

    ```powershell
    php artisan queue:work
    ```

### Stripe payments (optional)

Paid enrollment (`POST /api/student/courses/{id}/enroll`) needs Stripe test keys.

1. Set `STRIPE_SECRET` to your test secret key (`sk_test_...`) in `.env`. `StudentController` cannot be resolved without it, so the student and catalog endpoints return 500 until it is set to some value.
2. Install the [Stripe CLI](https://docs.stripe.com/stripe-cli), log in and forward webhooks to the API:

    ```powershell
    stripe login
    stripe listen --forward-to localhost:8000/api/payments/stripe/webhook
    ```

3. Copy the `whsec_...` signing secret that `stripe listen` prints into `STRIPE_WEBHOOK_SECRET`, then run `php artisan config:clear`.

Checkout redirects go to `FRONTEND_URL` (default `http://localhost:5173`).

### Tests

```powershell
php artisan test
```

Tests use in-memory SQLite, the `sync` queue and a faked `local_uploads` disk, so they need no external services.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
