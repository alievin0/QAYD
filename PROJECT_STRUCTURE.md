# PROJECT_STRUCTURE.md

Version: 1.0
Status: Foundation
Priority: Critical

---

# Purpose

This document defines the official folder structure of Qayd.

Every developer.

Every AI assistant.

Every automation.

Must follow this structure.

No exceptions.

---

# Repository Structure

QAYD/

README.md

docs/

frontend/

backend/

mobile/

ai/

database/

infrastructure/

scripts/

.github/

---

# Documentation

docs/

01_Foundation/

02_Architecture/

03_Product/

04_Database/

05_AI/

06_API/

07_Security/

08_UI_UX/

09_Modules/

10_Research/

11_Roadmap/

12_Testing/

13_Deployment/

---

# Frontend

frontend/

app/

components/

layouts/

hooks/

services/

stores/

types/

utils/

styles/

public/

tests/

---

# Frontend Components

components/

ui/

dashboard/

accounting/

banking/

inventory/

payroll/

sales/

reports/

settings/

ai/

shared/

---

# Backend

backend/

app/

bootstrap/

config/

database/

routes/

storage/

tests/

artisan

composer.json

---

# Backend Structure

app/

Http/

Controllers/

Requests/

Middleware/

Services/

Repositories/

Models/

Policies/

Events/

Listeners/

Jobs/

Notifications/

Mail/

Console/

Providers/

Enums/

Traits/

Helpers/

Exceptions/

Observers/

Resources/

---

# Database

database/

migrations/

seeders/

factories/

schemas/

erd/

backup/

---

# AI

ai/

agents/

memory/

workflows/

prompts/

rag/

tools/

ocr/

models/

reasoning/

planning/

forecasting/

security/

tests/

---

# AI Agents

agents/

general_accountant/

auditor/

tax/

payroll/

inventory/

treasury/

fraud/

reporting/

cfo/

ceo/

assistant/

---

# Mobile

mobile/

lib/

assets/

widgets/

screens/

services/

providers/

models/

utils/

themes/

tests/

---

# Infrastructure

infrastructure/

docker/

terraform/

nginx/

monitoring/

deployment/

cloud/

---

# API

backend/routes/

api.php

web.php

channels.php

console.php

health.php

---

# Assets

assets/

logos/

icons/

illustrations/

animations/

fonts/

images/

---

# Uploads

storage/

documents/

invoices/

receipts/

contracts/

reports/

exports/

imports/

temp/

---

# Testing

tests/

unit/

feature/

integration/

performance/

security/

ai/

api/

ui/

---

# Logs

logs/

application/

security/

audit/

ai/

performance/

integration/

---

# Config

config/

ai.php

accounting.php

banking.php

inventory.php

payroll.php

security.php

permissions.php

notifications.php

storage.php

integrations.php

---

# Integrations

integrations/

banks/

erp/

crm/

government/

payment/

email/

storage/

communication/

---

# Marketplace

marketplace/

plugins/

themes/

extensions/

sdk/

---

# Future Modules

modules/

accounting/

banking/

inventory/

sales/

purchasing/

payroll/

crm/

hr/

reports/

analytics/

forecasting/

documents/

ai/

compliance/

risk/

treasury/

budgeting/

fixed_assets/

manufacturing/

projects/

approvals/

---

# Naming Rules

Folders

lowercase

Words separated by "_"

Files

PascalCase for classes

camelCase for variables

snake_case for database

kebab-case for URLs

---

# File Ownership

Every feature owns

Controller

Service

Repository

Policy

Request

Tests

Documentation

No feature is complete without all of them.

---

# Documentation Rule

Every module must contain

README.md

Architecture.md

API.md

Database.md

AI.md

Testing.md

---

# Project Principles

Everything modular.

Everything documented.

Everything testable.

Everything scalable.

Everything secure.

Everything versioned.

---

# Long-Term Vision

The project should support

Millions of users.

Millions of companies.

Billions of accounting records.

Hundreds of AI agents.

Without changing the folder structure.

---

# Golden Rules

Never place files randomly.

Every module owns its logic.

Every feature owns its documentation.

Every service has tests.

Every API is documented.

Everything is scalable.

Everything is replaceable.

---

End of Document
