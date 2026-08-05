<?php

declare(strict_types=1);

use App\Broadcasting\CompanyChannel;
use Illuminate\Support\Facades\Broadcast;

/**
 * Broadcast channel authorization (SPRINT_02 §S2-13).
 *
 * One channel, and it is the only one: `private-company.{uuid}`, a company's refresh feed. The rule
 * that decides who may subscribe lives in {@see CompanyChannel} so it can be tested as the tenant
 * boundary it is; this file only says which name it answers to.
 *
 * The channel is keyed on the company's **UUID**, never its internal id. Internal ids are not
 * serialised to clients anywhere else in this system, and a channel name is about as serialised as a
 * value gets — it is written into a JavaScript subscribe call and plainly visible in the network log.
 */
Broadcast::channel('company.{companyUuid}', CompanyChannel::class);
