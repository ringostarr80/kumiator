<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kanäle (Spatie `log_name`) des Activity-Logs.
 *
 * Zentralisiert die zuvor über Listener/Models/Services verstreuten Magic-Strings
 * (CLAUDE.md: Enums statt Magic-Strings). Wird an den Schreib-Sites über
 * `Activity::useLog(ActivityChannel::AUTH->value)` bzw. in `getActivitylogOptions()`
 * über `->useLogName(ActivityChannel::USER->value)` verwendet.
 */
enum ActivityChannel: string
{
    case AUTH = 'auth';
    case USER = 'user';
    case SECURITY = 'security';
    case ROLE = 'role';
    case PERMISSION = 'permission';
    case PASSKEY = 'passkey';
    case FORENSIC = 'forensic';
}
