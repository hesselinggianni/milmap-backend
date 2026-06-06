<?php

namespace App\Services;

use App\Models\MailAccount;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

/**
 * MailboxService — thin wrapper around webklex/php-imap (reading + flagging)
 * and Symfony Mailer (sending) that operates on a per-inbox {@see MailAccount}.
 *
 * Every public method builds a fresh, dynamically-configured client from the
 * account's *decrypted* credentials. Secrets never leave this layer: they are
 * read from the encrypted model casts, handed straight to the transport, and
 * are never logged or returned. Exceptions are rethrown as generic
 * RuntimeExceptions so connection errors can surface to the admin UI without
 * echoing passwords.
 */
class MailboxService
{
    /**
     * Build and connect a webklex IMAP client for the given inbox.
     */
    protected function client(MailAccount $account)
    {
        $cm = new ClientManager();

        $client = $cm->make([
            'host'          => $account->imap_host,
            'port'          => (int) $account->imap_port,
            'encryption'    => $this->imapEncryption($account->imap_encryption),
            'validate_cert' => (bool) $account->imap_validate_cert,
            'username'      => $account->imapLogin(),
            'password'      => (string) $account->imap_password,
            'protocol'      => 'imap',
            'authentication' => null,
        ]);

        try {
            $client->connect();
        } catch (Throwable $e) {
            throw new RuntimeException('IMAP-verbinding mislukt: ' . $this->cleanError($e->getMessage()));
        }

        return $client;
    }

    /**
     * Map our stored encryption label to a webklex-understood value.
     * 'none' disables encryption entirely (boolean false).
     */
    protected function imapEncryption(?string $enc)
    {
        $enc = strtolower((string) $enc);
        return match ($enc) {
            '', 'none', 'false' => false,
            'starttls'          => 'starttls',
            'tls'               => 'tls',
            default             => 'ssl',
        };
    }

    /**
     * Probe both IMAP and SMTP so the admin gets immediate feedback when
     * saving / testing an inbox. Returns a per-protocol ok/error breakdown.
     */
    public function test(MailAccount $account): array
    {
        $result = ['imap' => false, 'smtp' => false, 'imap_error' => null, 'smtp_error' => null];

        try {
            $client = $this->client($account);
            $client->getFolders(); // forces a real LIST round-trip
            $client->disconnect();
            $result['imap'] = true;
        } catch (Throwable $e) {
            $result['imap_error'] = $this->cleanError($e->getMessage());
        }

        try {
            $transport = $this->smtpTransport($account);
            $transport->start();
            $transport->stop();
            $result['smtp'] = true;
        } catch (Throwable $e) {
            $result['smtp_error'] = $this->cleanError($e->getMessage());
        }

        return $result;
    }

    /**
     * List the inbox's folders as a flat [{path, name, unread}] array.
     */
    public function folders(MailAccount $account): array
    {
        $client = $this->client($account);
        $out = [];

        try {
            foreach ($client->getFolders(false) as $folder) {
                $unread = 0;
                try {
                    $unread = $folder->query()->whereUnseen()->setFetchBody(false)->get()->count();
                } catch (Throwable $e) {
                    // some special-use folders aren't selectable — skip the count
                }
                $out[] = [
                    'path'   => $folder->path,
                    'name'   => $folder->name,
                    'unread' => $unread,
                ];
            }
        } finally {
            $client->disconnect();
        }

        return $out;
    }

    /**
     * Fetch one page of message headers (newest first) for a folder.
     * Bodies are intentionally NOT fetched here to keep the list snappy.
     */
    public function messages(MailAccount $account, string $folderPath, int $page, int $perPage): array
    {
        $client = $this->client($account);

        try {
            $folder = $client->getFolderByPath($folderPath) ?: $client->getFolder($folderPath);

            $total = 0;
            try {
                $examine = $folder->examine();
                $total = (int) ($examine['exists'] ?? 0);
            } catch (Throwable $e) {
                $total = 0;
            }

            // whereAll() sets the "ALL" search key — without any criterion the
            // server rejects the bare "UID SEARCH" with "Missing search parameters".
            $messages = $folder->query()
                ->whereAll()
                ->setFetchOrder('desc')
                ->setFetchBody(false)
                ->limit($perPage, max(1, $page))
                ->get();

            $items = [];
            foreach ($messages as $message) {
                $items[] = $this->summarise($message);
            }

            return [
                'messages' => $items,
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
                'has_more' => ($page * $perPage) < $total,
            ];
        } catch (Throwable $e) {
            throw new RuntimeException('Map kon niet geladen worden: ' . $this->cleanError($e->getMessage()));
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Full message: sanitised HTML body, plaintext fallback and attachment
     * metadata. Marks the message \Seen on the server unless $peek is true.
     */
    public function show(MailAccount $account, string $folderPath, int $uid, bool $peek = false): array
    {
        $client = $this->client($account);

        try {
            $folder = $client->getFolderByPath($folderPath) ?: $client->getFolder($folderPath);
            $message = $folder->query()->getMessageByUid($uid);

            if (! $message) {
                throw new RuntimeException('Bericht niet gevonden.');
            }

            $html = $message->getHTMLBody();
            $text = $message->getTextBody();

            $attachments = [];
            foreach ($message->getAttachments() as $i => $att) {
                $attachments[] = [
                    'index'     => (int) $i,
                    'name'      => $att->getName() ?: ('bijlage-' . $i),
                    'size'      => (int) $att->getSize(),
                    'mime'      => $att->getMimeType() ?: 'application/octet-stream',
                    'inline'    => method_exists($att, 'getDisposition')
                        ? (strtolower((string) $att->getDisposition()) === 'inline')
                        : false,
                ];
            }

            if (! $peek) {
                try {
                    $message->setFlag('Seen');
                } catch (Throwable $e) {
                    // read-only mailbox — non-fatal
                }
            }

            $flags = $this->flagMap($message);

            return [
                'uid'         => $uid,
                'message_id'  => (string) $message->getMessageId(),
                'subject'     => (string) $message->getSubject(),
                'from'        => $this->addressList($message->getFrom()),
                'to'          => $this->addressList($message->getTo()),
                'cc'          => $this->addressList($message->getCc()),
                'reply_to'    => $this->addressList($message->getReplyTo()),
                'date'        => optional($message->getDate())->first()?->toIso8601String(),
                'html'        => $html ? $this->sanitiseHtml($html) : null,
                'text'        => $text ?: null,
                'attachments' => $attachments,
                'flags'       => $flags,
            ];
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('Bericht kon niet geopend worden: ' . $this->cleanError($e->getMessage()));
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Return a single attachment's raw bytes + metadata for streaming download.
     */
    public function attachment(MailAccount $account, string $folderPath, int $uid, int $index): array
    {
        $client = $this->client($account);

        try {
            $folder = $client->getFolderByPath($folderPath) ?: $client->getFolder($folderPath);
            $message = $folder->query()->getMessageByUid($uid);

            if (! $message) {
                throw new RuntimeException('Bericht niet gevonden.');
            }

            $attachments = $message->getAttachments();
            $att = $attachments[$index] ?? null;
            if (! $att) {
                throw new RuntimeException('Bijlage niet gevonden.');
            }

            return [
                'name'    => $att->getName() ?: ('bijlage-' . $index),
                'mime'    => $att->getMimeType() ?: 'application/octet-stream',
                'content' => $att->getContent(),
            ];
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('Bijlage kon niet geladen worden: ' . $this->cleanError($e->getMessage()));
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Set or clear a server-side flag (Seen | Flagged | Answered | Deleted).
     */
    public function setFlag(MailAccount $account, string $folderPath, int $uid, string $flag, bool $value): array
    {
        $allowed = ['Seen', 'Flagged', 'Answered', 'Deleted'];
        $flag = ucfirst(strtolower($flag));
        if (! in_array($flag, $allowed, true)) {
            throw new RuntimeException('Onbekende vlag.');
        }

        $client = $this->client($account);

        try {
            $folder = $client->getFolderByPath($folderPath) ?: $client->getFolder($folderPath);
            $message = $folder->query()->getMessageByUid($uid);
            if (! $message) {
                throw new RuntimeException('Bericht niet gevonden.');
            }

            if ($value) {
                $message->setFlag($flag);
            } else {
                $message->unsetFlag($flag);
            }

            return $this->flagMap($message);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('Vlag kon niet aangepast worden: ' . $this->cleanError($e->getMessage()));
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Send (or reply to) a message over SMTP. Appends the inbox signature,
     * carries In-Reply-To/References when replying, attaches uploaded files,
     * and flags the original \Answered on a successful reply.
     *
     * @param  array<string,mixed>      $data         to[], cc[], subject, body_html, in_reply_to, references
     * @param  array<int,UploadedFile>  $attachments
     */
    public function send(MailAccount $account, array $data, array $attachments = []): array
    {
        $transport = $this->smtpTransport($account);
        $mailer = new Mailer($transport);

        $email = new Email();
        $email->from(new Address($account->email, $account->from_name ?: $account->name));

        foreach ($this->normaliseRecipients($data['to'] ?? []) as $addr) {
            $email->addTo($addr);
        }
        foreach ($this->normaliseRecipients($data['cc'] ?? []) as $addr) {
            $email->addCc($addr);
        }

        if (empty($email->getTo())) {
            throw new RuntimeException('Geen geldige ontvanger opgegeven.');
        }

        $email->subject((string) ($data['subject'] ?? '(geen onderwerp)'));

        $bodyHtml = (string) ($data['body_html'] ?? '');
        $fullHtml = $this->composeHtml($bodyHtml, $account->signature_html);
        $email->html($fullHtml);
        $email->text($this->htmlToText($fullHtml));

        // Threading headers so replies thread correctly in the recipient's client.
        $inReplyTo = $this->cleanMessageId($data['in_reply_to'] ?? null);
        if ($inReplyTo) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $inReplyTo);
            $references = $this->cleanMessageId($data['references'] ?? null) ?: $inReplyTo;
            $email->getHeaders()->addIdHeader('References', $references);
        }

        foreach ($attachments as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $email->attach(
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName(),
                    $file->getClientMimeType() ?: 'application/octet-stream'
                );
            }
        }

        try {
            $mailer->send($email);
        } catch (Throwable $e) {
            throw new RuntimeException('Verzenden mislukt: ' . $this->cleanError($e->getMessage()));
        }

        // Best-effort: flag the original message \Answered.
        if (! empty($data['reply_folder']) && ! empty($data['reply_uid'])) {
            try {
                $this->setFlag($account, (string) $data['reply_folder'], (int) $data['reply_uid'], 'Answered', true);
            } catch (Throwable $e) {
                // non-fatal
            }
        }

        return ['sent' => true];
    }

    // ── Internals ──────────────────────────────────────────────────────

    protected function smtpTransport(MailAccount $account): EsmtpTransport
    {
        $enc = strtolower((string) $account->smtp_encryption);
        // ssl → implicit TLS (typically :465). tls/starttls → STARTTLS. none → plain.
        $implicitTls = $enc === 'ssl';

        $transport = new EsmtpTransport(
            $account->smtp_host,
            (int) $account->smtp_port,
            $implicitTls
        );

        if ($enc === 'none' && method_exists($transport, 'setAutoTls')) {
            $transport->setAutoTls(false);
        }

        $transport->setUsername($account->smtpLogin());
        $transport->setPassword((string) $account->smtp_password);

        return $transport;
    }

    /**
     * Compact header view used by the message list.
     */
    protected function summarise($message): array
    {
        return [
            'uid'             => (int) $message->getUid(),
            'subject'         => (string) $message->getSubject(),
            'from'            => $this->addressList($message->getFrom()),
            'date'            => optional($message->getDate())->first()?->toIso8601String(),
            'has_attachments' => method_exists($message, 'hasAttachments') ? (bool) $message->hasAttachments() : false,
            'flags'           => $this->flagMap($message),
        ];
    }

    /**
     * Normalise webklex flag collection into the booleans the UI cares about.
     */
    protected function flagMap($message): array
    {
        $flags = [];
        try {
            foreach ($message->getFlags() as $flag) {
                $flags[] = strtolower(ltrim((string) $flag, '\\'));
            }
        } catch (Throwable $e) {
            // ignore
        }

        return [
            'seen'     => in_array('seen', $flags, true),
            'flagged'  => in_array('flagged', $flags, true),
            'answered' => in_array('answered', $flags, true),
        ];
    }

    /**
     * Convert a webklex address attribute into [{name, email}] entries.
     *
     * Robust against the several shapes webklex/php-imap returns across versions:
     * an iterable Attribute of Address objects, a single Address, a plain header
     * string ("Name <a@b>, c@d"), or an array. Crucially we read mail/personal
     * via null-coalescing property access (which triggers the Address __get) and
     * fall back to getters / __toString — the old property_exists() check
     * returned false on builds that expose those via magic, silently dropping the
     * sender so the inbox showed no "from".
     */
    protected function addressList($attribute): array
    {
        if (! $attribute) {
            return [];
        }

        if (is_string($attribute)) {
            return $this->parseAddressStrings($attribute);
        }

        $items = is_iterable($attribute) ? $attribute : [$attribute];

        $out = [];
        foreach ($items as $addr) {
            $entry = $this->extractAddress($addr);
            if ($entry) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Pull a single {name, email} pair out of whatever an address entry is.
     */
    protected function extractAddress($addr): ?array
    {
        if ($addr === null) {
            return null;
        }

        if (is_string($addr)) {
            return $this->parseAddressStrings($addr)[0] ?? null;
        }

        $email = null;
        $name  = null;

        if (is_object($addr)) {
            // ?? uses isset() semantics, so undeclared properties resolved via a
            // magic __get still work and undefined ones don't warn.
            $email = $addr->mail ?? $addr->email ?? $addr->address ?? null;
            $name  = $addr->personal ?? $addr->name ?? null;

            if (! $email && method_exists($addr, 'getEmail')) {
                $email = $addr->getEmail();
            }
            if (! $name && method_exists($addr, 'getName')) {
                $name = $addr->getName();
            }
            // Last resort: __toString() yields the full "Name <email>".
            if (! $email && method_exists($addr, '__toString')) {
                $parsed = $this->parseAddressStrings((string) $addr)[0] ?? null;
                if ($parsed) {
                    $email = $parsed['email'];
                    $name  = $name ?: $parsed['name'];
                }
            }
        } elseif (is_array($addr)) {
            $email = $addr['mail'] ?? $addr['email'] ?? $addr['address'] ?? null;
            $name  = $addr['personal'] ?? $addr['name'] ?? null;
        }

        $email = is_string($email) ? trim($email) : null;
        $name  = is_string($name) ? trim($name) : null;

        if (! $email) {
            return null;
        }

        return ['name' => ($name !== null && $name !== '' ? $name : null), 'email' => $email];
    }

    /**
     * Parse a raw header value ("Name <a@b>, c@d") into [{name, email}] entries.
     */
    protected function parseAddressStrings(string $raw): array
    {
        $out = [];
        // Split on commas that aren't inside a <...> bracket.
        foreach (preg_split('/,(?![^<]*>)/', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(.*)<([^>]+)>\s*$/', $part, $m)) {
                $name  = trim($m[1], " \t\"'");
                $email = trim($m[2]);
            } else {
                $name  = null;
                $email = $part;
            }
            if ($email !== '') {
                $out[] = ['name' => ($name !== null && $name !== '' ? $name : null), 'email' => $email];
            }
        }

        return $out;
    }

    /**
     * Accepts a list of "email" strings or {email} objects and returns Address[].
     *
     * @return array<int,Address>
     */
    protected function normaliseRecipients($value): array
    {
        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            $email = is_array($entry) ? ($entry['email'] ?? null) : $entry;
            $name  = is_array($entry) ? ($entry['name'] ?? null) : null;
            $email = trim((string) $email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[] = $name ? new Address($email, (string) $name) : new Address($email);
            }
        }

        return $out;
    }

    /**
     * Body + per-inbox signature, wrapped in a tidy container.
     */
    protected function composeHtml(string $body, ?string $signature): string
    {
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1f2937;">';
        $html .= $body !== '' ? $body : '';
        if (filled($signature)) {
            $html .= '<br><br><div class="milmap-signature">' . $signature . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    protected function htmlToText(string $html): string
    {
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html);
        $text = preg_replace('/<\s*\/p\s*>/i', "\n\n", $text);
        $text = strip_tags($text);

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Defense-in-depth: strip active content from incoming HTML before it is
     * sent to the browser. The frontend ALSO renders this inside a sandboxed
     * iframe (scripts disabled), so this is a second layer, not the only one.
     */
    protected function sanitiseHtml(string $html): string
    {
        $html = preg_replace('#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is', '', $html);
        $html = preg_replace('#<\s*(iframe|object|embed|form|meta|link|base)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = preg_replace('#<\s*(iframe|object|embed|meta|link|base)\b[^>]*/?\s*>#is', '', $html);
        // Strip inline event handlers (on*) and javascript: URLs.
        $html = preg_replace('#\son\w+\s*=\s*"[^"]*"#i', '', $html);
        $html = preg_replace("#\son\w+\s*=\s*'[^']*'#i", '', $html);
        $html = preg_replace('#\son\w+\s*=\s*[^\s>]+#i', '', $html);
        $html = preg_replace('#javascript\s*:#i', '#', $html);

        return $html;
    }

    /**
     * Strip surrounding angle brackets / whitespace from a Message-ID so it
     * passes Symfony's IdentificationHeader validation.
     */
    protected function cleanMessageId($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = trim($value, '<> ');

        return $value !== '' ? $value : null;
    }

    /**
     * Strip anything that could leak a credential out of a driver error before
     * it reaches the client.
     */
    protected function cleanError(string $message): string
    {
        $message = preg_replace('/password[^\s]*/i', 'password[…]', $message);
        $message = trim(preg_replace('/\s+/', ' ', $message));

        return mb_substr($message, 0, 240);
    }
}
