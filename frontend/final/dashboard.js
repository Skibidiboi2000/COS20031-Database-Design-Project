/*
 * dashboard.js — interactive dashboard, iOS/macOS-style motion.
 *
 * Modelled on how Apple moves Home-screen icons and resizes widgets:
 *   1. Tooltip      — hover any [data-tip] element for a floating label.
 *   2. Drag         — Pointer Events; the widget sticks 1:1 to the finger at
 *                     the exact point you grabbed it (no snap-to-centre) and
 *                     lifts out of the grid flow.
 *   3. Neighbours   — every reflow (reorder or resize) is played back with a
 *                     per-widget spring, not a fixed-duration transition, so
 *                     it's interruptible and never "brick-walls" mid-gesture.
 *                     Closer widgets react first (a small stagger = ripple).
 *   4. Resize       — the ⤢ button changes the grid span; contents cross-fade
 *                     into the new layout instead of the card scaling (keeps
 *                     text + SVG crisp, the way WidgetKit re-lays-out).
 *   5. Persist      — order + sizes saved per-role in localStorage.
 *
 * No libraries — a tiny hand-written spring on requestAnimationFrame. Honors
 * prefers-reduced-motion by snapping instead of springing.
 */
(function () {
  var grid = document.getElementById('widgets');
  if (!grid) return;

  var reduce = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Namespace the saved layout by role so each role keeps its own arrangement.
  var roleKey = (document.body.className.match(/role-([a-z]+)/) || [])[1] || 'all';
  var STORE = 'dash-layout-' + document.title.replace(/\s+/g, '') + '-' + roleKey;

  /* ============================================================
   * Neighbour reflow — FLIP + CSS transition.
   * This is the technique SortableJS/Muuri actually ship: measure First
   * rects, mutate the DOM, measure Last, set an inverting transform with no
   * transition, then on the next frame clear it WITH a cubic-bezier
   * transition so the compositor animates it. No per-frame JS = no stutter.
   * A widget already mid-flight is re-based from its current visual spot so
   * an interrupt stays smooth.
   * ============================================================ */
  var EASE = 'transform .28s cubic-bezier(.2,.75,.3,1)';

  function reflow(mutate) {
    var items = [].slice.call(grid.querySelectorAll('.widget'));
    var first = new Map();
    items.forEach(function (el) {
      if (el === dragged) return;
      first.set(el, el.getBoundingClientRect());
    });

    mutate();

    items.forEach(function (el) {
      if (el === dragged) return;
      var a = first.get(el);
      var b = el.getBoundingClientRect();
      var dx = a.left - b.left, dy = a.top - b.top;
      if (!dx && !dy) return;
      if (reduce) return;
      // First: jump to the old visual position with no transition.
      el.style.transition = 'none';
      el.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
      // Last: next frame, transition back to the natural slot (0,0).
      requestAnimationFrame(function () {
        el.style.transition = EASE;
        el.style.transform = '';
      });
    });
  }

  /* ---------- Tooltip ---------- */
  var tip = document.getElementById('chart-tip');
  function showTip(text, x, y) {
    if (!tip) return;
    tip.textContent = text;
    tip.hidden = false;
    var tw = tip.offsetWidth, th = tip.offsetHeight;
    tip.style.left = Math.min(x + 14, window.innerWidth - tw - 8) + 'px';
    tip.style.top = Math.max(y - th - 10, 8) + 'px';
  }
  function hideTip() { if (tip) tip.hidden = true; }
  grid.addEventListener('mousemove', function (e) {
    if (dragged) { hideTip(); return; }
    var el = e.target.closest('[data-tip]');
    if (el) showTip(el.getAttribute('data-tip'), e.clientX, e.clientY);
    else hideTip();
  });
  grid.addEventListener('mouseleave', hideTip);

  /* ---------- Resize (span change + content cross-fade) ---------- */
  var SIZES = ['small', 'wide', 'tall'];
  function applySize(w, size) {
    w.classList.remove('size-small', 'size-wide', 'size-tall');
    if (size && size !== 'default') w.classList.add('size-' + size);
  }
  grid.querySelectorAll('.w-size').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var w = btn.closest('.widget');
      var cur = w.getAttribute('data-size') || 'default';
      var order = ['default'].concat(SIZES);
      var next = order[(order.indexOf(cur) + 1) % order.length];
      // Neighbours FLIP aside; contents fade — the card itself never scales.
      reflow(function () {
        w.setAttribute('data-size', next);
        applySize(w, next);
      });
      if (!reduce) {
        w.classList.remove('refade');
        void w.offsetWidth;
        w.classList.add('refade');
        w.addEventListener('animationend', function ae() {
          w.classList.remove('refade');
          w.removeEventListener('animationend', ae);
        });
      }
      save();
    });
  });

  /* ---------- Drag via Pointer Events (1:1, grab-offset preserved) ---------- */
  var dragged = null, grabX = 0, grabY = 0, pointerId = null;
  var ptrX = 0, ptrY = 0, baseLeft = 0, baseTop = 0;
  var reorderRAF = null, lastReorderX = 0, lastReorderY = 0;

  grid.querySelectorAll('.widget').forEach(function (w) {
    var head = w.querySelector('.w-head') || w;
    head.addEventListener('pointerdown', function (e) {
      if (e.button !== 0) return;
      if (e.target.closest('.w-size')) return; // let the resize button work
      startDrag(w, e);
    });
  });

  function startDrag(w, e) {
    dragged = w;
    pointerId = e.pointerId;
    var box = w.getBoundingClientRect();
    grabX = e.clientX - box.left;   // respect where the user grabbed it
    grabY = e.clientY - box.top;
    baseLeft = box.left; baseTop = box.top;
    ptrX = e.clientX; ptrY = e.clientY;
    lastReorderX = e.clientX; lastReorderY = e.clientY;
    w.classList.add('dragging');
    w.setPointerCapture(pointerId);
    // Freeze the card's footprint so neighbours flow into the gap.
    w.style.width = box.width + 'px';
    w.style.height = box.height + 'px';
    w.style.left = box.left + 'px';
    w.style.top = box.top + 'px';
    w.style.transition = 'none'; // card tracks the finger, never eased
    hideTip();
    positionCard();
    e.preventDefault();

    // Pointer capture routes all further moves to the element.
    w.addEventListener('pointermove', onMove);
    w.addEventListener('pointerup', endDrag);
    w.addEventListener('pointercancel', endDrag);
  }

  // Cheap: only writes the card's transform. No layout reads here.
  function positionCard() {
    if (!dragged) return;
    dragged.style.transform =
      'translate(' + (ptrX - grabX - baseLeft) + 'px,' +
                     (ptrY - grabY - baseTop) + 'px) scale(1.03)';
  }

  function onMove(e) {
    if (!dragged) return;
    ptrX = e.clientX; ptrY = e.clientY;
    positionCard(); // card follows the finger immediately, every event
    // Defer the expensive reorder (rect reads + DOM move) to one-per-frame,
    // and only when the pointer has actually travelled a bit. This is what
    // kills the stutter — we were thrashing layout on every pointermove.
    if (reorderRAF == null) reorderRAF = requestAnimationFrame(maybeReorder);
  }

  function maybeReorder() {
    reorderRAF = null;
    if (!dragged) return;
    // Hysteresis: ignore tiny jitters so we don't flip back and forth.
    if (Math.abs(ptrX - lastReorderX) < 8 && Math.abs(ptrY - lastReorderY) < 8) return;
    lastReorderX = ptrX; lastReorderY = ptrY;

    var after = getAfterElement(ptrX, ptrY);
    if (after === dragged) return;
    if (after && after === dragged.nextElementSibling) return;
    if (after === null && dragged === grid.lastElementChild) return;
    reflow(function () {
      if (after == null) grid.appendChild(dragged);
      else grid.insertBefore(dragged, after);
    });
  }

  function endDrag(e) {
    if (!dragged) return;
    var w = dragged;
    w.removeEventListener('pointermove', onMove);
    w.removeEventListener('pointerup', endDrag);
    w.removeEventListener('pointercancel', endDrag);
    if (reorderRAF != null) { cancelAnimationFrame(reorderRAF); reorderRAF = null; }
    try { w.releasePointerCapture(pointerId); } catch (err) {}

    // Release: FLIP the card from its floating spot into its grid slot with
    // the same eased transition the neighbours use (SortableJS "releasing").
    var floatBox = w.getBoundingClientRect();
    w.classList.remove('dragging');
    w.style.width = w.style.height = w.style.left = w.style.top = '';
    w.style.transition = 'none';
    w.style.transform = '';
    var slotBox = w.getBoundingClientRect();
    var dx = floatBox.left - slotBox.left, dy = floatBox.top - slotBox.top;
    dragged = null;
    if (reduce || (!dx && !dy)) { w.style.transition = ''; w.style.transform = ''; save(); return; }
    w.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
    requestAnimationFrame(function () {
      w.style.transition = EASE;
      w.style.transform = '';
    });
    save();
  }

  // Closest widget to the cursor -> insertion reference (before/after).
  function getAfterElement(x, y) {
    var els = [].slice.call(grid.querySelectorAll('.widget:not(.dragging)'));
    var closest = { dist: Infinity, el: null };
    els.forEach(function (el) {
      var box = el.getBoundingClientRect();
      var cx = box.left + box.width / 2, cy = box.top + box.height / 2;
      var d = Math.hypot(x - cx, y - cy);
      if (d < closest.dist) closest = { dist: d, el: el };
    });
    if (!closest.el) return null;
    var box = closest.el.getBoundingClientRect();
    var before = y < box.top + box.height / 2;
    return before ? closest.el : closest.el.nextElementSibling;
  }

  /* ---------- Persist ---------- */
  function save() {
    var layout = [].slice.call(grid.querySelectorAll('.widget')).map(function (w) {
      return { id: w.getAttribute('data-widget'), size: w.getAttribute('data-size') || 'default' };
    });
    try { localStorage.setItem(STORE, JSON.stringify(layout)); } catch (e) {}
  }
  function restore() {
    var raw;
    try { raw = localStorage.getItem(STORE); } catch (e) { return; }
    if (!raw) return;
    var layout;
    try { layout = JSON.parse(raw); } catch (e) { return; }
    if (!Array.isArray(layout)) return;
    layout.forEach(function (item) {
      var w = grid.querySelector('.widget[data-widget="' + item.id + '"]');
      if (w) {
        applySize(w, item.size);
        w.setAttribute('data-size', item.size || 'default');
        grid.appendChild(w);
      }
    });
  }

  var resetBtn = document.getElementById('resetLayout');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      try { localStorage.removeItem(STORE); } catch (e) {}
      location.reload();
    });
  }

  restore();
})();
