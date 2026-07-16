# COMPANY_STRUCTURE.md

Version: 1.0
Status: Foundation
Priority: Critical

---

# Purpose

This document defines how companies are represented inside Qayd.

Every financial operation belongs to a company.

Every employee belongs to a company.

Every AI agent works inside the active company.

Companies are completely isolated from each other.

---

# Company Philosophy

Qayd is built for:

• Freelancers

• Small Businesses

• Medium Businesses

• Large Enterprises

• Holding Companies

The same architecture supports all of them.

---

# Company Creation

When a new user signs up they may:

1. Create a new company

or

2. Join an existing company

---

# Company Information

Required Fields

Company Name

Legal Name

Country

City

Currency

Language

Timezone

Fiscal Year

Tax System

Industry

Company Size

Logo

Business Registration Number

Tax Registration Number

---

# Company Types

Supported Types

Freelancer

Startup

SME

Enterprise

Holding Company

Government

Non-Profit

Educational

Healthcare

Retail

Manufacturing

Construction

Hospitality

Custom

---

# Company Hierarchy

Holding Company

↓

Subsidiary

↓

Branch

↓

Department

↓

Employees

---

Example

ABC Holding

│

├── ABC Kuwait

│      ├── Finance

│      ├── HR

│      ├── Sales

│

├── ABC Saudi

│      ├── Finance

│      ├── Warehouse

│

└── ABC UAE

---

# Branches

Every company may have unlimited branches.

Example

Kuwait City

Hawally

Dubai

Riyadh

Jeddah

London

Each branch may have:

Own employees

Own warehouse

Own inventory

Own customers

Own reports

Own permissions

---

# Departments

Finance

Accounting

Sales

Purchasing

Inventory

Warehouse

Marketing

HR

Legal

Operations

Production

IT

Customer Support

Administration

Custom Departments

---

# Employees

Every employee belongs to:

Company

↓

Branch

↓

Department

↓

Role

↓

Permissions

↓

Manager

↓

Employment Status

---

# Employee Status

Active

Inactive

Vacation

Suspended

Terminated

Contractor

Intern

---

# Multiple Companies

One user account

↓

May belong to multiple companies.

Example

Ali

↓

ABC Company

Role

Accountant

↓

XYZ Company

Role

Finance Manager

↓

123 Company

Role

Owner

Switching companies immediately changes

Permissions

Dashboard

Reports

AI Memory

Financial Data

---

# Invitations

Company Owner

↓

Invite User

↓

Email Invitation

↓

User Accepts

↓

Assign

Branch

Department

Role

Permissions

↓

Access Granted

---

# Company Assets

Every company manages:

Customers

Vendors

Products

Warehouses

Bank Accounts

Employees

Invoices

Journal Entries

Tax Reports

Payroll

Documents

AI Memory

Reports

---

# AI Company Memory

Every company has isolated AI memory.

The AI remembers

Company Policies

Chart of Accounts

Frequently Used Accounts

Preferred Accounting Style

Approval Workflow

Business Terminology

Frequently Asked Questions

The AI never shares memory between companies.

---

# Company Settings

General

Accounting

Tax

Currency

Language

Numbering

Approval Workflow

Security

Integrations

Notifications

AI Preferences

---

# Business Lifecycle

Create Company

↓

Configure Settings

↓

Create Branches

↓

Create Departments

↓

Invite Employees

↓

Import Data

↓

Connect Bank

↓

Start Operations

↓

AI Learns Continuously

---

# Future Vision

Large enterprises may contain

Hundreds of branches

Thousands of employees

Millions of transactions

Without changing the architecture.

---

# Golden Rules

Everything belongs to a company.

Companies are completely isolated.

Every employee has a role.

Every role has permissions.

Every action is audited.

AI memory is company-specific.

The architecture must support global expansion.

---

End of Document
