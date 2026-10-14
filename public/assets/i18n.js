"use strict";
// Interface translations, fed by the dictionary the PHP page inlines (window.ALTERNATECH_I18N, from lang/messages.json).
// Interface text carries its key (data-i18n, data-i18n-attr), so switching language re-translates the page in place;
// API data never carries a key and is never translated.
(() => {
  const boot = window.ALTERNATECH_I18N || { lang: 'en', messages: { en: {}, fr: {} }, cookie: 'alternatech_lang' };
  const messages = boot.messages, patterns = messages.patterns || [];
  let lang = boot.lang;
  const lookup = (l, key) => messages[l]?.[key];
  const has = key => lookup(lang, key) !== undefined || lookup('en', key) !== undefined;
  function t(key, params) {
    let k = key;
    if (params && typeof params.count === 'number') { const plural = key + (params.count === 1 ? '.one' : '.other'); if (has(plural)) k = plural; }
    const message = lookup(lang, k) ?? lookup('en', k) ?? key;
    return message.replace(/\{(\w+)\}/g, (m, name) => {
      if (!params || !(name in params)) return m;
      const value = params[name];
      return value && typeof value === 'object' && value.k ? t(value.k, value.p) : String(value);
    });
  }
  // Backend messages are English: shown as-is in English, translated by exact match or pattern in French, raw when unknown.
  function server(message) {
    if (typeof message !== 'string' || lang === 'en') return message;
    const exact = lookup(lang, 'server.' + message); if (exact !== undefined) return exact;
    for (const p of patterns) {
      const m = message.match(new RegExp(p.re)); if (!m) continue;
      const params = {}; p.params.forEach((name, i) => { params[name] = name === 'field' ? (has('col.' + m[i + 1]) ? t('col.' + m[i + 1]) : m[i + 1]) : m[i + 1]; });
      return t(p.key, params);
    }
    return message;
  }
  function clear(el) { delete el.dataset.i18n; delete el.dataset.i18nArgs; delete el.dataset.i18nServer; }
  function text(el, key, params) {
    clear(el); el.dataset.i18n = key; if (params) el.dataset.i18nArgs = JSON.stringify(params);
    el.textContent = t(key, params); return el;
  }
  // Plain data (a user's name, an API value): shown as given and left alone by later language switches.
  function raw(el, value) { clear(el); el.textContent = value; return el; }
  function message(el, value) { clear(el); el.dataset.i18nServer = value; el.textContent = server(value); return el; }
  // Errors raised by the frontend carry error.i18n = {key, params}; others hold a backend message.
  function error(el, err) { return err?.i18n ? text(el, err.i18n.key, err.i18n.params) : message(el, err?.message ?? ''); }
  function attr(el, name, key, params) {
    const map = el.dataset.i18nAttr ? JSON.parse(el.dataset.i18nAttr) : {}; map[name] = [key, params ?? null];
    el.dataset.i18nAttr = JSON.stringify(map); el.setAttribute(name, t(key, params ?? undefined)); return el;
  }
  function translated(key, params) { const e = new Error(t(key, params)); e.i18n = { key, params }; return e; }
  function apply(root = document) {
    const scope = root.querySelectorAll ? root : document;
    for (const el of scope.querySelectorAll('[data-i18n]')) el.textContent = t(el.dataset.i18n, el.dataset.i18nArgs ? JSON.parse(el.dataset.i18nArgs) : undefined);
    for (const el of scope.querySelectorAll('[data-i18n-server]')) el.textContent = server(el.dataset.i18nServer);
    for (const el of scope.querySelectorAll('[data-i18n-attr]')) for (const [name, [key, params]] of Object.entries(JSON.parse(el.dataset.i18nAttr))) el.setAttribute(name, t(key, params ?? undefined));
  }
  const LANGUAGES = ['fr', 'en'];
  function setLang(next) {
    // Only the two supported codes: never an arbitrary property name, attribute value or cookie text.
    if (!LANGUAGES.includes(next)) return;
    lang = next; document.documentElement.lang = next;
    document.cookie = `${boot.cookie}=${next}; Path=/; Max-Age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
    apply();
    for (const b of document.querySelectorAll('[data-lang-switch]')) b.setAttribute('aria-pressed', String(b.dataset.langSwitch === next));
  }
  document.addEventListener('click', event => { const b = event.target.closest?.('[data-lang-switch]'); if (b) setLang(b.dataset.langSwitch); });
  window.I18N = { get lang() { return lang; }, t, has, server, text, raw, message, error, attr, translated, apply, setLang };
})();
