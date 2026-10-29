<?php

namespace App\Service\Kscw;

use App\Models\Forms\Form;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * KSCW fork: "check in Wiedisync" on email fields.
 *
 * An email field with `wiedisync_lookup` only accepts an address that Wiedisync (the
 * club's member app) or ClubDesk knows for a current member. Optional per-field keys:
 *   - wiedisync_name_field     id of the name field: picks the member when a family shares
 *                              one address
 *   - wiedisync_section_field  id of a field that gets the member's section(s)
 *   - wiedisync_teams_field    id of a field that gets the member's team(s)
 *   - wiedisync_show_values    true = the live check also fills section/teams in the
 *                              respondent's browser. Default false: they only reach the
 *                              stored submission, so the public form never tells a stranger
 *                              which team someone plays in.
 *
 * Wiedisync answers on POST /kscw/opnform/v1/member-lookup and /signup (service token,
 * internal network only); see wiedisync `kscw-endpoints/src/opnform-members.js`.
 */
class WiedisyncMemberLookup
{
    public const FOUND = 'found';

    public const NOT_FOUND = 'not_found';

    public const AMBIGUOUS = 'ambiguous';

    public const UNAVAILABLE = 'unavailable';

    public static function isConfigured(): bool
    {
        return filled(config('kscw.wiedisync.url')) && filled(config('kscw.wiedisync.service_token'));
    }

    /** Email fields of the form with the Wiedisync check switched on. */
    public static function lookupFields(Form $form): array
    {
        return collect($form->properties ?? [])
            ->filter(fn ($p) => ($p['type'] ?? null) === 'email' && !empty($p['wiedisync_lookup']))
            ->values()
            ->all();
    }

    /** The typed name, when the email field points at a name field. */
    public static function nameValue(array $emailField, array $data): ?string
    {
        $id = $emailField['wiedisync_name_field'] ?? null;
        $value = $id ? ($data[$id] ?? null) : null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Error message for a status in the current (form) locale; null when the email is fine. */
    public static function message(string $status, array $emailField): ?string
    {
        return match ($status) {
            self::FOUND => null,
            self::NOT_FOUND => __('kscw.wiedisync.not_found'),
            self::AMBIGUOUS => empty($emailField['wiedisync_name_field'])
                ? __('kscw.wiedisync.ambiguous_own_email')
                : __('kscw.wiedisync.ambiguous_name'),
            default => __('kscw.wiedisync.unavailable'),
        };
    }

    /**
     * @return array{status: string, sections?: string[], teams?: string[]}
     */
    public function lookup(?string $email, ?string $name = null): array
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '') {
            return ['status' => self::NOT_FOUND];
        }
        if (!self::isConfigured()) {
            return ['status' => self::UNAVAILABLE];
        }

        // The rule, the controller fill and the live check ask the same question within
        // seconds; a short cache keeps that to one call. Errors are never cached.
        $key = 'kscw:wiedisync:lookup:' . hash('sha256', $email . "\n" . mb_strtolower(trim((string) $name)));
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $response = $this->client()->post('/kscw/opnform/v1/member-lookup', [
                'email' => $email,
                'name' => $name,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Wiedisync member lookup failed', ['error' => $e->getMessage()]);

            return ['status' => self::UNAVAILABLE];
        }

        $status = $response->successful() ? $response->json('status') : null;
        if (!in_array($status, [self::FOUND, self::NOT_FOUND, self::AMBIGUOUS], true)) {
            Log::warning('Wiedisync member lookup: unexpected answer', ['http_status' => $response->status()]);

            return ['status' => self::UNAVAILABLE];
        }

        $result = ['status' => $status];
        if ($status === self::FOUND) {
            $result['sections'] = self::strings($response->json('sections'));
            $result['teams'] = self::strings($response->json('teams'));
        }
        Cache::put($key, $result, now()->addMinutes(2));

        return $result;
    }

    /**
     * Sign the submitter into the form's private Wiedisync event(s). Throws on any
     * failure so the queued listener retries.
     */
    public function signup(Form $form, ?string $submissionId, string $email, ?string $name): array
    {
        return $this->client()->post('/kscw/opnform/v1/signup', [
            'form_slug' => $form->slug,
            'submission_id' => $submissionId,
            'email' => mb_strtolower(trim($email)),
            'name' => $name,
        ])->throw()->json() ?? [];
    }

    /**
     * Replace the mapped section/teams answers with Wiedisync's values — the respondent
     * does not get to pick their own team. Runs on validated data, so the email already
     * matched exactly one member.
     */
    public function fillSubmission(Form $form, array $data): array
    {
        foreach (self::lookupFields($form) as $field) {
            $email = $data[$field['id']] ?? null;
            if (!is_string($email) || trim($email) === '') {
                continue;
            }
            $result = $this->lookup($email, self::nameValue($field, $data));
            if ($result['status'] === self::FOUND) {
                $data = array_merge($data, self::mappedValues($form, $field, $result));
            }
        }

        return $data;
    }

    /** Values for the mapped fields, keyed by field id and shaped for each field's type. */
    public static function mappedValues(Form $form, array $emailField, array $result): array
    {
        $fields = collect($form->properties ?? [])->keyBy('id');
        $values = [];
        foreach (['wiedisync_section_field' => 'sections', 'wiedisync_teams_field' => 'teams'] as $key => $source) {
            $targetId = $emailField[$key] ?? null;
            if (!$targetId || !$fields->has($targetId)) {
                continue;
            }
            $values[$targetId] = self::shapeForField($fields[$targetId], $result[$source] ?? []);
        }

        return $values;
    }

    /** select → first value, multi_select → list, anything else → "a, b". */
    private static function shapeForField(array $field, array $values): mixed
    {
        $type = $field['type'] ?? 'text';
        if (in_array($type, ['select', 'multi_select'], true)) {
            // Prefer the field's own spelling of an option ("volleyball" → "Volleyball").
            $options = array_column($field[$type]['options'] ?? $field['options'] ?? [], 'name');
            $values = array_map(function ($value) use ($options) {
                foreach ($options as $option) {
                    if (mb_strtolower((string) $option) === mb_strtolower($value)) {
                        return $option;
                    }
                }

                return $value;
            }, $values);

            return $type === 'multi_select' ? array_values($values) : ($values[0] ?? null);
        }

        return $values ? implode(', ', $values) : null;
    }

    private static function strings(mixed $value): array
    {
        return array_values(array_filter((array) $value, fn ($v) => is_string($v) && $v !== ''));
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('kscw.wiedisync.url'), '/'))
            ->withHeaders(['X-KSCW-Service-Token' => (string) config('kscw.wiedisync.service_token')])
            ->acceptJson()
            ->asJson()
            ->timeout(max(1, (int) config('kscw.wiedisync.timeout', 5)));
    }
}
