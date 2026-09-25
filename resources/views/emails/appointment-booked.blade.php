<!doctype html>
<html>
<body style="font-family: sans-serif; color: #1b1b18;">
    <p>You're booked for a {{ $minutes }}-minute meeting with {{ config('app.name') }}.</p>
    <p><strong>When:</strong> {{ $when }}</p>
    <p>A calendar invite is attached.</p>
</body>
</html>
