<?php 

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\TicketStatus;

class StoreTicketRequest extends FormRequest
{
    /**
     * Geef de autorisatie voor de aanvraag.
     *
     * @return bool
     */
    public function authorize()
    {
        return true; // Hier kun je controleren of de gebruiker toegang heeft om een ticket aan te maken.
    }

    /**
     * Verkrijg de geldige regels voor de aanvraag.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'title' => 'required|string|max:255',
            'message' => 'nullable|string',
            'from' => 'nullable|string|max:255',
            'email' => 'nullable|string|max:255',
            'cc' => 'nullable|string|max:255',
            'bcc' => 'nullable|string|max:255',
            'from_ip' => 'nullable|string|max:255',
            'phonenumber' => 'nullable|string|max:20',
            'status_id' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    // Accepteer 0 of -1 als een geldige waarde voor concept
                    if ($value !== 0 && $value !== -1 && !TicketStatus::where('id', $value)->exists()) {
                        $fail('The selected status is invalid.');
                    }
                },
            ],            
            'priority' => 'nullable|in:none,low,normal,high,urgent',
            'assigned_to' => 'nullable|exists:users,id',
            'due_date' => 'nullable|integer|between:1,99999999999999', // validatie als integer
            'start_date' => 'nullable|integer|between:1,99999999999999', // validatie als integer
            'ticket_type' => 'nullable|string',
            'imap_account_id' => 'nullable|exists:imap_settings,id',
            'client_id' => 'nullable|exists:users,id',
            'attachments.*' => 'nullable|file|max:10240' // Max bestandsgrootte validatie
        ];
    }

    /**
     * Verkrijg de foutberichten voor de toegepaste regels.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'status_id.required' => 'De status van het ticket is vereist.',
            'status_id.integer' => 'De status_id moet een geldig nummer zijn.',
            'status_id.exists' => 'De opgegeven status bestaat niet.',
        ];
    }
}
