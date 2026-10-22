<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Pages\ViewHelpRequest;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Livewire\HelpRequestWidget;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\ParleyConversation;
use Joelseneque\HelpRequests\Models\ParleyMessage;
use Joelseneque\HelpRequests\Parley\Contracts\ParleyTool;
use Joelseneque\HelpRequests\Parley\KnowledgeLibrary;
use Joelseneque\HelpRequests\Parley\Parley;
use Joelseneque\HelpRequests\Tests\Fixtures\User;
use Livewire\Livewire;

beforeEach(function (): void {
    Mail::fake();
    config()->set('help-requests.parley.api_key', 'test-key');
    config()->set('help-requests.parley.knowledge_paths', [__DIR__.'/../Fixtures/help']);
    Gate::define('approve_timesheets', fn (User $user): bool => $user->is_admin);
});

/**
 * A Messages API response.
 *
 * @param  list<array<string, mixed>>  $content
 */
function modelReply(array $content, string $stopReason = 'end_turn'): array
{
    return ['id' => 'msg_'.uniqid(), 'type' => 'message', 'role' => 'assistant', 'content' => $content, 'stop_reason' => $stopReason];
}

function textReply(string $text): array
{
    return modelReply([['type' => 'text', 'text' => $text]]);
}

function toolReply(string $name, array|object $input, string $id = 'toolu_1'): array
{
    return modelReply([['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]], 'tool_use');
}

/**
 * @param  list<array<string, mixed>>  $replies
 */
function fakeModel(array $replies): void
{
    $sequence = Http::sequence();

    foreach ($replies as $reply) {
        $sequence->push($reply);
    }

    Http::fake(['api.anthropic.com/*' => $sequence]);
}

it('opens on the chat when it can answer, and hides it without an API key', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->assertSet('tab', 'parley')
        ->assertSee('Ask Parley');

    config()->set('help-requests.parley.api_key', null);

    Livewire::test(HelpRequestWidget::class)
        ->assertSet('tab', 'new')
        ->assertDontSee('Ask Parley');
});

it('answers from the help library and keeps both turns', function () {
    $this->actingAs(User::factory()->create(['name' => 'Dana Scully']));
    fakeModel([textReply('Press **Send to client** on the quote.')]);

    Livewire::test(HelpRequestWidget::class)
        ->set('pageUrl', 'https://example.test/admin/quotes/12')
        ->set('pageTitle', 'Quote Q-12')
        ->call('ask', 'How do I email this to the client?')
        ->assertSet('parleyError', '')
        ->assertSee('How do I email this to the client?')
        ->assertSeeHtml('<strong>Send to client</strong>');

    $conversation = ParleyConversation::query()->sole();

    expect($conversation->visibleMessages()->pluck('text')->all())
        ->toBe(['How do I email this to the client?', 'Press **Send to client** on the quote.'])
        ->and($conversation->page_title)->toBe('Quote Q-12');

    $request = Http::recorded()[0][0];
    $system = $request['system'][0]['text'];

    expect($request->hasHeader('x-api-key', 'test-key'))->toBeTrue()
        ->and($request->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01'))->toBeTrue()
        ->and($request['fallbacks'])->toBe('default')
        ->and($request['model'])->toBe('claude-opus-5-5')
        ->and($request['output_config'])->toBe(['effort' => 'low'])
        ->and($system)->toContain('A "docket" is the paperwork')
        ->and($system)->toContain('How do I send a quote?')
        ->and($request['system'][1]['text'])->toContain('Dana Scully')
        ->and(json_encode($request['messages'][0]['content'], JSON_UNESCAPED_SLASHES))->toContain('Articles about this page: quotes/sending-a-quote');
});

it('leaves articles out for people who may not read them', function () {
    $library = KnowledgeLibrary::make();

    $technician = User::factory()->create(['is_admin' => false]);
    $approver = User::factory()->create(['is_admin' => true]);

    expect($library->readableBy($technician)->pluck('slug')->all())->toBe(['quotes/sending-a-quote'])
        ->and($library->find('approving-timesheets', $technician))->toBeNull()
        ->and($library->readableBy($approver)->pluck('slug')->all())->toBe(['approving-timesheets', 'quotes/sending-a-quote']);
});

it('reads front matter, and falls back to the heading and first paragraph', function () {
    $article = KnowledgeLibrary::article('approving-timesheets', file_get_contents(__DIR__.'/../Fixtures/help/approving-timesheets.md'));

    expect($article->title)->toBe('How do I approve a timesheet?')
        ->and($article->summary)->toBe('Open Employees → Timesheets and press Approve on the week.')
        ->and($article->abilities)->toBe(['approve_timesheets'])
        ->and(KnowledgeLibrary::make()->articles()->firstWhere('slug', 'quotes/sending-a-quote')->pages)
        ->toBe(['admin/quotes', 'admin/quotes/*']);
});

it('reads an article when the model asks for one, and replays the turns unchanged', function () {
    $this->actingAs(User::factory()->create());
    fakeModel([
        toolReply('read_help_article', ['slug' => 'quotes/sending-a-quote']),
        textReply('Open the quote and press Send to client.'),
        textReply('Yes, from the same button.'),
    ]);

    $widget = Livewire::test(HelpRequestWidget::class)
        ->call('ask', 'How do I send a quote?')
        ->call('ask', 'Can I send it again?');

    $answer = ParleyMessage::query()->whereNotNull('text')->where('role', 'assistant')->first();

    expect($answer->meta['articles_read'])->toBe(['quotes/sending-a-quote'])
        ->and(ParleyMessage::query()->count())->toBe(6);

    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();

    expect(json_encode($requests[1]['messages'][2]['content']))->toContain('Press **Send to client**')
        // The second question carries every earlier turn, tool calls included.
        ->and(array_column($requests[2]['messages'], 'role'))->toBe(['user', 'assistant', 'user', 'assistant', 'user']);

    $widget->assertSee('Yes, from the same button.');
});

it('keeps an empty tool input an object when the history is replayed', function () {
    Parley::useTools([new class implements ParleyTool
    {
        public function name(): string
        {
            return 'my_open_jobs';
        }

        public function description(): string
        {
            return 'The jobs assigned to the person asking.';
        }

        public function inputSchema(): array
        {
            return ['type' => 'object', 'properties' => new stdClass];
        }

        public function handle(array $input, $user): string
        {
            return 'J-100 at 12 Smith St';
        }
    }]);

    $this->actingAs(User::factory()->create());
    fakeModel([
        toolReply('my_open_jobs', new stdClass),
        textReply('You have one job, J-100.'),
    ]);

    Livewire::test(HelpRequestWidget::class)->call('ask', 'What am I on today?');

    $second = Http::recorded()[1][0];

    expect($second->body())->toContain('"input":{}')
        ->and(json_encode($second['messages'][2]['content']))->toContain('J-100 at 12 Smith St')
        ->and(collect($second['tools'])->pluck('name')->all())->toContain('my_open_jobs');
});

it('turns a suggested request into a filled-in form that carries the chat', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    fakeModel([
        toolReply('suggest_help_request', ['type' => 'bug', 'summary' => 'The invoice total shows $0 on job J-12.']),
        textReply('That looks like a bug. You can send it with the button below.'),
    ]);

    $widget = Livewire::test(HelpRequestWidget::class)
        ->call('ask', 'The invoice total is $0 on J-12, why?')
        ->assertSee('Raise bug request');

    $answer = ParleyMessage::query()->where('role', 'assistant')->whereNotNull('text')->sole();

    $widget->call('raiseRequest', $answer->id)
        ->assertSet('tab', 'new')
        ->assertSet('category', 'bug')
        ->assertSet('comment', 'The invoice total shows $0 on job J-12.')
        ->assertSee('Your chat with Parley is sent with this request.')
        ->call('submit')
        ->assertHasNoErrors();

    $request = HelpRequest::query()->sole();

    expect($request->parley_conversation_id)->toBe(ParleyConversation::query()->sole()->id)
        ->and($request->is_internal)->toBeFalse();
});

it('files a question it could not answer as one internal request per chat', function () {
    Notification::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    fakeModel([
        toolReply('flag_missing_help', ['question' => 'How do I split an invoice?', 'missing' => 'Nothing covers splitting invoices.']),
        textReply("I don't know that one yet. I've passed it on."),
        toolReply('flag_missing_help', ['question' => 'Can an invoice go to two customers?', 'missing' => 'Billing more than one customer.'], 'toolu_2'),
        textReply("I don't know that either."),
    ]);

    $widget = Livewire::test(HelpRequestWidget::class)
        ->set('pageTitle', 'Invoices')
        ->call('ask', 'How do I split an invoice?')
        ->call('ask', 'Can it go to two customers?');

    $gap = HelpRequest::query()->sole();

    expect($gap->is_internal)->toBeTrue()
        ->and($gap->category)->toBe(HelpRequests::HELP_ARTICLE_CATEGORY)
        ->and($gap->categoryLabel())->toBe('Help article')
        ->and($gap->user_id)->toBe($user->id)
        ->and($gap->page_title)->toBe('Invoices')
        ->and($gap->comment)->toContain('Question: How do I split an invoice?')
        ->and($gap->replies()->sole()->body)->toContain('Can an invoice go to two customers?');

    // It is a note for the team, not a request the person raised.
    $widget->set('tab', 'mine')->assertSee("You haven't sent any help requests yet.", escape: false);
    Notification::assertNothingSentTo($user);
});

it('files a thumbs down as a gap, once', function () {
    $this->actingAs(User::factory()->create());
    fakeModel([textReply('Use the Split button.')]);

    $widget = Livewire::test(HelpRequestWidget::class)->call('ask', 'How do I split an invoice?');
    $answer = ParleyMessage::query()->where('role', 'assistant')->sole();

    $widget->call('rateAnswer', $answer->id, -1)
        ->call('rateAnswer', $answer->id, 1)
        ->call('rateAnswer', $answer->id, -1)
        ->assertSee("Thanks, we'll improve this answer.", escape: false);

    $gap = HelpRequest::query()->sole();

    expect($answer->fresh()->rating)->toBe(-1)
        ->and($gap->is_internal)->toBeTrue()
        ->and($gap->comment)->toContain('Question: How do I split an invoice?')
        ->and($gap->comment)->toContain('Use the Split button.');
});

it('ignores ratings on somebody else\'s answers', function () {
    $conversation = ParleyConversation::create(['user_id' => User::factory()->create()->id]);
    $answer = $conversation->messages()->create(['role' => 'assistant', 'text' => 'Hi', 'payload' => '[]']);

    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)->call('rateAnswer', $answer->id, -1);

    expect($answer->fresh()->rating)->toBeNull()
        ->and(HelpRequest::count())->toBe(0);
});

it('shows a failure and keeps nothing from it', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'Overloaded']], 529)]);

    Livewire::test(HelpRequestWidget::class)
        ->call('ask', 'How do I send a quote?')
        ->assertSet('parleyError', "Parley couldn't answer just now. Try again, or raise a help request.");

    expect(ParleyMessage::count())->toBe(0);
});

it('answers a declined question with a way forward', function () {
    $this->actingAs(User::factory()->create());
    fakeModel([modelReply([], 'refusal')]);

    Livewire::test(HelpRequestWidget::class)
        ->call('ask', 'Something it will not answer')
        ->assertSee("I can't help with that one.", escape: false);
});

it('limits how many questions a person asks in an hour', function () {
    config()->set('help-requests.parley.questions_per_hour', 1);
    $user = User::factory()->create();
    $this->actingAs($user);
    RateLimiter::clear('help-requests:parley:'.$user->id);
    fakeModel([textReply('One.')]);

    Livewire::test(HelpRequestWidget::class)
        ->call('ask', 'First question')
        ->call('ask', 'Second question')
        ->assertSet('parleyError', "That's a lot of questions in an hour. Try again a little later, or raise a help request.");

    Http::assertSentCount(1);
});

it('carries on today\'s chat, and starts a new one each day', function () {
    $this->travelTo(now()->setTime(9, 0));
    $user = User::factory()->create();
    $morning = ParleyConversation::create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test(HelpRequestWidget::class)
        ->assertSet('conversationId', $morning->id)
        ->call('newChat')
        ->assertSet('conversationId', null);

    $this->travelTo(now()->addDay()->setTime(8, 0));

    Livewire::test(HelpRequestWidget::class)->assertSet('conversationId', null);
});

it('ends the day on the Parley clock, not the server\'s', function () {
    config()->set('app.timezone', 'UTC');
    Parley::useTimezone('Australia/Melbourne');
    $user = User::factory()->create();

    // 23:00 UTC on the 1st is 10am on the 2nd in Melbourne.
    $this->travelTo(Carbon\Carbon::parse('2026-10-01 23:00:00', 'UTC'));
    $chat = ParleyConversation::create(['user_id' => $user->id]);

    // 01:00 UTC on the 2nd is still the same Melbourne day.
    $this->travelTo(Carbon\Carbon::parse('2026-10-02 01:00:00', 'UTC'));
    $this->actingAs($user);

    Livewire::test(HelpRequestWidget::class)->assertSet('conversationId', $chat->id);
});

it('lists earlier chats by day and opens one', function () {
    $this->travelTo(now()->setTime(12, 0));
    $user = User::factory()->create();

    $yesterday = ParleyConversation::create(['user_id' => $user->id]);
    $yesterday->messages()->create(['role' => 'user', 'text' => 'Where are the dockets?', 'payload' => '[]']);
    $yesterday->forceFill(['updated_at' => now()->subDay(), 'created_at' => now()->subDay()])->saveQuietly();

    $today = ParleyConversation::create(['user_id' => $user->id]);
    $today->messages()->create(['role' => 'user', 'text' => 'How do I send a quote?', 'payload' => '[]']);

    // Someone else's, and an empty one, are not listed.
    ParleyConversation::create(['user_id' => User::factory()->create()->id])
        ->messages()->create(['role' => 'user', 'text' => 'Not mine', 'payload' => '[]']);
    ParleyConversation::create(['user_id' => $user->id]);

    $this->actingAs($user);

    $widget = Livewire::test(HelpRequestWidget::class)->set('showingChats', true);

    expect(array_keys($widget->instance()->chatHistory))->toBe(['Today', 'Yesterday'])
        ->and(collect($widget->instance()->chatHistory)->flatten(1)->pluck('title')->all())
        ->toBe(['How do I send a quote?', 'Where are the dockets?']);

    $widget->assertSee('Where are the dockets?')
        ->assertDontSee('Not mine')
        ->call('openConversation', $yesterday->id)
        ->assertSet('conversationId', $yesterday->id)
        ->assertSet('showingChats', false)
        ->assertSee('Chat from '.now()->subDay()->format('D j M'));
});

it('will not open somebody else\'s chat', function () {
    $theirs = ParleyConversation::create(['user_id' => User::factory()->create()->id]);
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->call('openConversation', $theirs->id)
        ->assertSet('conversationId', null);
});

it('folds a long chat above its last few messages', function () {
    $user = User::factory()->create();
    $chat = ParleyConversation::create(['user_id' => $user->id]);

    foreach (range(1, 5) as $turn) {
        $chat->messages()->create(['role' => 'user', 'text' => "Question {$turn}", 'payload' => '[]']);
        $chat->messages()->create(['role' => 'assistant', 'text' => "Answer {$turn}", 'payload' => '[]']);
    }

    $this->actingAs($user);

    // Six messages show in full; the four before them are folded, their
    // questions listed one line each.
    Livewire::test(HelpRequestWidget::class)
        ->assertSee(['Earlier in this chat', 'Show all', 'Question 1', 'Question 2'])
        ->assertSeeInOrder(['Earlier in this chat', 'Question 1', 'Question 2', 'Answer 1', 'Fold earlier messages', 'Question 3', 'Answer 5']);
});

it('offers the articles about the current page as starting questions', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->call('loadStarterQuestions', '/admin/quotes/5')
        ->assertSet('starterQuestions', ['How do I send a quote?'])
        ->assertSee('How do I send a quote?');
});

it('shows the chat on the admin request page', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $conversation = ParleyConversation::create(['user_id' => User::factory()->create()->id]);
    $conversation->messages()->create(['role' => 'user', 'text' => 'Where are my dockets?', 'payload' => '[]']);
    $conversation->messages()->create(['role' => 'assistant', 'text' => 'On the **Attendance** tab.', 'payload' => '[]', 'rating' => -1]);

    $request = HelpRequest::factory()->create(['parley_conversation_id' => $conversation->id, 'is_internal' => true, 'category' => HelpRequests::HELP_ARTICLE_CATEGORY]);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Parley conversation')
        ->assertSee('Where are my dockets?')
        ->assertSeeHtml('<strong>Attendance</strong>')
        ->assertSee('marked unhelpful');
});

it('lists the library and the open gaps', function () {
    HelpRequest::factory()->create([
        'is_internal' => true,
        'category' => HelpRequests::HELP_ARTICLE_CATEGORY,
        'comment' => "Parley could not answer this.\n\nQuestion: How do I split an invoice?\nWhat was missing: x",
    ]);

    $this->artisan('help-requests:parley')
        ->expectsOutputToContain('quotes/sending-a-quote')
        ->expectsOutputToContain('1 unanswered question waiting for an article.')
        ->expectsOutputToContain('How do I split an invoice?')
        ->assertSuccessful();
});
