<?php

namespace Joelseneque\HelpRequests\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Joelseneque\HelpRequests\Database\Factories\HelpRequestReplyFactory;
use Joelseneque\HelpRequests\HelpRequests;

/**
 * @property int $id
 * @property int $help_request_id
 * @property int|null $user_id
 * @property string $body
 * @property string|null $screenshot_path
 * @property int|null $github_comment_id
 * @property string|null $github_author
 */
class HelpRequestReply extends Model
{
    /** @use HasFactory<HelpRequestReplyFactory> */
    use HasFactory;

    protected $fillable = [
        'help_request_id',
        'user_id',
        'body',
        'screenshot_path',
        'github_comment_id',
        'github_author',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_comment_id' => 'integer',
        ];
    }

    protected static function newFactory(): HelpRequestReplyFactory
    {
        return HelpRequestReplyFactory::new();
    }

    public function hasScreenshot(): bool
    {
        return $this->screenshot_path !== null;
    }

    public function getScreenshotUrl(): ?string
    {
        if ($this->screenshot_path === null) {
            return null;
        }

        return Storage::disk(config('help-requests.storage.disk'))->url($this->screenshot_path);
    }

    /**
     * Whether this reply has already been mirrored onto the linked GitHub issue.
     */
    public function isPostedToGithub(): bool
    {
        return $this->github_comment_id !== null;
    }

    /**
     * The name to show against the reply. `github_author` only appears on
     * replies imported from a GitHub comment.
     */
    public function authorName(): string
    {
        if ($this->user) {
            return HelpRequests::userName($this->user);
        }

        return $this->github_author ? "{$this->github_author} (GitHub)" : 'Support';
    }

    public function helpRequest(): BelongsTo
    {
        return $this->belongsTo(HelpRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(HelpRequests::userModel(), 'user_id');
    }
}
