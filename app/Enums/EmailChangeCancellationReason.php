<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Gründe, aus denen ein Pending-E-Mail-Wechsel endet, ohne bestätigt zu
 * werden. Landen als `cancelled_via` in den Properties des
 * `email_change_cancelled`-Eintrags und machen die Lebenszyklus-Spur einer
 * Anfrage im Audit-Log auswertbar.
 */
enum EmailChangeCancellationReason: string
{
    case RECIPIENT_REVOKED = 'recipient_revoked';
    case TTL_EXPIRED = 'ttl_expired';
    case EXPIRED_ON_CONFIRM = 'expired_on_confirm';
    case TARGET_TAKEN_ON_CONFIRM = 'target_taken_on_confirm';
    case SUPERSEDED_BY_NEW_REQUEST = 'superseded_by_new_request';
}
