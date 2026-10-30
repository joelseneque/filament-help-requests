<?php

namespace Joelseneque\HelpRequests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Joelseneque\HelpRequests\HelpRequests;

/**
 * One chat with Parley. A conversation is the unit a help request links back
 * to, so whoever picks the request up reads what was already tried.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $page_url
 * @property string|null $page_title
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ParleyConversation extends Model
{
    protected $fillable = [
        'user_id',
        'page_url',
        'page_title',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(HelpRequests::userModel(), 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ParleyMessage::class)->orderBy('id');
    }

    /**
     * The turns a person sees: their questions and Parley's answers, without
     * the tool calls and results the model exchanged in between.
     */
    public function visibleMessages(): HasMany
    {
        return $this->messages()->whereNotNull('text');
    }

    public function helpRequests(): HasMany
    {
        return $this->hasMany(HelpRequest::class);
    }

    /**
     * The internal request this conversation's unanswered questions are
     * collected on, if one has been raised.
     */
    public function helpArticleRequest(): ?HelpRequest
    {
        return $this->helpRequests()
            ->where('is_internal', true)
            ->where('category', HelpRequests::HELP_ARTICLE_CATEGORY)
            ->latest('id')
            ->first();
    }

    /**
     * How many questions have been asked, which caps how long a conversation
     * may run before Parley asks for a fresh one.
     */
    public function questionCount(): int
    {
        return $this->messages()->where('role', 'user')->whereNotNull('text')->count();
    }
}
