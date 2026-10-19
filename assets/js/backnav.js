/* IANOVA: the phone's Back button closes an open menu first instead of leaving the page. */
(function () {
  var overlays = [
    { btn: 'main-menu-btn', box: 'main-menu-drawer', close: function () { var c = document.getElementById('main-menu-close') || document.getElementById('main-menu-backdrop'); if (c) c.click(); } },
    { btn: 'shop-menu-btn', box: 'shop-menu-panel', close: function () { var c = document.getElementById('shop-menu-backdrop') || document.getElementById('shop-menu-btn'); if (c) c.click(); } }
  ];
  var pushed = {}, ignorePop = false;

  function isOpen(o) {
    var b = document.getElementById(o.btn), x = document.getElementById(o.box);
    if (b && b.getAttribute('aria-expanded') === 'true') return true;
    return !!(x && /(^|\s)(open|is-open|active|show|visible)(\s|$)/.test(x.className || ''));
  }

  function sync() {
    overlays.forEach(function (o) {
      var open = isOpen(o);
      if (open && !pushed[o.btn]) {
        pushed[o.btn] = true;
        try { history.pushState({ ianovaBack: o.btn }, ''); } catch (e) {}
      } else if (!open && pushed[o.btn]) {
        pushed[o.btn] = false;
        if (history.state && history.state.ianovaBack === o.btn) {
          ignorePop = true;
          history.back();
        }
      }
    });
  }

  window.addEventListener('popstate', function () {
    if (ignorePop) { ignorePop = false; return; }
    overlays.forEach(function (o) {
      if (pushed[o.btn] && isOpen(o)) { pushed[o.btn] = false; o.close(); }
      else if (pushed[o.btn]) { pushed[o.btn] = false; }
    });
  });

  if (window.MutationObserver) {
    new MutationObserver(sync).observe(document.documentElement, { attributes: true, subtree: true, attributeFilter: ['class', 'aria-expanded'] });
  }
})();
