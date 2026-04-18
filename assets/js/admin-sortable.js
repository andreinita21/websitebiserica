/*
 * Admin drag-to-reorder controller — shared by:
 *   - /admin/index.php     (categories & locations tables)
 *   - /admin/gallery.php   (gallery_categories table)
 *   - /admin/clergy.php    (clergy cards grid)
 *
 * Markup contract
 * ---------------
 *   <table data-sortable="cat_reorder">      <!-- or data-sortable-grid for grids -->
 *     <tr  data-sortable-id="<id>">
 *       ... <span data-drag-handle>…</span> ...
 *     </tr>
 *   </table>
 *
 *   <div data-sortable-grid="clergy_reorder">
 *     <article data-sortable-id="<id>">
 *       ... <span data-drag-handle>…</span> ...
 *     </article>
 *   </div>
 *
 * Control buttons
 * ---------------
 *   [data-enter-reorder]   -> snapshot baseline, body.is-reorder on
 *   [data-cancel-reorder]  -> restore baseline, animate, exit
 *   [data-save-reorder]    -> POST action=<name>, order=<id,id,...>, _token=<csrf>
 *
 * Feel
 * ----
 *   The dragged item's transform is driven live by the pointer, so it
 *   glides with the cursor (not snapped to DOM slots). When the visual
 *   center crosses another item, the DOM is mutated; siblings slide via
 *   FLIP and the dragged transform is recomputed so the user doesn't feel
 *   a jump. On pointerup the transform tweens back to 0 for a soft land.
 *
 * CSRF
 * ----
 *   Token is read from <meta name="bsv-csrf" content="…"> emitted by
 *   admin/_layout.php.
 */
(function () {
  'use strict';

  var body       = document.body;
  var container  = document.querySelector('[data-sortable-grid]')
                || document.querySelector('table[data-sortable]');
  if (!container) return;

  var isTable    = container.tagName === 'TABLE';
  var dragParent = isTable ? container.querySelector('tbody') : container;
  var action     = container.getAttribute('data-sortable-grid')
                || container.getAttribute('data-sortable');
  if (!dragParent || !action) return;

  var meta = document.querySelector('meta[name="bsv-csrf"]');
  var csrf = meta ? meta.getAttribute('content') : '';

  var baseline = null;
  var drag     = null;

  // --- Enter / cancel / save ---------------------------------------------
  qsa('[data-enter-reorder]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      baseline = snapshotOrder();
      body.classList.add('is-reorder');
    });
  });
  qsa('[data-cancel-reorder]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (baseline) animatedRestore(baseline);
      baseline = null;
      body.classList.remove('is-reorder');
    });
  });
  qsa('[data-save-reorder]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var current = snapshotOrder();
      if (!baseline || current.join(',') === baseline.join(',')) {
        baseline = null;
        body.classList.remove('is-reorder');
        return;
      }
      btn.disabled = true;
      persistOrder(current, function (ok) {
        btn.disabled = false;
        if (ok) {
          baseline = null;
          body.classList.remove('is-reorder');
          toast('Ordine salvată.', 'success');
        } else {
          toast('Nu am putut salva. Încercați din nou.', 'error');
        }
      });
    });
  });

  // --- Helpers -----------------------------------------------------------
  function qsa(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }
  function items()  { return Array.prototype.slice.call(dragParent.querySelectorAll('[data-sortable-id]')); }

  function snapshotOrder() {
    return items().map(function (c) { return c.getAttribute('data-sortable-id'); });
  }

  function animatedRestore(ids) {
    var current = items();
    var oldRects = new Map();
    current.forEach(function (c) { oldRects.set(c, c.getBoundingClientRect()); });
    ids.forEach(function (id) {
      var c = dragParent.querySelector('[data-sortable-id="' + id + '"]');
      if (c) dragParent.appendChild(c);
    });
    flipAnimate(current, oldRects);
  }

  /** FLIP: snapshot old positions, apply inverse transform + transition:none,
   *  force reflow, clear transform — CSS transition tweens back to zero. */
  function flipAnimate(list, oldRects) {
    list.forEach(function (c) {
      var old = oldRects.get(c);
      if (!old) return;
      var now = c.getBoundingClientRect();
      var dx = old.left - now.left;
      var dy = old.top  - now.top;
      if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) return;
      c.style.transition = 'none';
      c.style.transform  = 'translate(' + dx + 'px,' + dy + 'px)';
      c.getBoundingClientRect();   // force reflow — commit the snapped frame
      c.style.transition = '';     // fall back to CSS transition
      c.style.transform  = '';     // animate toward the rest position
    });
  }

  function persistOrder(ids, done) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('order',  ids.join(','));
    fd.append('_token', csrf);
    fetch(location.pathname + (location.search || ''), {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
    .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
    .then(function (j) { done(!!(j && j.ok)); })
    .catch(function () { done(false); });
  }

  function toast(msg, type) {
    var t = document.querySelector('.reorder-toast');
    if (!t) {
      t = document.createElement('div');
      t.className = 'reorder-toast';
      (container.parentNode || document.body).insertBefore(t, container);
    }
    t.textContent = msg;
    t.setAttribute('data-type', type);
    t.classList.remove('is-hiding');
    t.classList.add('is-showing');
    clearTimeout(t._hideT);
    t._hideT = setTimeout(function () {
      t.classList.remove('is-showing');
      t.classList.add('is-hiding');
    }, 2400);
  }

  // --- Live drag ----------------------------------------------------------
  dragParent.addEventListener('pointerdown', function (e) {
    if (!body.classList.contains('is-reorder')) return;
    var handle = e.target.closest('[data-drag-handle]');
    if (!handle) return;
    var item = handle.closest('[data-sortable-id]');
    if (!item) return;
    e.preventDefault();

    // Record where inside the item the user grabbed it, so the item's
    // top-left stays at (cursor - grabOffset) throughout the drag.
    var rect = item.getBoundingClientRect();
    drag = {
      item: item,
      grabOffsetX: e.clientX - rect.left,
      grabOffsetY: e.clientY - rect.top
    };
    item.classList.add('is-dragging');
    try { handle.setPointerCapture(e.pointerId); } catch (_) {}
  });

  dragParent.addEventListener('pointermove', function (e) {
    if (!drag) return;

    // 1. Apply the transform that keeps the item visually under the cursor.
    //    We clear the transform first so getBoundingClientRect returns the
    //    *natural* position (where CSS would place the item with no transform).
    drag.item.style.transition = 'none';
    drag.item.style.transform  = '';
    var natural = drag.item.getBoundingClientRect();
    var tx = (e.clientX - drag.grabOffsetX) - natural.left;
    var ty = (e.clientY - drag.grabOffsetY) - natural.top;
    if (isTable) tx = 0;                 // rows span the full table width
    drag.item.style.transform = translate(tx, ty);

    // 2. Find an insertion target based on the item's visual center.
    var list = items();
    if (list.length < 2) return;
    var cx = natural.left + tx + natural.width  / 2;
    var cy = natural.top  + ty + natural.height / 2;

    var target;
    var resolved = false;
    for (var i = 0; i < list.length; i++) {
      var c = list[i];
      if (c === drag.item) continue;
      var r = c.getBoundingClientRect();

      var inside = isTable
        ? (cy >= r.top && cy <= r.bottom)
        : (cx >= r.left && cx <= r.right && cy >= r.top && cy <= r.bottom);
      if (!inside) continue;

      if (isTable) {
        target = (cy < r.top + r.height / 2) ? c : c.nextElementSibling;
      } else {
        // Grid: primary axis is Y (reading order), then X inside the row.
        var midY = r.top  + r.height / 2;
        var midX = r.left + r.width  / 2;
        if (cy < midY - r.height * 0.2)      target = c;
        else if (cy > midY + r.height * 0.2) target = c.nextElementSibling;
        else                                 target = (cx < midX) ? c : c.nextElementSibling;
      }
      resolved = true;
      break;
    }

    // Edge cases (pointer above first / below last) — tables only; grids skip.
    if (!resolved && isTable) {
      var others = list.filter(function (c) { return c !== drag.item; });
      if (!others.length) return;
      var firstR = others[0].getBoundingClientRect();
      var lastR  = others[others.length - 1].getBoundingClientRect();
      if      (cy < firstR.top)      target = others[0];
      else if (cy > lastR.bottom)    target = null;
      else return;
      resolved = true;
    }
    if (!resolved) return;
    if (drag.item === target || drag.item.nextSibling === target) return;

    // 3. Mutate DOM + FLIP the siblings + re-apply drag transform so the
    //    dragged item visually stays at the same spot on screen.
    var oldRects = new Map();
    list.forEach(function (c) {
      if (c === drag.item) return;
      oldRects.set(c, c.getBoundingClientRect());
    });

    dragParent.insertBefore(drag.item, target);

    flipAnimate(list.filter(function (c) { return c !== drag.item; }), oldRects);

    drag.item.style.transform = '';
    var newNatural = drag.item.getBoundingClientRect();
    var newTx = (e.clientX - drag.grabOffsetX) - newNatural.left;
    var newTy = (e.clientY - drag.grabOffsetY) - newNatural.top;
    if (isTable) newTx = 0;
    drag.item.style.transform = translate(newTx, newTy);
  });

  function endDrag() {
    if (!drag) return;
    var item = drag.item;
    drag = null;
    // Soft land: transition the transform back to zero; strip the dragging
    // decoration after the animation completes.
    item.style.transition = 'transform 220ms cubic-bezier(0.2, 0.8, 0.2, 1)';
    item.style.transform  = '';
    setTimeout(function () {
      item.classList.remove('is-dragging');
      item.style.transition = '';
    }, 230);
  }
  dragParent.addEventListener('pointerup',     endDrag);
  dragParent.addEventListener('pointercancel', endDrag);

  function translate(x, y) { return 'translate(' + x + 'px,' + y + 'px)'; }
})();
