# CODING_STANDARDS.md

Version: 1.0
Status: Foundation
Priority: Critical

---

# Purpose

This document defines how code is written inside Qayd.

Every developer.

Every AI assistant.

Every contributor.

Must follow these standards.

---

# General Principles

Readable Code

Maintainable Code

Testable Code

Reusable Code

Secure Code

---

# Architecture

Clean Architecture

SOLID Principles

Repository Pattern

Service Layer

Dependency Injection

No Business Logic inside Controllers.

---

# Naming

Use meaningful names.

Bad

data

Good

invoiceRepository

Bad

a

Good

companyUser

---

# Folder Structure

Controllers

Services

Repositories

Models

Policies

Requests

Events

Listeners

Jobs

Notifications

Resources

Tests

---

# Functions

Single Responsibility.

Maximum

50 Lines Preferred.

One purpose only.

---

# Classes

One responsibility.

Small.

Focused.

---

# Comments

Explain WHY.

Not WHAT.

Avoid unnecessary comments.

---

# Error Handling

Never ignore exceptions.

Always log.

Return meaningful errors.

---

# Logging

System Logs

Audit Logs

Security Logs

AI Logs

Performance Logs

---

# Database

Never use raw SQL unless necessary.

Use Transactions.

Use Indexes.

Never duplicate data.

---

# Security

Validate Inputs.

Escape Outputs.

Use Prepared Statements.

Never expose secrets.

Never trust client input.

---

# Testing

Unit Tests

Integration Tests

Feature Tests

API Tests

UI Tests

---

# Git

Small commits.

Meaningful messages.

Example

Add AI approval workflow

Fix payroll validation

Improve journal entry performance

---

# Pull Requests

Every PR requires

Review

Tests

Documentation Update

---

# API

REST Standards

Versioning

Validation

Documentation

---

# Performance

Avoid N+1 Queries.

Use Cache.

Queue Heavy Jobs.

Lazy Loading when appropriate.

---

# AI Code

Never allow AI to bypass business rules.

AI must use official APIs.

AI cannot write directly to database.

---

# Documentation

Every new feature requires

Documentation

Tests

Architecture Notes

---

# Code Review Checklist

Readable

Secure

Tested

Documented

Performant

No duplicated logic

---

# Golden Rules

Write for humans.

Optimize later.

Never sacrifice security.

Never duplicate business logic.

Keep everything modular.

---

End of Document
