@extends('emails.layout')
@section('title', 'Uitnodiging — MilMap')
@section('body')

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="vertical-align:top;width:56px;">
        <div style="width:48px;height:48px;background:#1a2433;border:1px solid #2a3a52;
                    border-radius:10px;text-align:center;line-height:48px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
               fill="none" stroke="#2b7fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
               style="display:inline-block;vertical-align:middle;">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
        </div>
      </td>
      <td style="vertical-align:middle;padding-left:14px;">
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:-0.01em;line-height:1.2;">
          Uitnodiging ontvangen
        </h1>
        <p style="margin:0;font-size:13px;color:#94a3b8;">U bent uitgenodigd voor een workspace</p>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#1e293b;margin:0 0 24px;"></div>

  <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7e8a9c;">
    Workspace toegang
  </p>
  <p style="margin:0 0 24px;font-size:14px;line-height:1.7;color:#cbd5e1;">
    U bent uitgenodigd om deel te nemen aan de workspace
    <strong style="color:#f8fafc;">{{ $workspace->name }}</strong> op het MilMap Training Platform.
    Klik op de knop hieronder om de uitnodiging te accepteren.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="border-radius:8px;background:#2b7fff;">
        <a href="{{ route('workspace.invite.accept', ['id' => $invitation->id]) }}"
           style="display:inline-block;height:44px;padding:0 24px;line-height:44px;
                  font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">
          Uitnodiging accepteren
        </a>
      </td>
    </tr>
  </table>

  <div style="height:1px;background:#1e293b;margin:0 0 20px;"></div>

  <p style="margin:0;padding:12px 16px;background:#0d1320;border:1px solid #1e293b;border-radius:8px;
            font-size:13px;color:#94a3b8;line-height:1.5;">
    Verwacht u deze uitnodiging niet? Dan kunt u deze e-mail veilig negeren.
  </p>

@endsection
