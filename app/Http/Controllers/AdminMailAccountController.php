<?php

namespace App\Http\Controllers;

use App\Models\MailAccount;
use App\Services\MailboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * CRUD for the admin's IMAP/SMTP inboxes. Every query is scoped to the
 * authenticated admin (user_id), so one admin can never see or touch another's
 * mailbox configuration. Passwords are write-only: they are accepted on
 * create/update but never returned (see MailAccount::toClientArray()).
 */
class AdminMailAccountController extends Controller
{
    public function __construct(private MailboxService $mailbox)
    {
    }

    public function index()
    {
        $accounts = MailAccount::where('user_id', Auth::id())
            ->orderBy('created_at')
            ->get()
            ->map->toClientArray();

        return response()->json(['accounts' => $accounts]);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request, true);
        $data['user_id'] = Auth::id();

        $account = MailAccount::create($data);

        return response()->json(['account' => $account->toClientArray()], 201);
    }

    public function update(Request $request, string $id)
    {
        $account = $this->find($id);
        $data = $this->validatePayload($request, false);

        // Only overwrite a password when a non-empty value was supplied,
        // so the "leave blank to keep current" UX works.
        if (empty($data['imap_password'])) {
            unset($data['imap_password']);
        }
        if (empty($data['smtp_password'])) {
            unset($data['smtp_password']);
        }

        $account->update($data);

        return response()->json(['account' => $account->fresh()->toClientArray()]);
    }

    public function destroy(string $id)
    {
        $account = $this->find($id);
        $account->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Live-test the stored (or just-submitted) credentials against the servers.
     */
    public function test(string $id)
    {
        $account = $this->find($id);
        $result = $this->mailbox->test($account);

        return response()->json($result);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    protected function find(string $id): MailAccount
    {
        return MailAccount::where('user_id', Auth::id())
            ->where('id', $id)
            ->firstOrFail();
    }

    protected function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'name'               => ['required', 'string', 'max:120'],
            'from_name'          => ['nullable', 'string', 'max:120'],
            'email'              => ['required', 'email', 'max:190'],
            'imap_host'          => ['required', 'string', 'max:190'],
            'imap_port'          => ['required', 'integer', 'between:1,65535'],
            'imap_encryption'    => ['required', Rule::in(['ssl', 'tls', 'starttls', 'none'])],
            'imap_username'      => ['nullable', 'string', 'max:190'],
            'imap_password'      => [$creating ? 'required' : 'nullable', 'string', 'max:512'],
            'imap_validate_cert' => ['boolean'],
            'smtp_host'          => ['required', 'string', 'max:190'],
            'smtp_port'          => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption'    => ['required', Rule::in(['ssl', 'tls', 'starttls', 'none'])],
            'smtp_username'      => ['nullable', 'string', 'max:190'],
            'smtp_password'      => [$creating ? 'required' : 'nullable', 'string', 'max:512'],
            'signature_html'     => ['nullable', 'string', 'max:20000'],
            'is_active'          => ['boolean'],
        ]);
    }
}
