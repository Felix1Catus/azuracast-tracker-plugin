/* Tracker plugin: "Branches" button on AzuraCast station pages.
 * AzuraCast's admin UI is a single-page app, so this watches the URL. On /station/<id>/... it asks the API
 * whether the current user may view branches (cached per station) and, if so, shows a button that opens
 * /branch-tracker/<id>. Nothing is shown to users without the "View Branches" permission. */
(() => {
  'use strict';
  const allowed = {};               // station id -> true/false
  let btn = null;

  function button() {
    if (btn) return btn;
    btn = document.createElement('a');
    btn.id = 'branch-tracker-button';
    btn.textContent = 'Branches';
    btn.title = 'Open the branch board (Tracker plugin)';
    btn.setAttribute('aria-label', 'Open the branch board');
    btn.style.cssText = [
      'position:fixed', 'right:24px', 'bottom:24px', 'z-index:2147483000',
      'background:#16a34a', 'color:#fff', 'font:700 16px/1 system-ui,sans-serif',
      'padding:14px 22px', 'border-radius:28px', 'text-decoration:none',
      'border:2px solid #fff', 'box-shadow:0 6px 20px rgba(0,0,0,.45)', 'display:none',
      'animation:branchTrackerPulse 1.2s ease-out 3',
    ].join(';');
    btn.innerHTML = '<span aria-hidden="true" style="margin-right:8px">&#9673;</span>Branch Board';
    const style = document.createElement('style');
    style.textContent = '@keyframes branchTrackerPulse{0%{box-shadow:0 0 0 0 rgba(22,163,74,.8)}100%{box-shadow:0 0 0 22px rgba(22,163,74,0)}}'
      + '#branch-tracker-button:hover{background:#15803d!important}';
    document.head.appendChild(style);
    document.body.appendChild(btn);
    return btn;
  }

  async function check() {
    const m = location.pathname.match(/^\/station\/([^/]+)/);
    if (!m) { if (btn) btn.style.display = 'none'; return; }
    const id = decodeURIComponent(m[1]);
    if (!(id in allowed)) {
      allowed[id] = false;
      try {
        const res = await fetch(`/api/station/${encodeURIComponent(id)}/branches/live`, { credentials: 'same-origin' });
        allowed[id] = res.ok;
      } catch { allowed[id] = false; }
    }
    const b = button();
    b.href = `/branch-tracker/${encodeURIComponent(id)}`;
    b.style.display = allowed[id] ? 'inline-block' : 'none';
  }

  let last = '';
  setInterval(() => { if (location.pathname !== last) { last = location.pathname; check(); } }, 700);
})();
