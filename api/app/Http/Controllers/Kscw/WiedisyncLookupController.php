<?php

namespace App\Http\Controllers\Kscw;

use App\Http\Controllers\Controller;
use App\Models\Forms\Form;
use App\Service\Kscw\WiedisyncMemberLookup;
use Illuminate\Http\Request;

/**
 * KSCW fork: live "is this a member's email?" check while a public form is being filled
 * (POST /forms/{form}/wiedisync-lookup, rate-limited). Answers with a status and the
 * message to show under the email field; the mapped section/teams values only when the
 * field opted in with `wiedisync_show_values`. The submit runs the same check again
 * server-side (WiedisyncMemberRule), so this endpoint is a convenience, not the gate.
 */
class WiedisyncLookupController extends Controller
{
    public function check(Request $request, Form $form, WiedisyncMemberLookup $lookup)
    {
        $this->authorize('answer', $form);

        $validated = $request->validate([
            'field_id' => 'required|string|max:100',
            'email' => 'required|string|max:255',
            'name' => 'nullable|string|max:255',
        ]);

        $field = collect(WiedisyncMemberLookup::lookupFields($form))->firstWhere('id', $validated['field_id']);
        if (!$field) {
            abort(404);
        }

        // Not a well-formed address yet: the field's own email rule speaks, not us.
        if (!filter_var(trim($validated['email']), FILTER_VALIDATE_EMAIL) || !WiedisyncMemberLookup::isConfigured()) {
            return response()->json(['status' => 'skipped', 'message' => null]);
        }

        if ($form->language && in_array($form->language, Form::LANGUAGES)) {
            app()->setLocale($form->language);
        }

        $result = $lookup->lookup($validated['email'], $validated['name'] ?? null);
        $body = [
            'status' => $result['status'],
            'message' => WiedisyncMemberLookup::message($result['status'], $field),
        ];
        if ($result['status'] === WiedisyncMemberLookup::FOUND && !empty($field['wiedisync_show_values'])) {
            $body['fill'] = WiedisyncMemberLookup::mappedValues($form, $field, $result);
        }

        return response()->json($body);
    }
}
