"use strict";
// Header behaviour shared by every page: the collapsible account menu, and sign-out on pages without login.js.
(() => {
  const toggle = document.querySelector('#account-toggle'), panel = document.querySelector('#account-panel');
  if (toggle && panel) {
    const set = open => { toggle.setAttribute('aria-expanded', String(open)); panel.hidden = !open; };
    toggle.addEventListener('click', () => {
      const open = toggle.getAttribute('aria-expanded') !== 'true'; set(open);
      if (open) panel.querySelector('input, button, a[href]')?.focus();
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !panel.hidden) { set(false); toggle.focus(); } });
    document.addEventListener('click', event => { if (!panel.hidden && event.target.isConnected && !panel.contains(event.target) && !toggle.contains(event.target)) set(false); });
    // Lets the board open the menu, e.g. after a cancelled Google sign-in.
    window.alternatechAccountMenu = { open: () => set(true), close: () => set(false) };
  }
  const logout = document.querySelector('#site-logout'), notice = document.querySelector('#site-logout-status');
  if (logout) logout.addEventListener('click', async () => {
    logout.disabled = true; I18N.raw(notice, '');
    try {
      // Same sequence as login.js: the session's CSRF token, then POST /api/auth/logout.
      const response = await fetch('/api/auth/session', { headers: { Accept: 'application/json' } });
      const session = await response.json();
      if (!response.ok) throw new Error();
      if (session.user) {
        const result = await fetch('/api/auth/logout', { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': session.csrf_token } });
        if (!result.ok) throw new Error();
      }
      location.assign('/');
    } catch { I18N.text(notice, 'account.signOutError'); logout.disabled = false; }
  });
})();
