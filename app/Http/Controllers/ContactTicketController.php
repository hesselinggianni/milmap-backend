<?php

namespace App\Http\Controllers;

use App\Models\ContactTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactTicketController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'    => 'required|string|max:255',
                'email'   => 'required|email|max:255',
                'subject' => 'required|string|max:255',
                'message' => 'required|string|min:5|max:5000',
                'type'    => 'nullable|in:general,demo,pro,team,technical,other',
            ]);

            $validated['type']   = $validated['type'] ?? 'general';
            $validated['status'] = 'open';

            $ticket = ContactTicket::create($validated);

            $this->sendEmail($ticket);

            // Notificeer de admin-app (lokale melding via de notificatie-feed).
            \App\Models\AppNotification::notifyAdmins(
                'admin.support',
                'Nieuwe supportaanvraag',
                trim($ticket->name . ' — ' . $ticket->subject),
                ['ticket_id' => $ticket->id, 'email' => $ticket->email, 'action_url' => '/admin'],
            );

            return response()->json([
                'message'   => 'Ticket created successfully',
                'ticket_id' => $ticket->id,
                'data' => [
                    'name'       => $ticket->name,
                    'email'      => $ticket->email,
                    'subject'    => $ticket->subject,
                    'ticket_id'  => $ticket->id,
                    'created_at' => $ticket->created_at,
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating ticket',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        $tickets = ContactTicket::latest()->paginate(25);
        return response()->json($tickets);
    }

    public function update(Request $request, ContactTicket $ticket)
    {
        $validated = $request->validate([
            'status'   => 'sometimes|in:open,in_progress,closed',
            'priority' => 'sometimes|in:low,medium,high',
        ]);

        $ticket->update($validated);

        return response()->json(['message' => 'Ticket updated', 'data' => $ticket]);
    }

    private function sendEmail(ContactTicket $ticket): void
    {
        Mail::send('emails.contact-ticket', ['ticket' => $ticket], function ($message) use ($ticket) {
            $message->to('gianni@onavan.com')
                ->subject("Contact Ticket #{$ticket->id}: {$ticket->subject}")
                ->replyTo($ticket->email, $ticket->name);
        });
    }
}
