# Release history and reported issues

Historical versions are preserved in separate Git commits. The `dist/` directory contains one independently installable ZIP per version. Release tags exist in the local publishing bundle but have not been pushed to GitHub.

| Version | Change | Reported limitation or error |
| --- | --- | --- |
| 0.1.0 | Initial email-only resender | Resend action did not work in some installations. |
| 0.2.0 | Custom recipient review and To/CC/BCC replacement | Button or mail action was still unreliable on some admin screens. |
| 0.2.1 | Corrected admin script handling; direct `wp_mail()` test | Notification emails sent, but signature and PDF attachments were missing in some cases. |
| 0.3.0 | Preserve notification attachment configuration and JSON-encoded submission files | PDFs could still be missing despite being selected. |
| 0.4.0 | PDF selector, configuration precedence fix, fail-closed send protection | Diagnostic result: `0 / 2` selected PDFs generated; email blocked rather than sent without PDFs. |
| 0.4.1 | Native PDF outcome reporting | Generic native error: `Error in generating PDF.` |
| 0.4.2 | Diagnostic mPDF renderer test and underlying exception | mPDF reported: `Cannot find TTF TrueType font file "DejaVuSerifCondensed.ttf" in configured font directories.` The issue could also affect normal Bit Form PDF generation. |
| 0.4.3 | Font presence check and controlled repair | Recovery depends on correct writable font directories and the actual mPDF environment; verify against a staging site. |

## Operational safety

- These are historical development snapshots, not a claim that every release was production-ready.
- Test on a staging WordPress site with Bit Form Free/Pro versions matched to the indicated compatibility.
- Post SMTP or another WordPress mail transport is independent from PDF template rendering.
- Resending should not re-execute payments or external integrations. Failed required PDFs block incomplete sends.
- No real submission data, private error logs, email delivery logs, exported form configurations, vendor plugin packages, or WordPress credentials are included in this repository.