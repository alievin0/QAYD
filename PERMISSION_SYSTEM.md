# PERMISSION_SYSTEM.md

Version: 1.0
Status: Foundation
Priority: Critical

---

# Purpose

The Permission System controls every action inside Qayd.

Every request made by a user or AI agent must pass through this system.

No exceptions.

---

# Security Philosophy

Nobody has unlimited access.

Permissions are granted.

Never assumed.

The principle of Least Privilege is always applied.

---

# Permission Flow

Every request follows this order:

User

↓

Company

↓

Branch

↓

Department

↓

Role

↓

Custom Permissions

↓

Requested Resource

↓

Allow / Deny

---

# Access Levels

Level 1

Platform

(OpenAI Internal)

Only Qayd Administrators

---

Level 2

Company

Company Owner

---

Level 3

Branch

Example

Kuwait Branch

Saudi Branch

Dubai Branch

---

Level 4

Department

Finance

HR

Sales

Purchasing

Warehouse

Marketing

IT

Production

---

Level 5

User

Personal Permissions

---

# Roles

Default Roles

Owner

CEO

CFO

Finance Manager

Senior Accountant

Accountant

Auditor

HR Manager

Payroll Officer

Inventory Manager

Warehouse Employee

Sales Manager

Sales Employee

Purchasing Manager

Purchasing Employee

Read Only

External Auditor

Custom Role

---

# Permission Categories

Accounting

Banking

Payroll

Inventory

Purchasing

Sales

Tax

Reports

Settings

Administration

AI

Integrations

Documents

Users

Companies

Branches

Departments

---

# Example Permissions

Accounting

accounting.read

accounting.create

accounting.update

accounting.delete

accounting.export

---

Bank

bank.read

bank.reconcile

bank.transfer

bank.create

bank.delete

---

Payroll

payroll.read

payroll.calculate

payroll.approve

payroll.export

---

Inventory

inventory.read

inventory.adjust

inventory.transfer

inventory.delete

---

Reports

reports.read

reports.export

reports.share

---

AI

ai.chat

ai.generate

ai.analyze

ai.approve

ai.automation

---

# Permission Resolution

Example

Ali

↓

Company ABC

↓

Finance Department

↓

Senior Accountant

↓

Extra Permission

reports.export

Ali receives

Role Permissions

+

Custom Permissions

---

# Multiple Companies

A user may belong to multiple companies.

Example

Ali

Company A

Role

Accountant

Company B

Role

CFO

Permissions change automatically after switching companies.

---

# Branch Isolation

A Kuwait accountant

cannot see

Saudi branch

unless explicitly allowed.

---

# Department Isolation

HR

cannot access

Accounting

unless granted.

---

# AI Permissions

AI Agents NEVER bypass permissions.

If a user cannot access payroll,

AI cannot reveal payroll.

AI only receives data the current user is allowed to access.

---

# Temporary Permissions

Permissions may expire.

Example

External Auditor

Valid

01 Jan

↓

31 Jan

After expiration

Access automatically removed.

---

# Approval Chains

Some actions require approval.

Example

Delete Journal Entry

↓

Finance Manager

↓

CFO

↓

Completed

---

Example

Bank Transfer

↓

Finance Manager

↓

CEO

↓

Completed

---

# Sensitive Operations

Always require approval.

Examples

Delete Company

Delete Financial Records

Payroll Release

Bank Transfers

Tax Submission

User Management

Permission Changes

---

# Audit Trail

Every permission check is logged.

Stored Information

User

Company

IP Address

Device

Permission

Time

Result

---

# Emergency Lock

Company Owner may immediately lock

User

Branch

Department

Entire Company

---

# API Permissions

API Keys also have permissions.

Example

ERP Integration

Only

Invoices

Customers

Products

No Payroll Access.

---

# Default Rule

Everything is denied.

Permissions must be explicitly granted.

---

# Golden Rules

Never trust the client.

Always verify on the server.

Never bypass permissions.

AI follows the same rules as humans.

Every request is logged.

Everything is auditable.

---

# Long-Term Vision

Permission Studio

A visual interface where administrators can build custom roles by enabling or disabling hundreds of permissions without writing code.

---

End of Document
