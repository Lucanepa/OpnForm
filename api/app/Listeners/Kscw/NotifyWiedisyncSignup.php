<?php

namespace App\Listeners\Kscw;

use App\Events\Forms\FormSubmitted;
use App\Service\Kscw\WiedisyncMemberLookup;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * KSCW fork: after a submission is stored, tell Wiedisync who signed up, so it can sign
 * the member into the form's private event (wiedisync migration 434). Queued with
 * retries: Wiedisync restarting for a deploy must not lose a sign-up. Wiedisync's side
 * is idempotent, and a form without a bound event is a no-op there.
 */
class NotifyWiedisyncSignup implements ShouldQueue
{
    public int $tries = 6;

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(FormSubmitted $event): void
    {
        if (!WiedisyncMemberLookup::isConfigured()) {
            return;
        }
        $lookup = app(WiedisyncMemberLookup::class);
        foreach (WiedisyncMemberLookup::lookupFields($event->form) as $field) {
            $email = $event->data[$field['id']] ?? null;
            if (!is_string($email) || trim($email) === '') {
                continue;
            }
            $submissionId = $event->data['submission_id'] ?? null;
            $lookup->signup(
                $event->form,
                $submissionId === null ? null : (string) $submissionId,
                $email,
                WiedisyncMemberLookup::nameValue($field, $event->data),
            );
        }
    }
}
