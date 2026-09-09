{{--
  E-mailhandtekening voor de admin-mailing (campagnes + door de admin
  opgemaakte custom-templates). Wordt automatisch meegestuurd via
  emails.partials.unsubscribe-footer, dus geen aanpassing nodig per
  campagne-template. $locale komt mee als view-data (zie
  MailTemplateRegistry::resolve / CampaignMail) — valt terug op 'nl'.
--}}
@php
  $signOffByLocale = [
    'nl' => 'Met vriendelijke groet,',
    'en' => 'Kind regards,',
    'de' => 'Mit freundlichen Grüßen,',
    'es' => 'Saludos cordiales,',
    'fr' => 'Cordialement,',
    'it' => 'Cordiali saluti,',
    'ja' => 'よろしくお願いいたします。',
    'lt' => 'Pagarbiai,',
    'pl' => 'Z poważaniem,',
    'pt' => 'Com os melhores cumprimentos,',
    'tr' => 'Saygılarımızla,',
    'uk' => 'З повагою,',
  ];
  $signOff = $signOffByLocale[$locale ?? 'nl'] ?? $signOffByLocale['nl'];
@endphp

<div style="margin:28px 0 0;padding:20px 0 0;border-top:1px solid #1e293b;">
  <p style="margin:0 0 2px;font-size:13px;line-height:1.6;color:#94a3b8;">
    {{ $signOff }}
  </p>
  <p style="margin:0 0 10px;font-size:13px;line-height:1.6;font-weight:700;color:#e2e8f0;">
    Team MilMap
  </p>
  <p style="margin:0;font-size:12px;line-height:1.7;color:#64748b;">
    <a href="https://milmap.nl" style="color:#2b7fff;text-decoration:none;">milmap.nl</a>
    &nbsp;&middot;&nbsp;
    <a href="mailto:support@milmap.nl" style="color:#2b7fff;text-decoration:none;">support@milmap.nl</a>
  </p>
</div>
