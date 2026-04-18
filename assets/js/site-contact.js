/* Site-wide contact details loader. Reads /api/contact.php once per page and
 * populates any element marked with [data-contact="<key>"]. The exact key
 * decides whether it sets text, href, or iframe src — see fillSlot() below.
 *
 * Falls back silently to whatever static markup the HTML already contains, so
 * the site keeps working even when served without PHP (e.g. github-pages
 * preview).
 */
(function () {
  'use strict';

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function multilineHtml(text) {
    return escapeHtml(text).replace(/\r?\n/g, '<br>');
  }

  function fillSlot(el, c) {
    var key = el.getAttribute('data-contact');
    switch (key) {
      case 'address':
        el.innerHTML = multilineHtml(c.contact_address);
        break;
      case 'schedule_visiting':
        el.innerHTML = multilineHtml(c.contact_schedule_visiting);
        break;
      case 'schedule_liturgy':
        el.innerHTML = multilineHtml(c.contact_schedule_liturgy);
        break;
      case 'phone_display':
        el.textContent = c.contact_phone_display;
        break;
      case 'email':
        el.textContent = c.contact_email;
        break;
      case 'phone_link':
        if (c.contact_phone_link) el.setAttribute('href', 'tel:' + c.contact_phone_link);
        if (!el.hasAttribute('data-contact-keep-text')) {
          el.textContent = c.contact_phone_display || c.contact_phone_link;
        }
        break;
      case 'email_link':
        if (c.contact_email) el.setAttribute('href', 'mailto:' + c.contact_email);
        if (!el.hasAttribute('data-contact-keep-text')) {
          el.textContent = c.contact_email;
        }
        break;
      case 'map_link':
        if (c.contact_map_link_url) el.setAttribute('href', c.contact_map_link_url);
        break;
      case 'map_embed':
        if (c.contact_map_embed_url) el.setAttribute('src', c.contact_map_embed_url);
        break;
    }
  }

  function apply(c) {
    document.querySelectorAll('[data-contact]').forEach(function (el) {
      fillSlot(el, c);
    });
  }

  function load() {
    var nodes = document.querySelectorAll('[data-contact]');
    if (!nodes.length) return;

    var endpoint = (document.querySelector('[data-contact-endpoint]') || {}).getAttribute
      ? document.querySelector('[data-contact-endpoint]').getAttribute('data-contact-endpoint')
      : 'api/contact.php';

    fetch(endpoint, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (data) { if (data && data.contact) apply(data.contact); })
      .catch(function () { /* keep static fallback markup */ });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', load);
  } else {
    load();
  }
})();
