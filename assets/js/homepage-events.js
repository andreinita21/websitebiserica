/* Homepage upcoming events — loads the next few published events from the
 * JSON feed and renders them using the existing `.announcement` style. If
 * the feed is unreachable (e.g. the site is served without PHP), the static
 * markup that is already inside the container is kept as a graceful fallback.
 */

(function () {
  'use strict';

  var MONTHS_SHORT = ['Ian', 'Feb', 'Mar', 'Apr', 'Mai', 'Iun', 'Iul', 'Aug', 'Sep', 'Oct', 'Noi', 'Dec'];
  var MONTHS_LONG  = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie',
                      'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
  var WEEKDAYS     = ['Duminică', 'Luni', 'Marți', 'Miercuri', 'Joi', 'Vineri', 'Sâmbătă'];

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function parseIso(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
  }
  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function fmtTimeRange(s, e) {
    if (!s && !e) return 'Toată ziua';
    if (s && e) return s.slice(0, 5) + ' – ' + e.slice(0, 5);
    return (s || e).slice(0, 5);
  }

  function renderSkeleton(container) {
    container.innerHTML =
      '<div class="upcoming-events__skeleton" aria-hidden="true">' +
        '<div class="bone"></div><div class="bone"></div><div class="bone"></div>' +
      '</div>';
  }

  function renderEmpty(container) {
    container.innerHTML =
      '<p class="upcoming-events__empty" style="grid-column: 1 / -1;">' +
        'Momentan nu există evenimente programate. ' +
        '<a href="calendar.html" style="color: var(--c-gold);">Vezi calendarul complet</a>.' +
      '</p>';
  }

  function renderServiceCards(container, events) {
    if (!events || !events.length) {
      renderEmpty(container);
      return;
    }

    var html = events.map(function (ev) {
      var d = parseIso(ev.date);
      var timeLine = fmtTimeRange(ev.start_time, ev.end_time);
      var weekday = d ? WEEKDAYS[d.getDay()] : '';
      var datePart = d ? (d.getDate() + ' ' + MONTHS_LONG[d.getMonth()]) : '';
      var metaLine = [weekday, datePart, timeLine].filter(Boolean).join(' · ');

      var description = ev.description || '';
      if (description.length > 220) description = description.slice(0, 217).trim() + '…';

      return (
        '<article class="service-card is-visible">' +
          '<h3 class="service-card__title">' + escapeHtml(ev.title) + '</h3>' +
          '<p class="service-card__desc">' + escapeHtml(description) + '</p>' +
          '<div class="service-card__meta">' +
            '<span class="material-symbols-outlined" aria-hidden="true">schedule</span>' +
            '<span>' + escapeHtml(metaLine) + '</span>' +
          '</div>' +
        '</article>'
      );
    }).join('');

    container.innerHTML = html;
  }

  function renderEvents(container, events) {
    if (!events || !events.length) {
      renderEmpty(container);
      return;
    }

    var html = events.map(function (ev) {
      var d = parseIso(ev.date);
      var day = d ? pad(d.getDate()) : '--';
      var month = d ? MONTHS_SHORT[d.getMonth()] : '';
      var timeLine = fmtTimeRange(ev.start_time, ev.end_time);
      var fullDate = d ? (d.getDate() + ' ' + MONTHS_LONG[d.getMonth()]) : '';

      var description = ev.description || '';
      if (description.length > 180) description = description.slice(0, 177).trim() + '…';

      return (
        '<article class="announcement">' +
          '<div class="announcement__date" aria-hidden="true">' +
            '<span class="day">' + escapeHtml(day) + '</span>' +
            '<span class="month">' + escapeHtml(month) + '</span>' +
          '</div>' +
          '<div class="announcement__body">' +
            '<span class="tag">' + escapeHtml(ev.category_label || 'Eveniment') + '</span>' +
            '<h3 class="announcement__title">' + escapeHtml(ev.title) + '</h3>' +
            '<p class="announcement__desc">' +
              '<strong style="color: var(--c-gold); font-weight: 700;">' +
                escapeHtml(fullDate) + ' · ' + escapeHtml(timeLine) +
              '</strong>' +
              (description
                ? '<br><span style="display:inline-block; margin-top:6px;">' + escapeHtml(description) + '</span>'
                : '') +
              (ev.location
                ? '<br><span style="display:inline-block; margin-top:6px; color: var(--c-snow-muted); font-size: 0.88rem;">' +
                    '📍 ' + escapeHtml(ev.location) +
                  '</span>'
                : '') +
            '</p>' +
          '</div>' +
        '</article>'
      );
    }).join('');

    container.innerHTML = html;
  }

  function load(container) {
    var limit = parseInt(container.getAttribute('data-limit') || '3', 10);
    var endpoint = container.getAttribute('data-endpoint') || 'api/events.php';
    var variant = container.getAttribute('data-variant') || 'announcement';
    var recurrence = container.getAttribute('data-recurrence') || '';
    var distinct = container.getAttribute('data-distinct') === '1';

    var params = ['upcoming=1', 'limit=' + encodeURIComponent(limit)];
    if (recurrence) params.push('recurrence=' + encodeURIComponent(recurrence));
    if (distinct) params.push('distinct=1');
    var url = endpoint + '?' + params.join('&');

    var renderer = variant === 'service-card' ? renderServiceCards : renderEvents;

    renderSkeleton(container);

    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        renderer(container, (data && data.events) || []);
      })
      .catch(function () {
        // Leave whatever static fallback was there before; if we already
        // replaced it with a skeleton, at least render an empty-state.
        if (container.querySelector('.upcoming-events__skeleton')) {
          renderEmpty(container);
        }
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var containers = document.querySelectorAll('[data-upcoming-events]');
    containers.forEach(load);
  });
})();
