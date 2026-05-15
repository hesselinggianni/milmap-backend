<!DOCTYPE html>
<html>
<head>
    <title>Password Reset Successful</title>
</head>
<body>
    <h2>Hello {{ $name }},</h2>
    <p>Your password has been successfully reset. You can now log in with your new password.</p>
    <p>If you did not request this change, please contact our support team immediately.</p>
    <br>
    <p>Best regards,</p>
    <p>The {{ config('app.name') }} Team</p>
</body>
</html>
