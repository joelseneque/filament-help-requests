<?php

use Joelseneque\HelpRequests\Models\HelpRequestSetting;
use Joelseneque\HelpRequests\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Credentials for a GitHub App with a throwaway private key.
 */
function configureGitHubApp(): void
{
    static $privateKey = null;

    $privateKey ??= (function (): string {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return $pem;
    })();

    config()->set('help-requests.github.app_id', '12345');
    config()->set('help-requests.github.app_slug', 'acme-help');
    config()->set('help-requests.github.private_key', $privateKey);
    config()->set('help-requests.github.webhook_secret', 'webhook-secret');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeGitHubSettings(array $attributes = []): HelpRequestSetting
{
    configureGitHubApp();

    return HelpRequestSetting::create(array_merge([
        'github_enabled' => true,
        'github_repository' => 'acme/app',
        'github_installation_id' => 555,
        'github_account_login' => 'acme',
        'github_installation_token' => 'ghs_installationtoken',
        'github_installation_token_expires_at' => now()->addHour(),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $issue
 * @return array<string, mixed>
 */
function githubPayload(string $action, array $issue = [], string $repository = 'acme/app'): array
{
    return [
        'action' => $action,
        'issue' => array_merge([
            'number' => 42,
            'state' => $action === 'closed' ? 'closed' : 'open',
            'html_url' => 'https://github.com/acme/app/issues/42',
        ], $issue),
        'repository' => ['full_name' => $repository],
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function postGithubWebhook(array $payload, ?string $secret = 'webhook-secret', string $event = 'issues')
{
    $body = json_encode($payload);

    $headers = ['X-GitHub-Event' => $event];

    if ($secret !== null) {
        $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return test()->call('POST', route('help-requests.webhook'), [], [], [], collect($headers)
        ->mapWithKeys(fn ($value, $key) => ['HTTP_'.str_replace('-', '_', strtoupper($key)) => $value])
        ->merge(['CONTENT_TYPE' => 'application/json'])
        ->all(), $body);
}

/**
 * The organisation's issue fields as GitHub lists them: the default Priority
 * and Target date fields.
 *
 * @param  list<string>  $priorityOptions
 * @return list<array<string, mixed>>
 */
function fakeIssueFields(array $priorityOptions = ['Urgent', 'High', 'Medium', 'Low']): array
{
    return [
        [
            'id' => 11,
            'name' => 'Priority',
            'data_type' => 'single_select',
            'options' => array_map(fn (string $name): array => ['id' => crc32($name), 'name' => $name], $priorityOptions),
        ],
        ['id' => 12, 'name' => 'Target date', 'data_type' => 'date', 'options' => null],
        ['id' => 13, 'name' => 'Effort', 'data_type' => 'single_select', 'options' => [['id' => 1, 'name' => 'High']]],
    ];
}
