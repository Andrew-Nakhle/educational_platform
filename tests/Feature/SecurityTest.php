<?php

namespace Tests\Feature;

use App\Console\Commands\SetAdminPassword;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;
use App\Models\Payment;
use App\Support\Redact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * feature/security-and-deploy-docs: CORS origins, token expiry, the two
 * security commands, rate limits and secrets in responses and logs.
 * Every account, origin, key and password here is made up.
 */
class SecurityTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    private const FRONTEND = 'https://app.example.test';

    private const OTHER = 'https://other.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    protected function tearDown(): void
    {
        foreach (['CORS_ALLOWED_ORIGINS', 'SANCTUM_TOKEN_EXPIRATION_MINUTES', 'ADMIN_NEW_PASSWORD'] as $name) {
            $this->clearEnv($name);
        }

        parent::tearDown();
    }

    private function setEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function clearEnv(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    /** The config file as it reads the current environment. */
    private function configFile(string $name): array
    {
        return require config_path("{$name}.php");
    }

    // ---- 1. CORS ---------------------------------------------------------------------

    public function test_cors_allows_every_origin_when_the_env_is_unset(): void
    {
        $this->clearEnv('CORS_ALLOWED_ORIGINS');
        $this->assertSame(['*'], $this->configFile('cors')['allowed_origins']);

        $this->getJson('/api/categories', ['Origin' => self::OTHER])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_cors_origins_are_read_from_the_comma_separated_env(): void
    {
        $this->setEnv('CORS_ALLOWED_ORIGINS', ' https://app.example.test/ , https://admin.example.test,, ');

        $this->assertSame(
            ['https://app.example.test', 'https://admin.example.test'],
            $this->configFile('cors')['allowed_origins']
        );
    }

    public function test_only_the_configured_origin_gets_cors_headers(): void
    {
        $this->setEnv('CORS_ALLOWED_ORIGINS', self::FRONTEND);
        config(['cors' => $this->configFile('cors')]);

        $this->getJson('/api/categories', ['Origin' => self::FRONTEND])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND);

        // With a single allowed origin the CORS service always answers with
        // that origin, so another site is never allowed (its browser blocks it).
        $other = $this->getJson('/api/categories', ['Origin' => self::OTHER])->assertOk();
        $this->assertNotSame(self::OTHER, $other->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $other->headers->get('Access-Control-Allow-Origin'));

        // Preflight
        $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => self::FRONTEND,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeader('Access-Control-Allow-Origin', self::FRONTEND);
    }

    public function test_with_several_origins_an_unlisted_origin_gets_no_cors_header(): void
    {
        $this->setEnv('CORS_ALLOWED_ORIGINS', self::FRONTEND.',https://admin.example.test');
        config(['cors' => $this->configFile('cors')]);

        $this->getJson('/api/categories', ['Origin' => 'https://admin.example.test'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://admin.example.test');

        $this->getJson('/api/categories', ['Origin' => self::OTHER])
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => self::OTHER,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    // ---- 2. Token expiry -------------------------------------------------------------

    public function test_token_expiration_is_read_from_the_env(): void
    {
        $this->clearEnv('SANCTUM_TOKEN_EXPIRATION_MINUTES');
        $this->assertNull($this->configFile('sanctum')['expiration']);

        $this->setEnv('SANCTUM_TOKEN_EXPIRATION_MINUTES', '');
        $this->assertNull($this->configFile('sanctum')['expiration']);

        $this->setEnv('SANCTUM_TOKEN_EXPIRATION_MINUTES', '1440');
        $this->assertSame(1440, $this->configFile('sanctum')['expiration']);
    }

    public function test_an_expired_token_gets_a_401_json(): void
    {
        config(['sanctum.expiration' => 60]);
        $token = $this->tokenFor($this->makeStudent());

        $this->withToken($token)->getJson('/api/auth/profile')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->travel(61)->minutes();

        $this->withToken($token)->get('/api/auth/profile')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_without_an_expiration_old_tokens_keep_working(): void
    {
        config(['sanctum.expiration' => null]);
        $token = $this->tokenFor($this->makeStudent());

        $this->travel(400)->days();

        $this->withToken($token)->getJson('/api/auth/profile')->assertOk();
    }

    // ---- 3. tokens:revoke-all ----------------------------------------------------------

    public function test_tokens_revoke_all_asks_first_and_deletes_every_token(): void
    {
        $this->tokenFor($this->makeStudent());
        $this->tokenFor($this->makeInstructor());
        $this->tokenFor($this->makeAdmin());

        $this->artisan('tokens:revoke-all')
            ->expectsConfirmation('Delete all 3 personal access tokens? Every user will have to log in again.', 'no')
            ->assertFailed();
        $this->assertSame(3, PersonalAccessToken::count());

        $this->artisan('tokens:revoke-all')
            ->expectsConfirmation('Delete all 3 personal access tokens? Every user will have to log in again.', 'yes')
            ->expectsOutput('Revoked 3 personal access tokens.')
            ->assertSuccessful();
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_tokens_revoke_all_with_force_does_not_ask_and_the_old_token_is_refused(): void
    {
        $token = $this->tokenFor($this->makeStudent());

        $this->artisan('tokens:revoke-all', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, PersonalAccessToken::count());
        $this->withToken($token)->getJson('/api/auth/profile')->assertStatus(401);
    }

    public function test_tokens_revoke_all_with_no_tokens(): void
    {
        $this->artisan('tokens:revoke-all')
            ->expectsOutput('There are no tokens to revoke.')
            ->assertSuccessful();
    }

    // ---- 4. admin:set-password ---------------------------------------------------------

    public function test_set_admin_password_from_the_hidden_prompt_revokes_tokens_and_never_prints_it(): void
    {
        $admin = $this->makeAdmin(['email' => 'boss@example.test']);
        $this->tokenFor($admin);
        $this->tokenFor($admin);
        $other = $this->makeStudent();
        $this->tokenFor($other);
        $new = 'brand-new-admin-pass';

        $this->artisan('admin:set-password', ['email' => 'Boss@Example.test'])
            ->expectsQuestion('New password (at least 8 characters)', $new)
            ->expectsQuestion('Repeat the new password', $new)
            ->expectsOutput('The password was changed and 2 token(s) of this admin were revoked.')
            ->doesntExpectOutputToContain($new)
            ->assertSuccessful();

        $this->assertTrue(Hash::check($new, $admin->fresh()->password));
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertSame(1, $other->tokens()->count()); // only that admin's

        $this->postJson('/api/auth/login', ['email' => 'boss@example.test', 'password' => $new])->assertOk();
    }

    public function test_set_admin_password_reads_admin_new_password(): void
    {
        $admin = $this->makeAdmin(['email' => 'boss@example.test']);
        $this->setEnv('ADMIN_NEW_PASSWORD', 'env-admin-password');

        $this->artisan('admin:set-password', ['email' => 'boss@example.test'])
            ->expectsOutput('Using the password from ADMIN_NEW_PASSWORD.')
            ->doesntExpectOutputToContain('env-admin-password')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('env-admin-password', $admin->fresh()->password));
    }

    public function test_set_admin_password_refuses_short_or_mismatched_passwords(): void
    {
        $admin = $this->makeAdmin(['email' => 'boss@example.test']);
        $before = $admin->password;
        $this->tokenFor($admin);

        $this->artisan('admin:set-password', ['email' => 'boss@example.test'])
            ->expectsQuestion('New password (at least 8 characters)', 'short')
            ->expectsOutput('The password must be at least '.SetAdminPassword::MIN_LENGTH.' characters. Nothing was changed.')
            ->assertFailed();

        $this->artisan('admin:set-password', ['email' => 'boss@example.test'])
            ->expectsQuestion('New password (at least 8 characters)', 'long-enough-one')
            ->expectsQuestion('Repeat the new password', 'long-enough-two')
            ->expectsOutput('The passwords do not match. Nothing was changed.')
            ->assertFailed();

        $this->setEnv('ADMIN_NEW_PASSWORD', 'tiny');
        $this->artisan('admin:set-password', ['email' => 'boss@example.test'])->assertFailed();

        $this->assertSame($before, $admin->fresh()->password);
        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_set_admin_password_only_for_an_existing_admin(): void
    {
        $student = $this->makeStudent(['email' => 'learner@example.test']);
        $before = $student->password;

        foreach (['learner@example.test', 'nobody@example.test'] as $email) {
            $this->artisan('admin:set-password', ['email' => $email])
                ->expectsOutput('No admin account has this email.')
                ->assertFailed();
        }

        $this->assertSame($before, $student->fresh()->password);
    }

    // ---- 5. Rate limits ------------------------------------------------------------------

    public function test_login_is_limited_to_10_per_minute_with_a_json_429(): void
    {
        $this->makeStudent(['email' => 'learner@example.test']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'learner@example.test', 'password' => 'wrong-password'])
                ->assertStatus(401);
        }

        $this->post('/api/auth/login', ['email' => 'learner@example.test', 'password' => 'wrong-password'])
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Retry-After')
            ->assertJson(['message' => 'Too Many Attempts.']);

        $this->travel(61)->seconds();
        $this->postJson('/api/auth/login', ['email' => 'learner@example.test', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_forgot_password_is_limited_to_5_per_minute_with_a_json_429(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => "unknown{$i}@example.test"])->assertOk();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'unknown6@example.test'])
            ->assertStatus(429)
            ->assertJson(['message' => 'Too Many Attempts.']);
    }

    public function test_register_reset_password_and_contact_are_limited_too(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->mapWithKeys(fn ($route) => [$route->methods()[0].' '.$route->uri() => $route->gatherMiddleware()]);

        $this->assertContains('throttle:10,1', $routes['POST api/auth/login']);
        foreach (['register', 'register-instructor', 'forgot-password', 'reset-password'] as $path) {
            $this->assertContains('throttle:5,1', $routes["POST api/auth/{$path}"], $path);
        }
        $this->assertContains('throttle:5,1', $routes['POST api/contact']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/reset-password', [])->assertStatus(422);
        }
        $this->postJson('/api/auth/reset-password', [])->assertStatus(429);
    }

    // ---- 6. Secrets in responses and logs -------------------------------------------

    public function test_user_responses_never_contain_password_hashes_or_remember_tokens(): void
    {
        $admin = $this->makeAdmin(['email' => 'boss@example.test', 'remember_token' => 'fake-remember-token']);
        $this->makeStudent(['remember_token' => 'fake-remember-token']);
        $this->makeInstructor(['remember_token' => 'fake-remember-token']);

        $bodies = [
            $this->postJson('/api/auth/login', ['email' => 'boss@example.test', 'password' => self::PASSWORD])->getContent(),
            $this->actingAs($admin, 'sanctum')->getJson('/api/auth/profile')->getContent(),
            $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users')->getContent(),
            $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users?page=1')->getContent(),
        ];

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString('"password"', $body);
            $this->assertStringNotContainsString('remember_token', $body);
            $this->assertStringNotContainsString('fake-remember-token', $body);
            $this->assertStringNotContainsString('$2y$', $body); // bcrypt hash prefix
        }
    }

    public function test_admin_payments_never_contain_checkout_urls_or_client_secrets(): void
    {
        $admin = $this->makeAdmin();
        $course = $this->makeCourse(null, ['price' => 25]);
        Payment::create([
            'user_id' => $this->makeStudent()->id,
            'course_id' => $course->id,
            'payment_method' => 'card',
            'provider' => PaymentProvider::STRIPE,
            'amount' => 25,
            'currency' => 'usd',
            'status' => PaymentStatus::PENDING,
            'stripe_checkout_session_id' => 'cs_test_fake_session',
        ]);

        foreach (['/api/admin/payments', '/api/admin/payments?page=1'] as $url) {
            $body = $this->actingAs($admin, 'sanctum')->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('checkout_url', $body);
            $this->assertStringNotContainsString('checkout.stripe.com', $body);
            $this->assertStringNotContainsString('client_secret', $body);
        }
    }

    public function test_redact_removes_keys_client_secrets_bearer_tokens_and_url_signatures(): void
    {
        $text = 'Invalid API Key provided: sk_test_****fake and rk_live_fake123, whsec_fakeSecret, '
            .'pi_3Fake_secret_FakeSecret99, Authorization: Bearer 1|fakeTokenValue, '
            .'https://files.example.test/a.png?X-Amz-Credential=FAKEKEY%2F20261026&X-Amz-Signature=deadbeef&signature=abc123';

        $clean = Redact::secrets($text);

        foreach (['sk_test_', 'rk_live_', 'whsec_', '_secret_', 'fakeTokenValue', 'FAKEKEY', 'deadbeef', 'abc123'] as $secret) {
            $this->assertStringNotContainsString($secret, $clean, $secret);
        }
        $this->assertStringContainsString('X-Amz-Signature=[redacted]', $clean);
        $this->assertStringContainsString('Bearer [redacted]', $clean);
        $this->assertSame('', Redact::secrets(null));
        $this->assertSame('Nothing to hide.', Redact::secrets('Nothing to hide.'));
    }
}
