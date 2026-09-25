<?php

namespace Joelseneque\HelpRequests\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Joelseneque\HelpRequests\Database\Factories\HelpRequestFactory;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\HelpRequests;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $page_url
 * @property string|null $page_title
 * @property string|null $category
 * @property string $comment
 * @property string|null $screenshot_path
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
    /** @use HasFactory<HelpRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'page_url',
        'page_title',
        'category',
        'comment',
        'screenshot_path',
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => HelpRequestStatus::class,
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

    public function getScreenshotUrl(): ?string
    {
        if ($this->screenshot_path === null) {
            return null;
        }

        return Storage::disk(config('help-requests.storage.disk'))->url($this->screenshot_path);
    }
}
