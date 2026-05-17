<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Nieuwe gebruiker</title>
</head>
<body>
    <h2>Nieuwe gebruiker geregistreerd</h2>

    <p>
        Er is een nieuwe gebruiker geregistreerd op MilMap.
    </p>

    <ul>
        <li><strong>Email:</strong> {{ $user->email }}</li>
        <li><strong>ID:</strong> {{ $user->id }}</li>
        <li><strong>Datum:</strong> {{ now() }}</li>
    </ul>
</body>
</html>