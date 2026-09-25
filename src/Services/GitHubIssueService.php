<?php

namespace Joelseneque\HelpRequests\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestReply;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;

/**
 * Mirrors help requests into GitHub issues and keeps the two in step.
 *
 * Which repository to use is chosen per database on the Help Request settings
 * page rather than in the environment, so every call reads the singleton
 * HelpRequestSetting.
 */
class GitHubIssueService
{
    public function __construct(protected GitHubAppTokenService $tokens) {}

    public function settings(): ?HelpRequestSetting
    {
        return HelpRequestSetting::query()->first();
    }

    public function isConfigured(): bool
    {
        return $this->tokens->isConfigured() && (bool) $this->settings()?->isGithubConnected();
    }

    /**
     * Every repository the installation can reach, for the settings page picker.
     *
     * Cached briefly because a Filament Select re-evaluates its options on every
     * render, and the list only changes when the installation is reconfigured.
     *
     * @return array<string, string> Full name keyed to itself, for a Select.
     */
    public function availableRepositories(bool $fresh = false): array
    {
        $settings = $this->settings();

        if (! $settings?->isGithubInstalled()) {
            return [];
        }

        $cacheKey = "github-repositories-{$settings->github_installation_id}";

        if (! $fresh && ($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $repositories = $this->fetchRepositories($settings);

        // Never cache an empty list — a transient API failure would otherwise
        // leave the picker blank for the whole cache window.
        if ($repositories !== []) {
            Cache::put($cacheKey, $repositories, now()->addMinutes(5));
        }

        return $repositories;
    }

    /**
     * @return array<string, string>
     */
    protected function fetchRepositories(HelpRequestSetting $settings): array
    {
        $repositories = [];
        $page = 1;

        do {
            try {
                $response = $this->client($settings)
                    ->get('/installation/repositories', ['per_page' => 100, 'page' => $page]);
            } catch (\Throwable $e) {
                Log::error('Could not list GitHub repositories', ['error' => $e->getMessage()]);

                return $repositories;
            }

            if ($response->failed()) {
                Log::error('GitHub repository listing rejected', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return $repositories;
            }

            foreach ($response->json('repositories', []) as $repository) {
                $repositories[$repository['full_name']] = $repository['full_name'];
            }

            $page++;
            // GitHub caps a page at 100; a short page means we have them all.
        } while (count($response->json('repositories', [])) === 100);

        ksort($repositories);

        return $repositories;
    }

    /**
     * Verify the installation can actually write issues to the chosen repository.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        if (! $this->tokens->isConfigured()) {
            return ['ok' => false, 'message' => 'The GitHub App is not configured. Set GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY in the environment.'];
        }

        $settings = $this->settings();

        if (! $settings?->isGithubInstalled()) {
            return ['ok' => false, 'message' => 'The GitHub App is not installed yet. Use Connect to GitHub to install it.'];
        }

        if (blank($settings->github_repository)) {
            return ['ok' => false, 'message' => 'Connected to GitHub, but no repository has been selected yet.'];
        }

        $repository = $settings->githubRepository();

        try {
            $response = $this->client($settings)->get("/repos/{$repository}");
        } catch (\Throwable $e) {
            Log::error('GitHub connection test failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Could not reach GitHub: '.$e->getMessage()];
        }

        if ($response->status() === 401) {
            return ['ok' => false, 'message' => 'GitHub rejected the installation token (401). Reconnect the app.'];
        }

        if ($response->status() === 404) {
            return ['ok' => false, 'message' => "Repository {$repository} was not found, or the installation has no access to it. Check the repository is included in the app installation."];
        }

        if ($response->failed()) {
            return ['ok' => false, 'message' => "GitHub returned {$response->status()}: ".$this->errorMessage($response->json())];
        }

        if ($response->json('has_issues') === false) {
            return ['ok' => false, 'message' => "Issues are disabled on {$repository}. Enable them in the repository settings."];
        }

        // The repository `permissions` block reflects contents access, which this
        // app does not request — the granted issues permission is the one that
        // decides whether an issue can be created.
        $installation = $this->tokens->fetchInstallation((int) $settings->github_installation_id);

        if (($installation['permissions']['issues'] ?? null) !== 'write') {
            return ['ok' => false, 'message' => "Connected to {$repository}, but the app installation does not have write access to Issues."];
        }

        return ['ok' => true, 'message' => 'Connected to '.$response->json('full_name', $repository).'.'];
    }

    /**
     * Create the GitHub issue mirroring a help request.
     *
     * @return array{ok: bool, message: string}
     */
    public function createIssue(HelpRequest $helpRequest): array
    {
        $settings = $this->settings();

        if (! $settings?->isGithubConnected()) {
            return ['ok' => false, 'message' => 'GitHub is not configured.'];
        }

        if ($helpRequest->hasGithubIssue()) {
            return ['ok' => false, 'message' => "This request is already linked to issue #{$helpRequest->github_issue_number}."];
        }

        $repository = $settings->githubRepository();

        $payload = array_filter([
            'title' => $this->issueTitle($helpRequest),
            'body' => $this->issueBody($helpRequest),
            'labels' => $settings->github_default_labels ?: null,
        ]);

        try {
            $response = $this->client($settings)->post("/repos/{$repository}/issues", $payload);
        } catch (\Throwable $e) {
            Log::error('GitHub issue creation failed', [
                'help_request_id' => $helpRequest->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Could not reach GitHub: '.$e->getMessage()];
        }

        if ($response->failed()) {
            Log::error('GitHub issue creation rejected', [
                'help_request_id' => $helpRequest->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return ['ok' => false, 'message' => "GitHub returned {$response->status()}: ".$this->errorMessage($response->json())];
        }

        $issue = $response->json();

        $helpRequest->update([
            'github_issue_number' => $issue['number'] ?? null,
            'github_repository' => $repository,
            'github_issue_url' => $issue['html_url'] ?? null,
            'github_issue_state' => $issue['state'] ?? 'open',
            'github_synced_at' => now(),
        ]);

        return ['ok' => true, 'message' => "Created issue #{$helpRequest->github_issue_number} in {$repository}."];
    }

    /**
     * Pull the current issue state from GitHub and apply it locally.
     *
     * @return array{ok: bool, message: string}
     */
    public function syncFromGithub(HelpRequest $helpRequest): array
    {
        $settings = $this->settings();

        if (! $settings?->isGithubConnected()) {
            return ['ok' => false, 'message' => 'GitHub is not configured.'];
        }

        if (! $helpRequest->hasGithubIssue()) {
            return ['ok' => false, 'message' => 'This request is not linked to a GitHub issue.'];
        }

        $repository = $helpRequest->github_repository ?: $settings->githubRepository();

        try {
            $response = $this->client($settings)->get("/repos/{$repository}/issues/{$helpRequest->github_issue_number}");
        } catch (\Throwable $e) {
            Log::error('GitHub issue fetch failed', [
                'help_request_id' => $helpRequest->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Could not reach GitHub: '.$e->getMessage()];
        }

        if ($response->status() === 404) {
            return ['ok' => false, 'message' => "Issue #{$helpRequest->github_issue_number} no longer exists in {$repository}."];
        }

        if ($response->failed()) {
            return ['ok' => false, 'message' => "GitHub returned {$response->status()}: ".$this->errorMessage($response->json())];
        }

        $status = $this->applyIssue($helpRequest, $response->json());

        return ['ok' => true, 'message' => "Issue #{$helpRequest->github_issue_number} is {$helpRequest->github_issue_state} — status set to {$status->getLabel()}."];
    }

    /**
     * Apply a GitHub issue payload (from the API or a webhook) to a help request.
     */
    public function applyIssue(HelpRequest $helpRequest, array $issue): HelpRequestStatus
    {
        $status = $this->statusForIssue($issue, $helpRequest->status);

        $helpRequest->update([
            'status' => $status,
            'resolved_at' => $status === HelpRequestStatus::Resolved ? ($helpRequest->resolved_at ?? now()) : null,
            'github_issue_url' => $issue['html_url'] ?? $helpRequest->github_issue_url,
            'github_issue_state' => $issue['state'] ?? $helpRequest->github_issue_state,
            'github_synced_at' => now(),
        ]);

        return $status;
    }

    /**
     * Push a local status change back to the linked issue, closing or reopening
     * it so both systems agree.
     *
     * @return array{ok: bool, message: string}
     */
    public function pushStatus(HelpRequest $helpRequest): array
    {
        $settings = $this->settings();

        if (! $settings?->isGithubConnected() || ! $helpRequest->hasGithubIssue()) {
            return ['ok' => false, 'message' => 'Nothing to push.'];
        }

        $repository = $helpRequest->github_repository ?: $settings->githubRepository();

        $payload = match ($helpRequest->status) {
            HelpRequestStatus::Resolved => ['state' => 'closed', 'state_reason' => 'completed'],
            HelpRequestStatus::Closed => ['state' => 'closed', 'state_reason' => 'not_planned'],
            default => ['state' => 'open', 'state_reason' => 'reopened'],
        };

        if (($helpRequest->github_issue_state ?? 'open') === $payload['state']) {
            return ['ok' => true, 'message' => 'GitHub is already up to date.'];
        }

        try {
            $response = $this->client($settings)
                ->patch("/repos/{$repository}/issues/{$helpRequest->github_issue_number}", $payload);
        } catch (\Throwable $e) {
            Log::error('GitHub issue update failed', [
                'help_request_id' => $helpRequest->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Could not reach GitHub: '.$e->getMessage()];
        }

        if ($response->failed()) {
            Log::error('GitHub issue update rejected', [
                'help_request_id' => $helpRequest->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return ['ok' => false, 'message' => "GitHub returned {$response->status()}: ".$this->errorMessage($response->json())];
        }

        $helpRequest->update([
            'github_issue_state' => $response->json('state', $payload['state']),
            'github_synced_at' => now(),
        ]);

        return ['ok' => true, 'message' => "Issue #{$helpRequest->github_issue_number} set to {$helpRequest->github_issue_state}."];
    }

    /**
     * Mirror a reply written in the app onto the linked issue, so the dev team
     * sees on the issue what the requester was told.
     *
     * This is one-way: comments made on the issue are the team's own internal
     * discussion and are never pulled back into the help request thread.
     *
     * @return array{ok: bool, message: string}
     */
    public function postComment(HelpRequestReply $reply): array
    {
        $settings = $this->settings();
        $helpRequest = $reply->helpRequest;

        if (! $settings?->isGithubConnected() || ! $helpRequest?->hasGithubIssue()) {
            return ['ok' => false, 'message' => 'Nothing to post.'];
        }

        if ($reply->isPostedToGithub()) {
            return ['ok' => false, 'message' => 'This reply is already on the GitHub issue.'];
        }

        $repository = $helpRequest->github_repository ?: $settings->githubRepository();
        $appName = HelpRequests::appName();
        $author = $reply->user ? HelpRequests::userName($reply->user) : $appName;

        $body = "**{$author}** replied in {$appName}:\n\n{$reply->body}";

        if ($screenshot = $reply->getScreenshotUrl()) {
            $body .= "\n\n![Screenshot]({$screenshot})";
        }

        try {
            $response = $this->client($settings)->post(
                "/repos/{$repository}/issues/{$helpRequest->github_issue_number}/comments",
                ['body' => $body],
            );
        } catch (\Throwable $e) {
            Log::error('GitHub comment failed', [
                'help_request_reply_id' => $reply->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Could not reach GitHub: '.$e->getMessage()];
        }

        if ($response->failed()) {
            Log::error('GitHub comment rejected', [
                'help_request_reply_id' => $reply->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return ['ok' => false, 'message' => "GitHub returned {$response->status()}: ".$this->errorMessage($response->json())];
        }

        $reply->update(['github_comment_id' => $response->json('id')]);

        return ['ok' => true, 'message' => 'Comment posted to GitHub.'];
    }

    /**
     * Map a GitHub issue to a help request status.
     *
     * An open issue keeps an existing "In Progress" status rather than dropping
     * it back to "Open" — GitHub has no equivalent state, so the local value is
     * the more specific one.
     */
    public function statusForIssue(array $issue, ?HelpRequestStatus $current = null): HelpRequestStatus
    {
        if (($issue['state'] ?? 'open') !== 'closed') {
            return $current === HelpRequestStatus::InProgress
                ? HelpRequestStatus::InProgress
                : HelpRequestStatus::Open;
        }

        return ($issue['state_reason'] ?? null) === 'not_planned'
            ? HelpRequestStatus::Closed
            : HelpRequestStatus::Resolved;
    }

    protected function client(HelpRequestSetting $settings): PendingRequest
    {
        return Http::withToken($this->tokens->installationToken($settings))
            ->baseUrl(rtrim((string) config('help-requests.github.api_url'), '/'))
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout(20);
    }

    protected function issueTitle(HelpRequest $helpRequest): string
    {
        $summary = Str::of($helpRequest->comment)->squish()->limit(80)->toString();

        $category = $helpRequest->categoryLabel();

        return "Help request #{$helpRequest->id}".($category ? " [{$category}]" : '').": {$summary}";
    }

    protected function issueBody(HelpRequest $helpRequest): string
    {
        $helpRequest->loadMissing('user');

        $lines = [
            '**Submitted by:** '.HelpRequests::userName($helpRequest->user).($helpRequest->user?->email ? " ({$helpRequest->user->email})" : ''),
            '**Submitted at:** '.$helpRequest->created_at?->format('d M Y, g:i A'),
        ];

        if ($category = $helpRequest->categoryLabel()) {
            $lines[] = "**Type:** {$category}";
        }

        if ($helpRequest->page_title || $helpRequest->page_url) {
            $lines[] = '**Page:** '.($helpRequest->page_url
                ? '['.($helpRequest->page_title ?: $helpRequest->page_url).']('.$helpRequest->page_url.')'
                : $helpRequest->page_title);
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = $helpRequest->comment;

        if ($helpRequest->hasVideo()) {
            $lines[] = '';
            $lines[] = '**Video:** '.$helpRequest->video_url;
        }

        if ($screenshot = $helpRequest->getScreenshotUrl()) {
            $lines[] = '';
            $lines[] = "![Screenshot]({$screenshot})";
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '[View in '.HelpRequests::appName().']('.HelpRequests::adminUrl($helpRequest).')';
        $lines[] = '';
        $lines[] = '<sub>Closing this issue marks the help request as resolved. Closing it as "not planned" marks it closed.</sub>';

        return implode("\n", $lines);
    }

    protected function errorMessage(mixed $body): string
    {
        if (is_array($body) && isset($body['message'])) {
            return (string) $body['message'];
        }

        return 'Unknown error.';
    }
}
