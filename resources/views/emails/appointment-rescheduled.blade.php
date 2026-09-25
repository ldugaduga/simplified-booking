<!doctype html>
<html>
<body style="font-family: sans-serif; color: #1b1b18;">
    <p>Your {{ $minutes }}-minute meeting with {{ config('app.name') }} has moved.</p>
    <p><strong>New time:</strong> {{ $when }}</p>
    <p>This replaces any earlier invite for this meeting. An updated calendar invite is attached.</p>
</body>
</html>
