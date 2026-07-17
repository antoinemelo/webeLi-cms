<?php

declare(strict_types=1);

namespace App\Application\Forms;

interface FormSubmissionActivitySink
{
    /** @param array<string,mixed> $event */
    public function recordFormSubmission(array $event): void;
}
