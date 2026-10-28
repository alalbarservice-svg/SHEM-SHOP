# SHEM single invoice path (28.10.2026, staging only)

What changed: SHEM invoices only through SHEM DOK. Auto invoice after a card or PayPal purchase is unchanged. Manual payments get no auto invoice; the admin opens the DOK editor (pre-filled from the order) and issues the PDF with the fields of the chosen method (cash, transfer, card, PayPal, other).
Closed on SHEM only: legacy invoice settings (PUT 410), template preview (410), legacy document creation on payment, legacy issue-document. SHIMI untouched.
UI wording changes (need owner approval, before/after screenshots exist): invoice settings block replaced by a "חשבוניות" panel with a link to SHEM DOK; issue button reads "הפקת חשבונית ב-SHEM DOK".
Tests: see docs/project-state.md. Not run: HawkScan, real 3DS, any live deploy.
Not deleted (needs owner approval): wp_scc_invoice_documents legacy rows and the legacy `invoice` setting row on staging.
