<?php

namespace App\Mail;

use App\Models\Team;
use App\Support\Branding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChangeDigest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Team $team, public string $period, public array $items, public string $heading) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.change-digest', with: [
            'groups' => collect($this->items)->groupBy('property'),
            'appUrl' => url('/app/'.$this->team->slug),
            'settingsUrl' => url('/app/'.$this->team->slug.'/alerts'),
            'name' => Branding::name(),
        ]);
    }
}
