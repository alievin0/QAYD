<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The closed set of structural audit categories, backing the Postgres `audit_category` enum
 * (docs/database/DATABASE_AUDIT_LOGS.md "# audit_logs Table Design"). The specific `action` string
 * (e.g. `invoice.voided`) stays free-form; the category is the broad type used for fast filtering.
 */
enum AuditCategory: string
{
    case DataMutation = 'data_mutation';
    case Auth = 'auth';
    case Permission = 'permission';
    case AiAction = 'ai_action';
    case System = 'system';
}
