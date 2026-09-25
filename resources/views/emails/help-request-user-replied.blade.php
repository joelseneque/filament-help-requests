<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New reply on help request</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: #ffffff; border-radius: 8px; padding: 40px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);">

        <h1 style="color: #1a1a1a; font-size: 22px; margin-bottom: 20px;">New reply on a help request</h1>

        <p style="margin-bottom: 8px;">
            <strong>{{ \Joelseneque\HelpRequests\HelpRequests::userName($helpRequest->user, 'A user') }}</strong>
            replied to their help request:
        </p>

        <div style="background-color: #f4f4f4; border-radius: 6px; padding: 12px 16px; margin-bottom: 24px; color: #666666; font-size: 14px; white-space: pre-wrap;">{{ \Illuminate\Support\Str::limit($helpRequest->comment, 300) }}</div>

        <h2 style="color: #1a1a1a; font-size: 16px; margin-bottom: 8px;">Their reply</h2>
        <div style="background-color: #f9f9f9; border-radius: 6px; padding: 16px; margin-bottom: 24px; white-space: pre-wrap;">{{ $reply->body }}</div>

        @if ($reply->hasScreenshot())
            <div style="margin-bottom: 24px;">
                <a href="{{ $reply->getScreenshotUrl() }}" style="display: inline-block;">
                    <img src="{{ $reply->getScreenshotUrl() }}" alt="Screenshot" style="max-width: 100%; height: auto; border-radius: 6px; border: 1px solid #eeeeee;">
                </a>
            </div>
        @endif

        <div style="text-align: center; margin-bottom: 8px;">
            <a href="{{ $viewUrl }}" style="display: inline-block; background-color: #1a1a1a; color: #ffffff; text-decoration: none; padding: 10px 24px; border-radius: 6px; font-weight: 600; font-size: 14px;">View &amp; Reply</a>
        </div>

        <hr style="border: none; border-top: 1px solid #eeeeee; margin: 30px 0;">

        <p style="color: #999999; font-size: 12px; text-align: center; margin-bottom: 0;">
            This is an automated message from {{ \Joelseneque\HelpRequests\HelpRequests::appName() }}.
        </p>
    </div>
</body>
</html>
