/* SHEM support bot - admin app. Vanilla JS, DOM built with textContent only. */
(function () {
  'use strict';
  var C = window.SSB_ADMIN || {};
  var app = document.getElementById('ssb-app');
  if (!app) return;

  function h(tag, attrs) {
    var e = document.createElement(tag);
    if (attrs) for (var k in attrs) {
      if (k === 'class') e.className = attrs[k];
      else if (k === 'text') e.textContent = attrs[k];
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] !== false && attrs[k] != null) e.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    }
    for (var i = 2; i < arguments.length; i++) {
      var c = arguments[i];
      if (c == null || c === false) continue;
      if (Array.isArray(c)) c.forEach(function (x) { if (x) e.appendChild(typeof x === 'string' ? document.createTextNode(x) : x); });
      else e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return e;
  }
  function api(method, path, body) {
    return fetch(C.api + '?rest_route=/ssb/v1/' + path, {
      method: method, credentials: 'same-origin',
      headers: Object.assign({ 'X-WP-Nonce': C.nonce }, body ? { 'Content-Type': 'application/json' } : {}),
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'תשובה לא תקינה מהשרת' }; }); });
  }
  function toast(msg, bad) {
    var t = h('div', { class: 'ssb-toast' + (bad ? ' bad' : ''), role: 'status', text: msg });
    document.body.appendChild(t); setTimeout(function () { t.remove(); }, 3500);
  }
  function fmtDate(s) { if (!s) return ''; var d = new Date(s.replace(' ', 'T') + 'Z'); return isNaN(d) ? s : d.toLocaleString('he-IL', { timeZone: 'Asia/Jerusalem', dateStyle: 'short', timeStyle: 'short' }); }
  function fmtSla(t) {
    if (t.status === 'closed') return '-';
    if (t.sla_remaining == null) return '';
    var s = Math.abs(t.sla_remaining), hh = Math.floor(s / 3600), mm = Math.floor((s % 3600) / 60);
    var txt = hh + ':' + (mm < 10 ? '0' : '') + mm;
    return t.sla_overdue ? 'באיחור ' + txt : txt;
  }

  var TABS = [['brain', 'מוח'], ['tickets', 'פניות'], ['dash', 'דשבורד'], ['unans', 'שאלות שלא נענו'], ['settings', 'הגדרות']];
  var st = { tab: (location.hash || '#brain').slice(1), prefill: null };
  if (!TABS.some(function (t) { return t[0] === st.tab; })) st.tab = 'brain';

  var nav = h('div', { class: 'ssb-tabs', role: 'tablist' });
  var view = h('div', { class: 'ssb-view' });
  app.textContent = ''; app.appendChild(nav); app.appendChild(view);

  function renderNav() {
    nav.textContent = '';
    TABS.forEach(function (t) {
      nav.appendChild(h('button', {
        type: 'button', role: 'tab', 'aria-selected': st.tab === t[0] ? 'true' : 'false', class: 'ssb-tab' + (st.tab === t[0] ? ' on' : ''), text: t[1],
        onclick: function () { go(t[0]); }
      }));
    });
  }
  function go(tab, prefill) {
    st.tab = tab; st.prefill = prefill || null; location.hash = tab; renderNav();
    view.textContent = '';
    ({ brain: brainTab, tickets: ticketsTab, dash: dashTab, unans: unansTab, settings: settingsTab })[tab]();
  }

  /* ---------------------------------------------------------------- brain */
  function brainTab() {
    var secSel = h('select', { 'aria-label': 'קטגוריה' }, h('option', { value: '', text: 'כל הקטגוריות' }));
    Object.keys(C.sections).forEach(function (k) { secSel.appendChild(h('option', { value: k, text: C.sections[k] })); });
    var q = h('input', { type: 'search', placeholder: 'חיפוש בכותרת / תוכן', 'aria-label': 'חיפוש' });
    var list = h('div', { class: 'ssb-list' });
    var form = h('div', { class: 'ssb-card' });
    var items = [];

    function paint() {
      var sec = secSel.value, qq = q.value.trim().toLowerCase();
      list.textContent = '';
      var rows = items.filter(function (r) { return (!sec || r.section === sec) && (!qq || (r.title + ' ' + r.body + ' ' + (r.keywords || '')).toLowerCase().indexOf(qq) > -1); });
      if (!rows.length) list.appendChild(h('p', { class: 'ssb-muted', text: 'אין רשומות' }));
      var tb = h('tbody');
      rows.forEach(function (r) {
        tb.appendChild(h('tr', { class: r.active == 1 ? '' : 'off' },
          h('td', null, h('button', { type: 'button', class: 'link', text: r.title, onclick: function () { edit(r); } })),
          h('td', { text: C.sections[r.section] ? C.sections[r.section].split(' (')[0] : r.section }),
          h('td', { text: r.active == 1 ? 'פעיל' : 'כבוי' }),
          h('td', { text: 'v' + r.version }),
          h('td', { text: (r.updated_by || '') + ' ' + fmtDate(r.updated_at) })));
      });
      list.appendChild(h('table', { class: 'ssb-table' }, h('thead', null, h('tr', null, h('th', { text: 'כותרת' }), h('th', { text: 'קטגוריה' }), h('th', { text: 'מצב' }), h('th', { text: 'גרסה' }), h('th', { text: 'עודכן' }))), tb));
    }
    function load() { api('GET', 'admin/brain').then(function (d) { if (!d.ok) return toast(d.error || 'שגיאה', 1); items = d.items; paint(); }); }

    function edit(r) {
      r = r || { section: secSel.value || 'faq', title: '', keywords: '', body: '', meta: '', active: 1 };
      form.textContent = '';
      var f = {
        section: h('select', { id: 'f-sec' }),
        slug: h('input', { id: 'f-slug', value: r.slug || '', readonly: !!r.id, dir: 'ltr' }),
        title: h('input', { id: 'f-title', value: r.title }),
        keywords: h('textarea', { id: 'f-kw', rows: 2 }),
        body: h('textarea', { id: 'f-body', rows: 9 }),
        meta: h('textarea', { id: 'f-meta', rows: 2, dir: 'ltr' }),
        active: h('input', { id: 'f-act', type: 'checkbox' })
      };
      Object.keys(C.sections).forEach(function (k) { var o = h('option', { value: k, text: C.sections[k] }); if (k === r.section) o.selected = true; f.section.appendChild(o); });
      f.keywords.value = r.keywords || ''; f.body.value = r.body || ''; f.meta.value = r.meta || ''; f.active.checked = r.active == 1;
      function row(lbl, id, el, hint) { return h('p', null, h('label', { for: id, text: lbl }), el, hint ? h('span', { class: 'ssb-muted', text: hint }) : null); }
      form.appendChild(h('h2', { text: r.id ? 'עריכה: ' + r.title : 'רשומה חדשה' }));
      form.appendChild(row('קטגוריה', 'f-sec', f.section));
      if (!r.id) form.appendChild(row('מזהה (לא חובה, אנגלית)', 'f-slug', f.slug, 'משמש משפטי מערכת ושלבי הזמנה'));
      form.appendChild(row('כותרת / שאלה', 'f-title', f.title));
      form.appendChild(row('מילות מפתח', 'f-kw', f.keywords, 'מופרדות בפסיק. עוזרות לבוט למצוא את הרשומה'));
      form.appendChild(row('תוכן / תשובה', 'f-body', f.body, 'הבוט יענה רק מהטקסט הזה'));
      form.appendChild(row('מידע נוסף (JSON, לא חובה)', 'f-meta', f.meta, 'לדוגמה סטטוס הזמנה: {"wc_status":"wc-processing"}'));
      form.appendChild(h('p', null, f.active, h('label', { for: 'f-act', text: ' פעיל' })));
      var actions = h('p', { class: 'ssb-actions' },
        h('button', {
          type: 'button', class: 'button button-primary', text: 'שמירה (נכנס לתוקף מיד)', onclick: function () {
            var body = { id: r.id || 0, section: f.section.value, slug: f.slug.value, title: f.title.value, keywords: f.keywords.value, body: f.body.value, meta: f.meta.value, active: f.active.checked ? 1 : 0 };
            api('POST', 'admin/brain', body).then(function (d) { if (!d.ok) return toast(d.error || 'שגיאה', 1); toast('נשמר. גרסה ' + d.item.version); form.textContent = ''; load(); });
          }
        }));
      if (r.id) {
        actions.appendChild(h('button', { type: 'button', class: 'button', text: 'היסטוריית גרסאות', onclick: function () { versions(r); } }));
        actions.appendChild(h('button', {
          type: 'button', class: 'button', text: r.active == 1 ? 'כיבוי' : 'הפעלה', onclick: function () {
            api('POST', 'admin/brain/' + r.id + '/active', { active: r.active == 1 ? 0 : 1 }).then(function (d) { if (!d.ok) return toast(d.error, 1); toast('עודכן'); form.textContent = ''; load(); });
          }
        }));
      }
      actions.appendChild(h('button', { type: 'button', class: 'button', text: 'ביטול', onclick: function () { form.textContent = ''; } }));
      form.appendChild(actions);
      form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function versions(r) {
      api('GET', 'admin/brain/' + r.id + '/versions').then(function (d) {
        if (!d.ok) return toast(d.error, 1);
        var box = h('div', { class: 'ssb-card' }, h('h3', { text: 'גרסאות: ' + r.title }));
        d.versions.forEach(function (v) {
          box.appendChild(h('div', { class: 'ssb-ver' },
            h('strong', { text: 'v' + v.version + ' ' }), h('span', { text: v.action + ' - ' + (v.editor || '') + ' - ' + fmtDate(v.created_at) }),
            h('pre', { text: v.body }),
            h('button', {
              type: 'button', class: 'button', text: 'שחזור לגרסה הזו', onclick: function () {
                api('POST', 'admin/brain/' + r.id + '/restore', { version: v.version }).then(function (x) { if (!x.ok) return toast(x.error, 1); toast('שוחזר (נשמר כגרסה חדשה)'); form.textContent = ''; load(); });
              }
            })));
        });
        form.textContent = ''; form.appendChild(box);
      });
    }

    view.appendChild(h('div', { class: 'ssb-bar' }, secSel, q, h('button', { type: 'button', class: 'button button-primary', text: '+ רשומה חדשה', onclick: function () { edit(null); } })));
    secSel.addEventListener('change', paint); q.addEventListener('input', paint);
    view.appendChild(list); view.appendChild(form);
    load();
    if (st.prefill) edit(st.prefill);
  }

  /* -------------------------------------------------------------- tickets */
  function ticketsTab() {
    var stSel = h('select', { 'aria-label': 'סטטוס' }, h('option', { value: '', text: 'כל הסטטוסים' }));
    Object.keys(C.statuses).forEach(function (k) { stSel.appendChild(h('option', { value: k, text: C.statuses[k] })); });
    var prSel = h('select', { 'aria-label': 'עדיפות' }, h('option', { value: '', text: 'כל העדיפויות' }));
    Object.keys(C.priorities).forEach(function (k) { prSel.appendChild(h('option', { value: k, text: C.priorities[k] })); });
    var q = h('input', { type: 'search', placeholder: 'מספר פנייה / שם / טלפון / הזמנה', 'aria-label': 'חיפוש' });
    var list = h('div', { class: 'ssb-list' }), detail = h('div', { class: 'ssb-card' });

    function load() {
      var qs = 'admin/tickets&status=' + encodeURIComponent(stSel.value) + '&priority=' + encodeURIComponent(prSel.value) + '&q=' + encodeURIComponent(q.value);
      api('GET', qs).then(function (d) {
        if (!d.ok) return toast(d.error, 1);
        list.textContent = '';
        if (!d.tickets.length) { list.appendChild(h('p', { class: 'ssb-muted', text: 'אין פניות' })); return; }
        var tb = h('tbody');
        d.tickets.forEach(function (t) {
          tb.appendChild(h('tr', { class: 'pr-' + t.priority + (t.sla_overdue ? ' late' : '') },
            h('td', null, h('button', { type: 'button', class: 'link', text: t.number, onclick: function () { open(t.id); } })),
            h('td', { text: t.name }), h('td', { text: t.reason }), h('td', { text: t.priority_label }), h('td', { text: t.status_label }),
            h('td', { text: fmtSla(t) }), h('td', { text: t.assignee || '-' }), h('td', { text: fmtDate(t.created_at) })));
        });
        list.appendChild(h('table', { class: 'ssb-table' }, h('thead', null, h('tr', null, ['מספר', 'שם', 'סיבה', 'עדיפות', 'סטטוס', 'SLA', 'מטפל', 'נפתח'].map(function (x) { return h('th', { text: x }); }))), tb));
      });
    }
    function open(id) {
      api('GET', 'admin/tickets/' + id).then(function (d) {
        if (!d.ok) return toast(d.error, 1);
        var t = d.ticket; detail.textContent = '';
        detail.appendChild(h('h2', { text: 'פנייה ' + t.number }));
        detail.appendChild(h('p', null, h('strong', { text: t.name }), ' | ', h('span', { dir: 'ltr', text: t.contact }), t.order_number ? ' | הזמנה ' + t.order_number : ''));
        detail.appendChild(h('p', { text: 'סיבה: ' + t.reason + ' | SLA: ' + fmtSla(t) }));
        detail.appendChild(h('blockquote', { text: t.summary || '' }));
        var ss = h('select', { id: 't-st' }); Object.keys(C.statuses).forEach(function (k) { var o = h('option', { value: k, text: C.statuses[k] }); if (k === t.status) o.selected = true; ss.appendChild(o); });
        var ps = h('select', { id: 't-pr' }); Object.keys(C.priorities).forEach(function (k) { var o = h('option', { value: k, text: C.priorities[k] }); if (k === t.priority) o.selected = true; ps.appendChild(o); });
        var as = h('input', { id: 't-as', value: t.assignee || '', placeholder: 'שם המטפל' });
        detail.appendChild(h('div', { class: 'ssb-bar' }, h('label', { for: 't-st', text: 'סטטוס' }), ss, h('label', { for: 't-pr', text: 'עדיפות' }), ps, h('label', { for: 't-as', text: 'מטפל' }), as,
          h('button', { type: 'button', class: 'button', text: 'עדכון', onclick: function () { api('POST', 'admin/tickets/' + id, { status: ss.value, priority: ps.value, assignee: as.value }).then(function (x) { if (!x.ok) return toast(x.error, 1); toast('עודכן'); open(id); load(); }); } })));
        var nl = h('div', { class: 'ssb-notes' });
        d.notes.forEach(function (n) { nl.appendChild(h('div', { class: 'note k-' + n.kind }, h('small', { text: n.kind + ' | ' + (n.author || '') + ' | ' + fmtDate(n.created_at) }), h('pre', { text: n.body }))); });
        detail.appendChild(h('h3', { text: 'שרשור והערות' })); detail.appendChild(nl);
        var rep = h('textarea', { rows: 4, 'aria-label': 'תשובה ללקוח', placeholder: 'תשובה ללקוח (תישלח במייל כשהמייל חי, ותירשם בשרשור)' });
        var note = h('textarea', { rows: 2, 'aria-label': 'הערה פנימית', placeholder: 'הערה פנימית (הלקוח לא רואה)' });
        detail.appendChild(h('p', null, rep, h('button', { type: 'button', class: 'button button-primary', text: 'שליחת תשובה ללקוח', onclick: function () { api('POST', 'admin/tickets/' + id + '/reply', { body: rep.value }).then(function (x) { if (!x.ok) return toast(x.error, 1); toast('התשובה נשמרה'); open(id); load(); }); } })));
        detail.appendChild(h('p', null, note, h('button', { type: 'button', class: 'button', text: 'שמירת הערה פנימית', onclick: function () { api('POST', 'admin/tickets/' + id + '/note', { body: note.value }).then(function (x) { if (!x.ok) return toast(x.error, 1); toast('נשמר'); open(id); }); } })));
        if (d.history.length > 1) {
          detail.appendChild(h('h3', { text: 'היסטוריית הלקוח' }));
          detail.appendChild(h('ul', null, d.history.map(function (x) { return h('li', { text: x.number + ' - ' + x.reason + ' - ' + x.status + ' - ' + fmtDate(x.created_at) }); })));
        }
        detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      });
    }
    view.appendChild(h('div', { class: 'ssb-bar' }, stSel, prSel, q, h('button', { type: 'button', class: 'button', text: 'סינון', onclick: load }),
      h('button', { type: 'button', class: 'button', text: 'סימון התראות כנקראו', onclick: function () { api('POST', 'admin/notices/seen').then(function () { toast('סומן'); }); } })));
    view.appendChild(list); view.appendChild(detail);
    stSel.addEventListener('change', load); prSel.addEventListener('change', load);
    api('GET', 'admin/notices').then(function (d) {
      if (d && d.ok && d.unseen > 0) view.insertBefore(h('div', { class: 'notice notice-warning', role: 'alert' }, h('p', { text: 'יש ' + d.unseen + ' התראות חדשות על פניות.' })), view.firstChild);
    });
    load();
  }

  /* ------------------------------------------------------------ dashboard */
  function dashTab() {
    var days = h('select', { 'aria-label': 'טווח' }, [7, 30, 90, 365].map(function (n) { var o = h('option', { value: n, text: n + ' ימים' }); if (n === 30) o.selected = true; return o; }));
    var box = h('div');
    function load() {
      api('GET', 'admin/dashboard&days=' + days.value).then(function (d) {
        if (!d.ok) return toast(d.error, 1);
        box.textContent = '';
        function stat(l, v) { return h('div', { class: 'ssb-stat' }, h('div', { class: 'v', text: String(v) }), h('div', { text: l })); }
        var pct = d.conversations ? Math.round(100 * d.resolved_by_bot / d.conversations) + '%' : '-';
        box.appendChild(h('div', { class: 'ssb-stats' }, stat('שיחות', d.conversations), stat('נענו על ידי הבוט', d.resolved_by_bot + ' (' + pct + ')'), stat('הועברו לנציג', d.escalated),
          stat('פניות באיחור SLA', d.tickets_overdue), stat('דירוג ממוצע', d.rating_avg == null ? '-' : d.rating_avg + ' (' + d.rating_count + ')')));
        box.appendChild(h('h3', { text: 'השאלות הנפוצות' }));
        box.appendChild(h('ol', null, d.top_questions.length ? d.top_questions.map(function (x) { return h('li', { text: x.text + ' (' + x.n + ')' }); }) : [h('li', { text: 'אין נתונים עדיין' })]));
        box.appendChild(h('h3', { text: 'פניות לפי סטטוס' }));
        box.appendChild(h('ul', null, d.tickets_by_status.map(function (x) { return h('li', { text: (C.statuses[x.status] || x.status) + ': ' + x.n }); })));
        box.appendChild(h('p', null, h('button', { type: 'button', class: 'button', text: 'שאלות שלא נענו (' + d.unanswered.length + ')', onclick: function () { go('unans'); } })));
      });
    }
    days.addEventListener('change', load);
    view.appendChild(h('div', { class: 'ssb-bar' }, h('label', { text: 'טווח' }), days)); view.appendChild(box); load();
  }

  /* ----------------------------------------------------------- unanswered */
  function unansTab() {
    var box = h('div');
    function load() {
      api('GET', 'admin/dashboard&days=365').then(function (d) {
        if (!d.ok) return toast(d.error, 1);
        box.textContent = '';
        if (!d.unanswered.length) { box.appendChild(h('p', { class: 'ssb-muted', text: 'אין שאלות פתוחות שלא נענו' })); return; }
        var tb = h('tbody');
        d.unanswered.forEach(function (u) {
          tb.appendChild(h('tr', null, h('td', { text: u.question }), h('td', { text: String(u.hits) }), h('td', { text: fmtDate(u.last_seen) }),
            h('td', null,
              h('button', { type: 'button', class: 'button', text: 'הוספה למוח', onclick: function () { api('POST', 'admin/unanswered/' + u.id, { status: 'added' }); go('brain', { section: 'faq', title: u.question, keywords: '', body: '', meta: '', active: 1 }); } }),
              h('button', { type: 'button', class: 'button', text: 'התעלמות', onclick: function () { api('POST', 'admin/unanswered/' + u.id, { status: 'ignored' }).then(load); } }))));
        });
        box.appendChild(h('table', { class: 'ssb-table' }, h('thead', null, h('tr', null, ['שאלה', 'פעמים', 'אחרונה', 'פעולה'].map(function (x) { return h('th', { text: x }); }))), tb));
      });
    }
    view.appendChild(h('p', { class: 'ssb-muted', text: 'שאלות שהבוט לא ידע לענות עליהן. הוסיפו תשובה למוח והבוט יענה מיד.' })); view.appendChild(box); load();
  }

  /* ------------------------------------------------------------- settings */
  function settingsTab() {
    var box = h('div', { class: 'ssb-card' });
    api('GET', 'admin/settings').then(function (d) {
      if (!d.ok) return toast(d.error, 1);
      var s = d.settings, f = {};
      function field(key, label, type, hint) {
        f[key] = h('input', { id: 's-' + key, type: type || 'text', value: s[key] == null ? '' : s[key], dir: type === 'email' ? 'ltr' : null });
        return h('p', null, h('label', { for: 's-' + key, text: label }), f[key], hint ? h('span', { class: 'ssb-muted', text: hint }) : null);
      }
      var en = h('input', { id: 's-enabled', type: 'checkbox' }); en.checked = !!+s.enabled;
      box.appendChild(h('p', null, en, h('label', { for: 's-enabled', text: ' הבוט פעיל באתר' })));
      box.appendChild(field('bot_name', 'שם הבוט'));
      box.appendChild(field('manager_email', 'מייל מנהל להתראות', 'email', d.mail_live ? 'שליחת מייל אמיתית פעילה' : 'בסביבת הבדיקות מיילים נרשמים ביומן בלבד ואינם נשלחים'));
      box.appendChild(field('wa_number', 'וואטסאפ נציג השירות (ספרות בלבד, עם קידומת מדינה)', 'text', 'ריק כרגע. פניות שהבוט לא ידע לענות עליהן נרשמות בתור ללא שליחה עד שיוגדר מספר ויחובר ערוץ שליחה'));
      box.appendChild(field('retention_days', 'שמירת יומני שיחה (ימים)', 'number', 'פניות נשמרות. רק תמלילי שיחה נמחקים'));
      box.appendChild(field('rate_msgs_per_10min', 'מגבלת הודעות ללקוח ל-10 דקות', 'number'));
      box.appendChild(field('rate_tickets_per_hour', 'מגבלת פניות לשעה ללקוח', 'number'));
      var sla = {};
      ['urgent', 'high', 'normal', 'low'].forEach(function (k) {
        sla[k] = h('input', { id: 's-sla-' + k, type: 'number', value: s.sla_hours[k] });
        box.appendChild(h('p', null, h('label', { for: 's-sla-' + k, text: 'SLA תגובה ראשונה (שעות עבודה) - ' + C.priorities[k] }), sla[k]));
      });
      var hr = {
        sun: h('input', { id: 's-h1', value: s.hours['sun-thu'].join('-'), dir: 'ltr' }),
        fri: h('input', { id: 's-h2', value: s.hours.fri ? s.hours.fri.join('-') : '', dir: 'ltr' })
      };
      box.appendChild(h('p', null, h('label', { for: 's-h1', text: 'שעות ראשון-חמישי (HH:MM-HH:MM)' }), hr.sun));
      box.appendChild(h('p', null, h('label', { for: 's-h2', text: 'שעות שישי (ריק = סגור)' }), hr.fri));
      box.appendChild(h('p', { class: 'ssb-actions' },
        h('button', {
          type: 'button', class: 'button button-primary', text: 'שמירת הגדרות', onclick: function () {
            function pr(v) { var m = /^(\d\d:\d\d)-(\d\d:\d\d)$/.exec(v.trim()); return m ? [m[1], m[2]] : null; }
            var body = {
              enabled: en.checked ? 1 : 0, bot_name: f.bot_name.value, manager_email: f.manager_email.value, wa_number: f.wa_number.value, retention_days: f.retention_days.value,
              rate_msgs_per_10min: f.rate_msgs_per_10min.value, rate_tickets_per_hour: f.rate_tickets_per_hour.value,
              sla_hours: { urgent: sla.urgent.value, high: sla.high.value, normal: sla.normal.value, low: sla.low.value },
              hours: { 'sun-thu': pr(hr.sun.value), fri: hr.fri.value.trim() === '' ? null : pr(hr.fri.value) }
            };
            if (!body.hours['sun-thu'] || (hr.fri.value.trim() !== '' && !body.hours.fri)) return toast('פורמט שעות לא תקין', 1);
            api('POST', 'admin/settings', body).then(function (x) { if (!x.ok) return toast(x.error, 1); toast('נשמר'); });
          }
        }),
        h('button', { type: 'button', class: 'button', text: 'ניקוי שיחות ישנות עכשיו', onclick: function () { if (confirm('למחוק תמלילי שיחה ישנים מעבר לתקופת השמירה? פניות לא נמחקות.')) api('POST', 'admin/purge').then(function (x) { toast(x.ok ? 'נמחקו ' + x.removed + ' רשומות שיחה' : x.error, !x.ok); }); } })));
      view.appendChild(box);
    });
  }

  go(st.tab);
})();
