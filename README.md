# ElderShield 🛡️

> AI-Powered Scam Detection & Awareness Platform

![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?logo=mysql&logoColor=white)
![Ollama](https://img.shields.io/badge/AI-Ollama%20Local-black)
![License](https://img.shields.io/badge/License-MIT-green)
![Status](https://img.shields.io/badge/Status-Prototype-orange)

ElderShield protects elderly users from scams — phishing, impersonation, tech support fraud, romance scams, and more. Seniors submit suspicious messages or screenshots, a local AI model analyzes the content, and caregivers are automatically alerted to high-risk reports.

---

## Table of Contents

- [Demo](#demo)
- [Project Overview](#project-overview)
- [Goals](#goals)
- [Local Setup](#local-setup)
- [AI Component](#ai-component-key-differentiator)
- [Database Schema](#database-schema)
- [CRUD Functionality](#crud-functionality)
- [Subscription & Billing](#subscription--billing)
- [Dashboard Analytics](#dashboard-analytics)
- [Notification System](#notification-system)
- [Cybersecurity & Privacy](#cybersecurity--privacy)
- [Technology Stack](#technology-stack)
- [Author](#author)
- [License](#license)

---

## Demo

[![▶ Watch the Demo](https://img.shields.io/badge/YouTube-Watch%20Demo-red?logo=youtube)](https://youtu.be/zMp5k4DBVxo)

---

## Project Overview

ElderShield uses a three-role system with a role-aware interface for each user type.

### Elder Interface
A simplified, accessibility-first interface designed for seniors.

- Submit suspicious messages, calls, or emails for AI analysis (text + optional screenshot)
- Receive a scam likelihood score (0–100%) with a plain-language explanation
- View detected scam type and manipulation tactics
- Get clear "What to do next" guidance written for a non-technical audience
- Review, edit, and delete previously submitted reports

### Caregiver Dashboard
A monitoring interface for family members and professional caregivers.

- Monitor incidents submitted by linked elders
- Receive automatic notifications for medium (≥40%) and high-risk (≥70%) reports
- View 7-day incident analytics: daily volume chart + top scam categories this week
- Manage caregiver–elder relationships (link requests, approvals, revocations)
- Free plan: up to 2 linked elders — Premium plan: unlimited

### Admin Dashboard
A full management console for platform administration.

- Manage all users, roles, and account status
- Broadcast notifications to all users or send targeted messages to one user
- Manually edit or re-run AI analysis on any incident
- Manage caregiver subscription plans (upgrade, downgrade, pause)
- View 30-day incident analytics: volume chart, risk distribution donut, scam category breakdown, month-over-month comparison

---

## Goals
- Reduce scam victimization among elderly populations
- Provide easy-to-understand scam explanations written for seniors
- Enable caregivers to intervene before financial or emotional harm occurs
- Maintain strong privacy and cybersecurity practices through secure system design
- Run fully offline — no cloud AI dependency, no user data sent to third parties

---

## Local Setup

### Prerequisites
- PHP 8.1+
- MySQL 5.7.8+ or MariaDB 10.2.7+ (required for JSON column type)
- Apache via MAMP (Mac) or WAMP (Windows)
- PHP extensions: `pdo_mysql` and `curl`
- [Ollama](https://ollama.ai) — optional, required only for AI scam analysis

### Step 1 — Place the project files
**MAMP (Mac):** Copy the project into `/Applications/MAMP/htdocs/eldershield/`  
**WAMP (Windows):** Copy the project into `C:\wamp64\www\eldershield\`

### Step 2 — Configure APP_URL
Open `src/config/config.php` and uncomment the line that matches your setup:
```php
// define('APP_URL', 'http://localhost/eldershield/src');      // WAMP or MAMP Pro
// define('APP_URL', 'http://localhost:8888/eldershield/src'); // MAMP Standard
```

### Step 3 — Configure the database port
Open `src/config/db.php` and set `DB_PORT` to match your environment:
- WAMP or MAMP Pro → `3306`
- MAMP Standard → `8889`

### Step 4 — Import the schema
In phpMyAdmin, create a database named `eldershield`, then import `src/database/eldershield.sql`. This creates all tables and three starter accounts ready for login.

### Step 5 — Enable PHP extensions (WAMP only)
Left-click the WAMP tray icon → PHP → PHP Extensions → enable `php_pdo_mysql` and `php_curl`. WAMP will restart Apache automatically.

> **WAMP users — security headers:** The app's security headers (CSP, X-Frame-Options, etc.) require `mod_headers` to be active. Left-click the WAMP tray icon → Apache → Apache Modules → enable `headers_module`. Without this the app still runs normally, but the security headers will not be applied.

### Step 6 — Load demo data (optional but recommended)
Visit the seed URL in your browser (adjust port if needed):
```
http://localhost/eldershield/src/database/seed.php
```
This populates 19 users, 30+ realistic scam incidents, caregiver links, and full analytics data. Delete `seed.php` after running it.

### Step 7 — Set up Ollama (optional)
Download from [ollama.ai](https://ollama.ai), then run:
```bash
ollama pull qwen3-vl:8b
```
The app runs without Ollama — submitted incidents will show an analysis error message until it is running.

### Default Login Credentials

| Role | Email | Password |
|---|---|---|
| Admin | admin@eldershield.com | password123 |
| Elder | dorothy@example.com | password123 |
| Caregiver | sarah@example.com | password123 |

---

## AI Component (Key Differentiator)

ElderShield uses **Ollama** running locally to analyze scam reports — no external API keys or internet connection required. The default model is `qwen2.5vl:7b`, a vision-capable model that can analyze both text descriptions and uploaded screenshots.

Analysis runs **asynchronously** in a background CLI process so the elder's page loads immediately while the AI works. Results are saved to the database and the page auto-refreshes when ready.

The AI detects patterns including:
- Urgency and time pressure
- Fear-based language
- Authority impersonation
- Gift card, wire transfer, and prepaid card requests
- Social engineering tactics common in grandparent, romance, and tech support scams

Each incident generates structured output with five fields:

| Field | Description |
|---|---|
| `scam_probability` | Integer 0–100 |
| `scam_category` | `phishing`, `impersonation`, `romance_scam`, `tech_support`, `lottery_prize`, `grandparent_scam`, `investment_fraud`, `other`, or `not_a_scam` |
| `manipulation_tactics` | Array of detected tactic labels (e.g. `urgency`, `fear_based_language`) |
| `explanation_simple` | 2–3 plain sentences written for a senior audience |
| `recommended_action` | 2–3 concrete steps the user should take |

Admins can manually override any field or trigger a fresh AI re-run from the incident detail page.

---

## Database Schema

Built on **MySQL** with 5 tables in a fully relational design.

![ERD Diagram](docs/Latest_ERD.png)

### Tables

| Table | Purpose |
|---|---|
| `users` | All accounts — elders, caregivers, admins. Stores role, plan (free/premium), plan expiry, and active status |
| `incidents` | Scam reports submitted by elders. Links to user, stores content, optional image path, and status |
| `analysis` | One-to-one with incidents. Stores AI output: probability, category, tactics, explanation, recommended action |
| `account_links` | Caregiver–elder relationships. Status: `pending`, `active`, or `revoked` |
| `notifications` | In-app notifications. Supports auto-alerts, admin broadcasts, and targeted single-user messages |

---

## CRUD Functionality

### Users
- **Create** — register as elder or caregiver; admin creates admin accounts
- **Read** — role-aware dashboard, profile page, admin user list
- **Update** — profile info, password (min 7 characters), role changes (admin only), plan changes
- **Delete/Deactivate** — admin can deactivate or permanently delete accounts

### Incidents
- **Create** — elder submits text + optional screenshot
- **Read** — elder sees own history; caregiver sees linked elders'; admin sees all
- **Update** — elder can edit and re-submit; admin can edit analysis fields or reprompt AI
- **Delete** — elder can delete own reports; admin can delete any

### Analysis
- **Create** — generated automatically by Ollama after incident submission
- **Read** — displayed on incident detail page with risk gauge, tactics, explanation
- **Update** — admin manual edit or AI re-run (reprompt); both use upsert
- **Delete** — cleared when incident is deleted (cascade) or admin clears for elder re-submission

### Notifications
- **Create** — auto-generated on medium/high risk; admin broadcast; admin targeted message; billing events
- **Read** — notification inbox (user sees own; admin sees all)
- **Update** — mark read/unread via AJAX; admin edits propagate to all broadcast copies
- **Delete** — user deletes own; admin can delete any or clear all

### Account Links
- **Create** — caregiver sends link request by elder email
- **Read** — admin sees all relationships; caregiver sees own; elder sees linked caregivers on profile
- **Update** — approve (pending → active), revoke (delete), reactivate
- **Delete** — hard delete on revoke (allows re-linking)

---

## Subscription & Billing

| Plan | Price | Elder Link Limit |
|---|---|---|
| Caregiver Free | $0 | 2 |
| Caregiver Premium | $9.99/month | Unlimited |
| Elder | Always free | N/A |

- Invoices generated monthly via `cli/run_billing.php` (designed for a cron job)
- Payment processing simulated at 95% success rate for demo purposes
- Failed payments generate a caregiver notification and can be retried from the billing page
- Admins can upgrade, downgrade, or pause any caregiver's plan from `admin_subscriptions.php`

---

## Dashboard Analytics

### Caregiver (7-Day View)
- High-risk count this week
- Stacked bar chart — daily incidents broken down by risk level (high / medium / low)
- Horizontal bar chart — top scam categories among linked elders this week

### Admin (30-Day View)
- Total users, total incidents, all-time high-risk count
- This-month incident count with % change vs last month
- 30-day stacked bar chart — daily volume across all users
- Risk distribution donut chart — percentage breakdown of all analyzed incidents
- Top scam categories horizontal bar chart for the past 30 days

---

## Notification System

- **Auto-alerts** — triggered when AI analysis returns ≥40% (medium) or ≥70% (high); sent to linked caregivers and all admins
- **Broadcast** — admin sends a message to every active user at once
- **Targeted** — admin sends a private message to one specific user selected from a dropdown
- **Edit propagation** — editing a broadcast notification updates all recipient copies simultaneously
- **Billing notifications** — sent automatically on invoice success or failure
- Mark read/unread via AJAX without page reload

---

## Cybersecurity & Privacy

- All database queries use **PDO prepared statements** — no SQL injection possible
- Passwords hashed with **bcrypt** (cost factor 12); minimum 7 characters enforced at both form and server level
- **CSRF tokens** on every state-changing form
- **Session ID regeneration** on login (prevents session fixation attacks)
- Uploaded images validated by MIME type, `getimagesize()`, and extension whitelist (JPG, PNG, GIF, WEBP)
- PHP execution **blocked** in the `uploads/` directory via `.htaccess`
- All user-generated output escaped with `htmlspecialchars()` via the `e()` helper
- Security headers on every response: `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`
- AI runs **fully locally via Ollama** — no user data is transmitted to any external service

---

## Technology Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.1+ |
| Database | MySQL (PDO) |
| Frontend | HTML5 + CSS3 (custom, no framework) |
| AI / NLP | Ollama (local) — default model: `qwen3-vl:8b` |
| Web Server | Apache via MAMP or XAMPP |
| Security | bcrypt · CSRF tokens · PDO · session hardening · CSP headers |

---

## Author

**Joe Milner** — [github.com/syrm4](https://github.com/syrm4)

Originally built as a team project with Sunny Nguyen and Cameron Hubbard
for IS 6465 at the University of Utah's David Eccles School of Business.

---

## License

MIT License

Copyright (c) 2025 Joe Milner

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
