/* Homepage written announcements — loads published announcements from the
 * JSON feed and renders them using the `.announcement` style. Independent
 * from the events/calendar feed; content is managed via admin/announcements.
 */

(function () {
  'use strict';

  var MONTHS_SHORT = ['Ian', 'Feb', 'Mar', 'Apr', 'Mai', 'Iun', 'Iul', 'Aug', 'Sep', 'Oct', 'Noi', 'Dec'];

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

  function renderSkeleton(container) {
    container.innerHTML =
      '<div class="upcoming-events__skeleton" aria-hidden="true">' +
        '<div class="bone"></div><div class="bone"></div><div class="bone"></div>' +
      '</div>';
  }

  function renderEmpty(container) {
    container.innerHTML =
      '<p class="upcoming-events__empty">' +
        'Momentan nu există anunțuri noi.' +
      '</p>';
  }

  function renderBadge(a) {
    var mode = a.date_mode || 'single';

    if (mode === 'duration') {
      return '';
    }

    if (mode === 'interval') {
      var dStart = parseIso(a.relevant_on);
      var dEnd   = parseIso(a.relevant_until);
      if (!dStart || !dEnd) return renderBadgeSingle(a);
      var startDay   = pad(dStart.getDate());
      var startMonth = MONTHS_SHORT[dStart.getMonth()];
      var endDay     = pad(dEnd.getDate());
      var endMonth   = MONTHS_SHORT[dEnd.getMonth()];
      var aria = 'Valabil între ' + startDay + ' ' + startMonth + ' și ' + endDay + ' ' + endMonth;
      return (
        '<div class="announcement__date announcement__date--interval" aria-label="' + escapeHtml(aria) + '">' +
          '<span class="range">' +
            '<span class="range__from">' + escapeHtml(startDay) + ' ' + escapeHtml(startMonth) + '</span>' +
            '<span class="range__sep" aria-hidden="true">–</span>' +
            '<span class="range__to">' + escapeHtml(endDay) + ' ' + escapeHtml(endMonth) + '</span>' +
          '</span>' +
        '</div>'
      );
    }

    return renderBadgeSingle(a);
  }

  function renderBadgeSingle(a) {
    var d = parseIso(a.relevant_on);
    var day = d ? pad(d.getDate()) : '--';
    var month = d ? MONTHS_SHORT[d.getMonth()] : '';
    return (
      '<div class="announcement__date" aria-label="Valabil la ' + escapeHtml(day) + ' ' + escapeHtml(month) + '">' +
        '<span class="day">' + escapeHtml(day) + '</span>' +
        '<span class="month">' + escapeHtml(month) + '</span>' +
      '</div>'
    );
  }

  function sortItems(items) {
    // Duration-mode announcements are time-bound without a fixed date, so they
    // surface first. Remaining items keep the server-side date ordering.
    var duration = [];
    var rest     = [];
    items.forEach(function (a) {
      if ((a.date_mode || 'single') === 'duration') duration.push(a);
      else rest.push(a);
    });
    return duration.concat(rest);
  }

  function renderAnnouncements(container, items) {
    if (!items || !items.length) {
      renderEmpty(container);
      return;
    }

    container.innerHTML = sortItems(items).map(function (a) {
      var body = a.body || '';
      if (body.length > 260) body = body.slice(0, 257).trim() + '…';
      var hasBadge = (a.date_mode || 'single') !== 'duration';
      var articleClass = 'announcement' + (hasBadge ? '' : ' announcement--no-date');

      return (
        '<article class="' + articleClass + '">' +
          renderBadge(a) +
          '<div class="announcement__body">' +
            (a.tag ? '<span class="tag">' + escapeHtml(a.tag) + '</span>' : '') +
            '<h3 class="announcement__title">' + escapeHtml(a.title) + '</h3>' +
            (body ? '<p class="announcement__desc">' + escapeHtml(body) + '</p>' : '') +
          '</div>' +
        '</article>'
      );
    }).join('');
  }

  function load(container) {
    var limit = parseInt(container.getAttribute('data-limit') || '3', 10);
    var endpoint = container.getAttribute('data-endpoint') || 'api/announcements.php';
    var url = endpoint + '?limit=' + encodeURIComponent(limit);

    renderSkeleton(container);

    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        renderAnnouncements(container, (data && data.announcements) || []);
      })
      .catch(function () {
        if (container.querySelector('.upcoming-events__skeleton')) {
          renderEmpty(container);
        }
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var containers = document.querySelectorAll('[data-announcements]');
    containers.forEach(load);
  });
})();
