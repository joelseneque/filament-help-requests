<?php

namespace Joelseneque\HelpRequests\Parley;

use Illuminate\Support\Str;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Jobs\CreateGitHubIssueForHelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;
use Joelseneque\HelpRequests\Models\ParleyConversation;
use Joelseneque\HelpRequests\Services\GitHubIssueService;
use Joelseneque\HelpRequests\Support\HelpRequestNotifier;

/**
 * A question Parley could not answer from the help library, filed as an
 * internal help request so somebody writes the missing article.
 *
 * It lands in the same triage list as everything else (and on GitHub, when
 * issues are raised automatically), under the "Help article" type. The
 * person who asked never sees it in their own list: they asked a question,
 * not for a ticket.
 *
 * One request per conversation. A second gap in the same chat is added to
 * the open request as a reply rather than raising another, so a person who
 * asks three ways round the same missing article files one note, not three.
 */
class RecordHelpArticleGap
{
    public static function record(ParleyConversation $conversation, string $question, string $missing): HelpRequest
    {
        $note = static::note($question, $missing);
        $existing = $conversation->helpArticleRequest();

        if ($existing && ! $existing->status->isFinished()) {
            $reply = $existing->replies()->create(['user_id' => null, 'body' => "Also asked:\n\n{$note}"]);

            if ($existing->hasGithubIssue()) {
                app(GitHubIssueService::class)->postComment($reply);
            }

            return $existing;
        }

        $helpRequest = HelpRequest::create([
            'user_id' => $conversation->user_id,
            'page_url' => $conversation->page_url,
            'page_title' => $conversation->page_title,
            'category' => HelpRequests::HELP_ARTICLE_CATEGORY,
            'is_internal' => true,
            'parley_conversation_id' => $conversation->getKey(),
            'comment' => Parley::name()." could not answer this from the help library.\n\n{$note}\n\nWrite or update a help article, then resolve this request.",
            'status' => HelpRequestStatus::Open,
        ]);

        if (config('help-requests.parley.notify_admins_of_gaps')) {
            HelpRequestNotifier::newRequestForAdmins($helpRequest);
        }

        if (HelpRequestSetting::query()->first()?->github_auto_create && app(GitHubIssueService::class)->isConfigured()) {
            CreateGitHubIssueForHelpRequest::dispatch($helpRequest);
        }

        return $helpRequest;
    }

    protected static function note(string $question, string $missing): string
    {
        return 'Question: '.Str::limit(trim($question), 1000)
            ."\nWhat was missing: ".Str::limit(trim($missing), 1000);
    }
}
