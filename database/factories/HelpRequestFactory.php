<?php

namespace Joelseneque\HelpRequests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Models\HelpRequest;

/**
 * @extends Factory<HelpRequest>
 */
class HelpRequestFactory extends Factory
{
    protected $model = HelpRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => HelpRequests::userModel()::factory(),
            'page_url' => $this->faker->url(),
            'page_title' => $this->faker->sentence(3),
            'comment' => $this->faker->paragraph(),
            'screenshot_path' => null,
            'status' => HelpRequestStatus::Open,
            'resolved_at' => null,
        ];
    }

    public function linkedToIssue(int $number = 42, string $repository = 'acme/app'): static
    {
        return $this->state(fn (array $attributes): array => [
            'github_issue_number' => $number,
            'github_repository' => $repository,
            'github_issue_url' => "https://github.com/{$repository}/issues/{$number}",
            'github_issue_state' => 'open',
        ]);
    }
}
