<?php

namespace Joelseneque\HelpRequests\Livewire;

use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Jobs\CreateGitHubIssueForHelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;
use Joelseneque\HelpRequests\Models\ParleyConversation;
use Joelseneque\HelpRequests\Models\ParleyMessage;
use Joelseneque\HelpRequests\Parley\AskParley;
use Joelseneque\HelpRequests\Parley\KnowledgeLibrary;
use Joelseneque\HelpRequests\Parley\Parley;
use Joelseneque\HelpRequests\Parley\RecordHelpArticleGap;
use Joelseneque\HelpRequests\Services\GitHubIssueService;
use Joelseneque\HelpRequests\Support\HelpRequestNotifier;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * The floating "?" button on every panel page: ask Parley, raise a request,
 * and read and reply to your own.
 */
class HelpRequestWidget extends Component
{
    use WithFileUploads;

    protected const FILE_TYPE_MESSAGE = 'Attach an image, PDF, Word, Excel, CSV or text file.';

    public bool $open = false;

    public string $tab = 'new';

    public string $comment = '';

    /**
     * Screenshots keyed by slot. A removed one is set to null rather than
     * unset, so slots never shift away from their notes in $screenshotNotes.
     *
     * @var array<int, TemporaryUploadedFile|null>
     */
    public array $screenshots = [];

    public string $pageUrl = '';

    public string $pageTitle = '';

    public string $category = '';

    public string $videoUrl = '';

    /**
     * The Parley conversation on screen. Null until the first question.
     */
    public ?int $conversationId = null;

    /**
     * The conversation a request being written came out of, so the request
     * carries the chat with it.
     */
    public ?int $requestConversationId = null;

    public string $parleyError = '';

    /**
     * Whether the chat tab is showing the list of earlier chats instead of the
     * one in view.
     */
    public bool $showingChats = false;

    /**
     * Help articles about the page the widget was opened on, offered as
     * starting questions on an empty chat.
     *
     * @var list<string>
     */
    public array $starterQuestions = [];

    /**
     * The panel's brand name, stripped from `document.title` so the widget can
     * say which page the request is about.
     */
    public string $brandName = '';

    /**
     * The request a notification's "View request" button asked us to open, so
     * the thread can be picked out of the list. Null on an ordinary page load.
     */
    public ?int $highlightRequestId = null;

    /**
     * Reply body keyed by help request id, used in the "My Requests" thread.
     *
     * @var array<int, string>
     */
    public array $replyBody = [];

    /**
     * Reply screenshots keyed by help request id, then slot, matching $replyBody.
     *
     * @var array<int, array<int, TemporaryUploadedFile|null>>
     */
    public array $replyScreenshots = [];

    /**
     * Notes for the numbered markers drawn on each screenshot, keyed by the
     * screenshot's slot, in marker order. The markers themselves are burned
     * into the image in the browser; the notes are appended to the comment so
     * they reach every channel.
     *
     * @var array<int, array<int, string|null>>
     */
    public array $screenshotNotes = [];

    /**
     * Marker notes keyed by help request id, matching $replyScreenshots.
     *
     * @var array<int, array<int, array<int, string|null>>>
     */
    public array $replyScreenshotNotes = [];

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'comment' => ['required', 'string', 'min:3', 'max:5000'],
            ...$this->screenshotRules('screenshots', 'screenshotNotes'),
            'category' => ['nullable', 'string', Rule::in(array_keys(HelpRequests::categories()))],
            'videoUrl' => [
                'nullable',
                'url:https',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! HelpRequests::isAllowedVideoUrl((string) $value)) {
                        $fail('Paste a share link from '.implode(' or ', config('help-requests.video.allowed_hosts')).'.');
                    }
                },
            ],
        ];

        if (! HelpRequests::videoLinksEnabled()) {
            unset($rules['videoUrl']);
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    protected function screenshotRules(string $files, string $notes): array
    {
        return [
            $files => ['nullable', 'array', 'max:'.config('help-requests.storage.max_files', 5)],
            "{$files}.*" => [
                'nullable',
                'file',
                'mimetypes:'.implode(',', config('help-requests.storage.accepted_file_types')),
                'extensions:'.implode(',', HelpRequests::acceptedExtensions()),
                'max:'.config('help-requests.storage.max_size_kb'),
            ],
            $notes => ['nullable', 'array'],
            "{$notes}.*" => ['nullable', 'array', 'max:20'],
            "{$notes}.*.*" => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['videoUrl' => 'video link', 'screenshots' => 'attachments', 'screenshots.*' => 'attachment'];
    }

    /**
     * The rule's own message lists raw MIME types, which mean nothing to a requester.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'screenshots.*.mimetypes' => self::FILE_TYPE_MESSAGE,
            'screenshots.*.extensions' => self::FILE_TYPE_MESSAGE,
            'replyScreenshots.*.*.mimetypes' => self::FILE_TYPE_MESSAGE,
            'replyScreenshots.*.*.extensions' => self::FILE_TYPE_MESSAGE,
        ];
    }

    /**
     * Open straight onto a thread when a notification linked here with
     * `?help_request={id}`.
     *
     * This widget renders on every panel page, so `mount()` runs on every
     * request and has to stay cheap and quiet: a missing, unparseable or
     * someone else's id is ignored rather than reported.
     */
    public function mount(): void
    {
        $this->brandName = (string) (Filament::getCurrentPanel()?->getBrandName() ?? '');

        if (Parley::isEnabled()) {
            $this->tab = 'parley';
            $this->conversationId = $this->resumableConversationId();
        }

        $id = (int) request()->query('help_request');

        if ($id <= 0) {
            return;
        }

        $isOwn = HelpRequest::query()
            ->whereKey($id)
            ->where('user_id', auth()->id())
            ->raisedByRequester()
            ->exists();

        if (! $isOwn) {
            return;
        }

        $this->highlightRequestId = $id;
        $this->open = true;
        $this->tab = 'mine';
    }

    #[On('open-help-requests')]
    public function openMyRequests(): void
    {
        $this->open = true;
        $this->tab = 'mine';

        unset($this->myRequests);
    }

    public function submit(): void
    {
        // Removed screenshots are nulls; drop them so the count rule sees real ones.
        $this->screenshots = array_filter($this->screenshots);

        $this->validate();

        $helpRequest = HelpRequest::create([
            'user_id' => auth()->id(),
            'page_url' => $this->pageUrl !== '' ? $this->pageUrl : null,
            'page_title' => $this->pageTitle !== '' ? $this->pageTitle : null,
            'category' => $this->category !== '' ? $this->category : null,
            'parley_conversation_id' => $this->ownConversation($this->requestConversationId)?->getKey(),
            'video_url' => HelpRequests::videoLinksEnabled() && trim($this->videoUrl) !== '' ? trim($this->videoUrl) : null,
            'comment' => $this->withMarkerNotes($this->comment, $this->screenshots, $this->screenshotNotes),
            'attachments' => $this->storeUploads($this->screenshots),
            'status' => HelpRequestStatus::Open,
        ]);

        HelpRequestNotifier::newRequestForAdmins($helpRequest);

        if (HelpRequestSetting::query()->first()?->github_auto_create && app(GitHubIssueService::class)->isConfigured()) {
            CreateGitHubIssueForHelpRequest::dispatch($helpRequest);
        }

        $this->reset('comment', 'screenshots', 'screenshotNotes', 'category', 'videoUrl', 'requestConversationId');
        $this->tab = 'mine';

        unset($this->myRequests);

        Notification::make()
            ->title('Help request sent')
            ->body('Thanks — we\'ll get back to you shortly.')
            ->success()
            ->send();
    }

    public function replyTo(int $helpRequestId): void
    {
        $helpRequest = HelpRequest::query()
            ->where('user_id', auth()->id())
            ->raisedByRequester()
            ->findOrFail($helpRequestId);

        $this->replyScreenshots[$helpRequestId] = array_filter($this->replyScreenshots[$helpRequestId] ?? []);

        $this->validate(
            [
                "replyBody.{$helpRequestId}" => ['required', 'string', 'min:1', 'max:5000'],
                ...$this->screenshotRules("replyScreenshots.{$helpRequestId}", "replyScreenshotNotes.{$helpRequestId}"),
            ],
            attributes: [
                "replyBody.{$helpRequestId}" => 'reply',
                "replyScreenshots.{$helpRequestId}" => 'attachments',
                "replyScreenshots.{$helpRequestId}.*" => 'attachment',
            ],
        );

        $screenshots = $this->replyScreenshots[$helpRequestId];

        $reply = $helpRequest->replies()->create([
            'user_id' => auth()->id(),
            'body' => $this->withMarkerNotes(
                trim($this->replyBody[$helpRequestId]),
                $screenshots,
                $this->replyScreenshotNotes[$helpRequestId] ?? [],
            ),
            'attachments' => $this->storeUploads($screenshots),
        ]);

        if ($helpRequest->hasGithubIssue()) {
            app(GitHubIssueService::class)->postComment($reply);
        }

        HelpRequestNotifier::userReplyForAdmins($helpRequest, $reply);

        unset($this->replyBody[$helpRequestId], $this->replyScreenshots[$helpRequestId], $this->replyScreenshotNotes[$helpRequestId]);
        unset($this->myRequests);

        Notification::make()
            ->title('Reply sent')
            ->success()
            ->send();
    }

    /**
     * Ask Parley a question. The page is captured by the browser just before,
     * as it is for a request.
     */
    public function ask(string $question): void
    {
        $this->parleyError = '';
        $question = trim($question);

        if (! Parley::isEnabled() || mb_strlen($question) < 2) {
            return;
        }

        $question = Str::limit($question, 2000, '');
        $conversation = $this->ownConversation($this->conversationId);

        if ($conversation && $conversation->questionCount() >= (int) config('help-requests.parley.max_questions', 20)) {
            $this->parleyError = 'This chat is getting long. Start a new chat to keep going.';

            return;
        }

        $limiterKey = 'help-requests:parley:'.auth()->id();

        if (RateLimiter::tooManyAttempts($limiterKey, (int) config('help-requests.parley.questions_per_hour', 40))) {
            $this->parleyError = 'That\'s a lot of questions in an hour. Try again a little later, or raise a help request.';

            return;
        }

        RateLimiter::hit($limiterKey, 3600);

        $conversation ??= ParleyConversation::create(['user_id' => auth()->id()]);
        $this->conversationId = $conversation->getKey();

        try {
            AskParley::make()->ask(
                $conversation,
                auth()->user(),
                $question,
                $this->pageUrl !== '' ? $this->pageUrl : null,
                $this->pageTitle !== '' ? $this->pageTitle : null,
            );
        } catch (RuntimeException $exception) {
            report($exception);

            $this->parleyError = Parley::name().' couldn\'t answer just now. Try again, or raise a help request.';
        }

        unset($this->parleyMessages);
    }

    public function newChat(): void
    {
        $this->conversationId = null;
        $this->parleyError = '';
        $this->showingChats = false;

        unset($this->parleyMessages);
    }

    /**
     * Carry on an earlier chat from the list.
     */
    public function openConversation(int $conversationId): void
    {
        if (! $this->ownConversation($conversationId)) {
            return;
        }

        $this->conversationId = $conversationId;
        $this->parleyError = '';
        $this->showingChats = false;

        unset($this->parleyMessages);
    }

    /**
     * The person's chats, newest first, grouped under the day they were last
     * used: Today, Yesterday, then the date.
     *
     * @return array<string, list<array{id: int, title: string, time: string}>>
     */
    #[Computed]
    public function chatHistory(): array
    {
        $timezone = Parley::timezone();
        $today = now($timezone)->startOfDay();

        return ParleyConversation::query()
            ->where('user_id', auth()->id())
            ->whereHas('messages', fn (Builder $query): Builder => $query->where('role', ParleyMessage::ROLE_USER)->whereNotNull('text'))
            ->addSelect(['first_question' => ParleyMessage::query()
                ->select('text')
                ->whereColumn('parley_conversation_id', 'parley_conversations.id')
                ->where('role', ParleyMessage::ROLE_USER)
                ->whereNotNull('text')
                ->orderBy('id')
                ->limit(1)])
            ->latest('updated_at')
            ->limit(40)
            ->get()
            ->groupBy(function (ParleyConversation $conversation) use ($timezone, $today): string {
                $day = $conversation->updated_at->copy()->setTimezone($timezone)->startOfDay();

                return match (true) {
                    $day->equalTo($today) => 'Today',
                    $day->equalTo($today->copy()->subDay()) => 'Yesterday',
                    default => $day->format($day->year === $today->year ? 'D j M' : 'D j M Y'),
                };
            })
            ->map(fn ($conversations): array => $conversations->map(fn (ParleyConversation $conversation): array => [
                'id' => $conversation->getKey(),
                'title' => Str::limit((string) $conversation->getAttribute('first_question'), 60),
                'time' => $conversation->updated_at->copy()->setTimezone($timezone)->format('g:i a'),
            ])->values()->all())
            ->all();
    }

    /**
     * What the chat in view is: today's, or the day an earlier one was had.
     */
    #[Computed]
    public function conversationLabel(): string
    {
        $conversation = $this->ownConversation($this->conversationId);

        if ($conversation === null) {
            return 'New chat';
        }

        $timezone = Parley::timezone();
        $day = $conversation->created_at->copy()->setTimezone($timezone);

        return $day->isSameDay(now($timezone)) ? "Today's chat" : 'Chat from '.$day->format('D j M');
    }

    /**
     * Thumbs up or down on an answer. Down also files the question as a gap
     * in the help library: an answer somebody found unhelpful is the
     * clearest sign an article is missing or wrong.
     */
    public function rateAnswer(int $messageId, int $rating): void
    {
        $message = $this->ownAnswer($messageId);
        $rating = $rating >= 0 ? 1 : -1;

        if ($message === null || $message->rating === $rating) {
            return;
        }

        $message->update(['rating' => $rating]);

        if ($rating < 0 && ! isset($message->meta['help_article_request_id'])) {
            $question = $message->conversation->messages()
                ->where('id', '<', $message->getKey())
                ->where('role', ParleyMessage::ROLE_USER)
                ->whereNotNull('text')
                ->latest('id')
                ->value('text');

            $gap = RecordHelpArticleGap::record(
                $message->conversation,
                (string) $question,
                'The answer was marked unhelpful. '.Parley::name().' said: '.Str::limit((string) $message->text, 600),
            );

            $message->update(['meta' => [...($message->meta ?? []), 'help_article_request_id' => $gap->getKey()]]);
        }

        unset($this->parleyMessages);
    }

    /**
     * Move from the chat to the request form, filled in with what Parley
     * suggested — or, with no suggestion, the last question asked.
     */
    public function raiseRequest(?int $messageId = null): void
    {
        $conversation = $this->ownConversation($this->conversationId);
        $suggestion = $messageId ? $this->ownAnswer($messageId)?->suggestedRequest() : null;

        if ($suggestion) {
            $this->comment = $suggestion['summary'];
            $this->category = $suggestion['type'] ?? '';
        } elseif ($conversation && $this->comment === '') {
            $this->comment = (string) $conversation->messages()
                ->where('role', ParleyMessage::ROLE_USER)
                ->whereNotNull('text')
                ->latest('id')
                ->value('text');
        }

        $this->requestConversationId = $conversation?->getKey();
        $this->tab = 'new';
    }

    /**
     * @return Collection<int, ParleyMessage>
     */
    #[Computed]
    public function parleyMessages(): Collection
    {
        return $this->ownConversation($this->conversationId)?->visibleMessages()->get()
            ?? new Collection;
    }

    #[Computed]
    public function parleyName(): string
    {
        return Parley::name();
    }

    #[Computed]
    public function parleyEnabled(): bool
    {
        return Parley::isEnabled();
    }

    /**
     * Questions to start an empty chat with: the titles of the articles about
     * the page the widget is on. Read from the library once, when the chat
     * tab is first drawn on a page.
     */
    public function loadStarterQuestions(string $path): void
    {
        if (! Parley::isEnabled()) {
            return;
        }

        $this->starterQuestions = KnowledgeLibrary::make()
            ->aboutPath((string) parse_url($path, PHP_URL_PATH), auth()->user())
            ->take(3)
            ->pluck('title')
            ->all();
    }

    protected function ownConversation(?int $id): ?ParleyConversation
    {
        if (! $id) {
            return null;
        }

        return ParleyConversation::query()->where('user_id', auth()->id())->find($id);
    }

    protected function ownAnswer(int $messageId): ?ParleyMessage
    {
        return ParleyMessage::query()
            ->whereKey($messageId)
            ->where('role', ParleyMessage::ROLE_ASSISTANT)
            ->whereNotNull('text')
            ->whereHas('conversation', fn (Builder $query): Builder => $query->where('user_id', auth()->id()))
            ->first();
    }

    /**
     * Today's chat carries on when the widget is opened again — on the next
     * page, or after a reload. Each day starts a fresh one; yesterday's is in
     * the list of chats. "Today" is on the Parley clock, so a chat at 9am in
     * Melbourne is not yesterday's because the server keeps UTC.
     */
    protected function resumableConversationId(): ?int
    {
        return ParleyConversation::query()
            ->where('user_id', auth()->id())
            ->where('updated_at', '>=', now(Parley::timezone())->startOfDay()->setTimezone(config('app.timezone')))
            ->latest('updated_at')
            ->value('id');
    }

    /**
     * Notes keep their marker's number, so a blank note in the middle does not
     * shift the numbers away from the dots burned into the image. Screenshots
     * are numbered in the order they are stored, and only when there are
     * several. Notes for a removed screenshot are dropped with it. Other
     * files carry no markers, so they don't count towards the numbering.
     *
     * @param  array<int, TemporaryUploadedFile|null>  $screenshots
     * @param  array<int, array<int, string|null>>  $notes
     */
    protected function withMarkerNotes(string $text, array $screenshots, array $notes): string
    {
        $screenshots = array_filter(
            $screenshots,
            fn (?TemporaryUploadedFile $file): bool => str_starts_with((string) $file?->getMimeType(), 'image/'),
        );
        $number = 0;

        foreach (array_keys($screenshots) as $slot) {
            $number++;
            $lines = [];

            foreach (array_values($notes[$slot] ?? []) as $index => $note) {
                if (trim((string) $note) !== '') {
                    $lines[] = ($index + 1).'. '.trim($note);
                }
            }

            if ($lines !== []) {
                $label = count($screenshots) > 1 ? "Marked on screenshot {$number}:" : 'Marked on screenshot:';
                $text .= "\n\n{$label}\n".implode("\n", $lines);
            }
        }

        return $text;
    }

    /**
     * Each file keeps its original name inside its own folder, so the stored
     * basename doubles as the display name without two uploads colliding.
     *
     * @param  array<int, TemporaryUploadedFile|null>  $files
     * @return list<string>|null
     */
    protected function storeUploads(array $files): ?array
    {
        $paths = array_values(array_filter(array_map(
            fn (TemporaryUploadedFile $file): string|false => $file->storeAs(
                config('help-requests.storage.directory').'/'.Str::ulid(),
                HelpRequests::attachmentFileName($file->getClientOriginalName()),
                config('help-requests.storage.disk'),
            ),
            array_filter($files),
        )));

        return $paths === [] ? null : $paths;
    }

    /**
     * @return Collection<int, HelpRequest>
     */
    #[Computed]
    public function myRequests(): Collection
    {
        $requests = HelpRequest::query()
            ->where('user_id', auth()->id())
            ->raisedByRequester()
            ->with(['replies.user'])
            ->latest()
            ->limit(20)
            ->get();

        /*
         * A notification can link to a request older than the twenty most
         * recent, and a highlight pointing at a row that was never rendered is
         * just another dead link. It goes to the top: it is what the user
         * clicked through to see.
         */
        if ($this->highlightRequestId && ! $requests->contains('id', $this->highlightRequestId)) {
            $linked = HelpRequest::query()
                ->where('user_id', auth()->id())
                ->raisedByRequester()
                ->with(['replies.user'])
                ->find($this->highlightRequestId);

            if ($linked) {
                $requests->prepend($linked);
            }
        }

        return $requests;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function categories(): array
    {
        return HelpRequests::categories();
    }

    public function render(): View
    {
        return view('help-requests::livewire.help-request-widget');
    }
}
