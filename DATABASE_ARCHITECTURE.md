# DATABASE_ARCHITECTURE.md

Version: 1.0
Status: Foundation
Priority: Critical

---

# Purpose

The database is the single source of truth.

Every business action inside Qayd must be stored, audited and related to a company.

---

# Database Philosophy

Qayd uses:

PostgreSQL

The database must support:

- Multi Company
- Multi Branch
- Multi Currency
- Multi Language
- AI Ready
- Enterprise Scale

---

# Core Entities

User

Company

Branch

Department

Role

Permission

Employee

Customer

Vendor

Product

Warehouse

Inventory

Invoice

Journal Entry

Bank Account

Payment

Payroll

Tax

AI Conversation

Notification

Audit Log

Attachment

---

# Multi-Tenant Model

Every record belongs to a company.

Example

Users

↓

Company

↓

Invoices

↓

Journal Entries

↓

Reports

A user may belong to multiple companies.

Each company is completely isolated.

---

# Core Relationships

User

↓

CompanyUser

↓

Company

↓

Branch

↓

Department

↓

Role

↓

Permissions

---

# Company Structure

Company

↓

Branches

↓

Departments

↓

Employees

↓

Financial Data

Nothing exists without a Company.

---

# Main Tables

users

companies

company_users

branches

departments

roles

permissions

customers

vendors

products

warehouses

inventory

bank_accounts

journal_entries

journal_lines

invoices

invoice_items

payments

payroll

tax_reports

notifications

audit_logs

attachments

ai_conversations

ai_messages

---

# AI Tables

ai_agents

ai_tasks

ai_memory

ai_logs

ai_decisions

These tables are separated from accounting data.

---

# Attachments

Every document is linked.

Examples

Invoice

↓

PDF

↓

OCR Result

↓

AI Analysis

↓

Journal Entry

---

# Audit System

Every modification creates an audit log.

Stores

Who

When

Old Value

New Value

Reason

IP Address

Device

---

# Soft Delete

Financial records are never permanently deleted.

Deleted records remain recoverable.

---

# Database Principles

One source of truth.

No duplicated data.

Everything belongs to a company.

Everything is auditable.

Everything is scalable.

Database integrity comes before convenience.

---

End of Document
