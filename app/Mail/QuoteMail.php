<?php

namespace App\Mail;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteMail extends Mailable
{
    use Queueable, SerializesModels;

    public Quote $quote;
    public object $company;
    public ?string $staffNote;
    protected ?string $pngBinary;

    public function __construct(
        Quote $quote,
        object $company,
        ?string $pngBinary = null,
        ?string $staffNote = null
    ) {
        $this->quote = $quote->loadMissing('items');
        $this->company = $company;
        $this->pngBinary = $pngBinary;
        $this->staffNote = $staffNote;
    }

    public function envelope(): Envelope
    {
        $brandName = $this->company->name ?? 'Hải Phòng Tech';
        return new Envelope(
            subject: "[{$brandName}] Báo giá thiết bị #{$this->quote->quote_code}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quote',
            with: [
                'quote' => $this->quote,
                'company' => $this->company,
                'staffNote' => $this->staffNote,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (empty($this->pngBinary)) {
            return [];
        }

        $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $this->quote->quote_code) ?: 'HPT';

        return [
            Attachment::fromData(fn () => $this->pngBinary, "Bao-gia-{$safeCode}.png")
                ->withMime('image/png'),
        ];
    }
}
