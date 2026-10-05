<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <title>{{ $headline }}</title>
</head>
<body style="font-family: Arial, sans-serif; color: #0f172a; line-height: 1.6;">
    <h2 style="margin: 0 0 12px;">{{ $headline }}</h2>

    @if ($body)
        <pre style="white-space: pre-wrap; background: #f1f5f9; padding: 12px; border-radius: 6px; font-size: 13px;">{{ $body }}</pre>
    @endif

    @if ($run)
        <table style="border-collapse: collapse; font-size: 14px;">
            <tr><td style="padding: 2px 12px 2px 0; color: #64748b;">Backup</td><td>{{ $run->name }}</td></tr>
            <tr><td style="padding: 2px 12px 2px 0; color: #64748b;">Status</td><td>{{ $run->status }}</td></tr>
            <tr><td style="padding: 2px 12px 2px 0; color: #64748b;">Pornit</td><td>{{ $run->started_at?->timezone('Europe/Chisinau')->format('Y-m-d H:i') }}</td></tr>
            <tr><td style="padding: 2px 12px 2px 0; color: #64748b;">Durată</td><td>{{ $run->durationSeconds() }}s</td></tr>
            @if ($run->type === 'backup')
                <tr><td style="padding: 2px 12px 2px 0; color: #64748b;">Off-site</td><td>{{ $run->remote_status }}</td></tr>
            @endif
        </table>

        @foreach ($run->artifacts ?? [] as $artifact)
            <p style="margin: 4px 0; font-size: 14px;">
                {{ $artifact['status'] === 'completed' ? '✔' : ($artifact['status'] === 'skipped' ? '–' : '✘') }}
                {{ $artifact['label'] ?? $artifact['component'] }}
                @if (! empty($artifact['error']))
                    <span style="color: #b91c1c;">— {{ $artifact['error'] }}</span>
                @endif
            </p>
        @endforeach
    @endif

    <p><a href="{{ $adminUrl }}">Deschide pagina Backup-uri din admin</a></p>
</body>
</html>
