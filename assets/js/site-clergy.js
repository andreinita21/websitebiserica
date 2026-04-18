/* Clergy section loader for despre.html.
 *
 * Reads /api/clergy.php once, renders one .clergy-card per published member
 * into [data-clergy-list], and wires up a small lightbox that zooms the
 * clicked portrait to fullscreen. Falls back silently to whatever static
 * markup the HTML already contains (so the page stays useful without PHP).
 */
(function () {
  'use strict';

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function buildCard(member, index) {
    var delayClass = index > 0 ? ' delay-' + Math.min(index, 3) : '';
    var role = member.role ? '<span class="clergy-card__role">' + escapeHtml(member.role) + '</span>' : '';
    var bio  = member.bio  ? '<p class="clergy-card__bio">' + escapeHtml(member.bio) + '</p>' : '';
    var alt  = 'Portret ' + (member.name || 'membru al clerului');
    return (
      '<article class="clergy-card reveal' + delayClass + '">' +
        '<button type="button" class="clergy-card__photo" data-clergy-zoom="' + member.id + '" ' +
                'aria-label="Vezi fotografia mărită — ' + escapeHtml(member.name || '') + '">' +
          '<img src="' + escapeHtml(member.photo_url) + '" alt="' + escapeHtml(alt) + '" loading="lazy">' +
        '</button>' +
        role +
        '<h3 class="clergy-card__name">' + escapeHtml(member.name || '') + '</h3>' +
        bio +
      '</article>'
    );
  }

  function render(container, members) {
    if (!members.length) {
      container.innerHTML = '';
      return;
    }
    container.innerHTML = members.map(buildCard).join('');
  }

  // ---- Lightbox -----------------------------------------------------------

  var lb = null;
  var lbImg = null;
  var lbCaption = null;
  var lbRole = null;
  var lastFocus = null;
  var membersById = {};

  function ensureLightbox() {
    if (lb) return lb;
    lb = document.createElement('div');
    lb.className = 'clergy-lightbox';
    lb.setAttribute('role', 'dialog');
    lb.setAttribute('aria-modal', 'true');
    lb.setAttribute('aria-hidden', 'true');
    lb.innerHTML =
      '<button type="button" class="clergy-lightbox__close" aria-label="Închide">' +
        '<span class="material-symbols-outlined" aria-hidden="true">close</span>' +
      '</button>' +
      '<figure class="clergy-lightbox__figure">' +
        '<div class="clergy-lightbox__frame">' +
          '<img alt="" data-role="img">' +
        '</div>' +
        '<figcaption class="clergy-lightbox__cap">' +
          '<span class="clergy-lightbox__role" data-role="role"></span>' +
          '<span class="clergy-lightbox__name" data-role="name"></span>' +
        '</figcaption>' +
      '</figure>';
    document.body.appendChild(lb);

    lbImg     = lb.querySelector('[data-role="img"]');
    lbCaption = lb.querySelector('[data-role="name"]');
    lbRole    = lb.querySelector('[data-role="role"]');

    lb.addEventListener('click', function (e) {
      if (e.target === lb || e.target.closest('.clergy-lightbox__close')) {
        closeLightbox();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (lb.classList.contains('is-open') && e.key === 'Escape') closeLightbox();
    });
    return lb;
  }

  function openLightbox(memberId, trigger) {
    var member = membersById[memberId];
    if (!member) return;
    ensureLightbox();
    lbImg.src = member.photo_url;
    lbImg.alt = 'Portret ' + (member.name || '');
    lbCaption.textContent = member.name || '';
    lbRole.textContent = member.role || '';
    lastFocus = trigger || document.activeElement;
    document.body.classList.add('is-clergy-lightbox-open');
    lb.setAttribute('aria-hidden', 'false');
    // Force reflow so the transition kicks in.
    void lb.offsetWidth;
    lb.classList.add('is-open');
    var closeBtn = lb.querySelector('.clergy-lightbox__close');
    if (closeBtn) {
      try { closeBtn.focus({ preventScroll: true }); } catch (e) {}
    }
  }

  function closeLightbox() {
    if (!lb) return;
    lb.classList.remove('is-open');
    lb.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('is-clergy-lightbox-open');
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try { lastFocus.focus({ preventScroll: true }); } catch (e) {}
    }
  }

  function bindZoom(container) {
    container.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-clergy-zoom]');
      if (!btn) return;
      e.preventDefault();
      openLightbox(parseInt(btn.getAttribute('data-clergy-zoom'), 10), btn);
    });
  }

  // Re-trigger the .reveal IntersectionObserver from main.js for the cards
  // we just injected, so they fade in like the rest of the page.
  function refreshReveal(container) {
    var revealItems = container.querySelectorAll('.reveal');
    if (!revealItems.length) return;
    if (!('IntersectionObserver' in window)) {
      revealItems.forEach(function (el) { el.classList.add('is-visible'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          io.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0.05 });
    revealItems.forEach(function (el) { io.observe(el); });
  }

  function load() {
    var container = document.querySelector('[data-clergy-list]');
    if (!container) return;

    fetch('api/clergy.php', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (data) {
        if (!data || !Array.isArray(data.members)) return;
        membersById = {};
        data.members.forEach(function (m) { membersById[m.id] = m; });
        render(container, data.members);
        bindZoom(container);
        refreshReveal(container);
      })
      .catch(function () { /* keep static fallback markup */ });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', load);
  } else {
    load();
  }
})();
