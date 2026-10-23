(function () {
  'use strict';
  const table = document.getElementById('bfres-entries');
  if (!table || !window.bfEmailResender) return;
  const testMailButton = document.getElementById('bfres-test-mail');
  const testMailAddress = document.getElementById('bfres-test-address');
  const testMailResult = document.getElementById('bfres-test-result');
  const all = document.getElementById('bfres-all');
  const progress = document.getElementById('bfres-progress');
  const bulk = document.getElementById('bfres-bulk');
  const template = document.getElementById('bfres-template');
  const buttons = [...document.querySelectorAll('.bfres-one')];
  const panel = document.getElementById('bfres-preview');
  const summary = document.getElementById('bfres-preview-summary');
  const custom = document.getElementById('bfres-custom');
  const to = document.getElementById('bfres-to');
  const cc = document.getElementById('bfres-cc');
  const bcc = document.getElementById('bfres-bcc');
  const confirm = document.getElementById('bfres-confirm');
  const cancel = document.getElementById('bfres-cancel');
  let busy = false;
  let reviewedIds = [];
  let reviewedTemplate = '0';
  let reviewable = false;
  let pdfChoices = {};

  const selected = () => [...document.querySelectorAll('.bfres-check:checked')].map(x => Number(x.value));
  const setBusy = state => {
    busy = state;
    bulk.disabled = state;
    buttons.forEach(b => b.disabled = state);
    confirm.disabled = state;
  };
  all.addEventListener('change', () => document.querySelectorAll('.bfres-check').forEach(x => x.checked = all.checked));
  const append = (parent, tag, value) => {
    const el = document.createElement(tag);
    el.textContent = value;
    parent.appendChild(el);
    return el;
  };
  const request = async (action, ids, extra = {}) => {
    const params = new URLSearchParams();
    params.append('action', action);
    params.append('nonce', bfEmailResender.nonce);
    params.append('form_id', table.dataset.formId);
    params.append('template_id', reviewedTemplate);
    ids.forEach(id => params.append('entry_ids[]', String(id)));
    Object.entries(extra).forEach(([key, value]) => params.append(key, value));
    const response = await fetch(bfEmailResender.ajaxUrl, { method: 'POST', body: params, credentials: 'same-origin' });
    let body;
    try { body = await response.json(); }
    catch (_) { throw new Error('Unexpected server response (not JSON). Check WordPress/PHP error logs. HTTP ' + response.status); }
    if (!response.ok || !body.success) throw new Error(body?.data?.message || ('Request failed (HTTP ' + response.status + ').'));
    return body.data;
  };
  testMailButton?.addEventListener('click', async () => {
    const address = testMailAddress.value.trim();
    if (!/^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/.test(address)) {
      testMailResult.textContent = 'Enter a valid test email address.';
      return;
    }
    if (!window.confirm('Send one real test message via Post SMTP / WordPress to ' + address + '?')) return;
    testMailButton.disabled = true;
    testMailResult.textContent = 'Testing wp_mail()…';
    try {
      const data = await request('bf_email_resender_test_mail', [], { address });
      testMailResult.textContent = data.message || 'Mail transport test request completed.';
    } catch (e) {
      testMailResult.textContent = 'Mail transport test failed: ' + e.message;
    } finally {
      testMailButton.disabled = false;
    }
  });
  const mode = () => document.querySelector('input[name="bfres-recipient-mode"]:checked').value;
  document.querySelectorAll('input[name="bfres-recipient-mode"]').forEach(radio => {
    radio.addEventListener('change', () => { custom.hidden = mode() !== 'custom'; });
  });
  const review = async (ids) => {
    if (busy || !ids.length) return;
    if (ids.length > bfEmailResender.previewMax) {
      progress.textContent = `Select no more than ${bfEmailResender.previewMax} entries at a time.`;
      return;
    }
    panel.hidden = false;
    summary.replaceChildren();
    append(summary, 'p', 'Loading recipient details…');
    progress.textContent = '';
    setBusy(true);
    reviewedIds = ids;
    reviewedTemplate = template.value;
    reviewable = false;
    pdfChoices = {};
    try {
      const data = await request('bf_email_resender_preview', ids);
      const pdfCatalog = data.pdf_catalog || [];
      summary.replaceChildren();
      let count = 0;
      let errors = 0;
      let attachmentProblems = 0;
      const allAddresses = { to: new Set(), cc: new Set(), bcc: new Set() };
      for (const entry of data.entries) {
        const section = document.createElement('div');
        section.className = 'bfres-review-entry';
        append(section, 'strong', 'Entry #' + entry.entry_id);
        if (entry.error) { append(section, 'p', entry.error); errors++; }
        else if (!entry.notifications.length) { append(section, 'p', 'No notifications match the current submission conditions.'); errors++; }
        else {
          for (const notice of entry.notifications) {
            count++;
            const sub = document.createElement('p');
            append(sub, 'strong', notice.template + ': ');
            if (!(notice.to || []).length) {
              append(sub, 'span', '⚠ Original To is empty: use custom recipient mode or fix notification routing. ');
            }
            for (const field of ['to', 'cc', 'bcc']) {
              (notice[field] || []).forEach(address => allAddresses[field].add(address));
              const text = (notice[field] || []).join(', ') || '(none)';
              append(sub, 'span', field.toUpperCase() + ' ' + text + ' · ');
            }
            section.appendChild(sub);
            const assets = notice.attachments;
            if (assets) {
              const key = entry.entry_id + ':' + notice.template_id;
              const selected = (assets.pdf_ids || []).map(Number);
              pdfChoices[key] = [...selected];
              if (pdfCatalog.length) {
                const group = document.createElement('fieldset');
                group.className = 'bfres-pdf-choices';
                const legend = document.createElement('legend');
                legend.textContent = 'Attach Bit Form Pro PDFs to this notification';
                group.appendChild(legend);
                for (const item of pdfCatalog) {
                  const row = document.createElement('label');
                  const checkbox = document.createElement('input');
                  checkbox.type = 'checkbox';
                  checkbox.value = String(item.id);
                  checkbox.checked = selected.includes(Number(item.id));
                  checkbox.addEventListener('change', () => {
                    const chosen = [...group.querySelectorAll('input:checked')].map(x => Number(x.value));
                    pdfChoices[key] = chosen;
                    const current = section.querySelector('.bfres-pdf-chosen');
                    if (current) current.textContent = chosen.length ? chosen.length + ' PDF(s) selected for attachment.' : 'No PDFs selected. If you expect a PDF, select its template above.';
                  const expected = section.querySelector('.bfres-asset-status .bfres-pdf-count');
                  if (expected) expected.textContent = chosen.length + ' selected PDF(s)';
                  });
                  row.appendChild(checkbox);
                  append(row, 'span', ' ' + item.title + ' (#' + item.id + ')');
                  group.appendChild(row);
                }
                const chosenLine = append(group, 'p', selected.length ? selected.length + ' PDF(s) selected for attachment.' : 'No PDFs selected. If you expect a PDF, select its template above.');
                chosenLine.className = 'bfres-pdf-chosen';
                section.appendChild(group);
              }
              const assetLine = document.createElement('p');
              assetLine.className = 'bfres-asset-status';
              append(assetLine, 'strong', 'Expected assets: ');
              const selectedLine = append(assetLine, 'span', `${selected.length} selected PDF(s)`);
              selectedLine.className = 'bfres-pdf-count';
              append(assetLine, 'span', `; ${assets.uploads_available}/${assets.uploads_required} submission file(s); ${assets.media_available}/${assets.media_required} media file(s); ${assets.signatures_available}/${assets.signature_fields} stored signature(s) available.`);
              section.appendChild(assetLine);
              for (const msg of (assets.blocking || [])) {
                const line = append(section, 'p', '⚠ Cannot send with missing configured attachments: ' + msg);
                line.className = 'bfres-asset-error';
                attachmentProblems++;
              }
              for (const msg of (assets.warnings || [])) {
                const line = append(section, 'p', '⚠ ' + msg);
                line.className = 'bfres-asset-warning';
              }
            }
          }
        }
        summary.appendChild(section);
      }
      append(summary, 'p', count + ' notification(s) eligible across ' + ids.length + ' submission(s).' + (errors ? ' ' + errors + ' entry/entries need attention.' : ''));
      // Original recipients are visible for all entries above. When editing one entry,
      // populate editable fields with that entry's recipient list by default.
      // For a bulk edit don't combine unrelated entries' addresses into a new global To.
      const single = ids.length === 1;
      to.value = single ? [...allAddresses.to].join(', ') : '';
      cc.value = single ? [...allAddresses.cc].join(', ') : '';
      bcc.value = single ? [...allAddresses.bcc].join(', ') : '';
      document.querySelector('input[name="bfres-recipient-mode"][value="original"]').checked = true;
      custom.hidden = true;
      reviewable = count > 0 && attachmentProblems === 0;
      if (attachmentProblems) progress.textContent = 'Attachments need attention: ' + attachmentProblems + ' blocking issue(s). Nothing has been sent. Fix the PDF / file settings before resending.';
      if (count === 0) progress.textContent = 'No matching email notifications to send. Review the selected form and notification template.';
    } catch (e) {
      summary.replaceChildren();
      append(summary, 'p', 'Could not load email recipients: ' + e.message);
      progress.textContent = 'Recipient review failed. No email has been sent.';
      reviewable = false;
    } finally {
      setBusy(false);
      confirm.disabled = !reviewable;
    }
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  };
  const send = async () => {
    if (busy || !reviewedIds.length) return;
    const recipientMode = mode();
    const options = { recipient_mode: recipientMode };
    if (recipientMode === 'custom') {
      const parse = raw => raw.split(/[,;\r\n]+/).map(x => x.trim()).filter(Boolean);
      const validate = raw => parse(raw).every(x => /^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/.test(x));
      if (!parse(to.value).length || ![to.value, cc.value, bcc.value].every(validate)) {
        window.alert('Enter at least one valid To email address. Separate multiple addresses with commas or new lines.');
        return;
      }
      options.recipient_to = to.value;
      options.recipient_cc = cc.value;
      options.recipient_bcc = bcc.value;
    }
    options.pdf_choices = JSON.stringify(pdfChoices);
    const countSelectedPdfs = Object.values(pdfChoices).reduce((sum, ids) => sum + ids.length, 0);
    const label = template.options[template.selectedIndex]?.text || 'selected notification';
    if (template.value !== reviewedTemplate) { window.alert('Notification selection changed. Review recipients again.'); return; }
    const msg = recipientMode === 'original'
      ? 'Send to the original recipients calculated from the current Bit Form configuration?'
      : 'REPLACE original recipients with the custom To/CC/BCC addresses shown?';
    if (!window.confirm(`Send ${label} for ${reviewedIds.length} submission(s)?\n\n${msg}\n\nSelected PDF attachment(s): ${countSelectedPdfs}.\nEmails with missing selected PDFs will be blocked, not sent without attachments.\n\nThis sends real emails.`)) return;
    setBusy(true);
    let complete = 0;
    let issues = 0;
    try {
      for (let i = 0; i < reviewedIds.length; i += bfEmailResender.maxBatch) {
        const ids = reviewedIds.slice(i, i + bfEmailResender.maxBatch);
        const data = await request('bf_email_resender_send', ids, options);
        for (const item of data.results) {
          const dest = document.querySelector(`.bfres-result[data-id="${item.entry_id}"]`);
          if (dest) {
            dest.textContent = (item.status === 'accepted' ? '✓ ' : item.status === 'skipped' ? '— ' : '⚠ ') + item.message;
            dest.className = 'bfres-result bfres-' + item.status;
          }
          if (item.status !== 'accepted') issues++;
          complete++;
        }
        progress.textContent = `${complete} / ${reviewedIds.length} processed (${issues} requiring attention)`;
      }
      panel.hidden = true;
      progress.textContent += '. Reload this page for the updated resend history.';
    } catch (e) {
      progress.textContent = `Stopped after ${complete} / ${reviewedIds.length}. ${e.message} Check the results before retrying to avoid duplicates.`;
    } finally {
      setBusy(false);
    }
  };
  cancel.addEventListener('click', () => { panel.hidden = true; reviewedIds = []; });
  confirm.addEventListener('click', send);
  bulk.addEventListener('click', () => {
    const ids = selected();
    if (!ids.length) { window.alert('Select one or more entries first.'); return; }
    review(ids);
  });
  buttons.forEach(button => button.addEventListener('click', () => review([Number(button.dataset.id)])));
})();
