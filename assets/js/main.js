/* Biserica Sfântul Vasile — shared interactions
 * Keep this file small; add only what the whole site needs. */

(function () {
  'use strict';

  var doc = document;

  /* 1. Header: add .is-scrolled after the user scrolls a bit. */
  var header = doc.querySelector('.site-header');
  if (header) {
    var onScroll = function () {
      if (window.scrollY > 24) header.classList.add('is-scrolled');
      else header.classList.remove('is-scrolled');
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* 2. Mobile menu toggle (lock body scroll while open). */
  var toggle = doc.querySelector('[data-menu-toggle]');
  var menu = doc.querySelector('[data-mobile-menu]');
  if (toggle && menu) {
    var setOpen = function (open) {
      menu.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      doc.body.style.overflow = open ? 'hidden' : '';
      var icon = toggle.querySelector('.material-symbols-outlined');
      if (icon) icon.textContent = open ? 'close' : 'menu';
    };
    toggle.addEventListener('click', function () {
      setOpen(!menu.classList.contains('is-open'));
    });
    menu.addEventListener('click', function (e) {
      var link = e.target.closest('a');
      if (link) setOpen(false);
    });
    window.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.classList.contains('is-open')) setOpen(false);
    });
  }

  /* 3. Reveal on scroll via IntersectionObserver. */
  var reveals = doc.querySelectorAll('.reveal');
  if (reveals.length) {
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            io.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
      reveals.forEach(function (el) { io.observe(el); });
    } else {
      reveals.forEach(function (el) { el.classList.add('is-visible'); });
    }
  }

  /* 4. Current year in footer. */
  var year = doc.querySelector('[data-year]');
  if (year) year.textContent = new Date().getFullYear();

})();
