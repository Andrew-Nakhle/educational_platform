<?php

namespace Tests\Feature;

use App\Enums\User\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * GET /api/admin/users?search=: case-insensitive match on the email and
 * the name parts, combined with role, status and pagination.
 */
class AdminUserSearchTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();

        $this->actingAs($this->makeAdmin(['email' => 'admin@example.test']), 'sanctum');

        $this->makeStudent(['email' => 'lina.haddad@example.test'], ['first_name' => 'Lina', 'father_name' => 'Fadi', 'last_name' => 'Haddad']);
        $this->makeStudent(['email' => 'omar@example.test', 'status' => UserStatus::BLOCKED], ['first_name' => 'Omar', 'father_name' => 'Sami', 'last_name' => 'Nasser']);
        $this->makeStudent(['email' => 'maya@example.test'], ['first_name' => 'Maya', 'father_name' => 'Nabil', 'last_name' => 'Haddad']);
        $this->makeInstructor(['email' => 'rami@example.test'], ['first_name' => 'Rami', 'father_name' => 'Adel', 'last_name' => 'Fares']);
        $this->makeInstructor(['email' => 'nour@example.test', 'status' => UserStatus::PENDING], ['first_name' => 'Nour', 'father_name' => 'Walid', 'last_name' => 'Haddad']);
    }

    private function emails(string $query): array
    {
        return collect($this->getJson('/api/admin/users'.$query)->assertOk()->json('data'))->pluck('email')->sort()->values()->all();
    }

    public function test_without_search_the_response_is_unchanged(): void
    {
        $response = $this->getJson('/api/admin/users')->assertOk()->assertJsonCount(6, 'data');
        $this->assertSame(['data'], array_keys($response->json()));

        // An empty search is ignored.
        $this->assertCount(6, $this->emails('?search='));
        $this->assertCount(6, $this->emails('?search=%20%20'));
    }

    public function test_matches_names_and_emails_case_insensitively(): void
    {
        $this->assertSame(['lina.haddad@example.test', 'maya@example.test', 'nour@example.test'], $this->emails('?search=HADDAD'));
        $this->assertSame(['lina.haddad@example.test'], $this->emails('?search=lIn'));
        $this->assertSame(['omar@example.test'], $this->emails('?search=sami')); // father's name
        $this->assertSame(['rami@example.test'], $this->emails('?search=Fares')); // instructor profile
        $this->assertSame(['omar@example.test'], $this->emails('?search=OMAR@EXAMPLE'));
        $this->assertSame(['admin@example.test'], $this->emails('?search=admin@')); // no profile, email only
    }

    public function test_every_word_must_match(): void
    {
        $this->assertSame(['lina.haddad@example.test'], $this->emails('?search=Lina%20Haddad'));
        $this->assertSame(['maya@example.test'], $this->emails('?search=haddad%20MAYA'));
        $this->assertSame([], $this->emails('?search=Lina%20Nasser'));
    }

    public function test_no_match_returns_an_empty_list(): void
    {
        $this->getJson('/api/admin/users?search=zzz')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_wildcards_in_the_input_are_literal(): void
    {
        // Unescaped, "_" and "%" would match every user.
        $this->assertSame([], $this->emails('?search=_'));
        $this->assertSame([], $this->emails('?search=%25'));
        $this->assertSame([], $this->emails('?search=!'));
    }

    public function test_combines_with_role_status_and_pagination(): void
    {
        $this->assertSame(['lina.haddad@example.test', 'maya@example.test'], $this->emails('?search=haddad&role=student'));
        $this->assertSame(['nour@example.test'], $this->emails('?search=haddad&role=instructor&status=pending'));
        $this->assertSame([], $this->emails('?search=haddad&status=blocked'));

        $page = $this->getJson('/api/admin/users?search=haddad&page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $this->assertStringContainsString('search=haddad', $page->json('links.prev'));
    }

    public function test_search_is_validated(): void
    {
        $this->getJson('/api/admin/users?search='.str_repeat('a', 101))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['search']);

        $this->getJson('/api/admin/users?search[]=lina')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['search']);
    }

    public function test_is_admin_only(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/users?search=lina')->assertUnauthorized();

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->getJson('/api/admin/users?search=lina')
            ->assertForbidden();
    }
}
