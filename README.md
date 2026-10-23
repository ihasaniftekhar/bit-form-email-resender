# Bit Form Email Resender (0.2.1)

An independent WordPress companion plugin for **Bit Form Free 3.4.0**, with optional **Bit Form Pro 3.4.0**. No modifications to either Bit Form plugin are required. This package does not contain any Bit Form proprietary plugin code.

## Install

1. **Back up** the website and test on a staging site first. Keep your existing Bit Form Free and Pro plugins installed and active.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, choose `bit-form-email-resender-0.2.1.zip`, then install and activate it.
3. Open **Bit Form → Resend Emails** (requires WordPress administrator `manage_options` capability).
4. Select the form, locate an entry, and choose **Resend email**. Or select entries and click **Resend selected**.
5. Choose **Review & resend** on one response or select responses and choose **Review & resend selected**.
6. Review the currently calculated recipients for each email template. Select **Use original recipients (default)** or **Replace with different recipients**.
7. Custom mode lets you specify **To**, optional **CC**, and optional **BCC**. For one submission, the editable boxes prefill with its original calculated recipients. For multiple submissions, the preview shows each entry's recipients but custom boxes start empty to avoid unintentionally combining recipients across different entries. Changing to custom mode replaces *all* recipients, including Bit Form Pro conditional recipients; it does not add an extra copy to original recipients.
8. Confirm sending. Review the per-entry result and **Recent resend activity** below the table.

## How it works

- Reads the existing Bit Form entry and form field data directly from Bit Form's own tables.
- Reads current enabled email notification templates and settings through Bit Form `EmailTemplateHandler`.
- Resolves currently enabled default notifications and evaluates active classic/basic and Pro advanced on-submit email workflow rules. This email-only evaluator never submits another entry and does not execute unrelated success actions, CRMs, webhooks, payments, or double-opt-in actions.
- Previews per-entry currently resolved recipients, including smart-tag recipients and Pro conditional routing. The **default** does not modify any recipient settings. The **custom** option overrides To/CC/BCC and supersedes Pro conditional routing *temporarily for the resend only*, without saving any changes to Bit Form's templates.
- Calls Bit Form's own `MailNotifier::notify()` for each eligible email notification, so it uses the plugin's existing email body, email/SMTP transport, uploaded attachments, signature images and Pro PDF hooks where available.
- Adds an entry timeline event for resend and records a bounded local audit summary of the last 150 attempts in the WordPress options table.
- Checks admin privileges and a WordPress nonce, limits each server call to 5 entries, validates form ownership and uses an atomic per-entry lock against simultaneous clicks.
- A success result means **WordPress reported successful mail handoff**; it does **not** prove delivery to the recipient's inbox.

## Important limitations

- **Compatibility lock:** Sending is disabled unless Bit Form Free is exactly **3.4.0**. The supplied Pro 3.4.0 source was reviewed; behavior with another Pro version has not been tested.
- **Dedicated menu, not embedded into the React responses table:** Bit Form's admin responses view is a compiled single-page application. This initial release uses an independent Bit Form submenu for a stable, update-safe UI. It lists submission IDs, eligible contact fields and submission times, plus bulk and single resend buttons.
- **Original recipients means recalculated recipients:** The current templates, recipient settings, and conditional rules are used; the actual To/CC/BCC originally used are not stored in every Bit Form submission. This is not a historical email log replay. For example, a rule referring to the current date may evaluate differently than it did at the original submission. An edited form can have different fields or templates than it had at submission time.
- **No double-opt-in replay:** Confirmation/activation messages and their tokens are intentionally excluded to avoid generating or corrupting new opt-in links.
- **Files:** Resending file uploads and images requires that the original files still exist at the stored paths. Pro PDFs are generated using Bit Form's installed Pro hook if configured. PDF features and SMTP email delivery still require staging and live testing.
- **Other custom hooks:** Third-party filters, field transformations and extensions can influence email conditions, content and dispatch. Verify each form-specific workflow in staging, especially when it uses many custom field actions or custom email routing.
- **Result logging:** The status is collected through WordPress `wp_mail_succeeded` / `wp_mail_failed` hooks. A mail provider that bypasses these hooks may return an unconfirmed result even if it independently sent the message. Verify external SMTP logs before retrying.
- **Recipients and privacy:** The bounded audit records the `To` addresses observed at handoff and whether recipients were overridden. WordPress administrators can access these details in the database. It does not capture CC/BCC in its own audit records. If custom mode applies across multiple notification templates, all those templates are sent to the same replacement addresses, so choose the correct notification in the dropdown.
- **Administrator-only:** Non-admin Bit Form editors cannot trigger resends by default.
- **Entry status:** Deleted/trashed or pending-double-opt-in submissions with Bit Form status `2` are excluded for safety.

## Suggested acceptance test (staging)

1. Create a normal Bit Form entry sent to a mailbox you control.
2. Click Review & resend and ensure the default original recipient list is displayed.
3. Switch to custom recipients. Verify changing To (and optionally CC/BCC) sends **only** to the custom mailbox, not the original mailbox. Switch back to original mode to verify the unmodified routing.
4. Verify resend reproduces subject, body, dynamic values and sender settings.
5. Test with an email template that uses a file upload, image, signature, and (if applicable) Pro PDF attachment.
6. Test a matched and an unmatched conditional notification, plus a Pro advanced conditional email rule.
7. Test 2–5 selected entries, confirm they each send just once, and check the recent resend audit.
8. Ensure the submission count, paid transactions, webhooks and CRM entries have not changed.
9. Confirm status is shown only as accepted/attempted and check actual provider delivery logs to establish inbox delivery.

## Uninstall

Deactivate and delete the plugin in WordPress. Existing Bit Form entries and templates are unaffected. The bounded audit option (`bf_email_resender_history_v1`) remains in the database for recordkeeping; remove it manually if desired. In-progress locks are removed automatically after normal requests, with stale-lock recovery during the next resend attempt.

## Testing performed for this build

- PHP syntax validation of plugin entry point.
- Source inspection of uploaded Bit Form Free and Pro 3.4.0 archives.
- PHP syntax and JavaScript parse checks. Standalone checks for recipient validation and Pro conditional-routing override. Tests from the initial release also covered default enabled/disabled notifications, classic conditional match/mismatch, and Pro inline email show/hide.
- **Not** installed or tested against a running WordPress site, database, SMTP service or Pro PDF generator. Use staging before production.

## Upgrading from 0.1.0

On WordPress Plugins, upload `bit-form-email-resender-0.2.1.zip` and choose **Replace current with uploaded** if prompted. If replacing is not offered, deactivate/delete only the companion **Bit Form Email Resender** plugin and then install 0.2.1; do not uninstall Bit Form Free or Pro. Existing Bit Form entries are never altered.

If the resend does not work, copy the precise error displayed after **Review & resend** or **Confirm & send**. Check browser Developer Tools → Network → `admin-ajax.php` and the WordPress/PHP error logs for a 400/403/409/500 response; a staging test is strongly recommended.

## Version 0.2.1 troubleshooting

- Adds an admin-only **Send mail transport test** button. This explicitly calls `wp_mail()` with a recipient supplied by the administrator and a plain test body. It checks Post SMTP separately from Bit Form's notification logic. Sending is limited to one test every 30 seconds per admin account.
- More robust admin script loading based on the actual page slug / generated hook; addresses the case where the buttons appeared but nothing happened because the admin script was never loaded.
- The admin page clarifies that **Review & resend** previews recipients; the actual send happens after **Confirm & send emails**.
- Submission resends explicitly report a missing resolved `To` address and distinguish Bit Form failing to call `wp_mail()` from mail transport handoff failures. Custom recipients may be used when the original template's To value is empty.
- If a test email appears in Post SMTP's logs but a resend does not, inspect the on-page resend status and Bit Form workflow and email template routing. Do not repeatedly retry uncertain sends; verify provider logs first to avoid duplicates.
- No claim of live WordPress/Post SMTP testing: tested by PHP lint, JavaScript parse, and static source inspection only.
