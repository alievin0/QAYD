# SYSTEM_ARCHITECTURE.md

Version: 1.0
Status: Core Architecture
Priority: Critical

---

# Overview

Qayd is an AI Financial Operating System.

The platform is composed of multiple independent systems that work together through secure APIs.

Each system has one responsibility.

No system should become responsible for everything.

---

# High Level Architecture

                    User
                      │
                      ▼
          Web / Mobile Applications
                      │
                      ▼
                Backend API
                      │
      ┌───────────────┼───────────────┐
      ▼               ▼               ▼
 Authentication   Business Logic     AI Engine
      │               │               │
      └───────────────┼───────────────┘
                      ▼
                 PostgreSQL
                      │
         ┌────────────┼────────────┐
         ▼            ▼            ▼
      Redis      Object Storage   Search

---

# Main Components

The platform consists of:

1. Web Application

2. Mobile Application

3. Backend API

4. AI Engine

5. Database

6. Storage

7. Notification System

8. Integration Engine

---

# Web Application

Technology

Next.js

Responsibilities

- Dashboard
- Accounting
- Reports
- Settings
- Company Management
- AI Chat
- Administration

---

# Mobile Application

Technology

Flutter

Responsibilities

- Dashboard
- Notifications
- Approvals
- Reports
- AI Assistant

---

# Backend API

Technology

Laravel

Responsibilities

- Authentication
- Authorization
- Business Rules
- Financial Logic
- APIs
- Queue
- Notifications

Backend is the single source of truth.

---

# AI Engine

Technology

FastAPI + Python

Responsibilities

- AI Agents
- OCR
- Financial Analysis
- AI Chat
- Report Generation
- Workflow Automation

The AI Engine NEVER edits the database directly.

All operations pass through Backend API.

---

# Database

Technology

PostgreSQL

Stores

- Companies
- Users
- Accounting
- Inventory
- Payroll
- Reports
- Logs

---

# Cache

Redis

Used for

- Sessions
- Cache
- Queues
- AI Memory Cache

---

# Object Storage

Cloudflare R2

Stores

- PDFs
- Invoices
- Images
- Attachments
- Reports

---

# Notification Engine

Supports

- Email

- SMS

- WhatsApp

- Push Notifications

- In-App Notifications

---

# Integration Engine

Responsible for connecting:

Banks

Payment Gateways

Government APIs

ERP Systems

POS Systems

CRM Systems

Email Providers

Storage Providers

Every integration passes through this layer.

---

# Security Layer

Handles

Authentication

Authorization

Encryption

Audit Logs

Rate Limiting

2FA

Permissions

Secrets

---

# Company Isolation

Every company is completely isolated.

Company A cannot access Company B.

Even AI agents only receive data from the active company.

---

# Permission Layer

Every request checks

User

↓

Company

↓

Role

↓

Permission

↓

Branch

↓

Department

↓

Requested Resource

If any validation fails

Access is denied.

---

# AI Workflow

User

↓

Backend

↓

AI Engine

↓

Reasoning

↓

Response

↓

Backend Validation

↓

Database Update (if approved)

AI never bypasses backend validation.

---

# Long-Term Vision

Qayd will evolve into

The Operating System of Every Business.

Accounting will become only one module inside a larger intelligent business platform.

---

End of Document
