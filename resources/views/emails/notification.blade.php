{{-- resources/views/emails/notification.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <title>{{ $emailData['subject'] }}</title>
</head>
<body>
    <p>{{ $content }}</p>
</body>
</html>
