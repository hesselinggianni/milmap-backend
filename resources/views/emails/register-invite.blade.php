<!DOCTYPE html>
<html>
<head>
    <title>Register and join Invitation</title>
</head>
<body>
    <h1>You have been invited to join {{ $workspace->name }}</h1>
    <p>Hi,</p>
    <p>You have been invited to join the workspace "{{ $workspace->name }}" on MilMap.</p>
    <p>Click the button below to accept the invitation:</p>
    <a href="{{ route('workspace.invite.accept', ['id' => $invitation->id]) }}" style="display:inline-block; padding:10px 20px; background-color:#4CAF50; color:white; text-decoration:none; border-radius:5px;">
        Accept Invitation
    </a>
    <p>If you did not expect this invitation, you can safely ignore this email.</p>
    <p>Thanks,<br>Onavan</p>
</body>
</html>
