# CRUD Reference

Full create, read, update, and delete operations across all five ElderShield entities.

---

## Users
- **Create** — register as elder or caregiver; admin creates admin accounts
- **Read** — role-aware dashboard, profile page, admin user list
- **Update** — profile info, password (min 7 characters), role changes (admin only), plan changes
- **Delete/Deactivate** — admin can deactivate or permanently delete accounts

## Incidents
- **Create** — elder submits text + optional screenshot
- **Read** — elder sees own history; caregiver sees linked elders'; admin sees all
- **Update** — elder can edit and re-submit; admin can edit analysis fields or reprompt AI
- **Delete** — elder can delete own reports; admin can delete any

## Analysis
- **Create** — generated automatically by Ollama after incident submission
- **Read** — displayed on incident detail page with risk gauge, tactics, explanation
- **Update** — admin manual edit or AI re-run (reprompt); both use upsert
- **Delete** — cleared when incident is deleted (cascade) or admin clears for elder re-submission

## Notifications
- **Create** — auto-generated on medium/high risk; admin broadcast; admin targeted message; billing events
- **Read** — notification inbox (user sees own; admin sees all)
- **Update** — mark read/unread via AJAX; admin edits propagate to all broadcast copies
- **Delete** — user deletes own; admin can delete any or clear all

## Account Links
- **Create** — caregiver sends link request by elder email
- **Read** — admin sees all relationships; caregiver sees own; elder sees linked caregivers on profile
- **Update** — approve (pending → active), revoke (delete), reactivate
- **Delete** — hard delete on revoke (allows re-linking)
