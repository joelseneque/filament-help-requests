<?php

namespace Joelseneque\HelpRequests\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Joelseneque\HelpRequests\Database\Factories\HelpRequestFactory;
use Joelseneque\HelpRequests\Enums\HelpRequestPriority;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Models\Concerns\HasAttachments;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $page_url
 * @property string|null $page_title
 * @property string|null $category
 * @property HelpRequestPriority $priority
 * @property Carbon|null $due_at
 * @property bool $is_internal
 * @property int|null $parley_conversation_id
 * @property string $comment
 * @property list<string>|null $attachments
 * @property string|null $video_url
 * @property HelpRequestStatus $status
 * @property Carbon|null $resolved_at
 * @property int|null $github_issue_number
 * @property string|null $github_repository
 * @property string|null $github_issue_url
 * @property string|null $github_issue_state
 * @property Carbon|null $github_synced_at
 */
class HelpRequest extends Model
{
    use HasAttachments;

    /** @use HasFactory<HelpRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'page_url',
        'page_title',
        'category',
        'priority',
        'due_at',
        'is_internal',
        'parley_conversation_id',
        'comment',
        'attachments',
        'video_url',
        'status',
        'resolved_at',
        'github_issue_number',
        'github_repository',
        'github_issue_url',
        'github_issue_state',
        'github_synced_at',
    ];

    /**
     * Medium is also the DB default; repeating it here means a freshly built
     * model has a priority before it is saved.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => 'medium',
    ];

    /**
     * Every creation path (widget, Parley, factories) gets a deadline without
     * having to compute one. An explicit due_at is kept, and created_at is
     * preferred over now() so a back-dated row is due relative to when it was raised.
     */
    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->due_at ??= $request->priority->dueAt($request->created_at ?? now());
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => HelpRequestStatus::class,
            'priority' => HelpRequestPriority::class,
            'due_at' => 'datetime',
            'attachments' => 'array',
            'is_internal' => 'boolean',
            'resolved_at' => 'datetime',
            'github_issue_number' => 'integer',
            'github_synced_at' => 'datetime',
        ];
    }

    protected static function newFactory(): HelpRequestFactory
    {
        return HelpRequestFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(HelpRequests::userModel(), 'user_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(HelpRequestReply::class)->orderBy('created_at');
    }

    /**
     * The Parley chat this request came out of, if it did.
     */
    public function parleyConversation(): BelongsTo
    {
        return $this->belongsTo(ParleyConversation::class);
    }

    /**
     * Requests the requester raised themselves — what "My Requests" lists.
     * Internal ones, such as a question Parley could not answer, are notes
     * for the team.
     *
     * @param  Builder<HelpRequest>  $query
     */
    public function scopeRaisedByRequester(Builder $query): void
    {
        $query->where('is_internal', false);
    }

    /**
     * Past its deadline and still waiting on the team. Requests without a
     * due_at (created before priorities existed) are never overdue.
     */
    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && ! $this->status->isFinished();
    }

    /**
     * Query twin of isOverdue().
     *
     * @param  Builder<HelpRequest>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', [HelpRequestStatus::Resolved, HelpRequestStatus::Closed]);
    }

    public function categoryLabel(): ?string
    {
        return HelpRequests::categoryLabel($this->category);
    }

    public function hasVideo(): bool
    {
        return filled($this->video_url);
    }

    public function isLoomVideo(): bool
    {
        $host = strtolower((string) parse_url((string) $this->video_url, PHP_URL_HOST));

        return $host === 'loom.com' || str_ends_with($host, '.loom.com');
    }

    /**
     * The player URL for a Loom share link, so the admin page can play the
     * recording in place. Null for anything that is not a Loom video.
     */
    public function videoEmbedUrl(): ?string
    {
        if (! $this->isLoomVideo()) {
            return null;
        }

        if (! preg_match('#/(?:share|embed)/([a-zA-Z0-9]+)#', (string) $this->video_url, $matches)) {
            return null;
        }

        return "https://www.loom.com/embed/{$matches[1]}";
    }

    public function hasGithubIssue(): bool
    {
        return $this->github_issue_number !== null;
    }
}
