<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasUuids;

    protected $table = 'payments';

    protected $fillable = [
        'amount',
        'currency',
        'page_count',
        'user_id',
        'email',
        'phone',
        'reference',
        'mpesa_merchant_request_id',
        'mpesa_checkout_request_id',
        'mpesa_result_code',
        'mpesa_callback_payload',
        'status',
        'pdf_job_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'page_count' => 'integer',
        'mpesa_callback_payload' => 'array',
    ];

    public function pdfJob(): BelongsTo
    {
        return $this->belongsTo(PdfJob::class, 'pdf_job_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function generateReference(): string
    {
        return 'pay_' . strtoupper(Str::random(16));
    }
}
