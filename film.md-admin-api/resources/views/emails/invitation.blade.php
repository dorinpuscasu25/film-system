<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $appName }} invitation</title>
</head>
<body style="font-family: Arial, sans-serif; color: #0f172a; line-height: 1.6;">
    <p>Hello{{ $inviteeName ? ' '.$inviteeName : '' }},</p>

    <p>{{ $inviterName }} invited you to join {{ $appName }}.</p>

    @if ($roleNames !== [])
        <p>Your roles: <strong>{{ implode(', ', $roleNames) }}</strong></p>
    @endif

    <p>
        Open this link to accept the invitation and set your password:
        <br>
        <a href="{{ $acceptUrl }}">{{ $acceptUrl }}</a>
    </p>

    @if ($expiresAt)
        <p>This invitation expires on {{ $expiresAt->timezone(config('app.timezone'))->format('Y-m-d H:i') }}.</p>
    @endif

    <p>If you were not expecting this invite, you can ignore this email.</p>
</body>
</html>
