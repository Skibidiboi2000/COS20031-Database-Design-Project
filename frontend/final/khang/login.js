/*
 * login.js — progressive two-step sign-in (Apple-style).
 *
 * Step 1 shows only the email/username field. On "Continue" we reveal the
 * password step. For Staff we just check the email isn't empty; the server
 * looks the email up in the database to decide Driver vs Mechanic.
 * If JavaScript is off, the CSS fallback shows both steps so nobody is stuck.
 */
(function () {
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.querySelectorAll('form.two-step').forEach(function (form) {
    var role      = form.getAttribute('data-role');
    var stepEmail = form.querySelector('.step-email');
    var stepPass  = form.querySelector('.step-pass');
    var idField   = form.querySelector('.step-email input');   // email or username
    var title     = form.querySelector('.step-title');         // single title at the top
    var roleInput = form.querySelector('input[name="role"]');
    var btnGo      = form.querySelector('.btn-continue');
    var btnBack    = form.querySelector('.link-back');
    var msg;

    // JS is on, so start on the email step only.
    form.classList.add('js-two-step');

    function showError(text) {
      if (!msg) {
        msg = document.createElement('div');
        msg.className = 'field-error';
        idField.parentNode.appendChild(msg);
      }
      msg.textContent = text;
    }
    function clearError() { if (msg) msg.textContent = ''; }

    // Remember the original title so "back" can restore it.
    var originalTitle = title ? title.textContent : '';

    function goToPassword() {
      var value = (idField.value || '').trim();
      if (!value) { showError('Please enter your ' + (role === 'admin' ? 'username.' : 'email.')); return; }

      clearError();
      form.classList.add('on-pass');
      var pw = stepPass.querySelector('input');
      if (pw) pw.focus();
    }

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
  });
})();
