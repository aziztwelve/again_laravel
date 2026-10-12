<?php

namespace App\Services\Client;

use App\Models\Client;

class ClientConsentService
{
    /** Apply only explicitly supplied, validated flags; legacy requests preserve consent. */
    public function sync(Client $client, array $data, bool $confirmedByClient = false): void
    {
        $changes = [];
        foreach (['privacy_policy_accepted', 'personal_data_consent', 'oferta_accepted', 'subscribed_to_newsletter'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $changes[$field] = (bool) $data[$field];
            }
        }

        if (array_key_exists('personal_data_consent', $changes)) {
            if (! $changes['personal_data_consent']) {
                $changes['personal_data_consent_at'] = null;
            } elseif ($confirmedByClient) {
                // Do not invent a customer confirmation date for imports/admin edits.
                $changes['personal_data_consent_at'] = $client->personal_data_consent_at ?? now();
            }
        }

        if ($changes !== []) {
            $client->update($changes);
        }
    }
}
