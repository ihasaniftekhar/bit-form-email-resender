## 0.4.0
- Fix empty workflow PDF selections incorrectly overriding current email template PDFs.
- Display each notification's selectable enabled Bit Form Pro PDF templates during resend review.
- Validate selections against the current form and normalize PDF references for the native Pro generator.
- Refuse to send if selected PDFs fail to reach the WordPress mail handoff; include PDF generation diagnostics.
- Preserve original/custom To, CC, BCC; avoid duplicate integrations/payment workflows.

# Changelog

## 0.3.0
- Preserve matched workflow notification details, including its file and PDF selections.
- Restore array-valued fields from Bit Form's JSON-encoded entry metadata.
- Preflight required PDF templates, uploads, media attachments, and signature file availability.
- Display expected assets in the review dialog, block missing required assets, and report files and PDFs captured during WordPress mail handoff.
- Keep custom To/CC/BCC and Post SMTP transport test from 0.2.1.
