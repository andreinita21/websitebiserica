/* Biserica Sfântul Vasile — calendar widget (vanilla JS, no build step).
 *
 * Renders two views (agenda list, week) from the events API.
 * Public API:
 *   BsvCalendar.mount(container, { endpoint })
 */

(function (global) {
  'use strict';

  var MONTHS = [
    'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie',
    'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'
  ];
  var MONTHS_SHORT = ['ian','feb','mar','apr','mai','iun','iul','aug','sep','oct','noi','dec'];
  var WEEKDAYS_LONG = ['luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă', 'duminică'];

  /* --- Date helpers (all local-time, no timezone surprises) ---------------- */
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function isoDate(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parseIso(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
    if (!m) return null;
    return new Date(+m[1], +m[2] - 1, +m[3]);
  }
  function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
  function addMonths(d, n) { return new Date(d.getFullYear(), d.getMonth() + n, 1); }
  function addDays(d, n) {
    var r = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    r.setDate(r.getDate() + n);
    return r;
  }
  function sameDay(a, b) {
    return a.getFullYear() === b.getFullYear()
        && a.getMonth() === b.getMonth()
        && a.getDate() === b.getDate();
  }
  /** Monday of the week containing d (local). */
  function startOfWeek(d) {
    var day = d.getDay();                   // 0=Sun..6=Sat
    var diff = (day === 0 ? -6 : 1 - day);  // shift Sunday back 6 days
    return addDays(d, diff);
  }
  function fmtMonthTitle(d)  { return MONTHS[d.getMonth()] + ' ' + d.getFullYear(); }
  function fmtWeekTitle(start) {
    var end = addDays(start, 6);
    if (start.getMonth() === end.getMonth()) {
      return start.getDate() + '–' + end.getDate() + ' ' + MONTHS[start.getMonth()] + ' ' + end.getFullYear();
    }
    return start.getDate() + ' ' + MONTHS_SHORT[start.getMonth()]
         + ' – ' + end.getDate() + ' ' + MONTHS_SHORT[end.getMonth()]
         + ' ' + end.getFullYear();
  }
  function fmtTime(t) {
    if (!t) return '';
    return t.slice(0, 5); // HH:MM
  }
  function fmtTimeRange(s, e) {
    if (!s && !e) return 'Toată ziua';
    if (s && e) return fmtTime(s) + ' – ' + fmtTime(e);
    return fmtTime(s || e);
  }

  /* --- DOM helpers --------------------------------------------------------- */
  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      for (var k in attrs) {
        if (!Object.prototype.hasOwnProperty.call(attrs, k)) continue;
        var v = attrs[k];
        if (k === 'class') node.className = v;
        else if (k === 'html') node.innerHTML = v;
        else if (k === 'text') node.textContent = v;
        else if (k.slice(0, 2) === 'on' && typeof v === 'function') {
          node.addEventListener(k.slice(2).toLowerCase(), v);
        } else if (v === false || v == null) {
          /* skip */
        } else {
          node.setAttribute(k, v);
        }
      }
    }
    if (children) {
      (Array.isArray(children) ? children : [children]).forEach(function (c) {
        if (c == null || c === false) return;
        node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
      });
    }
    return node;
  }

  function icon(name) {
    return el('span', { class: 'material-symbols-outlined', 'aria-hidden': 'true' }, name);
  }

  /* --- Core calendar ------------------------------------------------------- */
  function Calendar(host, options) {
    this.host = host;
    this.endpoint = (options && options.endpoint) || '/api/events.php';
    this.view = 'list';             // 'list' (agendă) | 'week' (săptămână)
    this.cursor = startOfMonth(new Date());
    this.cache = new Map();         // key: 'YYYY-MM' or 'listYYYY-MM' → events[]
    this.eventsByDate = new Map();  // key: 'YYYY-MM-DD' → events[]
    this.dialog = null;

    this._build();
    this._render();
  }

  Calendar.prototype._build = function () {
    this.root = el('div', { class: 'calendar', role: 'region', 'aria-label': 'Calendar evenimente' });

    /* toolbar */
    var prev = el('button', { class: 'calendar__nav-btn', type: 'button', 'aria-label': 'Perioada anterioară' }, icon('chevron_left'));
    var next = el('button', { class: 'calendar__nav-btn', type: 'button', 'aria-label': 'Perioada următoare' }, icon('chevron_right'));
    var today = el('button', { class: 'calendar__today', type: 'button' }, 'Astăzi');

    prev.addEventListener('click', this._prev.bind(this));
    next.addEventListener('click', this._next.bind(this));
    today.addEventListener('click', this._today.bind(this));

    this.titleEl = el('div', { class: 'calendar__title' });

    var views = el('div', { class: 'calendar__views', role: 'tablist', 'aria-label': 'Vizualizare calendar' });
    this.viewButtons = {};
    [['list', 'Agendă'], ['week', 'Săptămână']].forEach(function (pair) {
      var key = pair[0], label = pair[1];
      var btn = el('button', {
        class: 'calendar__view-btn' + (key === this.view ? ' is-active' : ''),
        role: 'tab',
        type: 'button',
        'aria-selected': key === this.view ? 'true' : 'false',
        'data-view': key
      }, label);
      btn.addEventListener('click', this._setView.bind(this, key));
      views.appendChild(btn);
      this.viewButtons[key] = btn;
    }, this);

    var toolbar = el('div', { class: 'calendar__toolbar' }, [
      el('div', { class: 'calendar__nav' }, [prev, today, next]),
      this.titleEl,
      views
    ]);

    this.body = el('div', { class: 'calendar__body' });

    this.root.appendChild(toolbar);
    this.root.appendChild(this.body);

    this.host.appendChild(this.root);
  };

  Calendar.prototype._setView = function (view) {
    if (this.view === view) return;
    this.view = view;
    Object.keys(this.viewButtons).forEach(function (k) {
      var b = this.viewButtons[k];
      b.classList.toggle('is-active', k === view);
      b.setAttribute('aria-selected', k === view ? 'true' : 'false');
    }, this);
    this._render();
  };

  Calendar.prototype._prev = function () {
    if (this.view === 'week') this.cursor = addDays(this.cursor, -7);
    else this.cursor = addMonths(this.cursor, -1);
    this._render();
  };

  Calendar.prototype._next = function () {
    if (this.view === 'week') this.cursor = addDays(this.cursor, 7);
    else this.cursor = addMonths(this.cursor, 1);
    this._render();
  };

  Calendar.prototype._today = function () {
    this.cursor = this.view === 'week' ? new Date() : startOfMonth(new Date());
    this._render();
  };

  Calendar.prototype._render = function () {
    // Update title
    if (this.view === 'week') {
      this.titleEl.textContent = fmtWeekTitle(startOfWeek(this.cursor));
    } else {
      this.titleEl.textContent = 'Agendă · ' + fmtMonthTitle(this.cursor);
    }

    // Show a skeleton while loading
    this.body.innerHTML = '';
    this.body.appendChild(el('div', { class: 'calendar__state' }, [
      el('span', { class: 'material-symbols-outlined icon', 'aria-hidden': 'true' }, 'hourglass_empty'),
      el('h3', {}, 'Se încarcă evenimentele...')
    ]));

    this._fetchRange().then(function () {
      if (this.view === 'week') this._renderWeek();
      else this._renderList();
    }.bind(this)).catch(function () {
      this.body.innerHTML = '';
      this.body.appendChild(el('div', { class: 'calendar__state' }, [
        el('span', { class: 'material-symbols-outlined icon' }, 'cloud_off'),
        el('h3', {}, 'Nu s-au putut încărca evenimentele'),
        el('p', {}, 'Verificați conexiunea sau reveniți mai târziu.')
      ]));
    }.bind(this));
  };

  Calendar.prototype._fetchRange = function () {
    var from, to;
    if (this.view === 'week') {
      var wStart = startOfWeek(this.cursor);
      from = addDays(wStart, -7);
      to   = addDays(wStart,  14);
    } else {
      from = startOfMonth(this.cursor);
      to   = addDays(addMonths(from, 1), -1);
    }
    var key = isoDate(from) + '|' + isoDate(to);
    if (this.cache.has(key)) {
      this._indexEvents(this.cache.get(key));
      return Promise.resolve();
    }
    var url = this.endpoint + '?from=' + isoDate(from) + '&to=' + isoDate(to);
    return fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        var events = (data && data.events) || [];
        this.cache.set(key, events);
        this._indexEvents(events);
      }.bind(this));
  };

  Calendar.prototype._indexEvents = function (events) {
    this.eventsByDate = new Map();
    events.forEach(function (ev) {
      var list = this.eventsByDate.get(ev.date);
      if (!list) { list = []; this.eventsByDate.set(ev.date, list); }
      list.push(ev);
    }, this);
    this.eventsByDate.forEach(function (list) {
      list.sort(function (a, b) {
        return (a.start_time || '').localeCompare(b.start_time || '');
      });
    });
  };

  /* --- Week view ---------------------------------------------------------- */
  Calendar.prototype._renderWeek = function () {
    var start = startOfWeek(this.cursor);
    var today = new Date();
    var frag = el('div', { class: 'calendar__week' });

    for (var i = 0; i < 7; i++) {
      var day = addDays(start, i);
      var iso = isoDate(day);
      var col = el('div', {
        class: 'calendar__week-col' + (sameDay(day, today) ? ' is-today' : '')
      });
      col.appendChild(el('div', { class: 'calendar__week-head' }, [
        el('span', { class: 'calendar__week-day' }, WEEKDAYS_LONG[i]),
        el('span', { class: 'calendar__week-num' }, String(day.getDate()))
      ]));

      var events = this.eventsByDate.get(iso) || [];
      if (events.length === 0) {
        col.appendChild(el('span', { class: 'calendar__state', style: 'padding: var(--s-3) 0; font-size: 0.78rem;' }, '—'));
      } else {
        events.forEach(function (ev) {
          var item = el('button', {
            class: 'calendar__week-event',
            type: 'button',
            'data-cat': ev.category,
            'aria-label': ev.title
          }, [
            el('time', { datetime: iso + 'T' + (ev.start_time || '00:00') }, fmtTimeRange(ev.start_time, ev.end_time)),
            el('h4', {}, ev.title)
          ]);
          item.addEventListener('click', this._openEvent.bind(this, ev));
          col.appendChild(item);
        }, this);
      }
      frag.appendChild(col);
    }

    this.body.innerHTML = '';
    this.body.appendChild(frag);
  };

  /* --- List view ---------------------------------------------------------- */
  Calendar.prototype._renderList = function () {
    var monthStart = startOfMonth(this.cursor);
    var monthEnd = addDays(addMonths(monthStart, 1), -1);
    var frag = el('div', { class: 'calendar__list' });

    var iso;
    var anything = false;
    for (var d = new Date(monthStart); d <= monthEnd; d = addDays(d, 1)) {
      iso = isoDate(d);
      var events = this.eventsByDate.get(iso);
      if (!events || !events.length) continue;
      anything = true;

      var group = el('div', { class: 'calendar__list-group' });
      group.appendChild(el('div', { class: 'calendar__list-date' }, [
        el('span', { class: 'day' }, String(d.getDate())),
        el('span', { class: 'month' }, MONTHS_SHORT[d.getMonth()].toUpperCase()),
        el('span', { class: 'weekday' }, WEEKDAYS_LONG[(d.getDay() + 6) % 7])
      ]));

      var entries = el('div', { class: 'calendar__list-entries' });
      events.forEach(function (ev) {
        var head = el('div', { class: 'calendar__list-item__head' }, [
          el('span', { class: 'calendar__list-item__time' }, fmtTimeRange(ev.start_time, ev.end_time)),
          el('span', { class: 'calendar__list-item__cat' }, ev.category_label || '')
        ]);
        var meta = el('div', { class: 'calendar__list-item__meta' }, [
          ev.location ? el('span', {}, [icon('location_on'), ev.location]) : null
        ]);
        var item = el('article', {
          class: 'calendar__list-item',
          tabindex: '0',
          'data-cat': ev.category
        }, [
          head,
          el('h3', { class: 'calendar__list-item__title' }, ev.title),
          ev.description ? el('p', { class: 'calendar__list-item__desc' }, ev.description) : null,
          meta
        ]);
        item.addEventListener('click', this._openEvent.bind(this, ev));
        item.addEventListener('keydown', function (ev2, e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this._openEvent(ev2); }
        }.bind(this, ev));
        entries.appendChild(item);
      }, this);

      group.appendChild(entries);
      frag.appendChild(group);
    }

    if (!anything) {
      frag.appendChild(el('div', { class: 'calendar__state' }, [
        el('span', { class: 'material-symbols-outlined icon' }, 'event_busy'),
        el('h3', {}, 'Nu există evenimente publicate în această lună'),
        el('p', {}, 'Reveniți în curând — programul se actualizează periodic.')
      ]));
    }

    this.body.innerHTML = '';
    this.body.appendChild(frag);
  };

  /* --- Event dialog -------------------------------------------------------- */
  Calendar.prototype._openEvent = function (ev) {
    if (!this.dialog) this._buildDialog();
    var d = parseIso(ev.date);
    var dateLine = (d ? WEEKDAYS_LONG[(d.getDay() + 6) % 7] + ', ' + d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear() : ev.date);
    this.dialog.querySelector('[data-eyebrow]').textContent = ev.category_label || '';
    this.dialog.querySelector('[data-title]').textContent = ev.title;
    this.dialog.querySelector('[data-date]').textContent = dateLine;
    this.dialog.querySelector('[data-time]').textContent = fmtTimeRange(ev.start_time, ev.end_time);

    var locRow = this.dialog.querySelector('[data-loc-row]');
    if (ev.location) {
      locRow.style.display = '';
      this.dialog.querySelector('[data-loc]').textContent = ev.location;
    } else {
      locRow.style.display = 'none';
    }

    var desc = this.dialog.querySelector('[data-desc]');
    if (ev.description) {
      desc.style.display = '';
      desc.textContent = ev.description;
    } else {
      desc.style.display = 'none';
    }

    if (typeof this.dialog.showModal === 'function') this.dialog.showModal();
    else this.dialog.setAttribute('open', '');
  };

  Calendar.prototype._buildDialog = function () {
    var dlg = el('dialog', { class: 'event-dialog', 'aria-labelledby': 'event-dialog-title' }, [
      el('div', { class: 'event-dialog__head' }, [
        el('button', { class: 'event-dialog__close', type: 'button', 'aria-label': 'Închide', 'data-close': '' }, icon('close')),
        el('div', { class: 'event-dialog__eyebrow', 'data-eyebrow': '' }),
        el('h2', { class: 'event-dialog__title', id: 'event-dialog-title', 'data-title': '' })
      ]),
      el('div', { class: 'event-dialog__body' }, [
        el('div', { class: 'event-dialog__meta' }, [
          icon('event'),
          el('div', {}, [el('span', { class: 'label' }, 'Data'), el('p', { 'data-date': '' })])
        ]),
        el('div', { class: 'event-dialog__meta' }, [
          icon('schedule'),
          el('div', {}, [el('span', { class: 'label' }, 'Ora'), el('p', { 'data-time': '' })])
        ]),
        el('div', { class: 'event-dialog__meta', 'data-loc-row': '' }, [
          icon('location_on'),
          el('div', {}, [el('span', { class: 'label' }, 'Locație'), el('p', { 'data-loc': '' })])
        ]),
        el('p', { class: 'event-dialog__desc', 'data-desc': '' })
      ])
    ]);

    dlg.addEventListener('click', function (e) {
      if (e.target.closest('[data-close]')) dlg.close();
      else if (e.target === dlg) dlg.close(); // backdrop
    });

    document.body.appendChild(dlg);
    this.dialog = dlg;
  };

  /* --- Public API --------------------------------------------------------- */
  global.BsvCalendar = {
    mount: function (container, options) {
      var host = typeof container === 'string' ? document.querySelector(container) : container;
      if (!host) return null;
      return new Calendar(host, options || {});
    }
  };

})(window);
