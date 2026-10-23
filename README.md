# Bit Form Email Resender — 0.4.0

Independent companion plugin for **Bit Form Free 3.4.0**, with optional **Bit Form Pro 3.4.0**. Reviewed against Bit Form 3.4.0 plugin source. No modifications to the underlying Bit Form plugins. Requires WordPress 6.5+ and PHP 8.0+.

## Install / upgrade

1. Back up and, where possible, test on staging first.
2. WordPress → Plugins → Add New Plugin → Upload Plugin → `bit-form-email-resender-0.4.0.zip`.
3. When upgrading from 0.2.1, select **Replace current with uploaded**. Leave Bit Form Free, Pro, and Post SMTP installed and active.
4. Open **Bit Form → Resend Emails**, select a form and one response, then **Review & resend**.
5. Review the original recipient list, **select the correct PDF templates if needed**, and inspect the **Expected assets** summary, which includes the number of configured Pro PDFs, submission attachments, media files, and saved signature images.
6. Optionally change To/CC/BCC by selecting **Replace with different recipients**. This overrides original and conditional recipients for this attempt only. The default uses current original recipients.
7. Click **Confirm & send emails**. Check the result and Post SMTP logs.

## PDF resending correction (0.4.0)

* The saved workflow's empty PDF configuration no longer hides selected PDFs from the current email template. This was a concrete bug in the prior companion plugin, especially after editing an email template while using existing workflows.
* The Review screen now presents **enabled PDF templates for that form** as checkboxes for **each entry and email notification**. Selections default to the current effective settings. If your PDF is not checked, select it before resending; selections are used for that one send and do not change Bit Form settings.
* PDF selections are checked server-side against the form's enabled PDF templates. They are normalized into Pro's expected `{"id":"..."}` format (rather than numeric IDs).
* A pre-mail guard prevents sending a notification if one or more selected PDF files are missing, unreadable, or fail generation. The resend result reports how many PDFs Bit Form Pro generated and how many reached the WordPress mail handoff. This avoids treating a PDF-less email as successful.
* The guard cannot fix a broken underlying Pro PDF renderer (mPDF, file permissions, PHP memory limits, etc.). If it blocks a send, check Bit Form Pro/PHP error logs. It does not guarantee that Post SMTP or the recipient retains all attachments after WordPress handoff.

## Attachment and signature fixes (0.3.0)

* **Workflow attachment configuration retained.** Prior versions rebuilt all notification details solely from the email template configuration. Bit Form workflows may save separate action-specific `attachment`, `mediaAttachment`, `pdfId`, and `pdfIds` values. The plugin now keeps the actual matched workflow's details and fills in only missing keys from the template configuration, mirroring the native submit flow.
* **Array-valued fields reconstructed.** Bit Form saves arrays (such as multi-upload file lists, signature lists, and repeater data) as JSON in its entry metadata. Resending now decodes JSON values for relevant field types so the native file and signature processors receive their expected structure.
* **Preflight check.** Before mailing, the plugin checks that required configured upload/media files are readable, that required PDF IDs resolve to enabled templates, and that Bit Form Pro's PDF-generation hook is registered. A failed preflight blocks the affected email instead of knowingly sending it without required assets. Signatures present on the submission are also checked and surfaced as availability warnings even when not explicitly configured as mail attachments.
* **Post-send counters.** The result now reports how many attached files, including PDFs, reached the WordPress `wp_mail` filter. The plugin flags the message as partial if the attached file count is below the configured count. This check does **not** guarantee Post SMTP or the recipient preserved inline CID signature images, so verify the delivered message.

**Important:** A signature appearing *within* an email or PDF is distinct from a separately attached signature file. Bit Form's native `MailNotifier` is still responsible for CID inline signature embedding and Bit Form Pro for PDF generation. The companion plugin does not auto-attach otherwise unselected signature files or alter the original notification template. It cannot recover deleted uploads or PDF templates.

## Existing behavior

* Resend single response or selected responses (up to five per send batch).
* Email-only replay of current eligible default, classic/basic and supported Pro on-submit workflow notifications. This does **not** resubmit the form, take payments, or replay integrations.
* Single notification selector or all eligible notifications.
* Review original To/CC/BCC and override with custom To/CC/BCC. The override is temporary; no Bit Form settings are modified.
* WordPress administrator (`manage_options`) only, nonce-protected requests, per-entry lock, action history, basic Post SMTP / `wp_mail` transport test.

## Diagnostic notes

* **No PDFs checked in Review:** Select the enabled PDF template you want attached to that specific email, then resend. Also check the email notification settings in Bit Form. Do not attach unrelated PDFs.
* **PDF template not found / disabled:** Enable or repair the corresponding Bit Form Pro PDF template first.
* **Missing signature upload:** The saved entry's signature file is missing or unreadable at the original Bit Form upload path. The plugin cannot reconstruct a drawn signature from its filename.
* **Selected PDF count is nonzero but Pro generation fails:** The plugin blocks email delivery instead of sending without PDFs. Inspect Bit Form Pro debug/PHP logs (PDF rendering, file permissions, memory limits). If the WordPress handoff includes PDFs but Post SMTP logs have none, investigate the transport/provider.
* **Signature file available but not visible inline:** Check that the email/PDF template includes its signature field. Bit Form embeds email signatures using `phpmailer_init` / CID; the chosen Post SMTP mail transport must preserve inline images. A regular SMTP transport may behave differently from a direct API integration.
* **Attachment previews are current-state checks:** Deleted files, changed templates, or conditional workflows can differ from when the entry was first submitted.

## Testing and limitations

PHP lint, JavaScript syntax validation, isolated PHP PDF-configuration tests and ZIP-integrity checks were performed. **A live WordPress + Bit Form Pro PDF render + Post SMTP delivery test was not available**, so test one known entry with a signature and PDF and inspect the received MIME attachments before bulk sending. WordPress reporting a successful mail handoff is not proof of inbox delivery.

The activity history stores time, form ID, entry ID, current administrator username, recipient mode, To addresses seen at handoff and attachment/PDF counts; it does not store message bodies or the private physical file paths. Removing this companion plugin does not alter Bit Form entries. The bounded history option remains in the database after removal.
