<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otpCode,
        public int $expiresMinutes,
        public ?string $userName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SYRTAK Verification Code | رمز التحقق',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            text: 'emails.otp-plain',
        );
    }

    public function subjectLine(): string
    {
        return (string) $this->envelope()->subject;
    }

    public function renderText(): string
    {
        $text = $this->content()->text;
        if (! is_string($text) || $text === '') {
            return '';
        }

        return view($text, [
            'otpCode' => $this->otpCode,
            'expiresMinutes' => $this->expiresMinutes,
            'userName' => $this->userName,
        ])->render();
    }
}
