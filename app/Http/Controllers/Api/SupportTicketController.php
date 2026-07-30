<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SupportTicketController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'job_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $user = $request->user();
        $jobId = $validated['job_id'] ?? null;

        $ticket = SupportTicket::create([
            'reference' => SupportTicket::generateReference(),
            'email' => strtolower($validated['email']),
            'subject' => $validated['subject'],
            'description' => $validated['description'],
            'status' => 'open',
            'user_id' => $user?->id,
            'pdf_job_id' => $jobId ?: null,
        ]);

        Log::info('[TenthLine] support.ticket.created', [
            'ticket_id' => $ticket->id,
            'reference' => $ticket->reference,
            'email' => $ticket->email,
            'job_id' => $jobId,
        ]);

        return response()->json([
            'ticket_id' => $ticket->id,
            'reference' => $ticket->reference,
            'message' => 'Your support ticket has been submitted. We will get back to you at ' . $ticket->email . '.',
        ], 201);
    }
}
