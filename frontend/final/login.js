/*
 * login.js — progressive two-step sign-in (Apple-style).
 *
 * Each sign-in form reveals its fields in two steps: first the email, then —
 * after "Continue" — the password. This keeps each step focused. The server
 * (login.php) does the real check against the Accounts table and decides where
 * the user goes based on their role; this script only handles the UI flow and
 * basic "don't submit an empty field" guarding.
 *
 * Progressive enhancement: if JavaScript is off, a CSS fallback shows both
 * steps at once so the form is still fully usable.
 */
(function () {
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Each role tab (Customer / Staff / Admin) has its own two-step form.
  document.querySelectorAll('form.two-step').forEach(function (form) {
    var role      = form.getAttribute('data-role');
    var stepEmail = form.querySelector('.step-email');
    var stepPass  = form.querySelector('.step-pass');
    var idField   = form.querySelector('.step-email input');   // email or username
    var title     = form.querySelector('.step-title');         // single title at the top
    var roleInput = form.querySelector('input[name="role"]');
    var btnGo      = form.querySelector('.btn-continue');
    var btnBack    = form.querySelector('.link-back');
    var pwField    = stepPass ? stepPass.querySelector('input[type="password"]') : null;
    var msg; // the inline error element, created lazily next to a field

    // JS is on, so collapse to the email step only (CSS reveals the rest).
    form.classList.add('js-two-step');

    // Show an inline error under a field (defaults to the email field).
    // Re-parents the message if it needs to move between the two steps.
    function showError(text, nearField) {
      var host = (nearField || idField).parentNode;
      if (!msg || msg.parentNode !== host) {
        if (msg) msg.remove();
        msg = document.createElement('div');
        msg.className = 'field-error';
        host.appendChild(msg);
      }
      msg.textContent = text;
    }
    function clearError() { if (msg) msg.textContent = ''; }

    // Remember the original title so "back" can restore it.
    var originalTitle = title ? title.textContent : '';

    // Step 1 -> Step 2: only advance if the email/username isn't blank.
    function goToPassword() {
      var value = (idField.value || '').trim();
      if (!value) { showError('Please enter your ' + (role === 'admin' ? 'username.' : 'email.')); return; }

      clearError();
      form.classList.add('on-pass');
      var pw = stepPass.querySelector('input');
      if (pw) pw.focus();
    }

    // Step 2 -> Step 1: go back to the email field and reset the title.
    function backToEmail() {
      clearError();
      form.classList.remove('on-pass');
      if (title) title.textContent = originalTitle;
      idField.focus();
    }

    btnGo.addEventListener('click', goToPassword);
    if (btnBack) btnBack.addEventListener('click', backToEmail);

    // Enter on the email field advances instead of submitting the whole form.
    idField.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); goToPassword(); }
    });

    // Guard the actual submit: never let a blank email or password through.
    // (The server enforces this too — this just avoids a wasted round-trip.)
    form.addEventListener('submit', function (e) {
      var email = (idField.value || '').trim();
      var pass  = pwField ? pwField.value : '';
      if (!email) { e.preventDefault(); backToEmail(); showError('Please enter your ' + (role === 'admin' ? 'username.' : 'email.')); return; }
      if (!pass)  { e.preventDefault(); showError('Please enter your password.', pwField); if (pwField) pwField.focus(); }
    });
  });
})();
