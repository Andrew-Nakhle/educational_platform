<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactMessageRequest;
use App\Http\Resources\ContactMessageResource;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    // Public contact form (rate-limited in routes/api.php).
    public function store(StoreContactMessageRequest $request): JsonResponse
    {
        ContactMessage::create($request->validated());

        return response()->json([
            'message' => 'Your message has been sent.',
        ], 201);
    }

    // Admin inbox, newest first. ?unread=1 lists unread messages only.
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'unread' => ['sometimes', 'boolean'],
        ]);

        $messages = ContactMessage::query()
            ->when(
                $request->boolean('unread'),
                fn ($query) => $query->whereNull('read_at')
            )
            ->latest()
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 15));

        return response()->json([
            'message' => 'Contact messages retrieved successfully.',
            'data' => ContactMessageResource::collection($messages),
            'meta' => $this->paginationMeta($messages),
        ]);
    }

    // Idempotent: a message read earlier keeps its first read_at.
    public function markAsRead(int $id): JsonResponse
    {
        $contactMessage = ContactMessage::findOrFail($id);

        if ($contactMessage->read_at === null) {
            $contactMessage->update(['read_at' => now()]);
        }

        return response()->json([
            'message' => 'Contact message marked as read.',
            'data' => new ContactMessageResource($contactMessage),
        ]);
    }
}
