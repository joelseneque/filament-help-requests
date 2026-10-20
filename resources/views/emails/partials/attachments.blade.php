{{-- Images inline, other files as named links. @param HelpRequest|HelpRequestReply $record --}}
@foreach ($record->imageAttachmentPaths() as $path)
    <div style="margin-bottom: 24px;">
        <a href="{{ $record->attachmentUrl($path) }}" style="display: inline-block;">
            <img src="{{ $record->attachmentUrl($path) }}" alt="{{ $record->attachmentName($path) }}" style="max-width: 100%; height: auto; border-radius: 6px; border: 1px solid #eeeeee;">
        </a>
    </div>
@endforeach
@if ($record->fileAttachmentPaths() !== [])
    <p style="margin-bottom: 24px;">
        <strong>Attached:</strong><br>
        @foreach ($record->fileAttachmentPaths() as $path)
            <a href="{{ $record->attachmentUrl($path) }}">{{ $record->attachmentName($path) }}</a>@if (! $loop->last)<br>@endif
        @endforeach
    </p>
@endif
