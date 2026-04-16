/* Biserica Sfântul Vasile — public gallery
 *
 * Responsibilities:
 *   - load photos + categories from api/gallery.php
 *   - render a responsive CSS-columns masonry grid
 *   - filter by category with a smooth FLIP animation
 *   - open a fullscreen lightbox with keyboard + swipe navigation
 *
 * No build step, no framework — vanilla ES5-ish with a couple of ES2017 touches
 * that every evergreen browser supports.
 */

(function () {
  'use strict';

  var STATE = {
    endpoint: 'api/gallery.php',
    photos: [],
    categories: [],
    activeSlug: 'all',        // 'all' or a category slug
    visibleIds: [],
    lightbox: {
      open: false,
      index: 0,
      items: []
    }
  };

  var els = {};

  document.addEventListener('DOMContentLoaded', init);

  function init() {
    var root = document.getElementById('bsv-gallery');
    if (!root) return;
    els.root = root;
    els.filters = document.createElement('div');
    els.filters.className = 'gallery-filters';
    els.filters.setAttribute('role', 'tablist');
    els.filters.setAttribute('aria-label', 'Filtrează după categorie');

    els.grid = document.createElement('ul');
    els.grid.className = 'gallery-grid';

    els.state = document.createElement('div');
    els.state.className = 'gallery-state';
    els.state.hidden = true;

    root.appendChild(els.filters);
    root.appendChild(els.grid);
    root.appendChild(els.state);

    renderSkeleton();
    loadData();
    buildLightbox();
  }

  /* -------------------------------------------------
   * Data loading
   * ------------------------------------------------- */
  function loadData() {
    var endpoint = els.root.getAttribute('data-endpoint') || STATE.endpoint;
    STATE.endpoint = endpoint;

    fetch(endpoint, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (json) {
        STATE.photos = Array.isArray(json.photos) ? json.photos : [];
        STATE.categories = Array.isArray(json.categories) ? json.categories : [];
        if (!STATE.photos.length) {
          showState('photo_library', 'Galeria este în pregătire',
            'Fotografiile parohiei vor apărea aici în curând.');
          els.grid.innerHTML = '';
          return;
        }
        hideState();
        renderFilters();
        renderGrid(STATE.photos.map(function (p) { return p.id; }), { initial: true });
      })
      .catch(function () {
        showState('error',
          'Galeria nu a putut fi încărcată',
          'Verificați conexiunea la internet și reîncărcați pagina.');
      });
  }

  function showState(icon, title, text) {
    els.state.hidden = false;
    els.state.innerHTML =
      '<span class="material-symbols-outlined" aria-hidden="true">' + icon + '</span>' +
      '<h3>' + escapeHtml(title) + '</h3>' +
      '<p>' + escapeHtml(text) + '</p>';
  }
  function hideState() { els.state.hidden = true; els.state.innerHTML = ''; }

  function renderSkeleton() {
    els.grid.innerHTML = '';
    var wrap = document.createElement('div');
    wrap.className = 'gallery-skeleton-grid';
    var variants = ['a', 'b', 'c', 'a', 'd', 'b', 'c', 'd', 'a', 'b'];
    variants.forEach(function (v) {
      var s = document.createElement('div');
      s.className = 'gallery-skeleton gallery-skeleton--' + v;
      wrap.appendChild(s);
    });
    els.grid.replaceWith(wrap);
    els.grid = wrap;
    // but revert the tag type back to UL after real data arrives
  }

  /* -------------------------------------------------
   * Filters
   * ------------------------------------------------- */
  function renderFilters() {
    els.filters.innerHTML = '';

    var total = STATE.photos.length;
    var chips = [{ slug: 'all', name: 'Toate', count: total }].concat(
      STATE.categories.map(function (c) {
        return { slug: c.slug, name: c.name, count: c.photo_count };
      })
    );

    chips.forEach(function (c) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'gallery-filter';
      btn.setAttribute('role', 'tab');
      btn.setAttribute('data-slug', c.slug);
      btn.setAttribute('aria-selected', c.slug === STATE.activeSlug ? 'true' : 'false');
      if (c.slug === STATE.activeSlug) btn.classList.add('is-active');
      btn.innerHTML =
        '<span>' + escapeHtml(c.name) + '</span>' +
        '<span class="gallery-filter__count">' + c.count + '</span>';
      btn.addEventListener('click', function () { setActiveSlug(c.slug); });
      els.filters.appendChild(btn);
    });
  }

  function setActiveSlug(slug) {
    if (slug === STATE.activeSlug) return;
    STATE.activeSlug = slug;
    Array.prototype.forEach.call(els.filters.children, function (b) {
      var isActive = b.getAttribute('data-slug') === slug;
      b.classList.toggle('is-active', isActive);
      b.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    var nextIds = STATE.photos
      .filter(function (p) {
        if (slug === 'all') return true;
        return (p.categories || []).some(function (c) { return c.slug === slug; });
      })
      .map(function (p) { return p.id; });

    renderGrid(nextIds);
  }

  /* -------------------------------------------------
   * Grid rendering + FLIP animation
   * ------------------------------------------------- */

  // Convert skeleton div → actual UL the first time we render real data.
  function ensureList() {
    if (els.grid.tagName === 'UL') return;
    var ul = document.createElement('ul');
    ul.className = 'gallery-grid';
    els.grid.replaceWith(ul);
    els.grid = ul;
  }

  function renderGrid(nextIds, opts) {
    ensureList();
    opts = opts || {};

    // 1. Measure current positions (FIRST)
    var existing = {};
    Array.prototype.forEach.call(els.grid.children, function (child) {
      var id = child.getAttribute('data-id');
      if (!id) return;
      existing[id] = child.getBoundingClientRect();
    });

    // 2. Mark leaving items so they fade out in place before removal.
    var nextSet = {};
    nextIds.forEach(function (id) { nextSet[id] = true; });

    var leaving = [];
    Array.prototype.forEach.call(els.grid.children, function (child) {
      var id = child.getAttribute('data-id');
      if (id && !nextSet[id]) leaving.push(child);
    });

    // 3. Build the next DOM: reuse existing nodes, create new ones.
    var fragment = document.createDocumentFragment();
    var nextNodes = {};
    nextIds.forEach(function (id) {
      var node = els.grid.querySelector('[data-id="' + id + '"]');
      if (!node) {
        node = buildTile(lookupPhoto(id));
        node.classList.add('is-entering');
      }
      nextNodes[id] = node;
      fragment.appendChild(node);
    });

    // Animate leaving items out first, then swap DOM so positions update.
    if (leaving.length) {
      leaving.forEach(function (node) { node.classList.add('is-leaving'); });
      window.setTimeout(swap, 260);
    } else {
      swap();
    }

    function swap() {
      els.grid.innerHTML = '';
      els.grid.appendChild(fragment);
      STATE.visibleIds = nextIds.slice();

      // 4. Measure new positions (LAST), invert, then play (FLIP).
      requestAnimationFrame(function () {
        Array.prototype.forEach.call(els.grid.children, function (node) {
          var id = node.getAttribute('data-id');
          var prev = existing[id];
          if (!prev) return; // new item handled separately
          var now = node.getBoundingClientRect();
          var dx = prev.left - now.left;
          var dy = prev.top - now.top;
          if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return;
          node.style.transform = 'translate3d(' + dx + 'px,' + dy + 'px,0)';
          // Next frame, release the transform — the CSS transition
          // slides the node from its inverted origin back to its natural
          // position.
          requestAnimationFrame(function () {
            node.style.transform = '';
          });
        });

        // Release entering items so their opacity/blur intro plays.
        Array.prototype.forEach.call(els.grid.querySelectorAll('.is-entering'), function (n) {
          // double RAF to ensure the browser has committed the starting state.
          requestAnimationFrame(function () {
            requestAnimationFrame(function () { n.classList.remove('is-entering'); });
          });
        });
      });

      if (!nextIds.length) {
        showState('image_search',
          'Nu există fotografii în această categorie',
          'Încercați alt filtru sau reveniți la categoria „Toate”.');
      } else {
        hideState();
      }
    }
  }

  function lookupPhoto(id) {
    for (var i = 0; i < STATE.photos.length; i++) {
      if (String(STATE.photos[i].id) === String(id)) return STATE.photos[i];
    }
    return null;
  }

  // Preferred order for <source type="..."> — smallest-format first so the
  // browser picks the most efficient encoder it supports.
  var FORMAT_PRIORITY = ['image/avif', 'image/webp', 'image/jpeg', 'image/png'];

  function buildSrcset(list) {
    // list: [{url, w, h, bytes}, ...]  sorted ascending by width (API does it)
    return list
      .filter(function (v) { return v && v.url && v.w; })
      .map(function (v) { return v.url + ' ' + v.w + 'w'; })
      .join(', ');
  }

  function pickFallbackVariant(variants, prefMime) {
    // Choose a mid-sized variant (≥800w) in the preferred fallback mime
    // to use as the <img src>. Defaults to the largest available, or the
    // original if no variants exist for that format.
    var list = variants && variants[prefMime];
    if (!list || !list.length) return null;
    for (var i = 0; i < list.length; i++) {
      if (list[i].w >= 800) return list[i];
    }
    return list[list.length - 1];
  }

  function buildPicture(p) {
    var variants = p.variants || {};
    var sizes = p.sizes || '(min-width: 1200px) 25vw, (min-width: 860px) 33vw, (min-width: 560px) 50vw, 100vw';

    var pic = document.createElement('picture');

    // One <source> per known format, in priority order.
    FORMAT_PRIORITY.forEach(function (mime) {
      var list = variants[mime];
      if (!list || !list.length) return;
      var src = buildSrcset(list);
      if (!src) return;
      var source = document.createElement('source');
      source.type = mime;
      source.srcset = src;
      source.sizes = sizes;
      pic.appendChild(source);
    });

    var img = document.createElement('img');
    img.className = 'gallery-tile__image';
    img.alt = p.title || (p.description ? trim(p.description, 90) : 'Fotografie din galerie');
    img.loading = 'lazy';
    img.decoding = 'async';
    if (p.width)  img.width  = p.width;
    if (p.height) img.height = p.height;

    // Fallback src + srcset: pick JPEG/PNG variants if we have them, else
    // fall back to the original (unresized) file. Browsers that don't
    // understand srcset simply use `src`.
    var fallbackVariants = variants['image/jpeg'] || variants['image/png'];
    if (fallbackVariants && fallbackVariants.length) {
      img.srcset = buildSrcset(fallbackVariants);
      img.sizes = sizes;
      var pick = pickFallbackVariant(variants, 'image/jpeg')
              || pickFallbackVariant(variants, 'image/png')
              || fallbackVariants[fallbackVariants.length - 1];
      img.src = pick ? pick.url : p.url;
    } else {
      img.src = p.url;
    }

    pic.appendChild(img);
    return pic;
  }

  function buildTile(p) {
    var li = document.createElement('li');
    li.className = 'gallery-item';
    li.setAttribute('data-id', String(p.id));

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'gallery-tile';
    btn.setAttribute('aria-label',
      (p.title || 'Fotografie') + ' — deschide vizualizarea completă');
    btn.addEventListener('click', function () { openLightbox(p.id); });

    btn.appendChild(buildPicture(p));

    if (p.title || (p.categories && p.categories.length)) {
      var overlay = document.createElement('div');
      overlay.className = 'gallery-tile__overlay';
      if (p.title) {
        var t = document.createElement('div');
        t.className = 'gallery-tile__title';
        t.textContent = p.title;
        overlay.appendChild(t);
      }
      if (p.categories && p.categories.length) {
        var wrap = document.createElement('div');
        wrap.className = 'gallery-tile__cats';
        p.categories.slice(0, 3).forEach(function (c) {
          var s = document.createElement('span');
          s.textContent = c.name;
          wrap.appendChild(s);
        });
        overlay.appendChild(wrap);
      }
      btn.appendChild(overlay);
    }

    li.appendChild(btn);
    return li;
  }

  /* -------------------------------------------------
   * Lightbox
   * ------------------------------------------------- */
  function buildLightbox() {
    var box = document.createElement('div');
    box.className = 'lightbox';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Vizualizare fotografie');
    box.setAttribute('aria-hidden', 'true');

    box.innerHTML =
      '<div class="lightbox__topbar">' +
        '<div class="lightbox__counter" data-role="counter">1 / 1</div>' +
        '<button type="button" class="lightbox__btn" data-role="close" aria-label="Închide vizualizarea">' +
          '<span class="material-symbols-outlined" aria-hidden="true">close</span>' +
        '</button>' +
      '</div>' +
      '<div class="lightbox__stage">' +
        '<button type="button" class="lightbox__nav lightbox__nav--prev" data-role="prev" aria-label="Fotografia anterioară">' +
          '<span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>' +
        '</button>' +
        '<figure class="lightbox__figure" data-role="figure">' +
          '<img alt="" data-role="img">' +
        '</figure>' +
        '<button type="button" class="lightbox__nav lightbox__nav--next" data-role="next" aria-label="Fotografia următoare">' +
          '<span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>' +
        '</button>' +
      '</div>' +
      '<div class="lightbox__panel">' +
        '<h2 class="lightbox__title" data-role="title" hidden></h2>' +
        '<p class="lightbox__description" data-role="desc" hidden></p>' +
        '<div class="lightbox__cats" data-role="cats" hidden></div>' +
      '</div>';

    document.body.appendChild(box);

    els.lightbox = {
      root:    box,
      counter: box.querySelector('[data-role="counter"]'),
      close:   box.querySelector('[data-role="close"]'),
      prev:    box.querySelector('[data-role="prev"]'),
      next:    box.querySelector('[data-role="next"]'),
      figure:  box.querySelector('[data-role="figure"]'),
      img:     box.querySelector('[data-role="img"]'),
      title:   box.querySelector('[data-role="title"]'),
      desc:    box.querySelector('[data-role="desc"]'),
      cats:    box.querySelector('[data-role="cats"]')
    };

    els.lightbox.close.addEventListener('click', closeLightbox);
    els.lightbox.prev.addEventListener('click', function () { step(-1); });
    els.lightbox.next.addEventListener('click', function () { step(1); });

    box.addEventListener('click', function (e) {
      // click on the dimmed backdrop (outside the figure/panel/buttons) closes
      var target = e.target;
      if (target === box || target.classList.contains('lightbox__stage')) {
        closeLightbox();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (!STATE.lightbox.open) return;
      if (e.key === 'Escape')      closeLightbox();
      else if (e.key === 'ArrowLeft')  step(-1);
      else if (e.key === 'ArrowRight') step(1);
    });

    // Touch: swipe to navigate. Tracks horizontal delta; small vertical
    // movement is still treated as a swipe intent. Scrolling the panel
    // vertically is not supported because the image is taller than viewport
    // only on very large images, and we already cap max-height in CSS.
    var touchStartX = 0, touchStartY = 0, touching = false;
    box.addEventListener('touchstart', function (e) {
      if (!STATE.lightbox.open) return;
      if (e.touches.length !== 1) return;
      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
      touching = true;
      box.classList.add('is-swiping');
    }, { passive: true });

    box.addEventListener('touchmove', function (e) {
      if (!touching) return;
      var dx = e.touches[0].clientX - touchStartX;
      var dy = e.touches[0].clientY - touchStartY;
      if (Math.abs(dx) > Math.abs(dy)) {
        els.lightbox.figure.style.transform = 'translate3d(' + dx + 'px, 0, 0)';
        els.lightbox.figure.style.opacity = String(1 - Math.min(Math.abs(dx) / 500, 0.4));
      }
    }, { passive: true });

    box.addEventListener('touchend', function (e) {
      if (!touching) return;
      touching = false;
      box.classList.remove('is-swiping');
      var dx = (e.changedTouches[0].clientX - touchStartX);
      var dy = (e.changedTouches[0].clientY - touchStartY);
      els.lightbox.figure.style.transform = '';
      els.lightbox.figure.style.opacity = '';
      if (Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy)) {
        step(dx < 0 ? 1 : -1);
      }
    });
  }

  function openLightbox(photoId) {
    STATE.lightbox.items = STATE.visibleIds.slice();
    var idx = STATE.lightbox.items.indexOf(photoId);
    if (idx < 0) idx = 0;
    STATE.lightbox.index = idx;
    STATE.lightbox.open = true;
    els.lightbox.root.classList.add('is-open');
    els.lightbox.root.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    paintLightbox();
    window.setTimeout(function () {
      try { els.lightbox.close.focus({ preventScroll: true }); } catch (e) {}
    }, 60);
  }

  function closeLightbox() {
    STATE.lightbox.open = false;
    els.lightbox.root.classList.remove('is-open');
    els.lightbox.root.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  function step(delta) {
    var len = STATE.lightbox.items.length;
    if (!len) return;
    var idx = (STATE.lightbox.index + delta + len) % len;
    STATE.lightbox.index = idx;
    paintLightbox(delta);
  }

  function paintLightbox(direction) {
    var items = STATE.lightbox.items;
    var idx = STATE.lightbox.index;
    var photo = lookupPhoto(items[idx]);
    if (!photo) return;

    var lb = els.lightbox;
    lb.counter.textContent = (idx + 1) + ' / ' + items.length;
    lb.prev.disabled = false;
    lb.next.disabled = false;
    if (items.length <= 1) {
      lb.prev.disabled = true;
      lb.next.disabled = true;
    }

    // Smooth cross-fade when navigating; handled by re-applying
    // the is-open animation classes.
    lb.figure.style.opacity = '0';
    lb.figure.style.transform = 'translate3d(' + (direction === -1 ? -14 : direction === 1 ? 14 : 0) + 'px,0,0) scale(0.985)';

    // Pick the best-sized variant for the lightbox: roughly the viewport
    // width, scaled by device pixel ratio. Fall back to the original if
    // the photo has no variants or none large enough.
    var url = pickLightboxUrl(photo);

    // Preload before swapping to avoid an unstyled flash.
    var loader = new Image();
    loader.onload = function () {
      lb.img.src = url;
      lb.img.alt = photo.title || photo.description || 'Fotografie';
      if (photo.width)  lb.img.width  = photo.width;
      if (photo.height) lb.img.height = photo.height;

      requestAnimationFrame(function () {
        lb.figure.style.transform = 'translate3d(0,0,0) scale(1)';
        lb.figure.style.opacity = '1';
      });
    };
    loader.src = url;

    if (photo.title) {
      lb.title.textContent = photo.title;
      lb.title.hidden = false;
    } else {
      lb.title.hidden = true;
    }

    if (photo.description) {
      lb.desc.textContent = photo.description;
      lb.desc.hidden = false;
    } else {
      lb.desc.hidden = true;
    }

    if (photo.categories && photo.categories.length) {
      lb.cats.innerHTML = '';
      photo.categories.forEach(function (c) {
        var s = document.createElement('span');
        s.textContent = c.name;
        lb.cats.appendChild(s);
      });
      lb.cats.hidden = false;
    } else {
      lb.cats.hidden = true;
    }

    // Preload neighbours for snappy nav.
    [items[(idx + 1) % items.length], items[(idx - 1 + items.length) % items.length]]
      .forEach(function (id) {
        var n = lookupPhoto(id);
        if (n) { var i = new Image(); i.src = pickLightboxUrl(n); }
      });
  }

  function pickLightboxUrl(photo) {
    var variants = photo.variants || {};
    var viewport = (window.innerWidth || 1200) * (window.devicePixelRatio || 1);

    // Prefer WebP → AVIF → JPEG → PNG, first >= viewport width.
    var order = ['image/webp', 'image/avif', 'image/jpeg', 'image/png'];
    for (var i = 0; i < order.length; i++) {
      var list = variants[order[i]];
      if (!list || !list.length) continue;
      for (var j = 0; j < list.length; j++) {
        if (list[j].w >= viewport) return list[j].url;
      }
      // Else take the largest one available in that format.
      return list[list.length - 1].url;
    }
    return photo.url;
  }

  /* -------------------------------------------------
   * Utilities
   * ------------------------------------------------- */
  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function trim(s, n) {
    s = String(s || '');
    return s.length > n ? s.slice(0, n - 1) + '…' : s;
  }
})();
