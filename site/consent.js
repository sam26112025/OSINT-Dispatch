/* IGRS cookie consent notice — lightweight, self-contained.
   Records the visitor's choice and signals Google Consent Mode v2 on accept.
   For EEA/UK personalised-ads compliance, also enable Google's certified CMP
   (AdSense -> Privacy & messaging -> GDPR message). */
(function () {
  try { if (localStorage.getItem('igrs_consent')) return; } catch (e) {}

  var PRIVACY = 'https://igrs.xyz/privacy-policy.html';

  var css = document.createElement('style');
  css.textContent =
    '#igrs-cc{position:fixed;left:0;right:0;bottom:0;z-index:99999;background:rgba(8,15,26,.97);' +
    'border-top:1px solid #0e4a7a;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);' +
    'color:#c8dff0;font-family:"IBM Plex Mono",ui-monospace,monospace;font-size:12.5px;line-height:1.55;' +
    'padding:14px 18px;display:flex;align-items:center;justify-content:center;gap:18px;flex-wrap:wrap;' +
    'box-shadow:0 -4px 22px rgba(0,0,0,.45)}' +
    '#igrs-cc .cc-t{max-width:760px}' +
    '#igrs-cc a{color:#00d4ff;text-decoration:none}#igrs-cc a:hover{text-decoration:underline}' +
    '#igrs-cc .cc-b{display:flex;align-items:center;gap:14px;flex-shrink:0}' +
    '#igrs-cc button{font-family:inherit;font-size:12.5px;border-radius:5px;cursor:pointer;padding:8px 18px;border:1px solid #0e4a7a}' +
    '#igrs-cc .cc-ok{background:#00d4ff;color:#04090f;border:none;font-weight:700}' +
    '#igrs-cc .cc-ok:hover{box-shadow:0 0 14px rgba(0,212,255,.5)}' +
    '#igrs-cc .cc-no{background:transparent;color:#7aa8cc}#igrs-cc .cc-no:hover{color:#c8dff0;border-color:#00d4ff}' +
    '@media(max-width:600px){#igrs-cc{font-size:11.5px;padding:12px 14px;gap:12px}}';
  document.head.appendChild(css);

  var bar = document.createElement('div');
  bar.id = 'igrs-cc';
  bar.setAttribute('role', 'region');
  bar.setAttribute('aria-label', 'Cookie notice');
  bar.innerHTML =
    '<span class="cc-t">IGRS uses cookies for analytics and to help fund this free platform through ads. ' +
    'You can accept or decline non-essential cookies. See our <a href="' + PRIVACY + '">Privacy Policy</a>.</span>' +
    '<span class="cc-b"><button type="button" class="cc-no">Decline</button>' +
    '<button type="button" class="cc-ok">Accept</button></span>';

  function gconsent(state) {
    if (typeof window.gtag === 'function') {
      try {
        window.gtag('consent', 'update', {
          ad_storage: state, analytics_storage: state,
          ad_user_data: state, ad_personalization: state
        });
      } catch (e) {}
    }
  }
  function close(choice) {
    try { localStorage.setItem('igrs_consent', choice); } catch (e) {}
    gconsent(choice === 'accepted' ? 'granted' : 'denied');
    if (bar.parentNode) bar.parentNode.removeChild(bar);
  }
  function mount() {
    document.body.appendChild(bar);
    bar.querySelector('.cc-ok').addEventListener('click', function () { close('accepted'); });
    bar.querySelector('.cc-no').addEventListener('click', function () { close('declined'); });
  }
  if (document.body) mount();
  else document.addEventListener('DOMContentLoaded', mount);
})();
