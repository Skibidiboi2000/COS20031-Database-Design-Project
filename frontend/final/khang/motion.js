/*
 * motion.js — lightweight Apple-style motion.
 * Adds a gentle fade + rise to any element with the `reveal` class as it
 * enters the viewport. No libraries; respects reduced-motion preferences.
 */
(function () {
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var items = document.querySelectorAll('.reveal');

  /* On a tab switch, bring the tab bar back into view instead of letting the
   * browser jump (which is jarring when a long tab is replaced by a short one).
   * Uses smooth scrolling unless reduced motion is requested. */
  function wireTabScroll(smooth) {
    var tabBar = document.querySelector('.tabs');
    if (!tabBar) return;
    document.querySelectorAll('input[name="tab"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        if (tabBar.getBoundingClientRect().top < 0) {
          tabBar.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' });
        }
      });
    });
  }

  // If motion is reduced or IntersectionObserver is unavailable, just show
  // everything immediately (final numbers included), wire instant tab scroll, stop.
  if (reduce || !('IntersectionObserver' in window)) {
    items.forEach(function (el) { el.classList.add('in'); });
    document.querySelectorAll('[data-count]').forEach(function (el) {
      el.textContent = (parseInt(el.getAttribute('data-count'), 10) || 0).toLocaleString();
    });
    wireTabScroll(false);
    return;
  }

  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('in');
        io.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

  items.forEach(function (el) { io.observe(el); });

  /* Count-up animation for stat numbers (elements with data-count).
   * Eases from 0 to the target once, shortly after load. */
  function animateCount(el) {
    var target = parseInt(el.getAttribute('data-count'), 10) || 0;
    var duration = 1000;
    var start = null;
    function step(ts) {
      if (!start) start = ts;
      var progress = Math.min((ts - start) / duration, 1);
      // easeOutCubic
      var eased = 1 - Math.pow(1 - progress, 3);
      el.textContent = Math.round(target * eased).toLocaleString();
      if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  // We only reach here when motion is allowed, so animate every stat number.
  document.querySelectorAll('[data-count]').forEach(function (el) { animateCount(el); });

  // Smooth scroll back to the tab bar on tab switch.
  wireTabScroll(true);
})();
