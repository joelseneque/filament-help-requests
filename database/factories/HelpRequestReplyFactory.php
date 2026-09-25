<?php

namespace Joelseneque\HelpRequests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestReply;

/**
 * @extends Factory<HelpRequestReply>
 */
class HelpRequestReplyFactory extends Factory
{
    protected $model = HelpRequestReply::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'help_request_id' => HelpRequest::factory(),
            'user_id' => HelpRequests::userModel()::factory(),
            'body' => $this->faker->paragraph(),
            'screenshot_path' => null,
        ];
    }

    public function withScreenshot(string $path = 'help-requests/reply-screenshot.png'): static
    {
        return $this->state(fn (array $attributes): array => [
            'screenshot_path' => $path,
        ]);
    }
}
