<?php

declare(strict_types=1);

namespace App\Mail;

interface MailerInterface
{
    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null): bool;
}
