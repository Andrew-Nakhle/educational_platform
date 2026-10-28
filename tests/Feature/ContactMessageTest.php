<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class ContactMessageTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTestData();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sara Khalil',
            'email' => 'sara@example.test',
            'message' => 'Do you offer group discounts?',
        ], $overrides);
    }

    public function test_guest_can_send_a_contact_message(): void
    {
        $this->postJson('/api/contact', $this->payload())
            ->assertCreated()
            ->assertExactJson(['message' => 'Your message has been sent.']);

        $this->assertDatabaseHas('contact_messages', [
            'name' => 'Sara Khalil',
            'email' => 'sara@example.test',
            'message' => 'Do you offer group discounts?',
            'read_at' => null,
        ]);
    }

    public function test_contact_message_is_validated(): void
    {
        $this->postJson('/api/contact', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'message']);

        $this->postJson('/api/contact', $this->payload([
            'name' => str_repeat('a', 101),
            'email' => 'not-an-email',
            'message' => str_repeat('a', 5001),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'message']);

        $this->assertSame(0, ContactMessage::count());
    }

    public function test_contact_form_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/contact', $this->payload())->assertCreated();
        }

        $this->postJson('/api/contact', $this->payload())->assertStatus(429);

        $this->assertSame(5, ContactMessage::count());
    }

    public function test_admin_lists_contact_messages_newest_first_with_meta(): void
    {
        $first = ContactMessage::create($this->payload(['name' => 'First']));
        $this->travel(1)->minutes();
        ContactMessage::create($this->payload(['name' => 'Second', 'read_at' => now()]));
        $this->travel(1)->minutes();
        ContactMessage::create($this->payload(['name' => 'Third']));

        $this->actingAs($this->makeAdmin(), 'sanctum');

        $this->getJson('/api/admin/contact-messages?per_page=2')
            ->assertOk()
            ->assertJsonPath('message', 'Contact messages retrieved successfully.')
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'total' => 3, 'per_page' => 2])
            ->assertJsonPath('data.0.name', 'Third')
            ->assertJsonPath('data.1.name', 'Second')
            ->assertJsonPath('data.1.is_read', true)
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'message', 'is_read', 'read_at', 'created_at']]]);

        $this->getJson('/api/admin/contact-messages?page=2&per_page=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id);

        $response = $this->getJson('/api/admin/contact-messages?unread=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->assertSame(['Third', 'First'], array_column($response->json('data'), 'name'));

        $this->getJson('/api/admin/contact-messages?per_page=51')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_admin_marks_a_contact_message_as_read_once(): void
    {
        $message = ContactMessage::create($this->payload());

        $this->actingAs($this->makeAdmin(), 'sanctum');

        $readAt = $this->patchJson("/api/admin/contact-messages/{$message->id}/read")
            ->assertOk()
            ->assertJsonPath('message', 'Contact message marked as read.')
            ->assertJsonPath('data.id', $message->id)
            ->assertJsonPath('data.is_read', true)
            ->json('data.read_at');

        $this->assertNotNull($readAt);

        $this->travel(5)->minutes();

        $this->patchJson("/api/admin/contact-messages/{$message->id}/read")
            ->assertOk()
            ->assertJsonPath('data.read_at', $readAt);

        $this->patchJson('/api/admin/contact-messages/999/read')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Contact message not found.']);
    }

    public function test_contact_messages_are_admin_only(): void
    {
        $message = ContactMessage::create($this->payload());

        $this->getJson('/api/admin/contact-messages')->assertUnauthorized();
        $this->patchJson("/api/admin/contact-messages/{$message->id}/read")->assertUnauthorized();

        foreach ([$this->makeStudent(), $this->makeInstructor()] as $user) {
            $this->actingAs($user, 'sanctum');

            $this->getJson('/api/admin/contact-messages')->assertForbidden();
            $this->patchJson("/api/admin/contact-messages/{$message->id}/read")->assertForbidden();
        }

        $this->assertNull($message->fresh()->read_at);
    }
}
