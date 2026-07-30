/*
 * greeting.js — an inline welcome line that types in, then erases and vanishes.
 *
 * Renders a friendly greeting for the signed-in user just below the header,
 * types it out character by character, holds for a beat, deletes it, then
 * collapses away so the layout returns to normal. Shown once per session.
 *
 * The page provides:
 *   <body data-greet-name="...">          — the display name
 *   <div id="greet-line"></div>           — where the line is typed
 * If either is missing, nothing happens.
 */
(function () {
  var body = document.body;
  var host = document.getElementById('greet-line');
  var name = (body.getAttribute('data-greet-name') || '').trim();
  if (!host || !name) return;

  var lines = [
    'Welcome back, ' + name + '!',
    'Good to see you, ' + name + '.',
    'Hey ' + name + ', ready when you are.',
    'Welcome back, ' + name + ' — let’s get to work.',
    'Hello again, ' + name + '.',
    'Back where you belong, ' + name + '.'
  ];
  var text = lines[Math.floor(Math.random() * lines.length)];

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var span = document.createElement('span');
  span.className = 'greet-text';
  var caret = document.createElement('span');
  caret.className = 'greet-caret';
  host.appendChild(span);
  host.appendChild(caret);

  // Reveal the line's space, then start.
  requestAnimationFrame(function () { host.classList.add('greet-open'); });

  function done() {
    host.classList.add('greet-done');       // fade text + collapse height
    setTimeout(function () {
      if (host.parentNode) host.parentNode.removeChild(host);
    }, 500);
  }

  // Reduced motion: show the whole line briefly, then collapse — no typing.
  if (reduce) {
    span.textContent = text;
    caret.style.display = 'none';
    setTimeout(done, 1200);
    return;
  }

  var typeSpeed = 45, eraseSpeed = 25, holdAfterType = 900, i = 0;

  function type() {
    if (i <= text.length) {
      span.textContent = text.slice(0, i);
      i++;
      setTimeout(type, typeSpeed);
    } else {
      setTimeout(erase, holdAfterType);
    }
  }
  function erase() {
    if (i > 0) {
      i--;
      span.textContent = text.slice(0, i);
      setTimeout(erase, eraseSpeed);
    } else {
      done();
    }
  }

  setTimeout(type, 260);
})();
