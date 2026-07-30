<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportAttachment;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SupportTicketController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'       => ['required', 'string', 'email', 'max:255'],
            'subject'     => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'job_id'      => ['required', 'string', 'exists:pdf_jobs,id'],
            'attachment'  => ['sometimes', 'nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,gif,webp,doc,docx'],
        ]);

        $user = $request->user();
        $jobId = $validated['job_id'];

        $ownsJob = \App\Models\PdfJob::where('id', $jobId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $ownsJob) {
            return response()->json([
                'message' => 'The selected document does not belong to your account.',
            ], 403);
        }

        $ticket = SupportTicket::create([
            'reference'   => SupportTicket::generateReference(),
            'email'       => strtolower($validated['email']),
            'subject'     => $validated['subject'],
            'description' => $validated['description'],
            'status'      => 'open',
            'user_id'     => $user->id,
            'pdf_job_id'  => $jobId,
        ]);

        if ($request->hasFile('attachment') && $request->file('attachment')?->isValid()) {
            $file = $request->file('attachment');
            $path = $file->storeAs(
                "support-attachments/{$ticket->id}",
                $file->getClientOriginalName(),
                'local',
            );

            SupportAttachment::create([
                'support_ticket_id' => $ticket->id,
                'type'              => 'user_upload',
                'original_filename' => $file->getClientOriginalName(),
                'storage_path'      => $path,
                'mime_type'         => $file->getMimeType() ?? $file->getClientMimeType(),
                'size_bytes'        => $file->getSize(),
            ]);
        }

        Log::info('[TenthLine] support.ticket.created', [
            'ticket_id'    => $ticket->id,
            'reference'    => $ticket->reference,
            'email'        => $ticket->email,
            'job_id'       => $jobId,
            'has_attachment' => $ticket->attachments()->exists(),
        ]);

        return response()->json([
            'ticket_id' => $ticket->id,
            'reference' => $ticket->reference,
            'message'   => 'Your support ticket has been submitted. We will get back to you at ' . $ticket->email . '.',
        ], 201);
    }
}
