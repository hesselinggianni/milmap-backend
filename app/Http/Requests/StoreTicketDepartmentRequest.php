<?php
// app/Http/Requests/StoreTicketDepartmentRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Pas aan op basis van je autorisatiebeleid
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'required|string',
            'icon' => 'required|string',
            'due_date' => 'nullable|integer',
            'priority' => 'required|in:none,low,normal,high,urgent',
            'assign_mails_primary' => 'nullable|exists:users,id',
            'imap_outgoing_id' => 'nullable|exists:imap_settings,id',
            'imap_incoming_ids' => 'nullable|array',
            // 'imap_incoming_ids.*' => 'exists:imap_settings,id',        
        ];
    }
}
