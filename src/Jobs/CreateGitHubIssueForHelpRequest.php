<?php

namespace Joelseneque\HelpRequests\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Services\GitHubIssueService;

/**
 * Pushes a newly submitted help request to GitHub in the background so a slow
 * or unavailable GitHub never blocks the person submitting the request.
 */
class CreateGitHubIssueForHelpRequest implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public HelpRequest $helpRequest) {}

    public function handle(GitHubIssueService $github): void
    {
        if (! $github->isConfigured() || $this->helpRequest->hasGithubIssue()) {
            return;
        }

        $result = $github->createIssue($this->helpRequest);

        if (! $result['ok']) {
            Log::warning('Could not create GitHub issue for help request', [
                'help_request_id' => $this->helpRequest->id,
                'message' => $result['message'],
            ]);
        }
    }
}
