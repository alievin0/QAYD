# Communication Tools — QAYD AI Layer
Version: 1.0
Status: Design Specification
Module: AI
Submodule: COMMUNICATION_TOOLS
---

# Purpose

Every one of QAYD's fifteen agents eventually needs to reach a human being — to hand over a decision it is not permitted to make itself, to page a reviewer before a window closes, to explain what it found, or to deliver a document a customer is waiting on. `TOOLS_PROMPTS.md` establishes that the per-module catalogs under `docs/ai/tools/*` are the authoritative, full-JSON-Schema source every agent document's own compressed "Tools & API Access" table is a projection of; this document is that catalog for the one capability every agent shares regardless of domain — **reaching a human, on purpose, through a specific channel, with a specific message.** Where `docs/ai/tools/ACCOUNTING_TOOLS.md` and its siblings enumerate the tools an agent uses to *see and propose*, this document enumerates the tools every agent uses to *tell someone*. It is the channel side of QAYD's human-in-the-loop guarantee: an autonomous finance workforce that works before anyone asks is only trustworthy if the moment it needs a human, that human actually finds out, on a channel they will actually see, with evidence they can actually verify — and if the one thing standing between an AI-drafted message and a customer's inbox is a human's own explicit review, every single time, no exception negotiable by confidence score.

This document owns eight tools: **`send_notification`**, **`send_email`**, **`send_whatsapp`**, **`send_sms`**, **`create_approval_request`**, **`get_approval_status`**, **`notify_role`**, and **`broadcast_alert`**. All eight are thin wrappers around two Laravel endpoint families — `POST /api/v1/notifications` (and its `/broadcast` sibling) and `/api/v1/auth/approvals` — exactly per `TOOLS_PROMPTS.md`'s invariant that a tool wrapper adds no judgment of its own. None of the eight write a financial fact anywhere; none can post, approve, transfer, or release anything. Their entire effect is informational delivery or the opening of a gate a human must still walk through — which is precisely why they sit outside the `write_propose` approval machinery `docs/ai/prompts/TOOLS_PROMPTS.md` § **Read vs Write Tools & The Approval Gate** describes for every other kind of write: requiring a human to pre-approve the act of *telling a human something needs approval* is a deadlock, not a safeguard, and this document is explicit throughout about exactly where that reasoning stops applying — the instant a message is addressed to anyone who is not company staff signed into their own QAYD session.

**Reused, not reinvented.** The `notifications`, `notification_templates`, and `notification_preferences` tables are defined once, in full, in `docs/database/ERD.md` § Notifications, and are not redefined here — this document is the tool-calling contract layered on top of that existing schema, per `docs/database/ERD.md`'s own "Future Expansion" notes for exactly this purpose. The `approval_requests` family is owned by `docs/api/AUTHORIZATION_API.md` (the endpoints this document's two approval tools wrap) and cross-referenced by `docs/ai/AI_FINANCE_OS.md` (the multi-approver escalation narrative) and `docs/ai/AI_COMMAND_CENTER.md` (the Approval Center's own read-optimized projection); § **Approval Requests**, below, reconciles the column-level differences between those documents explicitly rather than silently picking a favorite. Several sibling documents already reference a compressed version of a tool this document defines under a different, earlier-written name — `docs/ai/agents/AUDITOR_AGENT.md`'s `notify_users`, `docs/ai/agents/BANKING_AGENT.md`'s `notifications.raise`, `docs/ai/prompts/AGENT_PROMPTS.md`'s Approval Assistant `create_approval_request`/`send_reminder`/`escalate_decision` — every one of those is reconciled explicitly, by name, at the point in this document where the corresponding canonical tool is defined, following exactly the reconciliation discipline `docs/ai/prompts/AGENT_PROMPTS.md` § **Agent Prompt Templates** already established for confidence-scale and agent-code drift across the same corpus.

**Two schema additions, both additive, both already anticipated.** Two of the eight tools in this catalog need a column value `docs/database/ERD.md` did not yet enumerate. Both are additive — no existing row, query, or index changes shape — and both are called out once, here, rather than repeated at every tool that touches them:

```sql
-- 1. WhatsApp as notifications' and notification_templates' fifth channel.
--    docs/database/ERD.md already names this as the anticipated next channel
--    ("WhatsApp Business API as a fifth channel, following the same template
--    shape") — this migration is that addition, not a deviation from it.
ALTER TABLE notification_templates DROP CONSTRAINT notification_templates_channel_check;
ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_channel_check
    CHECK (channel IN ('in_app','email','sms','push','whatsapp'));

ALTER TABLE notifications DROP CONSTRAINT notifications_channel_check;
ALTER TABLE notifications ADD CONSTRAINT notifications_channel_check
    CHECK (channel IN ('in_app','email','sms','push','whatsapp'));

-- 2. A `suppressed` terminal delivery_status, distinct from `failed`: a
--    notification withheld because the recipient opted out, or because an
--    external send lacked an approved draft, is a correct outcome of a
--    successful API call, never a delivery fault worth a provider-side retry.
ALTER TABLE notifications DROP CONSTRAINT notifications_delivery_status_check;
ALTER TABLE notifications ADD CONSTRAINT notifications_delivery_status_check
    CHECK (delivery_status IN ('pending','sent','delivered','failed','suppressed'));

-- 3. Provider dispatch metadata for the three externally-routed channels.
--    Additive columns only; every existing row is unaffected (NULL default).
ALTER TABLE notifications ADD COLUMN provider VARCHAR(20) NULL;             -- 'mailgun' | 'ses' | 'twilio' | 'whatsapp_business'
ALTER TABLE notifications ADD COLUMN provider_message_id VARCHAR(120) NULL; -- provider's own id, for webhook reconciliation
ALTER TABLE notifications ADD COLUMN external_recipient JSONB NULL;        -- {"email":"..."} / {"phone_e164":"..."} when recipient is not a `users` row
CREATE INDEX idx_notifications_provider_message_id ON notifications (provider, provider_message_id) WHERE provider_message_id IS NOT NULL;
```

# Tool Catalog

| Tool | Channel(s) | Audience | Endpoint | Permission | Gate |
|---|---|---|---|---|---|
| `send_notification` | `in_app` (+ `push` fan-out) | Internal only (`users.id`) | `POST /api/v1/notifications` | `notifications.create` | None — structurally internal, see below |
| `send_email` | `email` | Internal or external | `POST /api/v1/notifications` | `communication.email.send` | None (internal) / approved draft required (external) |
| `send_whatsapp` | `whatsapp` | Internal or external | `POST /api/v1/notifications` | `communication.whatsapp.send` | None (internal) / approved draft required (external) |
| `send_sms` | `sms` | Internal or external | `POST /api/v1/notifications` | `communication.sms.send` | None (internal) / approved draft required (external) |
| `create_approval_request` | — | Named approver(s)/role | `POST /api/v1/auth/approvals` | `auth.approvals.create` | Is itself the gate — see § Approval Requests |
| `get_approval_status` | — | Read-only | `GET /api/v1/auth/approvals/{id}` | `auth.approvals.approve` or ownership | None (read) |
| `notify_role` | `in_app`+`push` (+ `email`/`sms` opt-in) | Every user holding a role | `POST /api/v1/notifications/broadcast` | `notifications.broadcast` | None, capped — see § Rate Limits & Anti-Spam |
| `broadcast_alert` | `in_app`+`push` (+ `sms` if `critical`) | Company-wide or permission-wide | `POST /api/v1/notifications/broadcast` | `notifications.broadcast` | Severity floor + issuing-agent allow-list |

**Why six of the eight carry no approval gate at all.** `docs/ai/prompts/TOOLS_PROMPTS.md`'s four tool categories (`read`, `write_propose`, `delegate`, `utility`) do not have a clean home for "tell a human something." Every tool in this catalog is registered `ai_tool_registry.category = 'utility'`: none of them changes a financial fact, none of them is reversible-by-definition the way a `draft` row is, and gating them behind the same approval machinery that gates `propose_journal_entry` would produce an infinite regress — the mechanism that tells a human "something needs your approval" cannot itself require a human's prior approval to fire. `send_notification` in particular is not merely policy-ungated but *structurally* internal-only, the same "capability absent from the schema" pattern `docs/ai/prompts/SAFETY_PROMPTS.md` § **Permission Enforcement In Prompts** uses for sensitive actions: its `recipients[].user_id` field resolves only against `users`, and customers/vendors are never `users` rows in QAYD's data model (they are `customers`/`customer_contacts` and `vendors`/`vendor_contacts`), so there is no value an agent could put in that field that reaches anyone outside the company's own staff. `send_email`, `send_whatsapp`, and `send_sms` are the only three tools in this catalog that *can* address a non-employee, and they are the only three where the "no gate" reasoning above stops applying — § **Safety & Guardrails**, below, is almost entirely about that boundary.

**Reconciling three earlier names for `send_notification`.** `docs/ai/agents/AUDITOR_AGENT.md`'s Tools & API Access table lists `notify_users` (`POST /api/v1/notifications`, permission `ai.automation`, "Push a Critical/High severity alert"); `docs/ai/agents/BANKING_AGENT.md`'s lists `notifications.raise` (`POST /api/v1/notifications`, permission `notifications.create`, "Fire `bank.duplicate_suspected`, `bank.reconciliation.discrepancy_detected`, etc."). Both are the identical underlying `ai_tool_registry` row this document names `send_notification`; `notify_users` and `notifications.raise` are that document's own shorthand, written before this catalog existed to give the capability one canonical name and JSON Schema. Per `docs/ai/prompts/TOOLS_PROMPTS.md`'s own stated rule — "when the two disagree, `ai_tool_registry` — and its module catalog in `docs/ai/tools/*` — is authoritative" — `send_notification`, as specified below, is the name and schema every orchestrator actually compiles into a model's tool list; `ai.automation` is accepted as a legacy-alias permission key for backward compatibility with agents provisioned before `notifications.create` existed, and resolves to the identical RBAC check.

# Tool Definitions

## send_notification

**Description.** Creates and delivers an in-app notification — and, where the recipient has a registered mobile device, a fan-out push notification carrying the same `title`/`body`/`data` — to one or more named QAYD users belonging to the active company. This is the lowest-friction tool in the catalog: delivering into a user's own already-authenticated session discloses nothing to anyone who could not already see it by logging in, so `send_notification` carries no approval gate, no `decision_id`, and no recipient-type restriction beyond "a `users` row in this company" — because that is the only kind of recipient its schema can express.

**When to use.** Use `send_notification` whenever the message is informational or a reminder addressed to one or a handful of specific, already-identified people: "your drafted journal entry was rejected with a comment," "the report you scheduled is ready," "this fiscal period closes in three days." Prefer `notify_role` instead when the audience is "whoever currently holds this role" rather than named individuals; prefer `create_approval_request` instead when the message is not actually informational but is a decision awaiting someone's accept/reject action — `send_notification` never itself expects a response, and an agent must not use it as a substitute for opening a real approval gate merely because the wording is a question.

**Input schema.**
```json
{
  "name": "send_notification",
  "description": "Deliver an in-app (+ push, where registered) notification to one or more users of the active company. Internal-only: recipients are resolved exclusively against `users.id`. Never requires human approval to call.",
  "input_schema": {
    "type": "object",
    "properties": {
      "recipients": {
        "type": "array", "minItems": 1, "maxItems": 50,
        "items": { "type": "object", "properties": { "user_id": { "type": "integer" } }, "required": ["user_id"], "additionalProperties": false }
      },
      "event_code": { "type": "string", "description": "A `notification_templates.code` this company has an active template for (e.g. 'ai.finished', 'approval.requested', 'fraud.case.opened'), or the literal string 'ad_hoc' when title/body are supplied directly." },
      "template_variables": { "type": "object", "description": "Placeholder values for the matched template. Required whenever event_code is not 'ad_hoc'." },
      "title": { "type": ["string", "null"], "maxLength": 200 },
      "body": { "type": ["string", "null"], "maxLength": 2000 },
      "priority": { "type": "string", "enum": ["normal", "high", "critical"], "default": "normal" },
      "data": { "type": "object", "description": "Deep-link payload, e.g. {\"type\":\"fraud_cases\",\"id\":4118}. Never contains an unmasked PII value — see Safety & Guardrails." },
      "dedup_key": { "type": ["string", "null"], "maxLength": 120, "description": "Application-level cooldown key; a second call with the same dedup_key for the same recipient within the configured cooldown window is suppressed, not duplicated — see Rate Limits & Anti-Spam." },
      "source": {
        "type": "object",
        "properties": { "agent_code": { "type": "string" }, "task_id": { "type": ["integer", "null"] }, "decision_id": { "type": ["integer", "null"] } },
        "required": ["agent_code"], "additionalProperties": false
      }
    },
    "required": ["recipients", "event_code", "source"],
    "additionalProperties": false
  }
}
```

**Endpoint.** `POST /api/v1/notifications` (`channel` fixed to `'in_app'` by the tool; the Laravel job additionally emits `'push'` rows for any recipient with a registered device token, per the same event).
**Permission.** `notifications.create` (legacy alias: `ai.automation`, see above).

**Output (`201 Created`).**
```json
{
  "success": true,
  "data": {
    "notifications": [
      { "id": 990441, "user_id": 1042, "channel": "in_app", "delivery_status": "delivered", "created_at": "2026-07-17T09:12:04Z" },
      { "id": 990442, "user_id": 1042, "channel": "push", "delivery_status": "sent", "created_at": "2026-07-17T09:12:04Z" }
    ]
  },
  "message": "Notification created", "errors": [],
  "meta": { "pagination": null }, "request_id": "b1c2d3e4-...", "timestamp": "2026-07-17T09:12:04Z"
}
```

**Errors.** `422 VALIDATION_ERROR` (no active template for `event_code` and `event_code != 'ad_hoc'`, or `ad_hoc` without `title`/`body`); `403 INSUFFICIENT_PERMISSION`; `404 RESOURCE_NOT_FOUND` (a `user_id` outside the active company); `429 RATE_LIMITED`. A recipient who has opted the matched `event_code`+channel out via `notification_preferences` still yields a `201` for the call itself, with that recipient's row `delivery_status = 'suppressed'` — opting out is a successful, expected outcome, never an error.

**Worked example.** The Auditor, having just written a `duplicate_suspected` finding (`docs/ai/prompts/AGENT_PROMPTS.md`'s own Auditor few-shot, entry `#48213` vs `#48190`), pages the Finance Manager by `user_id` rather than by role because the company's onboarding configured a single named reviewer for this signal type:
```json
{
  "recipients": [ { "user_id": 2207 } ],
  "event_code": "ad_hoc",
  "title": "Possible duplicate payment — Al-Rawda Trading Co.",
  "body": "Entry #48213 (KWD 2,450.000) closely matches #48190, posted 16 hours earlier, both referencing the same bill. Review before this posts further.",
  "priority": "high",
  "data": { "type": "journal_entries", "id": 48213 },
  "source": { "agent_code": "AUDITOR", "decision_id": 662431 }
}
```

## send_email

**Description.** Dispatches an email through the company's configured provider — Mailgun or Amazon SES, per `docs/foundation/TECH_STACK.md` — to either an internal `users` row or an external recipient (a customer, a vendor, or an arbitrary address) named explicitly in the call. Internal sends are `auto`, exactly like `send_notification`. External sends are categorically different: `recipient.external` may only be populated alongside a `decision_id` that resolves to an `ai_decisions` row of `decision_type = 'communication_draft'`, `status = 'accepted'`, whose stored `payload.subject`/`payload.body_html` match this call's `subject`/`body_html` **byte-for-byte** — a mismatch is rejected before any provider call is made. This is the same boundary `docs/ai/prompts/TOOLS_PROMPTS.md`'s `draft_payment_reminder` tool already establishes ("`suggest_only`, always, regardless of confidence — anything reaching an external party gets a human's eyes first"); `send_email` is the dispatch step that boundary was always going to need, made concrete.

**When to use.** Internal: delivering a generated report or a period-close summary with a PDF attachment to a Finance Manager or CFO. External: dispatching a payment reminder, an invoice copy, or a collections notice **only after** a human has reviewed and accepted the exact drafted copy — never as a first move on an agent's own initiative. Never use `send_email` to route around `docs/ai/agents/*`'s own stated boundary that a report's external distribution (`reports.share`) is staged, not sent, by the Reporting Agent; the human who clicks "send" on that staged draft is the one whose acceptance produces the `decision_id` this tool then requires.

**Input schema.**
```json
{
  "name": "send_email",
  "description": "Dispatch an email to an internal user or, with an accepted communication_draft decision_id whose payload matches exactly, an external party.",
  "input_schema": {
    "type": "object",
    "properties": {
      "recipient": {
        "type": "object",
        "properties": {
          "user_id": { "type": ["integer", "null"] },
          "external": {
            "type": ["object", "null"],
            "properties": {
              "email": { "type": "string", "format": "email" },
              "name": { "type": ["string", "null"] },
              "party_type": { "type": "string", "enum": ["customer", "vendor", "other"] },
              "party_id": { "type": ["integer", "null"] }
            },
            "required": ["email", "party_type"], "additionalProperties": false
          }
        },
        "description": "Exactly one of user_id or external must be set."
      },
      "event_code": { "type": "string" },
      "template_variables": { "type": "object" },
      "subject": { "type": ["string", "null"], "maxLength": 200 },
      "body_html": { "type": ["string", "null"] },
      "body_text": { "type": ["string", "null"] },
      "attachments": {
        "type": "array", "maxItems": 10,
        "items": { "type": "object", "properties": { "attachment_id": { "type": "integer" } }, "required": ["attachment_id"], "additionalProperties": false }
      },
      "reply_to": { "type": ["string", "null"], "format": "email" },
      "decision_id": { "type": ["integer", "null"], "description": "Required, and must reference an accepted communication_draft decision whose payload matches this call verbatim, whenever recipient.external is set. Ignored (may be null) for an internal recipient." },
      "source": { "type": "object", "properties": { "agent_code": { "type": "string" }, "task_id": { "type": ["integer", "null"] } }, "required": ["agent_code"], "additionalProperties": false }
    },
    "required": ["recipient", "event_code", "source"],
    "additionalProperties": false
  }
}
```

**Endpoint.** `POST /api/v1/notifications` (`channel = 'email'`).
**Permission.** `communication.email.send`.

**Output (`202 Accepted`, dispatch is asynchronous).**
```json
{
  "success": true,
  "data": { "notifications": [ { "id": 990501, "channel": "email", "delivery_status": "pending", "provider": "mailgun", "created_at": "2026-07-17T09:14:11Z" } ] },
  "message": "Email queued for delivery", "errors": [],
  "meta": { "pagination": null }, "request_id": "c2d3e4f5-...", "timestamp": "2026-07-17T09:14:11Z"
}
```
`delivery_status` transitions `pending → sent → delivered` (or `failed`) as Mailgun/SES webhook events land on `docs/api/API_WEBHOOKS.md`'s inbound handler, updating the same `notifications` row by `provider_message_id`.

**Errors.** `422 INVALID_FORMAT` (malformed email); `422 EXTERNAL_SEND_REQUIRES_APPROVED_DRAFT` (new code, this document — `recipient.external` set without a matching accepted `communication_draft`); `413 PAYLOAD_TOO_LARGE` (attachments); `403 INSUFFICIENT_PERMISSION`; async `PROVIDER_DELIVERY_FAILED` (bounce/complaint reported by the provider after acceptance, `delivery_status = 'failed'`, `data.bounce_reason` populated).

**Worked example.** Treasury Manager's `draft_payment_reminder` proposal (`docs/ai/prompts/TOOLS_PROMPTS.md`) has just been accepted by the Finance Manager (`ai_decisions#771320`, `decision_type='communication_draft'`, `status='accepted'`); the Approval Assistant's `draft_narrative_copy` output is dispatched verbatim:
```json
{
  "recipient": { "external": { "email": "ap@gos-trading.example", "name": "GOS Trading — Accounts Payable", "party_type": "vendor", "party_id": 1401 } },
  "event_code": "vendor.payment_reminder",
  "subject": "Payment scheduled — Bill GOS-88213",
  "body_html": "<p>This confirms Bill GOS-88213 (KWD 837.480) is scheduled for payment on 2026-07-20 per agreed terms.</p>",
  "decision_id": 771320,
  "source": { "agent_code": "TREASURY_MANAGER", "task_id": 990112 }
}
```

## send_whatsapp

**Description.** Dispatches a WhatsApp Business API message to an internal user's registered number or, with the same `decision_id`-gated external path `send_email` uses, to a customer or vendor. WhatsApp Business imposes two constraints neither of QAYD's other channels has: a **business-initiated** message (one the recipient did not message first) may only use a Meta-approved `template_name` with positional `template_variables`, never free text; a **session message** (free-text `session_message`) is only permitted inside the 24-hour customer-service window that opens the moment the recipient last messaged the company's WhatsApp Business number. Both constraints are enforced by this tool's own schema and endpoint, not left to the calling agent's judgment.

**When to use.** Gulf-market usage patterns favor WhatsApp over email or SMS for anything genuinely time-sensitive — `docs/ai/AI_COMMAND_CENTER.md` names Urgent Actions and the Morning Briefing as the first candidates. Use `send_whatsapp` for the same class of message `send_sms` handles, when the company has WhatsApp Business configured and the recipient has opted in; prefer `send_sms` when no WhatsApp opt-in is on file, since a template-only business-initiated message to a non-opted-in number is rejected outright, not merely discouraged.

**Input schema.**
```json
{
  "name": "send_whatsapp",
  "description": "Dispatch a WhatsApp Business API message. Business-initiated messages require a Meta-approved template_name; free-text session_message is only valid inside the 24-hour post-inbound window.",
  "input_schema": {
    "type": "object",
    "properties": {
      "recipient": {
        "type": "object",
        "properties": {
          "user_id": { "type": ["integer", "null"] },
          "external": {
            "type": ["object", "null"],
            "properties": { "whatsapp_number": { "type": "string", "pattern": "^\\+[1-9]\\d{7,14}$" }, "party_type": { "type": "string", "enum": ["customer", "vendor", "other"] }, "party_id": { "type": ["integer", "null"] } },
            "required": ["whatsapp_number", "party_type"], "additionalProperties": false
          }
        }
      },
      "template_name": { "type": ["string", "null"], "description": "Required unless a session_message is sent inside an open 24-hour window." },
      "template_variables": { "type": "object" },
      "session_message": { "type": ["string", "null"], "maxLength": 1600 },
      "decision_id": { "type": ["integer", "null"], "description": "Required for an external recipient, identical contract to send_email." },
      "source": { "type": "object", "properties": { "agent_code": { "type": "string" }, "task_id": { "type": ["integer", "null"] } }, "required": ["agent_code"], "additionalProperties": false }
    },
    "required": ["recipient", "source"],
    "additionalProperties": false
  }
}
```

**Endpoint.** `POST /api/v1/notifications` (`channel = 'whatsapp'`).
**Permission.** `communication.whatsapp.send`.

**Output (`202 Accepted`).** Identical envelope shape to `send_email`, `"provider": "whatsapp_business"`, `delivery_status` progressing `pending → sent → delivered` (WhatsApp additionally reports `read` via the same webhook path, stored in `data.read_at_provider` since `notifications.read_at` is reserved for in-app read receipts).

**Errors.** `422 TEMPLATE_NOT_APPROVED` (new code — `template_name` is unrecognized, pending Meta review, or rejected); `422 OUTSIDE_SESSION_WINDOW` (new code — `session_message` attempted with no template and no open 24-hour window); `422 RECIPIENT_NOT_OPTED_IN` (new code — external recipient has no recorded WhatsApp opt-in); `422 INVALID_FORMAT` (phone not E.164); `422 EXTERNAL_SEND_REQUIRES_APPROVED_DRAFT`; `403 INSUFFICIENT_PERMISSION`.

**Worked example.** The Approval Assistant sends a payslip-ready notice to an employee who has opted into WhatsApp payroll notices, using a pre-approved template rather than free text since this is business-initiated (the employee did not message first):
```json
{
  "recipient": { "user_id": 8871 },
  "template_name": "payslip_ready_v2",
  "template_variables": { "period": "July 2026", "portal_link": "https://app.qayd.com/payslips/44120" },
  "source": { "agent_code": "APPROVAL_ASSISTANT", "task_id": 552091 }
}
```

## send_sms

**Description.** Dispatches an SMS via Twilio to an internal user's registered phone or, with the `decision_id`-gated external path, a customer/vendor phone number. SMS is the most cost- and compliance-sensitive channel in the catalog: every message is billed per segment, and unlike email or in-app, an SMS recipient's own carrier-level `STOP` reply is a binding, immediate opt-out this tool must honor on the very next call, not merely on the next `notification_preferences` sync.

**When to use.** Time-critical alerts to a recipient who may not have the QAYD app open and has no WhatsApp Business relationship with the company — an OTP-adjacent-urgency alert, a same-day payment-due reminder, a critical fraud hold notice to a named human's personal phone when in-app/push has gone unacknowledged past its SLA. Prefer `send_whatsapp` when the recipient has opted in there; a message that would fit in one WhatsApp session message often costs measurably more as a multi-segment SMS.

**Input schema.**
```json
{
  "name": "send_sms",
  "description": "Dispatch an SMS via Twilio. Body capped at 320 characters (2 GSM-7 segments) to bound per-message cost; longer content belongs in an in-app notification with a deep link instead.",
  "input_schema": {
    "type": "object",
    "properties": {
      "recipient": {
        "type": "object",
        "properties": {
          "user_id": { "type": ["integer", "null"] },
          "external": { "type": ["object", "null"], "properties": { "phone_e164": { "type": "string", "pattern": "^\\+[1-9]\\d{7,14}$" }, "party_type": { "type": "string", "enum": ["customer", "vendor", "other"] }, "party_id": { "type": ["integer", "null"] } }, "required": ["phone_e164", "party_type"], "additionalProperties": false }
        }
      },
      "event_code": { "type": "string" },
      "template_variables": { "type": "object" },
      "body": { "type": ["string", "null"], "maxLength": 320 },
      "decision_id": { "type": ["integer", "null"], "description": "Required for an external recipient." },
      "source": { "type": "object", "properties": { "agent_code": { "type": "string" }, "task_id": { "type": ["integer", "null"] } }, "required": ["agent_code"], "additionalProperties": false }
    },
    "required": ["recipient", "event_code", "source"],
    "additionalProperties": false
  }
}
```

**Endpoint.** `POST /api/v1/notifications` (`channel = 'sms'`).
**Permission.** `communication.sms.send`.

**Output (`202 Accepted`).** Identical shape to `send_email`/`send_whatsapp`, `"provider": "twilio"`.

**Errors.** `422 INVALID_FORMAT` (not E.164); `422 MESSAGE_TOO_LONG`; `422 RECIPIENT_OPTED_OUT` (new code — a prior `STOP` is on file); `422 EXTERNAL_SEND_REQUIRES_APPROVED_DRAFT`; `403 INSUFFICIENT_PERMISSION`; async `PROVIDER_DELIVERY_FAILED` (carrier filtering/undeliverable).

**Worked example.** The hold-circuit-breaker guardrail (`docs/ai/agents/FRAUD_AGENT.md` § Guardrails, guardrail 2) has tripped; alongside the in-app/broadcast page, the CFO's personal phone is additionally SMS'd because the last in-app notification of this severity went unacknowledged for over an hour:
```json
{
  "recipient": { "user_id": 1005 },
  "event_code": "fraud.circuit_breaker_tripped",
  "template_variables": { "company_name": "Al-Rawda Trading & Logistics W.L.L.", "open_holds": "5" },
  "source": { "agent_code": "FRAUD_DETECTION", "task_id": 771442 }
}
```

## create_approval_request

**Description.** Opens a formal, tracked, SLA-timed human-in-the-loop gate on a specific pending action by creating an `approval_requests` row — the tool `docs/ai/prompts/AGENT_PROMPTS.md`'s Approval Assistant subsection already names (`create_approval_request` / `route_decision`, "own write scope, `approval_requests`/`approval_request_approvers` creation only — never the `decision` column"), specified here at the JSON-Schema level that document leaves implicit. Calling this tool never itself approves, executes, or advances anything; it is the mechanism by which "this requires a human" becomes a durable, escalating, auditable record instead of a sentence in an agent's reasoning that a busy reviewer might miss. Every agent in the roster whose mandate touches the fixed sensitive-operations list — bank transfer, payroll release, tax submission, voiding posted data, permission changes — ultimately calls this tool (directly, or via the Approval Assistant acting on its behalf) rather than the mutating endpoint itself, which is structurally absent from every agent's own tool schema per `docs/ai/prompts/TOOLS_PROMPTS.md`'s Layer 1.

**When to use.** Call `create_approval_request` once a `write_propose` tool's own result — or the Decision Engine's autonomy formula (`docs/ai/AI_FINANCE_OS.md`) — has already determined `autonomy_applied = 'requires_approval'`; do not call it speculatively "to see whether this needs approval" (a `read` tool — `get_approval_status` against `resource_type`/`resource_id`, or the company's `approval_chains` configuration — answers that question without creating a live, human-visible request). Do not call it for something that is merely informational; that is `send_notification` or `notify_role`, both of which expect no response.

**Input schema.**
```json
{
  "name": "create_approval_request",
  "description": "Open a tracked human approval gate on a specific pending action. Never approves, executes, or advances anything itself. Always AI-initiated when called through this MCP tool (a human approving their own action goes through the web app directly, not through this catalog).",
  "input_schema": {
    "type": "object",
    "properties": {
      "permission_key": { "type": "string", "description": "The sensitive permission this action requires, e.g. 'bank.transfer', 'payroll.release', 'tax.submit'. Must be a real, registered permission key." },
      "resource_type": { "type": "string", "description": "e.g. 'transfers', 'payroll_runs', 'tax_returns', 'journal_entries'." },
      "resource_id": { "type": ["integer", "null"], "description": "Null when the underlying draft has not yet been materialized as its own row." },
      "amount": { "type": ["string", "null"], "pattern": "^\\d+(\\.\\d{1,4})?$" },
      "currency_code": { "type": ["string", "null"] },
      "summary": { "type": "string", "maxLength": 240, "description": "One human-readable sentence a mobile push preview can render in full." },
      "payload": { "type": "object", "description": "The exact body that will execute against the real endpoint on final approval — verbatim, never re-derived at execution time." },
      "risk_level": { "type": "string", "enum": ["standard", "elevated", "critical"], "default": "standard" },
      "decision_id": { "type": "integer", "description": "The originating ai_decisions row. Required on every call through this tool." },
      "requested_approver_role": { "type": ["string", "null"], "description": "Override; when null, the approver chain is resolved from the company's own configured approval_chains for permission_key and amount." }
    },
    "required": ["permission_key", "resource_type", "summary", "payload", "decision_id"],
    "additionalProperties": false
  }
}
```

**Endpoint.** `POST /api/v1/auth/approvals`.
**Permission.** `auth.approvals.create`.

**Output (`202 Accepted`).**
```json
{
  "success": true,
  "data": {
    "id": "apr_01J2Y7A1BZQK", "company_id": 4821, "chain_id": 4,
    "permission_key": "payroll.release", "resource_type": "payroll_runs", "resource_id": 771,
    "amount": "48250.0000", "currency_code": "KWD",
    "status": "pending", "current_step": 1,
    "initiated_by_type": "ai_agent", "initiated_by_id": 233, "agent_id": "payroll-manager-v2",
    "ai_confidence": 0.9700, "ai_reasoning": "All 42 employee salary components recalculated...",
    "expires_at": "2026-07-19T09:00:00Z", "created_at": "2026-07-16T09:00:00Z"
  },
  "message": "Approval request created; step-1 approver notified", "errors": [],
  "meta": { "pagination": null }, "request_id": "d3e4f5a6-...", "timestamp": "2026-07-16T09:00:00Z"
}
```
The Laravel Service layer behind this endpoint fires the step-1 approver's `send_notification` (in-app + push, real time via Laravel Reverb) **inside the same transaction** that inserts the `approval_requests` row — the calling agent never has to remember a separate notify step for the baseline "you have something to review" alert; § **Approval Requests** covers when an agent should additionally call `notify_role`/`broadcast_alert` for extra visibility beyond that baseline.

**Errors.** `404 NO_ACTIVE_APPROVAL_CHAIN` (new code — no `approval_chains` row matches this `permission_key`/`amount` for this company; per `docs/api/AUTHORIZATION_API.md`'s own workflow, "none active? allow immediately" — this tool should not have been called at all, and the agent should re-check with a `read` tool before retrying anything); `409 DUPLICATE_ENTRY` (an open request already exists for the same `resource_type`+`resource_id`+`permission_key`); `422 VALIDATION_ERROR`; `403 INSUFFICIENT_PERMISSION`.

**Worked example.** See § **Examples** for the full fraud-alert-to-approval-request trace.

## get_approval_status

**Description.** Reads the current state of one approval request — its `status`, `current_step`, every recorded `approval_request_actions` entry, and, when the request originated from an AI proposal, the `ai_reasoning`/`ai_confidence`/`ai_source_documents` a human reviewer saw. Purely a `read`; carries no gate of its own.

**When to use.** Before retrying or escalating anything: per `docs/ai/prompts/TOOLS_PROMPTS.md`'s `409 Conflict` guidance, an agent must re-fetch current state via a read tool before deciding whether a pending action is still pending, was rejected, or expired — `get_approval_status` is that read for anything gated through this catalog. Also used by the Approval Assistant's own SLA-tracking loop (`get_sla_status` in `docs/ai/prompts/AGENT_PROMPTS.md`'s Approval Assistant tool list is this same read, filtered to the `expires_at`/`current_step` fields alone) to decide whether an escalation notification is due.

**Input schema.**
```json
{
  "name": "get_approval_status",
  "description": "Fetch the current status, step history, and (if AI-initiated) reasoning/confidence/sources of one approval request.",
  "input_schema": {
    "type": "object",
    "properties": {
      "approval_request_id": { "type": ["string", "null"], "description": "e.g. 'apr_01J2Y7A1BZQK'. Preferred when known." },
      "resource_type": { "type": ["string", "null"] },
      "resource_id": { "type": ["integer", "null"], "description": "Used with resource_type to look up the open request when approval_request_id is not yet known to the caller." }
    },
    "additionalProperties": false
  }
}
```
Exactly one lookup path must resolve: `approval_request_id`, or the `resource_type`+`resource_id` pair.

**Endpoint.** `GET /api/v1/auth/approvals/{id}` (or `GET /api/v1/auth/approvals?resource_type=...&resource_id=...` for the lookup form).
**Permission.** `auth.approvals.approve` or ownership of the request — reused verbatim from `docs/api/AUTHORIZATION_API.md`'s own endpoint table rather than a new key, since this tool wraps that exact endpoint.

**Output (`200 OK`).**
```json
{
  "success": true,
  "data": {
    "id": "apr_01J2Y7A1BZQK", "status": "pending", "current_step": 1,
    "actions": [],
    "ai_confidence": 0.9700, "ai_reasoning": "All 42 employee salary components recalculated against July fiscal_period; 0 variances >2% vs June.",
    "expires_at": "2026-07-19T09:00:00Z"
  },
  "message": "Approval request retrieved", "errors": [],
  "meta": { "pagination": null }, "request_id": "e4f5a6b7-...", "timestamp": "2026-07-17T10:00:00Z"
}
```

**Errors.** `404 RESOURCE_NOT_FOUND` (wrong id, or a different company's request); `403 INSUFFICIENT_PERMISSION` (neither an eligible approver for the current step nor the initiator).

## notify_role

**Description.** Delivers one notification to every user who currently holds a named role at the active company — resolved fresh at call time against `roles`/`company_users`, never a cached recipient list, so a role change between two calls is honored immediately. This is the tool underlying every "Purchasing Manager or Finance Manager (whichever owns the flagged document)" and "Finance Manager, Compliance Agent" line in `docs/ai/agents/FRAUD_AGENT.md`'s own escalation table (§ Guardrails & Human Approval, guardrail 4) — that table specifies *which* role is notified for *which* case category; `notify_role` is the concrete call that table compiles down to.

**When to use.** The audience is a function, not a person — "whoever is Finance Manager right now," not a specific `user_id` that might be stale by the time the call fires. Prefer `send_notification` when the recipient is a specific named individual already known to the calling context (e.g., a task explicitly assigned to one person); prefer `broadcast_alert` when the audience is wider than one role (company-wide, or "everyone holding any approval-capable permission").

**Input schema.**
```json
{
  "name": "notify_role",
  "description": "Notify every user currently holding a named role at the active company.",
  "input_schema": {
    "type": "object",
    "properties": {
      "role": {
        "type": "object",
        "properties": { "role_id": { "type": ["integer", "null"] }, "role_name": { "type": ["string", "null"] } },
        "description": "Exactly one of role_id or role_name."
      },
      "also_notify_roles": { "type": "array", "items": { "type": "string" }, "description": "Additional role names notified with the identical payload in the same call — reuses the escalation-prompt field docs/ai/prompts/AGENT_PROMPTS.md's inter-agent escalation shape already defines." },
      "event_code": { "type": "string" },
      "template_variables": { "type": "object" },
      "title": { "type": ["string", "null"], "maxLength": 200 },
      "body": { "type": ["string", "null"], "maxLength": 2000 },
      "priority": { "type": "string", "enum": ["normal", "high", "critical"], "default": "high" },
      "channels": { "type": "array", "items": { "type": "string", "enum": ["in_app", "push", "email", "sms"] }, "default": ["in_app", "push"] },
      "data": { "type": "object" },
      "source": { "type": "object", "properties": { "agent_code": { "type": "string" }, "task_id": { "type": ["integer", "null"] }, "correlation_ref": { "type": ["object", "null"] } }, "required": ["agent_code"], "additionalProperties": false }
    },
    "required": ["role", "event_code", "source"],
    "additionalProperties": false
  }
}
```

**Endpoint.** `POST /api/v1/notifications/broadcast` (`audience.type = 'role'`, internally resolved to the matching `role_id`).
**Permission.** `notifications.broadcast`.

**Output (`201 Created`).**
```json
{
  "success": true,
  "data": { "audience": { "type": "role", "role_name": "Finance Manager", "recipient_count": 2 }, "notifications": [ { "id": 990601, "user_id": 2207, "channel": "in_app", "delivery_status": "delivered" }, { "id": 990602, "user_id": 2318, "channel": "in_app", "delivery_status": "delivered" } ] },
  "message": "Broadcast to role delivered", "errors": [],
  "meta": { "pagination": null }, "request_id": "f5a6b7c8-...", "timestamp": "2026-07-17T09:12:05Z"
}
```

**Errors.** `404 ROLE_NOT_FOUND` (new code); `422 VALIDATION_ERROR` (a company currently has zero users holding the named role — the call still succeeds with `recipient_count: 0` rather than erroring, since a role temporarily going unstaffed is a company-configuration fact, not a malformed request); `403 INSUFFICIENT_PERMISSION`; `429 RATE_LIMITED`.

**Worked example.** See § **Examples**.

## broadcast_alert

**Description.** The widest-reach, most tightly-capped tool in the catalog: delivers one alert to every user in the company (`audience.type = 'company'`) or to every user holding a given permission key regardless of role (`audience.type = 'permission_key'`, e.g. everyone who can approve *anything*). `broadcast_alert` exists for the small number of events severe enough to justify interrupting a wide swath of a company's staff at once — `docs/ai/agents/FRAUD_AGENT.md`'s hold-circuit-breaker trip ("Finance Manager, CFO, and the Auditor role receive an immediate, distinct 'hold circuit breaker tripped' notification") and confirmed-sanctions-match escalation ("CFO and Owner immediately, cannot be silenced") are the canonical examples this tool is built for. It is not a louder version of `notify_role` for routine use — its own schema enforces `severity` in `{"high","critical"}` only, and only a fixed, short allow-list of agent codes (`FRAUD_DETECTION`, `COMPLIANCE_AGENT`, `CEO_ASSISTANT`, `APPROVAL_ASSISTANT`) may call it at all, enforced server-side against `source.agent_code`.

**When to use.** Reserve for events that are rare by construction: a tripped safety circuit breaker, a confirmed regulatory deadline miss, a going-concern indicator (`docs/ai/prompts/AGENT_PROMPTS.md`'s CFO Agent template: "flag it for immediate escalation to Auditor, CEO Assistant, and the human CFO/Owner simultaneously"). Every other "many people should know about this" case is better served by `notify_role` aimed at the one or two roles that actually own the response, which is both more targeted and outside `broadcast_alert`'s strict per-company daily cap (§ **Rate Limits & Anti-Spam**).

**Input schema.**
```json
{
  "name": "broadcast_alert",
  "description": "Company-wide or permission-wide urgent alert. Restricted to severity high/critical and to a fixed allow-list of issuing agents. Not a substitute for notify_role.",
  "input_schema": {
    "type": "object",
    "properties": {
      "audience": {
        "type": "object",
        "properties": { "type": { "type": "string", "enum": ["company", "permission_key"] }, "permission_key": { "type": ["string", "null"] } },
        "required": ["type"], "additionalProperties": false
      },
      "severity": { "type": "string", "enum": ["high", "critical"] },
      "event_code": { "type": "string" },
      "template_variables": { "type": "object" },
      "title": { "type": ["string", "null"], "maxLength": 200 },
      "body": { "type": ["string", "null"], "maxLength": 2000 },
      "bypass_preferences": { "type": "boolean", "default": false, "description": "true only accepted when severity='critical' — forces delivery even to a user who opted this event_code out, mirroring notification_preferences' own stated carve-out for security-critical codes." },
      "source": { "type": "object", "properties": { "agent_code": { "type": "string", "enum": ["FRAUD_DETECTION", "COMPLIANCE_AGENT", "CEO_ASSISTANT", "APPROVAL_ASSISTANT"] }, "task_id": { "type": ["integer", "null"] } }, "required": ["agent_code"], "additionalProperties": false }
    },
    "required": ["audience", "severity", "event_code", "source"],
    "additionalProperties": false
  }
}
```

**Endpoint.** `POST /api/v1/notifications/broadcast` (`audience.type = 'company'` or `'permission_key'`).
**Permission.** `notifications.broadcast`.

**Output (`201 Created`).** Same shape as `notify_role`'s, `"audience": { "type": "company", "recipient_count": 14 }`.

**Errors.** `403 FORBIDDEN_AUDIENCE` (new code — `source.agent_code` is not on the fixed allow-list, or `bypass_preferences=true` with `severity='high'`); `429 QUOTA_EXCEEDED` (the company's daily `broadcast_alert` cap, § Rate Limits & Anti-Spam, is already spent); `422 VALIDATION_ERROR`.

**Worked example.** See § **Examples**.

# Safety & Guardrails

**Agents notify; they do not decide, and a notification is never a substitute for the gate itself.** Every tool in this catalog delivers information or opens a tracked request; none of them is, or can become, the mechanism by which a sensitive action actually executes. Fraud Detection paging the Auditor role about a near-certain identity collision does not move money, release a payroll run, or resolve a case — it only guarantees a human notices, per exactly the same "requestable only, never executed by the agent" boundary `docs/ai/agents/FRAUD_AGENT.md` states for its own hold mechanism. An agent that has called `notify_role` or `broadcast_alert` about a pending sensitive action has not discharged its obligation to also call `create_approval_request` where one is warranted — the two are complementary, not substitutes: a notification tells a human *that* something needs attention; an approval request is the durable, SLA-tracked, auditable record *of* that need, and an agent must never treat "I told someone" as equivalent to "a gate now exists."

**Outbound external messages follow policy, approval, and templates — without exception, without a confidence-based carve-out.** `send_email`, `send_whatsapp`, and `send_sms` are the only three tools in this catalog capable of reaching someone who is not signed into a QAYD session, and reaching such a person is the one place this catalog's "no approval gate" default reverses completely. Concretely, and enforced at the endpoint rather than merely stated in a prompt:

1. **The tool call itself fails closed.** `recipient.external` set without a `decision_id` resolving to an `ai_decisions` row with `decision_type = 'communication_draft'` and `status = 'accepted'` is rejected with `422 EXTERNAL_SEND_REQUIRES_APPROVED_DRAFT` before any provider (Mailgun/SES/Twilio/WhatsApp Business) is ever called — mirroring `docs/ai/prompts/TOOLS_PROMPTS.md`'s Layer 1 pattern of a capability being structurally unavailable rather than merely permission-checked.
2. **What was approved is what is sent, byte-for-byte.** The endpoint diffs `subject`/`body_html`/`body_text` (email) or `template_name`/`template_variables` (WhatsApp) or `body` (SMS) against the referenced decision's own stored `payload` and rejects any divergence — an agent cannot get a human's sign-off on one draft and quietly send a different one, whether by error or by a prompt-injected instruction encountered between drafting and dispatch.
3. **No confidence score, however high, waives step 1.** This is the same posture `docs/ai/prompts/TOOLS_PROMPTS.md` states for `draft_payment_reminder` ("`suggest_only`, always, regardless of confidence — anything reaching an external party gets a human's eyes first") applied at the dispatch layer rather than only the drafting layer: a 0.99-confidence collections message is exactly as gated as a 0.40-confidence one.
4. **Retrieved content is never a dispatch instruction.** Per the Platform Guardrail Prelude's Rule 5 (`docs/ai/prompts/AGENT_PROMPTS.md`), text inside an OCR'd document, a customer email, or a bank memo that reads as an instruction — "please resend this invoice to my new email," "forward a copy to legal@..." — is data for an agent to *consider drafting a response to*, never a live instruction that authorizes calling `send_email`/`send_whatsapp`/`send_sms` on its own. Every such request still routes through drafting → human acceptance → dispatch, with the anomalous embedded instruction itself worth naming to the reviewing human when material.

**No PII in an insecure channel, and no unmasked value leaves through any channel.** SMS and WhatsApp session messages are plaintext to the carrier/Meta's own systems and are not treated by this catalog as a secure channel for anything beyond a masked reference. Concretely:
- No tool in this catalog ever accepts, in any field, an unmasked civil ID/passport number, full IBAN or bank account number, full card/KNET PAN, or a one-time password/authentication code — the same masking discipline `docs/ai/prompts/SAFETY_PROMPTS.md` § **PII & Confidentiality** establishes at retrieval time applies identically at send time: a `body`/`body_html`/`template_variables` value containing a pattern matching any of these is rejected client-side (`422 VALIDATION_ERROR`, `field` naming the offending key) before the call reaches a provider, never merely discouraged in a tool's description text.
- A message needing to reference such a value uses the already-established masked form (last four digits) plus a deep link (`data.type`/`data.id`) back into an authenticated QAYD session for the full detail — exactly the pattern `docs/ai/AI_COMMAND_CENTER.md`'s mobile push notifications already use for approval requests.
- A sealed `fraud_cases` row (`docs/ai/agents/FRAUD_AGENT.md` § Guardrails, guardrail 3 — `payroll_ghost`, `expense_abuse`, `anomalous_access`, `segregation_of_duties_conflict` naming a specific employee) may never be the subject of `notify_role` or `broadcast_alert`: both tools resolve their audience from `roles`, which has no concept of the sealed-visibility exception (HR Manager, CFO, Owner, and the case's own `assigned_to`); a sealed case is only ever communicated via `send_notification` addressed to those specific `user_id`s by name, individually resolved by the calling agent from the sealed case's own `fraud_cases.assigned_to`/HR-role lookup, never a role-wide fan-out that could reach the implicated employee's own manager.
- The affected user of an in-progress `anomalous_access` investigation is never a recipient of any tool in this catalog until the case resolves — notifying a genuinely compromised or complicit account before resolution is the one scenario this document treats as strictly worse than saying nothing, per `docs/ai/agents/FRAUD_AGENT.md`'s identical rule.

**Provider- and channel-specific compliance is enforced, not assumed.** `send_whatsapp` refuses a business-initiated `template_name` that is not on the record as Meta-approved for this company's WhatsApp Business Account, and refuses `session_message` outside an open 24-hour window — a company cannot configure around either constraint, since both are WhatsApp Business API platform rules, not QAYD policy. `send_sms` and `send_whatsapp` both refuse an external recipient with no recorded opt-in on file, and both honor an inbound `STOP`/opt-out reply on the very next call attempt, never merely at the next scheduled preference sync. `send_email` never auto-includes a customer or vendor on `cc`/`bcc` — those fields, where present at all, accept only additional internal `user_id`s.

# Approval Requests

**Reconciling `approval_requests` across three sibling documents.** `create_approval_request` and `get_approval_status` wrap the endpoints `docs/api/AUTHORIZATION_API.md` defines and owns (`POST`/`GET /api/v1/auth/approvals`), and that document's own column set — `chain_id`, `permission_key`, `resource_type`/`resource_id`, `initiated_by_type`/`initiated_by_id`/`agent_id`, `ai_confidence`/`ai_reasoning`/`ai_source_documents`, `current_step` — is this catalog's wire-level source of truth, because it is the document whose stated purpose is owning the authorization surface these two tools are thin wrappers around. Two other documents describe the identical underlying concept with a different column set, written for their own document's emphasis rather than in conflict on purpose: `docs/ai/AI_FINANCE_OS.md`'s `approval_requests` emphasizes the multi-approver/`ai_decisions` link (`decision_id`, `initiated_by_agent`, `required_approver_count`, `sequential`) and its own `approval_request_approvers` child table; `docs/ai/AI_COMMAND_CENTER.md`'s `ai_approval_requests` is that panel's own read-optimized projection, feeding the Approval Center's six real-time widgets and additionally supporting the one unilateral, reversible `status = 'held'` state Fraud Detection may set directly (never `approved`/`rejected`, which remain human-only in every one of the three documents without exception). A reader should resolve all three onto one mental model exactly as `docs/ai/prompts/AGENT_PROMPTS.md` § **Agent Prompt Templates** already resolves confidence-scale and agent-code drift elsewhere in this corpus: `docs/ai/AI_FINANCE_OS.md`'s `decision_id`/`initiated_by_agent`/`sequential` map onto `AUTHORIZATION_API.md`'s `payload.decision_id`/`agent_id`/an `approval_chains` step sequence respectively, and `docs/ai/AI_COMMAND_CENTER.md`'s `ai_approval_requests` is kept eventually consistent with the one canonical row by the same `approval.requested`/`approval.status_changed` domain events `notify_role` and `broadcast_alert` key off of — never a second write path a caller of this catalog needs to know about. `create_approval_request` writes exactly once, to the row `AUTHORIZATION_API.md` owns.

**The escalation chain this tool opens, end to end.**
```
create_approval_request
        │  approval_chains resolved for (company_id, permission_key, amount)
        ▼
approval_requests: status='pending', current_step=1  ── 202 Accepted
        │
        ▼
send_notification (in-app + push, real time) fired atomically to the step-1 approver(s),
inside the same transaction that inserted the row — never a separate tool call
        │
   step-1 approver: PATCH /api/v1/auth/approvals/{id} {"decision":"approved"|"rejected"}
        │
        ├─ rejected ──────────────────────────────► status='rejected'; initiator notified; nothing executes
        │
        ▼ approved, more steps remain
   current_step += 1 ─► send_notification fired to the next step's approver(s)
        │
        ▼ approved, final step
   status='approved' ─► the ORIGINAL payload executes against the real endpoint, under the
                          approving human's own session and re-validated permission
        │
        ▼ (no decision within sla_due_at, any step)
   Approval Assistant's own SLA loop (get_approval_status polled on a schedule) fires
   escalate_decision ─► notify_role (next role up the chain) or send_notification to a
   named fallback delegate ─► status='escalated', sla_due_at recomputed for the new step
```
Default SLA, reused verbatim from `docs/ai/prompts/AGENT_PROMPTS.md`'s Approval Assistant subsection: **4 business hours** for a request sourced from a `critical`-severity finding, **2 business days** otherwise; `docs/api/AUTHORIZATION_API.md`'s own default `expires_at` (72 hours, configurable down to a system minimum of 24) is the outer bound past which an unresolved request expires outright rather than merely escalating again.

**When an agent should call `notify_role`/`broadcast_alert` in addition to the automatic step-1 notification.** The baseline notification `create_approval_request` fires is scoped to the resolved approver chain alone. An agent additionally calls `notify_role` or `broadcast_alert` only when its own document specifies a *parallel*, non-approver audience that also needs to know — `docs/ai/agents/FRAUD_AGENT.md`'s "`risk_score ≥ 0.90` ... Auditor role, in real time, independent of the case-category routing" is exactly this: the Auditor is not an approver on the hold request, so paging them is a separate `notify_role` call the agent makes alongside, not instead of, whatever `create_approval_request` call (if any) the case also warrants.

# Rate Limits & Anti-Spam

**The platform-wide GCRA limiter applies to every endpoint in this catalog first.** `POST /api/v1/notifications`, `POST /api/v1/notifications/broadcast`, and `POST /api/v1/auth/approvals` are ordinary `/api/v1/*` routes and are metered by the identical GCRA-over-Redis algorithm, the identical four dimensions (`ip`, `user`, `company`, `api_key`), and the identical `X-RateLimit-*`/`Retry-After` response headers `docs/api/API_RATE_LIMITING.md` specifies platform-wide — nothing in this document overrides that floor. What follows is additive, communications-specific throttling layered on top of it, because "too many requests per minute" and "too many *humans interrupted* per hour" are different failure modes that need different limiters.

**`ai_tool_registry.max_calls_per_task` ceilings, per tool.** Per `docs/ai/prompts/TOOLS_PROMPTS.md`'s per-task call-count guardrail, every tool in this catalog carries its own conservative default, tunable per company:

| Tool | Default `max_calls_per_task` | Rationale |
|---|---|---|
| `send_notification` | 5 | A single task rarely needs to inform more than a handful of named people |
| `send_email` | 3 | External sends are already gated by `decision_id`; internal report delivery rarely needs more than one or two per task |
| `send_whatsapp` | 3 | Same rationale as `send_email`; also bounded by the 24-hour session-window mechanics |
| `send_sms` | 2 | The costliest per-message channel; a task needing more than two SMS sends almost always indicates a broader escalation, which belongs to `notify_role`/`broadcast_alert` instead |
| `notify_role` | 3 | A task rarely needs to page more than the primary and one escalation role |
| `broadcast_alert` | 1 | One company-wide interruption per task, ever — a second genuinely distinct broadcast belongs to a new task |
| `create_approval_request` | 2 | Covers the primary gate plus, rarely, one secondary gate on a related resource within the same task |
| `get_approval_status` | 20 | A `read`; the ceiling exists only to catch a runaway polling loop, not to conserve a scarce resource |

Hitting a ceiling does not fail the task silently — per `docs/ai/prompts/TOOLS_PROMPTS.md`'s own handling, `ai_tasks.status` transitions to `awaiting_human` with whatever was already sent intact and disclosed, never retried past the ceiling under a different guise.

**Per-recipient, per-event cooldown (`dedup_key`).** `send_notification`, `notify_role`, and `broadcast_alert` all accept an optional `dedup_key`; a second call carrying the same `dedup_key` for the same resolved recipient within a rolling cooldown window (default 30 minutes for `normal`/`high` priority, 5 minutes for `critical`) is suppressed — the API call itself still returns `201` with that recipient's row `delivery_status = 'suppressed'`, `data.suppressed_reason = 'cooldown'` — never silently dropped without a trace, and never surfaced to the calling agent as an error it should retry. The check is a simple existence query against `notifications` (`WHERE company_id = ? AND user_id = ? AND data->>'dedup_key' = ? AND created_at > now() - interval`), reusing the existing table rather than introducing a new one. This is what stops a noisy detector — a signal crossing and re-crossing a threshold within minutes — from paging the same Finance Manager a dozen times about what is, from a human's perspective, one still-open situation.

**`notification_preferences` opt-out is honored before a row is even created for a non-critical event**, per `docs/database/ERD.md`'s own stated rule; this catalog adds nothing to that mechanism beyond honoring it identically across all five channels, including the two `docs/database/ERD.md` had not yet enumerated (`whatsapp` per the schema addition in § Tool Catalog; `sms` opt-out additionally always mirrors a carrier-level `STOP`, which is honored even where a company's own `notification_preferences` row has not yet caught up).

**Channel-specific caps beyond generic throttling, reflecting real per-message cost and real platform policy:**
- **SMS and WhatsApp daily company cap.** Both channels bill per message to a real provider (Twilio; WhatsApp Business conversation-based pricing); each company's plan carries a `daily_external_message_cap` (Free: 20, Growth: 500, Enterprise: negotiated), tracked per calendar day per channel. Exceeding it returns `429 QUOTA_EXCEEDED` for that channel only — `send_notification`/`send_email`/in-app delivery on the same task are unaffected, since only the two per-message-billed channels are capped this way.
- **`broadcast_alert` per-company daily cap.** A hard ceiling of **3 per company per rolling 24 hours**, regardless of which allow-listed agent is calling — enforced centrally, not per-agent, because the harm this cap defends against (alert fatigue serious enough that a genuinely critical page starts being ignored) is a property of how many times the *company* was interrupted, not of any one agent's own call volume. A fourth attempt within the window returns `429 QUOTA_EXCEEDED`; the underlying finding is still recorded and still reaches its resolved audience via `notify_role` instead, which has no comparable cap — the cap protects against *company-wide* interruption specifically, never against the finding going unnotified altogether.
- **WhatsApp 24-hour session window** is not a QAYD-configurable limit at all — it is enforced identically for every company because it is a WhatsApp Business API platform rule, not a QAYD anti-spam policy; `send_whatsapp` returns `422 OUTSIDE_SESSION_WINDOW` rather than a `429`, because the constraint is about message *type* eligibility, not request *rate*.

**Two distinct `429` codes apply, reused verbatim from `docs/api/API_RATE_LIMITING.md` and `docs/api/API_ERROR_HANDLING.md`:** `RATE_LIMITED` for a short-window GCRA rejection (retry after `Retry-After` seconds, safe to auto-retry per the platform SDK's own behavior), and `QUOTA_EXCEEDED` for any of the caps enumerated above — a monthly/daily allotment exhaustion, not a burst — which an SDK must **not** auto-retry the way it auto-retries `RATE_LIMITED`, since `Retry-After` on a `QUOTA_EXCEEDED` may reflect hours, not seconds.

# Error Handling

Every response from every endpoint in this catalog uses the platform's one error envelope, unmodified, per `docs/api/API_ERROR_HANDLING.md` § **Error Envelope** — `success`/`data`/`message`/`errors[]`/`meta`/`request_id`/`timestamp`, `data: null` on any error, `success: false` if and only if `errors` is non-empty. This section adds the tool-specific codes this catalog introduces to that platform-wide catalog (additive, never a renaming of an existing code, per that document's own versioning discipline) and restates, once, the mandatory model behavior on each.

**New codes this catalog adds to `docs/api/API_ERROR_HANDLING.md`'s Error Code Catalog:**

| Code | HTTP | Category | Meaning |
|---|---|---|---|
| `EXTERNAL_SEND_REQUIRES_APPROVED_DRAFT` | 422 | Business Rule | `send_email`/`send_whatsapp`/`send_sms` targeted an external recipient without a `decision_id` resolving to an accepted, matching `communication_draft` |
| `TEMPLATE_NOT_APPROVED` | 422 | Business Rule | `send_whatsapp`'s `template_name` is unrecognized, pending Meta review, or rejected |
| `OUTSIDE_SESSION_WINDOW` | 422 | Business Rule | `send_whatsapp`'s `session_message` was attempted with no `template_name` and no open 24-hour window |
| `RECIPIENT_NOT_OPTED_IN` | 422 | Business Rule | External `send_whatsapp` recipient has no recorded opt-in |
| `RECIPIENT_OPTED_OUT` | 422 | Business Rule | External `send_sms` recipient has a `STOP` on file |
| `MESSAGE_TOO_LONG` | 422 | Validation | `send_sms`'s `body` exceeds 320 characters |
| `ROLE_NOT_FOUND` | 404 | Not Found | `notify_role`'s `role_id`/`role_name` does not resolve to a real role at this company |
| `FORBIDDEN_AUDIENCE` | 403 | Authorization | `broadcast_alert` called by an agent not on the fixed allow-list, or `bypass_preferences=true` at `severity='high'` |
| `NO_ACTIVE_APPROVAL_CHAIN` | 404 | Business Rule | `create_approval_request`'s `permission_key`/`amount` matches no configured `approval_chains` row for this company |
| `PROVIDER_DELIVERY_FAILED` | 500 (async, not a live HTTP response) | Integration | Mailgun/SES/Twilio/WhatsApp Business reported a bounce, complaint, or undeliverable status after acceptance; reported on the `notifications` row's own `delivery_status`/`data`, exactly as `WEBHOOK_DELIVERY_FAILED` is reported in `docs/api/API_WEBHOOKS.md` |

**Mandatory model behavior on each result class, extending `docs/ai/prompts/TOOLS_PROMPTS.md`'s shared table identically for this catalog:**

| Result | Required agent behavior |
|---|---|
| `403 FORBIDDEN_AUDIENCE` / `INSUFFICIENT_PERMISSION` | State the specific missing permission or allow-list exclusion by name; never retry with a different tool to route around it (e.g., substituting three `notify_role` calls for a denied `broadcast_alert`) |
| `404 ROLE_NOT_FOUND` / `NO_ACTIVE_APPROVAL_CHAIN` | Treat as "this company is not configured the way I assumed" — re-check with a `read` tool (the company's `roles` list, or its `approval_chains`) rather than guessing a substitute value and retrying blind |
| `409 DUPLICATE_ENTRY` (a second `create_approval_request` for the same resource) | Call `get_approval_status` once to observe the existing request's real state before deciding anything further; never create a second, competing request for the same underlying fact |
| `422 EXTERNAL_SEND_REQUIRES_APPROVED_DRAFT` | Never attempt to reformulate the message to "get around" the missing draft — draft the message as a `communication_draft` decision and stop; dispatch is a separate, later call once a human has accepted it |
| `422 TEMPLATE_NOT_APPROVED` / `OUTSIDE_SESSION_WINDOW` | Fall back to `send_sms` (internal) or disclose the constraint to the human reviewer (external) rather than attempting free text through the same channel a second time |
| `429 RATE_LIMITED` | Honor `Retry-After` exactly; do not immediately reissue |
| `429 QUOTA_EXCEEDED` | Do not auto-retry; state plainly that the company's cap for this channel/tool is exhausted for the current period, and — for `broadcast_alert` specifically — fall back to `notify_role` against the same audience's owning role rather than letting the finding go unnotified |
| `5xx` / timeout / async `PROVIDER_DELIVERY_FAILED` | The originating `ai_tasks` row records the failure; because the `notifications` row itself was already durably written before any provider call, a mid-flight provider failure never produces a duplicate send on retry — the same row's `delivery_status` is updated, not a new row created |

Every one of these follows `docs/ai/prompts/TOOLS_PROMPTS.md`'s bounded-retry discipline: at most two retries of the identical call within one task; a third consecutive failure ends that line of action and is disclosed to the next human who looks at the task, never silently abandoned.

# Examples

**Fraud alert → notify + create approval request**, traced at the tool-call layer underneath `docs/ai/agents/FRAUD_AGENT.md`'s own Scenario 1 — a near-duplicate invoice at Al-Rawda Trading & Logistics W.L.L. (`company_id` 4821), corroborated by a recent vendor bank-account change on vendor 1401, "GOS Trading." That document's own narrative states, in prose, "Purchasing Manager and Finance Manager are notified per the escalation table; the Auditor is not paged in real time, since 0.780 is below the 0.90 near-certain threshold." What follows is the exact sequence of this catalog's tools that narrative compiles down to, extended one step further than that document's own scenario: because the flagged bill's vendor also has an open Treasury payment-run line awaiting sign-off this same week, Fraud Detection's finding is significant enough to additionally open a tracked approval gate on releasing that hold, rather than leaving "hold applied" as the only durable record of the decision point.

**1. Trigger and scoring** (owned entirely by `docs/ai/agents/FRAUD_AGENT.md`, reused here without redefinition): `BILL-2026-004118` (KWD 840.000, invoice reference `GOS-88214`) scores `risk_score = 0.780` against vendor 1401's Day-0 sub-threshold bank-change signal and Day-6 near-duplicate `BILL-2026-004102`. `fraud_cases` opens (`id` 55214, `category = 'duplicate_invoice'`, `hold_applied = true`, `hold_entity_type = 'bills'`, `hold_entity_id = 4118`); `request_transaction_hold` (that document's own tool) has already fired, structurally blocking `purchasing.bills.approve` on this bill.

**2. This document's tools begin here — `notify_role`, twice, per the escalation table's exact routing** (Purchasing Manager and Finance Manager get the case-opened notice; Auditor is deliberately excluded at this risk band):
```json
{
  "role": { "role_name": "Purchasing Manager" },
  "also_notify_roles": ["Finance Manager"],
  "event_code": "fraud.case.opened",
  "title": "Fraud Detection: possible duplicate invoice — GOS Trading",
  "body": "Bill GOS-88214 (KWD 840.000) is on hold. It closely matches Bill GOS-88213, posted 3 days earlier for the same vendor and near-identical amount, immediately following a bank-account change on this vendor's file. Risk score 0.780.",
  "priority": "high",
  "data": { "type": "fraud_cases", "id": 55214 },
  "source": { "agent_code": "FRAUD_DETECTION", "task_id": 771005, "correlation_ref": { "type": "fraud_cases", "id": 55214 } }
}
```
Result: `201 Created`, `"audience": {"type":"role","recipient_count":2}` (one Purchasing Manager, one Finance Manager currently hold those roles at company 4821).

**3. `create_approval_request`**, opened because the vendor's Treasury payment-run line for this same relationship needs a tracked, escalating decision beyond the bill-level hold alone — Treasury Manager's own `draft_outgoing_payment` for a separate, still-legitimate GOS Trading invoice is paused pending the fraud finding's resolution, and that pause deserves the same SLA-timed record a bank transfer or payroll release would get, not merely an informal hold:
```json
{
  "permission_key": "bank.transfer",
  "resource_type": "bank_transactions",
  "resource_id": null,
  "amount": "837.480", "currency_code": "KWD",
  "summary": "Hold GOS Trading's pending payment run pending fraud case #55214 resolution",
  "payload": { "action": "hold_payment_run_line", "vendor_id": 1401, "fraud_case_id": 55214 },
  "risk_level": "elevated",
  "decision_id": 662431,
  "requested_approver_role": "Finance Manager"
}
```
Result: `202 Accepted`, `id: "apr_01J8K3M2XQRT"`, `status: "pending"`, `current_step: 1`; the Finance Manager's step-1 `send_notification` fires automatically, inside the same transaction, per § **Approval Requests** — a second, distinct notification from the `notify_role` call in step 2, because step 2 announced the *finding* and step 3 announces a *specific decision now awaiting that same person's action*.

**4. Human step** (owned by `docs/ai/agents/FRAUD_AGENT.md`'s own narrative): the Finance Manager reviews both signals, places an out-of-band callback to the vendor's on-file phone number, and confirms the bank change was legitimate and `BILL-2026-004118` was an accidental resubmission. She calls `fraud.case.hold.release` and `fraud.case.resolve` (`resolution = 'confirmed_error'`) through Fraud Detection's own endpoints, then resolves the approval request this catalog opened:
```
PATCH /api/v1/auth/approvals/apr_01J8K3M2XQRT  { "decision": "rejected", "reason": "Bank change confirmed legitimate via callback; duplicate was an accidental resubmission. Releasing hold through the ordinary fraud-case workflow instead of a payment hold." }
```

**5. `get_approval_status`**, called by the Approval Assistant's own SLA-tracking loop before it would otherwise have escalated at the 4-business-hour mark, observes `status: "rejected"`, `resolved_at` populated, and takes no further escalation action — the loop's own read confirms the situation resolved inside the SLA window rather than assuming so from the absence of a new event.

# Edge Cases

| Case | Handling |
|---|---|
| Recipient (`user_id`) has no verified email/phone on file for the requested channel | `422 VALIDATION_ERROR` naming the missing contact field; `send_notification` (in-app) always succeeds regardless, since it needs no external contact detail — an agent should fall back to it rather than treating the missing channel as a reason to send nothing |
| `send_whatsapp`'s `template_name` is submitted for Meta review but not yet approved | `422 TEMPLATE_NOT_APPROVED`; the calling agent falls back to `send_sms` for time-sensitive content rather than waiting on an approval timeline outside QAYD's control |
| Recipient has opted every channel out for a given `event_code`, and the event is not security-critical | The call succeeds; every recipient row is created with `delivery_status = 'suppressed'`; a company-configured `notification_preferences` opt-out is never silently overridden by an agent re-trying a different tool to reach the same person for the same event |
| A second fraud signal for the same `fraud_cases` row crosses threshold again within the `dedup_key` cooldown window | The second `notify_role`/`send_notification` call returns `201` with `delivery_status='suppressed'` for the already-notified recipients; the underlying `fraud_signals` row is still written in full — suppression applies to the *notification*, never to the *evidence* |
| `create_approval_request` is called for a `permission_key` this company has not configured any `approval_chains` for | `404 NO_ACTIVE_APPROVAL_CHAIN` — per `docs/api/AUTHORIZATION_API.md`'s own workflow, the correct next step is allowing the underlying action through its own ordinary `auto`/`suggest_only` path, never inventing an approver |
| An agent attempts `send_email`/`send_whatsapp`/`send_sms` to `recipient.external` with no `decision_id` at all | Rejected at schema/endpoint level, `422 EXTERNAL_SEND_REQUIRES_APPROVED_DRAFT`, before any provider is contacted — this is the single most load-bearing rejection in this catalog and has no override, company-configurable or otherwise |
| A provider (Mailgun/SES/Twilio/WhatsApp Business) reports a bounce, complaint, or failure three retries after acceptance | `notifications.delivery_status = 'failed'`, `data.bounce_reason` populated, `ai_logs` records the outcome; the originating task discloses the failed delivery to a human rather than treating "the API call was accepted" as equivalent to "the message arrived" |
| A `broadcast_alert` is attempted a fourth time in the same company within 24 hours | `429 QUOTA_EXCEEDED`; the finding is still recorded and still reaches its owning role via `notify_role`, which carries no comparable cap — the cap protects against company-wide interruption specifically, never against the finding going unnotified |
| An `approval_requests` row's `sla_due_at` passes with the configured fallback approver also unresponsive | Escalates again per the chain's next configured step, or to the Owner as the ultimate fallback role every `approval_chains` configuration guarantees — a request is never left indefinitely `pending` with no further escalation path |
| A sealed `fraud_cases` finding (a named employee, per `docs/ai/agents/FRAUD_AGENT.md` guardrail 3) needs to reach HR Manager and CFO | `notify_role` is not used, since its audience resolution has no sealed-visibility exception; `send_notification` addressed to those specific, individually-resolved `user_id`s is the only correct tool, per § **Safety & Guardrails** |
| A company has disabled a channel entirely (e.g., no Twilio account configured, no SMS budget) | The tool call still succeeds at the logical layer with `delivery_status` immediately `'failed'`, `data.failure_reason = 'channel_not_configured'`, rather than the endpoint being unavailable — an agent's schema-level tool list is unaffected by a company's own provider configuration, so the model always gets a clear, structured answer instead of a confusing 404 |
| The matched `notification_templates` row has no Arabic (`body_ar`) variant and the recipient's locale is Arabic | The English (`body_en`) variant is sent with `data.locale_fallback = true`; this is disclosed in the response `meta`, never silently substituted without a trace, so a company can find and fill the specific template gap |
| Two agents independently call `create_approval_request` for what is, from a human's perspective, the same underlying decision (e.g., Fraud Detection and Compliance Agent both flag the same transaction) | `docs/ai/agents/FRAUD_AGENT.md`'s own `correlation_ref` de-duplication key is checked before creating a second request for the same `resource_type`+`resource_id`; a second attempt within an open request's lifetime returns `409 DUPLICATE_ENTRY` with the existing request's id, so a human reviews one consolidated gate, never two competing ones for the identical fact |

# End of Document





