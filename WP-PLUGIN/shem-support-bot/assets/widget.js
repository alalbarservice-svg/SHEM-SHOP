/* SHEM support bot widget. Shadow DOM, RTL, textContent only (no HTML from the server is ever rendered). No secrets. */
(function () {
  'use strict';
  if (window.__ssbLoaded) return;
  var cfg = window.SSB_CFG || {};
  if (!cfg.api) return;
  window.__ssbLoaded = true;

  var API = cfg.api + '?rest_route=/ssb/v1/';
  var state = { open: false, conv: '', busy: false, inited: false, ticket: false, rated: false, count: 0 };

  var host = document.createElement('div');
  host.id = 'ssb-host';
  host.setAttribute('data-ssb', '1');
  var root = host.attachShadow({ mode: 'open' });

  var css = [
    ':host{all:initial}',
    '*{box-sizing:border-box}',
    '.w{position:fixed;left:16px;bottom:16px;z-index:2147483000;font-family:Arimo,Arial,sans-serif;direction:rtl;color:#000}',
    '.fab{width:56px;height:56px;border-radius:50%;border:2px solid #c9a96a;background:#000;color:#c9a96a;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(0,0,0,.35);padding:0}',
    '.fab:focus-visible,.btn:focus-visible,.chip:focus-visible,.x:focus-visible,.star:focus-visible,textarea:focus-visible{outline:3px solid #c9a96a;outline-offset:2px}',
    '.fab svg{width:26px;height:26px}',
    '.panel{display:none;position:absolute;left:0;bottom:68px;width:min(380px,calc(100vw - 32px));height:min(540px,calc(100vh - 110px));height:min(540px,calc(100dvh - 110px));background:#fff;border:1px solid #1f1f1f;border-radius:14px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.4);flex-direction:column}',
    '.panel.on{display:flex}',
    '.hd{background:#000;color:#fff;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:3px solid #c9a96a}',
    '.hd h2{margin:0;font-size:16px;font-weight:700;letter-spacing:.2px}',
    '.hd small{display:block;font-size:12px;color:#e8e4dc;font-weight:400;margin-top:2px}',
    '.x{background:transparent;border:0;color:#fff;font-size:26px;line-height:1;cursor:pointer;min-width:44px;min-height:44px}',
    '.log{flex:1;overflow-y:auto;padding:12px;background:#f6f4ef;display:flex;flex-direction:column;gap:8px}',
    '.m{max-width:86%;padding:9px 12px;border-radius:12px;font-size:15px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere}',
    '.m.b{background:#e8e4dc;color:#000;align-self:flex-start;border-bottom-right-radius:4px}',
    '.m.u{background:#000;color:#fff;align-self:flex-end;border-bottom-left-radius:4px}',
    '.m.t{background:#fff;border:1px solid #c9a96a;color:#000;align-self:flex-start}',
    '.chips{display:flex;flex-wrap:wrap;gap:6px;padding:8px 12px;background:#f6f4ef;border-top:1px solid #e8e4dc}',
    '.chip{border:1px solid #1f1f1f;background:#fff;color:#000;border-radius:999px;padding:0 14px;min-height:44px;font-size:14px;cursor:pointer;font-family:inherit}',
    '.chip:hover{background:#000;color:#fff}',
    '.ft{display:flex;gap:8px;padding:10px;border-top:1px solid #e8e4dc;background:#fff;align-items:flex-end}',
    'textarea{flex:1;resize:none;border:1px solid #1f1f1f;border-radius:10px;padding:10px;font-size:16px;font-family:inherit;min-height:44px;max-height:110px;direction:rtl}',
    '.btn{background:#000;color:#fff;border:2px solid #000;border-radius:10px;min-height:44px;min-width:64px;padding:0 14px;font-size:15px;cursor:pointer;font-family:inherit}',
    '.btn[disabled]{opacity:.5;cursor:default}',
    '.rate{padding:8px 12px;background:#fff;border-top:1px solid #e8e4dc;font-size:14px;display:none}',
    '.rate.on{display:block}',
    '.stars{display:flex;gap:4px;margin-top:6px}',
    '.star{background:#fff;border:1px solid #1f1f1f;border-radius:8px;min-width:44px;min-height:44px;font-size:16px;cursor:pointer;font-family:inherit}',
    '.star:hover{background:#c9a96a}',
    '.lnk{background:none;border:0;color:#000;text-decoration:underline;cursor:pointer;font-size:13px;padding:6px;min-height:32px;font-family:inherit}',
    '.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}',
    '@media (max-width:480px){.panel{position:fixed;left:8px;right:8px;bottom:84px;width:auto;height:calc(100dvh - 110px)}}',
    '@media (prefers-reduced-motion:no-preference){.panel.on{animation:p .18s ease-out}@keyframes p{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}}'
  ].join('\n');

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  var style = el('style'); style.textContent = css; root.appendChild(style);
  var w = el('div', 'w'); root.appendChild(w);

  var fab = el('button', 'fab'); fab.type = 'button';
  fab.setAttribute('aria-label', 'פתיחת צ׳אט שירות לקוחות');
  fab.setAttribute('aria-expanded', 'false');
  (function () {
    var NS = 'http://www.w3.org/2000/svg', svg = document.createElementNS(NS, 'svg'), path = document.createElementNS(NS, 'path');
    svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.8'); svg.setAttribute('stroke-linecap', 'round'); svg.setAttribute('stroke-linejoin', 'round'); svg.setAttribute('aria-hidden', 'true');
    path.setAttribute('d', 'M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z');
    svg.appendChild(path); fab.appendChild(svg);
  })();

  var panel = el('div', 'panel');
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'false');
  panel.setAttribute('aria-label', 'צ׳אט שירות לקוחות');

  var hd = el('div', 'hd');
  var ttl = el('div'); var h2 = el('h2', null, 'שירות לקוחות'); var sub = el('small', null, 'עונה רק על סמך המידע של החנות');
  ttl.appendChild(h2); ttl.appendChild(sub);
  var xb = el('button', 'x', '×'); xb.type = 'button'; xb.setAttribute('aria-label', 'סגירת הצ׳אט');
  hd.appendChild(ttl); hd.appendChild(xb);

  var log = el('div', 'log'); log.setAttribute('role', 'log'); log.setAttribute('aria-live', 'polite'); log.setAttribute('aria-relevant', 'additions');
  var chips = el('div', 'chips');
  var rate = el('div', 'rate');
  var rl = el('div', null, 'איך היה השירות?'); rate.appendChild(rl);
  var stars = el('div', 'stars'); rate.appendChild(stars);
  for (var i = 1; i <= 5; i++) (function (n) {
    var s = el('button', 'star', String(n)); s.type = 'button';
    s.setAttribute('aria-label', 'דירוג ' + n + ' מתוך 5');
    s.addEventListener('click', function () { sendRating(n); });
    stars.appendChild(s);
  })(i);

  var ft = el('form', 'ft');
  var ta = el('textarea'); ta.rows = 1; ta.maxLength = 600; ta.setAttribute('aria-label', 'כתיבת הודעה'); ta.placeholder = 'כתבו כאן...';
  var send = el('button', 'btn', 'שליחה'); send.type = 'submit';
  ft.appendChild(ta); ft.appendChild(send);
  var rateLnk = el('button', 'lnk', 'דרגו את השיחה'); rateLnk.type = 'button';
  var lnkRow = el('div'); lnkRow.style.cssText = 'text-align:center;background:#fff'; lnkRow.appendChild(rateLnk);

  panel.appendChild(hd); panel.appendChild(log); panel.appendChild(chips); panel.appendChild(rate); panel.appendChild(ft); panel.appendChild(lnkRow);
  w.appendChild(panel); w.appendChild(fab);

  function scroll() { log.scrollTop = log.scrollHeight; }
  function add(text, who) { var m = el('div', 'm ' + who, text); log.appendChild(m); scroll(); return m; }
  function setChips(list) {
    chips.textContent = '';
    (list || []).slice(0, 6).forEach(function (c) {
      var b = el('button', 'chip', String(c)); b.type = 'button';
      b.addEventListener('click', function () { submit(String(c)); });
      chips.appendChild(b);
    });
    chips.style.display = chips.childNodes.length ? 'flex' : 'none';
  }

  function api(method, path, body) {
    var ctl = ('AbortController' in window) ? new AbortController() : null;
    var timer = ctl ? setTimeout(function () { ctl.abort(); }, 25000) : null;
    return fetch(API + path, {
      method: method, credentials: 'omit', cache: 'no-store',
      headers: body ? { 'Content-Type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : undefined,
      signal: ctl ? ctl.signal : undefined
    }).then(function (r) { if (timer) clearTimeout(timer); return r.json(); });
  }

  function init() {
    if (state.inited) return;
    state.inited = true;
    api('GET', 'init').then(function (d) {
      if (!d || !d.ok) throw new Error('init');
      if (d.bot) h2.textContent = d.bot;
      add(d.greeting || 'שלום, במה אפשר לעזור?', 'b');
      setChips(d.chips);
    }).catch(function () {
      state.inited = false;
      add('השירות לא זמין כרגע. אפשר לפנות אלינו במייל או בוואטסאפ.', 'b');
    });
  }

  function submit(text) {
    text = (text || '').replace(/\s+$/, '');
    if (!text || state.busy) return;
    state.busy = true; send.disabled = true;
    add(text, 'u'); ta.value = ''; fit(); setChips([]);
    var typing = add('מקליד...', 'b');
    api('POST', 'chat', { conv: state.conv, message: text }).then(function (d) {
      typing.remove();
      if (!d || !d.ok) { add((d && d.error) || 'משהו השתבש. נסו שוב בעוד רגע.', 'b'); return; }
      if (d.conv) state.conv = d.conv;
      state.count++;
      add(d.text || '', d.type === 'ticket' ? 't' : 'b');
      setChips(d.chips);
      if (d.type === 'ticket') { state.ticket = true; showRate(); }
    }).catch(function () {
      typing.remove(); add('משהו השתבש בחיבור. נסו שוב בעוד רגע.', 'b');
    }).then(function () { state.busy = false; send.disabled = false; ta.focus(); });
  }

  function showRate() { if (!state.rated && state.conv) rate.classList.add('on'); }
  function sendRating(n) {
    if (!state.conv) return;
    api('POST', 'rate', { conv: state.conv, rating: n }).then(function () {
      state.rated = true; rate.classList.remove('on'); rateLnk.style.display = 'none';
      add('תודה על הדירוג.', 'b');
    }).catch(function () { add('לא הצלחנו לשמור את הדירוג.', 'b'); });
  }

  function fit() { ta.style.height = 'auto'; ta.style.height = Math.min(110, ta.scrollHeight) + 'px'; }

  function toggle(on) {
    state.open = on;
    panel.classList.toggle('on', on);
    fab.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (on) { init(); setTimeout(function () { ta.focus(); }, 30); } else { fab.focus(); }
  }

  fab.addEventListener('click', function () { toggle(!state.open); });
  xb.addEventListener('click', function () { toggle(false); });
  rateLnk.addEventListener('click', function () { if (state.conv && state.count) showRate(); else add('אפשר לדרג אחרי שיחה קצרה.', 'b'); });
  ft.addEventListener('submit', function (e) { e.preventDefault(); submit(ta.value); });
  ta.addEventListener('input', fit);
  ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(ta.value); } });
  root.addEventListener('keydown', function (e) { if (e.key === 'Escape' && state.open) toggle(false); });

  function mount() { if (!document.body) { return setTimeout(mount, 50); } document.body.appendChild(host); }
  mount();
})();
