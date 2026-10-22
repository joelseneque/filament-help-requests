<?php

namespace Joelseneque\HelpRequests\Parley\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Something Parley can look up in the host app — the status of a job, who a
 * record is assigned to — registered with
 * `HelpRequestsPlugin::make()->parleyTools([...])`.
 *
 * A tool runs as the person asking, so it must answer only what that person
 * could see on screen: query through the app's own scoped queries and
 * policies, never around them. Return plain text for the model to read.
 */
interface ParleyTool
{
    /**
     * The name the model calls it by: lowercase letters, digits and
     * underscores.
     */
    public function name(): string;

    /**
     * What it answers and when to use it, written for the model.
     */
    public function description(): string;

    /**
     * A JSON Schema object for the input.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input, Authenticatable $user): string;
}
