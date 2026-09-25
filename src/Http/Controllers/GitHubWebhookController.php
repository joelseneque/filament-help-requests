<?php

namespace Joelseneque\HelpRequests\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;
use Joelseneque\HelpRequests\Services\GitHubIssueService;
use Joelseneque\HelpRequests\Support\HelpRequestNotifier;

/**
 * Receives GitHub `issues` webhooks so an issue closed, reopened or re-labelled
 * in GitHub updates the matching help request without anyone touching the app.
 *
 * Issue comments are deliberately not mirrored back: the GitHub thread is the
 * dev team's internal discussion, and anything the requester should see is
 * written as a reply in the app.
 */
class GitHubWebhookController extends Controller
{
    /**
     * Issue actions that can change the status we track.
     */
    private const SUPPORTED_ACTIONS = [
        'opened',
        'closed',
        'reopened',
        'deleted',
    ];

    public function handle(Request $request, GitHubIssueService $github): JsonResponse
    {
        if (! $this->verifySignature($request)) {
            Log::warning('Invalid GitHub webhook signature');

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $event = $request->header('X-GitHub-Event');

        if ($event === 'ping') {
            return response()->json(['status' => 'pong']);
        }

        if ($event === 'installation' || $event === 'installation_repositories') {
            return $this->handleInstallationEvent($request);
        }

        if ($event !== 'issues') {
            return response()->json(['status' => 'ignored']);
        }

        $action = $request->input('action');

        if (! in_array($action, self::SUPPORTED_ACTIONS, true)) {
            return response()->json(['status' => 'ignored']);
        }

        $issue = $request->input('issue', []);
        $issueNumber = $issue['number'] ?? null;
        $repository = $request->input('repository.full_name');

        if (! $issueNumber) {
            return response()->json(['error' => 'Missing issue number'], 400);
        }

        $helpRequest = HelpRequest::query()
            ->where('github_issue_number', $issueNumber)
            ->when($repository, fn ($query) => $query->where('github_repository', $repository))
            ->first();

        if (! $helpRequest) {
            Log::info('No help request matched GitHub issue', [
                'issue' => $issueNumber,
                'repository' => $repository,
            ]);

            return response()->json(['status' => 'no_match']);
        }

        if ($action === 'deleted') {
            $helpRequest->update([
                'github_issue_number' => null,
                'github_repository' => null,
                'github_issue_url' => null,
                'github_issue_state' => null,
                'github_synced_at' => now(),
            ]);

            return response()->json(['success' => true, 'status' => 'unlinked']);
        }

        $status = $github->applyIssue($helpRequest, $issue);

        Log::info('Help request status updated from GitHub', [
            'help_request_id' => $helpRequest->id,
            'issue' => $issueNumber,
            'action' => $action,
            'status' => $status->value,
        ]);

        // No closing note is carried across — the discussion on the issue is
        // internal. A note for the requester is sent by replying in the app.
        if ($action === 'closed') {
            HelpRequestNotifier::resolvedForUser($helpRequest->refresh());
        }

        return response()->json(['success' => true, 'status' => $status->value]);
    }

    /**
     * React to the app being removed, suspended or restored in GitHub.
     *
     * Without this the integration would keep failing silently until someone
     * noticed issues were no longer being raised.
     */
    protected function handleInstallationEvent(Request $request): JsonResponse
    {
        $installationId = (int) $request->input('installation.id');
        $action = $request->input('action');

        $settings = HelpRequestSetting::query()->first();

        if (! $settings || $settings->github_installation_id !== $installationId) {
            return response()->json(['status' => 'no_match']);
        }

        if (in_array($action, ['deleted', 'suspend'], true)) {
            $settings->update([
                'github_installation_token' => null,
                'github_installation_token_expires_at' => null,
                'github_installation_failed_at' => now(),
            ]);

            Log::warning('GitHub App installation is no longer usable', [
                'installation_id' => $installationId,
                'action' => $action,
            ]);

            return response()->json(['success' => true, 'status' => 'disconnected']);
        }

        if ($action === 'unsuspend') {
            $settings->update(['github_installation_failed_at' => null]);

            return response()->json(['success' => true, 'status' => 'restored']);
        }

        return response()->json(['status' => 'ignored']);
    }

    /**
     * Verify the `X-Hub-Signature-256` HMAC against the App's webhook secret.
     */
    protected function verifySignature(Request $request): bool
    {
        $secret = config('help-requests.github.webhook_secret');

        if (blank($secret)) {
            Log::warning('GitHub webhook secret not configured, skipping verification');

            return true;
        }

        $signature = $request->header('X-Hub-Signature-256');

        if (blank($signature)) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
