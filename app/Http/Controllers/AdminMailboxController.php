<?php

namespace App\Http\Controllers;

use App\Models\MailAccount;
use App\Services\MailboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Live mailbox operations (read / flag / send) for a single {@see MailAccount}.
 *
 * The account is always resolved via the authenticated admin's user_id, so an
 * admin can only ever drive their own mailboxes. All IMAP/SMTP work is
 * delegated to {@see MailboxService}; this controller only validates input,
 * resolves the account and shapes the JSON / streamed response.
 */
class AdminMailboxController extends Controller
{
    public function __construct(private MailboxService $mailbox)
    {
    }

    public function folders(string $accountId)
    {
        $account = $this->find($accountId);

        return $this->guard(fn () => response()->json([
            'folders' => $this->mailbox->folders($account),
        ]));
    }

    public function messages(Request $request, string $accountId)
    {
        $account = $this->find($accountId);

        $folder  = $request->query('folder', 'INBOX');
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(5, (int) $request->query('per_page', 25)));

        return $this->guard(fn () => response()->json(
            $this->mailbox->messages($account, $folder, $page, $perPage)
        ));
    }

    public function show(Request $request, string $accountId, int $uid)
    {
        $account = $this->find($accountId);
        $folder  = $request->query('folder', 'INBOX');
        $peek    = $request->boolean('peek');

        return $this->guard(fn () => response()->json(
            $this->mailbox->show($account, $folder, $uid, $peek)
        ));
    }

    public function attachment(Request $request, string $accountId, int $uid, int $index)
    {
        $account = $this->find($accountId);
        $folder  = $request->query('folder', 'INBOX');

        $att = $this->mailbox->attachment($account, $folder, $uid, $index);

        return new StreamedResponse(function () use ($att) {
            echo $att['content'];
        }, 200, [
            'Content-Type'        => $att['mime'],
            'Content-Disposition' => 'attachment; filename="' . addslashes($att['name']) . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function flag(Request $request, string $accountId, int $uid)
    {
        $account = $this->find($accountId);

        $data = $request->validate([
            'folder' => ['nullable', 'string', 'max:255'],
            'flag'   => ['required', 'string', 'in:seen,flagged,answered,deleted'],
            'value'  => ['required', 'boolean'],
        ]);

        return $this->guard(fn () => response()->json([
            'flags' => $this->mailbox->setFlag(
                $account,
                $data['folder'] ?? 'INBOX',
                $uid,
                $data['flag'],
                (bool) $data['value']
            ),
        ]));
    }

    public function send(Request $request, string $accountId)
    {
        $account = $this->find($accountId);

        $data = $request->validate([
            'to'           => ['required'],
            'cc'           => ['nullable'],
            'subject'      => ['nullable', 'string', 'max:255'],
            'body_html'    => ['nullable', 'string', 'max:200000'],
            'in_reply_to'  => ['nullable', 'string', 'max:512'],
            'references'   => ['nullable', 'string', 'max:4000'],
            'reply_folder' => ['nullable', 'string', 'max:255'],
            'reply_uid'    => ['nullable', 'integer'],
            'attachments'  => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:15360'], // 15 MB each
        ]);

        $files = $request->file('attachments', []);

        return $this->guard(fn () => response()->json(
            $this->mailbox->send($account, $data, $files)
        ));
    }

    // ── Helpers ────────────────────────────────────────────────────────

    protected function find(string $accountId): MailAccount
    {
        return MailAccount::where('user_id', Auth::id())
            ->where('id', $accountId)
            ->firstOrFail();
    }

    /**
     * Run a mailbox closure and turn connection/runtime failures into a clean
     * 422 the UI can show, instead of a 500 stack trace.
     */
    protected function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
