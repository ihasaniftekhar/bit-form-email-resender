(function () {
  'use strict';
  const table = document.getElementById('bfres-entries');
  if (!table || !window.bfEmailResender) return;
  const all = document.getElementById('bfres-all');
  const progress = document.getElementById('bfres-progress');
  const bulk = document.getElementById('bfres-bulk');
  const template = document.getElementById('bfres-template');
  const buttons = [...document.querySelectorAll('.bfres-one')];
  let busy = false;
  const selected = () => [...document.querySelectorAll('.bfres-check:checked')].map(x => Number(x.value));
  all.addEventListener('change', () => document.querySelectorAll('.bfres-check').forEach(x => x.checked = all.checked));
  const send = async (ids) => {
    if (busy || !ids.length) return;
    const label = template.options[template.selectedIndex]?.text || 'selected notification';
    const yes = window.confirm(`Resend ${label} for ${ids.length} selected submission(s)? This will send real emails to the currently configured recipients.`);
    if (!yes) return;
    busy = true;
    bulk.disabled = true;
    buttons.forEach(x => x.disabled = true);
    progress.textContent = `Preparing ${ids.length} submission(s)…`;
    let complete = 0;
    let issues = 0;
    const results = [];
    try {
      for (let i = 0; i < ids.length; i += bfEmailResender.maxBatch) {
        const group = ids.slice(i, i + bfEmailResender.maxBatch);
        const params = new URLSearchParams();
        params.append('action', 'bf_email_resender_send');
        params.append('nonce', bfEmailResender.nonce);
        params.append('form_id', table.dataset.formId);
        params.append('template_id', template.value);
        group.forEach(id => params.append('entry_ids[]', String(id)));
        const response = await fetch(bfEmailResender.ajaxUrl, { method: 'POST', body: params, credentials: 'same-origin' });
        const body = await response.json();
        if (!response.ok || !body.success) throw new Error(body?.data?.message || 'Server request failed.');
        for (const item of body.data.results) {
          const dest = document.querySelector(`.bfres-result[data-id="${item.entry_id}"]`);
          if (dest) {
            dest.textContent = (item.status === 'accepted' ? '✓ ' : item.status === 'skipped' ? '— ' : '⚠ ') + item.message;
            dest.className = 'bfres-result bfres-' + item.status;
          }
          if (item.status !== 'accepted') issues++;
          results.push(item);
          complete++;
        }
        progress.textContent = `${complete} / ${ids.length} processed (${issues} requiring attention)`;
      }
      progress.textContent += '. Reload to view updated history.';
    } catch (e) {
      progress.textContent = `Stopped after ${complete} / ${ids.length}. ${e.message} Check the results before retrying to avoid duplicates.`;
    } finally {
      busy = false;
      bulk.disabled = false;
      buttons.forEach(x => x.disabled = false);
    }
  };
  bulk.addEventListener('click', () => {
    const ids = selected();
    if (!ids.length) { window.alert('Select one or more entries first.'); return; }
    send(ids);
  });
  buttons.forEach(button => button.addEventListener('click', () => send([Number(button.dataset.id)])));
})();
