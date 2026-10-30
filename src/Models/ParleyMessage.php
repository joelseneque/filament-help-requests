<?php

namespace Joelseneque\HelpRequests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One turn of a Parley conversation.
 *
 * `payload` is the turn's content exactly as the model API sent it (or as it
 * was sent), kept as JSON text rather than cast to an array: decoding to PHP
 * arrays turns an empty tool input `{}` into `[]`, and a history replayed
 * with any byte changed is refused or loses the model's earlier reasoning.
 *
 * @property int $id
 * @property int $parley_conversation_id
 * @property string $role
 * @property string|null $text
 * @property string $payload
 * @property array<string, mixed>|null $meta
 * @property int|null $rating
 * @property Carbon $created_at
 */
class ParleyMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    protected $fillable = [
        'parley_conversation_id',
        'role',
        'text',
        'payload',
        'meta',
        'rating',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'rating' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ParleyConversation::class, 'parley_conversation_id');
    }

    /**
     * The turn as the API expects it back, objects kept as objects.
     *
     * @return array{role: string, content: mixed}
     */
    public function toApiMessage(): array
    {
        return ['role' => $this->role, 'content' => json_decode($this->payload, associative: false)];
    }

    public function isFromAssistant(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }

    /**
     * Parley's answer as HTML. Markdown is rendered with raw HTML escaped and
     * unsafe links dropped: the text came from a model, and is drawn inside
     * the panel.
     */
    public function html(): string
    {
        return Str::markdown((string) $this->text, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * The help request Parley suggested raising, when it suggested one.
     *
     * @return array{type: string|null, summary: string}|null
     */
    public function suggestedRequest(): ?array
    {
        $suggestion = $this->meta['suggested_request'] ?? null;

        return is_array($suggestion) && filled($suggestion['summary'] ?? null) ? $suggestion : null;
    }
}
