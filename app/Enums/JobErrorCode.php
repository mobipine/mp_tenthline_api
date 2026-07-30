<?php

namespace App\Enums;

enum JobErrorCode: string
{
    case ZeroSuccessfulPages = 'zero_successful_pages';
    case OcrUnavailable = 'ocr_unavailable';
    case OcrTimeout = 'ocr_timeout';
    case PdfParseFailed = 'pdf_parse_failed';
    case LineNumberingFailed = 'line_numbering_failed';
    case PaymentDeadlineExpired = 'payment_deadline_expired';

    public function userMessage(): string
    {
        return match($this) {
            self::ZeroSuccessfulPages => 'None of the pages could be processed successfully. Please try uploading a clearer scan.',
            self::OcrUnavailable => 'Our OCR service is temporarily unavailable. Please try again in a few minutes.',
            self::OcrTimeout => 'Processing took too long for some pages. Please try a smaller document.',
            self::PdfParseFailed => 'The PDF could not be read. Please ensure it is not password-protected or corrupted.',
            self::LineNumberingFailed => 'Line numbering failed on one or more pages. Our team has been notified.',
            self::PaymentDeadlineExpired => 'Your processed document expired before payment was received. Please re-upload your document.',
        };
    }
}
