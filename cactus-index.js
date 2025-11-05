// if you edit this file, change the ?v= where referenced (e.g. in index.php) 
// to defeat browser caching
(function () {
  var cfg = document.getElementById('cactus-config');
  if (!cfg) return;

  var selectedEvent = (cfg.getAttribute('data-event') || '').trim();
  var PROBE_URL     = (cfg.getAttribute('data-probe') || '').trim();
  if (!selectedEvent || !PROBE_URL) return;

  var secPw    = document.getElementById('secPassword');
  var secActs  = document.getElementById('secActions');
  var pwNew    = document.getElementById('pwNew');
  var pwExists = document.getElementById('pwExists');
  var callsEl  = document.getElementById('callsign');
  var nameEl   = document.getElementById('name');
  var btnEdit  = document.getElementById('btnEdit');
  var btnAdmin = document.getElementById('btnAdmin');

  if (!callsEl) return;

  var lastProbed = '';
  var debTimer   = null;

  function show(passwordMode) {
    if (secPw)   secPw.style.display = 'block';  // force visible even if CSS hides
    if (secActs) secActs.style.display = 'flex'; // flex looks best for button row
    if (passwordMode === 'new') {
      if (pwNew)    pwNew.style.display = '';
      if (pwExists) pwExists.style.display = 'none';
    } else {
      if (pwNew)    pwNew.style.display = 'none';
      if (pwExists) pwExists.style.display = '';
    }
  }

  function probeNow() {
    var calls = (callsEl.value || '').trim().toUpperCase();
    if (!calls || calls === lastProbed) return;
    lastProbed = calls;

    var fd = new FormData();
    fd.append('callsign', calls);
    fd.append('event', selectedEvent);

    fetch(PROBE_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || j.ok !== true) return;
        var mode = (j.status === 'new') ? 'new' : 'exists';
        show(mode);
      })
      .catch(function(){ /* silent */ });
  }

  function scheduleProbe() {
    clearTimeout(debTimer);
    debTimer = setTimeout(probeNow, 250);
  }

  // Reveal logic
  callsEl.addEventListener('input', scheduleProbe);
  callsEl.addEventListener('blur', probeNow);
  callsEl.addEventListener('change', probeNow);
  callsEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); probeNow(); }
  });

  // Login + go via hidden form (has CSRF)
  function loginAndGo(dest) {
    var calls = (callsEl.value || '').trim().toUpperCase();
    var name  = (nameEl  && nameEl.value  || '').trim();
    if (!calls || !name) { alert('Please enter callsign and name.'); return; }

    var useNew = pwNew && pwNew.style.display !== 'none';
    var pw  = '';
    var pw2 = '';

    if (useNew) {
      var p1 = (document.getElementById('password').value  || '');
      var p2 = (document.getElementById('password2').value || '');
      if (!p1 || !p2) { alert('Please enter and confirm the new password.'); return; }
      if (p1 !== p2)  { alert('Passwords do not match.'); return; }
      pw = p1; pw2 = p2;
    } else {
      pw = (document.getElementById('passwordX').value || '');
      if (!pw) { alert('Please enter your password.'); return; }
    }

    var f = document.getElementById('loginForm');
    f.elements['redirect_to'].value = dest;
    f.elements['callsign'].value    = calls;
    f.elements['name'].value        = name;
    f.elements['password'].value    = pw;
    f.elements['password2'].value   = pw2;
    f.submit();
  }

  if (btnEdit)  btnEdit.addEventListener('click',  function(){ loginAndGo('scheduler'); });
  if (btnAdmin) btnAdmin.addEventListener('click', function(){ loginAndGo('admin'); });
})();
