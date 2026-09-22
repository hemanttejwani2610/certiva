# Certiva

**Student & Event Certificates for WordPress.**

Certiva lets you register students for exams, seminars, and conferences, design certificate templates visually, control exactly who is eligible for a certificate, and let recipients securely request and download their certificates by email — without ever exposing a private PDF at a guessable URL.

- Plugin slug / text domain: `certiva`
- WordPress identifiers, database tables, functions, and hooks are all prefixed `certiva_` to avoid collisions with other plugins.
- Requires PHP 8.0+ and WordPress 6.0+.

---

## Contents

- [Installation](#installation)
- [Data model & architecture](#data-model--architecture)
- [Admin: creating events, templates, and registrations](#admin-creating-events-templates-and-registrations)
  - [Bulk-importing students from a CSV](#bulk-importing-students-from-a-csv)
- [Eligibility model](#eligibility-model)
- [Certificate lifecycle: generate, regenerate, resend](#certificate-lifecycle-generate-regenerate-resend)
- [Shortcode: `[certiva_certificate_request]`](#shortcode-certiva_certificate_request)
- [Security design](#security-design)
- [Settings](#settings)
- [PDF generation: library choice & fonts](#pdf-generation-library-choice--fonts)
- [Uninstalling](#uninstalling)
- [Running the tests](#running-the-tests)
- [Manual end-to-end test walkthrough](#manual-end-to-end-test-walkthrough)
- [Translations](#translations)

---

## Installation

1. Copy the `certiva` folder into `wp-content/plugins/`.
2. This repository ships its `vendor/` directory (mPDF and its dependencies) already installed, so no build step is required to activate the plugin. If you want to rebuild it yourself:
   ```bash
   cd wp-content/plugins/certiva
   composer install --no-dev -o
   ```
3. Activate **Certiva** from the WordPress admin Plugins screen. Activation creates Certiva's database tables and a private, protected storage directory for generated PDFs (`wp-content/uploads/certiva-private/`).
4. A new **Certiva** menu appears in the admin sidebar with Registrations, Students, Events, Templates, and Settings screens.

---

## Data model & architecture

Certiva keeps each concern in its own layer:

| Layer | Location | Responsibility |
|---|---|---|
| Data | `includes/Data/` | Schema (dbDelta) and repositories for registrations, the student/email index, and download tokens |
| Post types | `includes/PostTypes/` | `certiva_student`, `certiva_event`, `certiva_template` |
| Admin UI | `includes/Admin/`, `templates/admin/` | Menus, the Registrations screen, settings, AJAX actions |
| PDF generation | `includes/Pdf/` | Field schema, mPDF rendering, certificate lifecycle logic |
| Email | `includes/Email/`, `templates/emails/` | Sending certificate-availability emails |
| Shortcode | `includes/Shortcode/` | `[certiva_certificate_request]` public form |
| Public flow | `includes/Public/` | Request handling and secure download streaming |
| Security | `includes/Security/` | Rate limiting and bot-abuse safeguards |

**Custom post types**

- **`certiva_student`** — full name (post title), email, optional student ID, and an open-ended list of extra key/value placeholder fields (e.g. "Course", "Grade") for use on certificates.
- **`certiva_event`** — title, type (`exam` / `seminar` / `conference`), date, optional location, a default certificate template, and a certificate-availability toggle.
- **`certiva_template`** — a background image, page size/orientation, and a JSON-encoded list of positioned, styled text fields (student name, event title, event date, certificate ID, issue date, or custom placeholders/static text), edited visually in the admin.

**Custom database tables** (relational data doesn't fit naturally as post meta):

- **`wp_certiva_registrations`** — links one student to one event, with an optional per-registration template override, an `eligible` flag, certificate status, the immutable `certificate_id`, the private PDF path, and issue/regeneration timestamps. A **unique key on `(student_id, event_id)`** prevents duplicate registrations at the database layer, not just in application code.
- **`wp_certiva_student_emails`** — a denormalized `student_id → normalized email` index, kept in sync whenever a student is saved/deleted, so the public request form can look up a student by email with a single indexed query instead of scanning `wp_postmeta`.
- **`wp_certiva_download_tokens`** — download tokens, stored as a **SHA-256 hash only** (never the raw token), scoped to one registration, with an expiry.

---

## Admin: creating events, templates, and registrations

1. **Certiva → Events → Add New**: set the title, type, date, optional location, a default certificate template, and whether certificates are enabled for this event yet.
2. **Certiva → Templates → Add New**: upload a background image, choose a page size/orientation, then use the visual designer to drag the built-in placeholder fields (student name, event title, event date, certificate ID, issue date) onto the certificate, styling each one (font, size, color, alignment, bold/italic). You can also add a custom placeholder tied to a student's extra field, or static text. **Preview with Sample Data** renders a real PDF with placeholder values so you can check the layout before saving.
3. **Certiva → Registrations**: register a student for an event, optionally overriding the event's default template, and optionally marking the registration eligible immediately. The same screen lists every registration with its eligibility, certificate status, and actions (Preview / Generate / Regenerate / Download / Resend / Remove). The same list, scoped to one student, also appears as a meta box on that student's edit screen.

### Bulk-importing students from a CSV

Click **Import CSV** above the Students list (or **Certiva → Import Students**) to add many students at once:

1. **Upload** a `.csv` file (5 MB max) whose first row is a header row.
2. **Map columns** — Certiva guesses Full Name / Email / Student ID from common header names, but you can point any column at Full Name, Email Address, Student ID, or "Extra Placeholder Field" (with your own label), or leave it unmapped. A preview of the first few values from each column is shown to help you check the mapping. Full Name and Email are required.
3. Choose whether a row whose email matches an **existing** student should **update** that student (merging in any mapped extra fields by label, without discarding fields not present in this file) or always create a new one — useful for re-importing an updated roster without creating duplicates.
4. **Import** — you'll get a summary of how many students were created/updated/skipped, with a reason for every skipped row (e.g. missing or invalid email).

Imports are capped at 5,000 rows per file and processed synchronously; split larger rosters into multiple files. The uploaded file is stored in Certiva's private directory only for the few minutes it takes to map and import it, then deleted.

---

## Eligibility model

Certiva deliberately separates *registration* from *eligibility* from *issuance*:

1. **Registering** a student for an event only records attendance/enrollment. It never grants a certificate by itself.
2. **Eligibility** requires two independent gates to both be true:
   - The **event's** "Certificate Availability" is set to *Enabled*.
   - The **individual registration's** "Eligible" checkbox is checked.

   This means registering a student for an exam does **not** imply they passed — an admin must explicitly mark that specific registration eligible (e.g. after grading), even if the event's certificates are already enabled for other students.
3. **Issuance** ("Generate") is blocked with a clear error unless both gates are true. The public download flow re-checks both gates again at download time, so revoking eligibility after a certificate was issued (or after a download link was emailed) immediately stops further downloads.

---

## Certificate lifecycle: generate, regenerate, resend

- **Generate** issues a certificate for the first time: it assigns a permanent `certificate_id`, renders the PDF from the effective template (the registration's override, or else the event's default) and current student/event data, and stores it privately. Calling Generate again on an already-issued certificate is a safe no-op.
- **Regenerate** re-renders the PDF using *current* student/event/template data, but **keeps the same `certificate_id` and the same registration row** — it never creates a duplicate or changes the ID. Editing a student's name, an event's title, or a template's design has **no effect on an already-issued certificate** until an admin explicitly clicks Regenerate.
- **Resend** re-sends the secure download-link email for one certificate to the student's email on file.
- **Preview** renders the PDF from live data on demand (open in a new tab) without requiring eligibility and without saving anything — useful for checking a certificate before deciding to mark it eligible.

---

## Shortcode: `[certiva_certificate_request]`

Add this shortcode to any page or post to show a certificate request form with a single required field: email address.

```
[certiva_certificate_request]
```

The form is responsive, keyboard-accessible, and labeled for screen readers. On submission, Certiva:

1. Validates the nonce, a honeypot field, and a minimum-time-to-submit check.
2. Rate-limits by IP address and by the submitted email address (configurable).
3. Looks up all of that email's eligible, already-issued certificates.
4. If any exist, emails **one message** listing them with an individual secure download link per certificate — **no PDF attachments**.
5. Always shows the identical response:

   > "If certificates are available for this email address, we'll send you a download link."

   This message is shown whether the email doesn't exist, exists with nothing eligible, or exists with certificates that were just emailed — so the form never reveals whether an email is registered or which events a student attended. Suspected bot submissions and rate-limited requests are silently treated the same way (no error shown, no work performed), so probing the form can't distinguish "blocked" from "no match."

---

## Security design

- **Secure download links** encode a registration ID and a cryptographically random, single-registration-scoped token. Only a SHA-256 hash of the token is stored in the database — mirroring how WordPress stores password-reset keys — so a database read alone can never produce a working link. Links expire after a configurable number of hours (default 48); expired or garbage tokens are rejected with a generic "invalid or expired" message, and a fresh request simply issues a new link.
- **Private PDFs**: generated certificates live in `wp-content/uploads/certiva-private/`, under a random filename unrelated to the printed certificate ID, protected by `.htaccess`/`web.config` deny-all rules as defense in depth. They are only ever served by PHP after a token (or an admin's capability + nonce) is verified — never by a direct, predictable URL.
- **Capabilities**: every admin screen and action checks `current_user_can( apply_filters( 'certiva_manage_capability', 'manage_options' ) )`, so you can delegate management to a custom role via the `certiva_manage_capability` filter.
- **Input/output**: all input is sanitized on read (`sanitize_text_field`, `sanitize_email`, `absint`, etc.), all output is escaped at the point of output, and all direct SQL uses `$wpdb->prepare()`.

---

## Settings

**Certiva → Settings** lets you configure:

- Download link expiry (hours).
- Rate limits: max requests per IP and per email address, each with its own time window.
- The outgoing "From" name/address used for certificate emails.
- **Uninstall behavior**: a checkbox, off by default, to permanently delete all Certiva data (students, events, templates, registrations, and certificate files) when the plugin is deleted. Leaving it unchecked means uninstalling the plugin keeps all your data intact.

---

## PDF generation: library choice & fonts

Certiva generates PDFs with **[mPDF](https://mpdf.github.io/)**, installed via Composer (`composer.json` → `mpdf/mpdf`) — no paid service, no external API, no server binary dependency beyond PHP's `mbstring` and `gd` extensions (which mPDF requires and which are near-universal on WordPress hosts).

**Why mPDF over TCPDF/FPDF:** mPDF supports OpenType Layout (OTL) shaping, which is required to correctly render Devanagari (Hindi) conjuncts and glyph reordering. TCPDF and FPDF only place Unicode glyphs one at a time and would produce broken, unshaped Hindi text.

**Fonts** (embedded/subset in every generated PDF, so recipients never need them installed):

- **Noto Sans** (SIL OFL) — the default Latin/Unicode font.
- **Lohit Devanagari** (SIL OFL) — used for Hindi text. This is a deliberate choice over a newer Noto Sans Devanagari build: in testing, mPDF's OTL/GSUB parser could not reliably parse the GSUB/GPOS tables in current Noto Devanagari releases (it hit an unsupported lookup structure and ran away consuming memory). Lohit Devanagari — the same font family mPDF's own maintainers bundle for other Indic scripts — has simpler tables that shape correctly. Admins choose the font per certificate field, so Hindi text should be placed in a field set to the "Lohit Devanagari (Hindi)" font family; the requirement to support Hindi is explicitly "when the selected font permits it," and font choice is what determines that.

Font files and their `OFL-*.txt` license texts live in `includes/Pdf/fonts/`. To swap in a different font, add its `.ttf` there and register it in `CertificateRenderer::make_instance()` and `FieldDefinitions::font_families()`.

**Reproducing the dependency setup:**
```bash
cd wp-content/plugins/certiva
composer install --no-dev -o
```

---

## Uninstalling

Deactivating the plugin only unschedules its cleanup cron job. Deleting the plugin runs `uninstall.php`, which by default **does nothing** to your data. Only if an admin has explicitly checked "Permanently delete all Certiva data" on the Settings screen does uninstalling remove students, events, templates, registrations, database tables, generated PDF files, and Certiva's options.

---

## Running the tests

Certiva ships PHPUnit tests covering certificate eligibility, duplicate-registration prevention (including at the database layer), download-link expiry and scope, PDF rendering (including Hindi text), and the public form's non-disclosure behavior.

1. Install dev dependencies:
   ```bash
   composer install
   ```
2. Set up the WordPress core PHPUnit test suite (standard WP-CLI scaffold script, included):
   ```bash
   bin/install-wp-tests.sh wordpress_test root '' localhost latest
   ```
3. Run the tests:
   ```bash
   WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit
   ```

If your `php` binary isn't on `PATH` under the exact name `php` (common on some local dev environments), define `WP_PHP_BINARY` with the full path in `wp-tests-config.php` — the WordPress test suite shells out to it directly.

---

## Manual end-to-end test walkthrough

To verify the complete flow by hand after installing:

1. **Create a student** — Certiva → Students → Add New. Set the title to a name and fill in the Email Address field.
2. **Create an event** — Certiva → Events → Add New. Set a type/date, and set **Certificate Availability** to *Enabled*.
3. **Create (or reuse) a template** — Certiva → Templates → Add New. Upload a background image, add at least the Student Name field, and save. Assign this template as the event's **Default Certificate Template** (edit the event again if needed).
4. **Register the student** — Certiva → Registrations → fill in the student, the event, and check **"Mark eligible for a certificate now"** (or leave it unchecked and check the eligibility box afterward in the registrations list — both work).
5. **Generate the certificate** — in the Registrations list, click **Generate** on that row. Its status should change to *Generated* with a certificate ID.
6. **Request it by email** — visit the page containing `[certiva_certificate_request]`, enter the student's email address, and submit. You should see the neutral confirmation message.
7. **Check the email** — an email listing the certificate with a secure download link should arrive at that address (check your mail-catcher, e.g. Mailpit, on local environments).
8. **Download the PDF** — click the link in the email; it should download a PDF showing the student's name and the other fields you placed on the template.

To see the non-disclosure behavior, repeat step 6 with an email address that has no student record — you'll get the exact same confirmation message, with no email sent.

---

## Translations

All user-facing strings use the `certiva` text domain and are marked for translation. A translation template is provided at `languages/certiva.pot` (regenerate with `wp i18n make-pot . languages/certiva.pot --domain=certiva --exclude=vendor,tests,bin` after changing strings).
