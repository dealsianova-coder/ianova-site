// Flash deal countdown — purely visual, resets locally on reach zero.
(function () {
  const hh = document.getElementById('hh');
  const mm = document.getElementById('mm');
  const ss = document.getElementById('ss');
  if (!hh) return;

  let total = 4 * 3600 + 12 * 60 + 33;
  function pad(n) { return n.toString().padStart(2, '0'); }
  function tick() {
    if (total <= 0) total = 4 * 3600 + 12 * 60 + 33;
    total--;
    hh.textContent = pad(Math.floor(total / 3600));
    mm.textContent = pad(Math.floor((total % 3600) / 60));
    ss.textContent = pad(total % 60);
  }
  setInterval(tick, 1000);
})();

// Quick visual confirmation on "Add to cart" — the actual add happens
// server-side via the form POST to cart_action.php; this just gives
// immediate feedback before the page reloads.
document.querySelectorAll('.add-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    btn.textContent = 'Added ✓';
    btn.style.background = 'var(--emerald)';
  });
});


// Selected offers carousel — products selected by the admin automatically rotate.
(function () {
  const carousel = document.querySelector('[data-offer-carousel]');
  if (!carousel) return;

  const track = carousel.querySelector('.offer-track');
  const slides = Array.from(carousel.querySelectorAll('.offer-slide'));
  const prev = carousel.querySelector('[data-offer-prev]');
  const next = carousel.querySelector('[data-offer-next]');
  const dots = carousel.querySelector('[data-offer-dots]');
  if (!track || slides.length < 2) {
    if (prev) prev.style.display = 'none';
    if (next) next.style.display = 'none';
    return;
  }

  let current = 0;
  let timer;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const dotButtons = slides.map(function (_, index) {
    const dot = document.createElement('button');
    dot.type = 'button';
    dot.className = 'offer-dot' + (index === 0 ? ' is-active' : '');
    dot.setAttribute('aria-label', 'Show offer ' + (index + 1));
    dot.addEventListener('click', function () { goTo(index); restart(); });
    dots.appendChild(dot);
    return dot;
  });

  function goTo(index) {
    current = (index + slides.length) % slides.length;
    track.style.transform = 'translateX(-' + (current * 100) + '%)';
    dotButtons.forEach(function (dot, i) {
      dot.classList.toggle('is-active', i === current);
    });
  }

  function stop() { if (timer) clearInterval(timer); }
  function restart() {
    stop();
    if (!reduceMotion) timer = setInterval(function () { goTo(current + 1); }, 4000);
  }

  prev.addEventListener('click', function () { goTo(current - 1); restart(); });
  next.addEventListener('click', function () { goTo(current + 1); restart(); });
  carousel.addEventListener('mouseenter', stop);
  carousel.addEventListener('mouseleave', restart);
  carousel.addEventListener('focusin', stop);
  carousel.addEventListener('focusout', restart);

  let startX = null;
  carousel.addEventListener('touchstart', function (event) { startX = event.touches[0].clientX; }, {passive:true});
  carousel.addEventListener('touchend', function (event) {
    if (startX === null) return;
    const delta = event.changedTouches[0].clientX - startX;
    if (Math.abs(delta) > 45) { goTo(current + (delta < 0 ? 1 : -1)); restart(); }
    startX = null;
  }, {passive:true});

  restart();
})();

// Premium top offer carousel — selected by admin, one hero offer at a time.
(function () {
  const root = document.querySelector('[data-top-offers]');
  if (!root) return;
  const slides = Array.from(root.querySelectorAll('[data-top-offer-slide]'));
  if (slides.length < 2) return;
  const dotsRoot = root.querySelector('[data-top-offer-dots]');
  const prev = root.querySelector('[data-top-offer-prev]');
  const next = root.querySelector('[data-top-offer-next]');
  let current = 0, timer, startX = null;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const dots = slides.map((slide, i) => {
    const b = document.createElement('button'); b.type='button'; b.className='top-offer-dot'+(i===0?' is-active':''); b.setAttribute('aria-label','Show selected offer '+(i+1));
    b.addEventListener('click',()=>{go(i); restart();}); dotsRoot.appendChild(b); return b;
  });
  function go(i){current=(i+slides.length)%slides.length; slides.forEach((s,n)=>s.classList.toggle('is-active',n===current)); dots.forEach((d,n)=>d.classList.toggle('is-active',n===current));}
  function stop(){if(timer) clearInterval(timer);}
  function restart(){stop(); if(!reduceMotion) timer=setInterval(()=>go(current+1),5000);}
  prev&&prev.addEventListener('click',()=>{go(current-1);restart();}); next&&next.addEventListener('click',()=>{go(current+1);restart();});
  root.addEventListener('mouseenter',stop); root.addEventListener('mouseleave',restart); root.addEventListener('focusin',stop); root.addEventListener('focusout',restart);
  root.addEventListener('touchstart',e=>{startX=e.touches[0].clientX},{passive:true}); root.addEventListener('touchend',e=>{if(startX===null)return; const d=e.changedTouches[0].clientX-startX; if(Math.abs(d)>45){go(current+(d<0?1:-1));restart();} startX=null;},{passive:true});
  restart();
})();



// ---- shrink header on scroll down, restore on scroll up ----
(function () {
  var lastY = window.scrollY;
  var topThreshold = 60;
  var moveThreshold = 15;
  var cooldownMs = 350;   // matches the CSS transition duration
  var lastToggle = 0;
  var ticking = false;

  function update() {
    var now = Date.now();
    var currentY = window.scrollY;
    var delta = currentY - lastY;

    if (currentY <= topThreshold) {
      if (document.body.classList.contains('header-shrink')) {
        document.body.classList.remove('header-shrink');
        lastToggle = now;
      }
      lastY = currentY;
    } else if (now - lastToggle > cooldownMs && Math.abs(delta) > moveThreshold) {
      if (delta > 0) {
        document.body.classList.add('header-shrink');
      } else {
        document.body.classList.remove('header-shrink');
      }
      lastToggle = now;
      lastY = currentY;
    }
    ticking = false;
  }

  window.addEventListener('scroll', function () {
    if (!ticking) {
      window.requestAnimationFrame(update);
      ticking = true;
    }
  }, { passive: true });
})();


// ---- shop by category dropdown ----
(function () {
  var btn = document.getElementById('shop-menu-btn');
  var panel = document.getElementById('shop-menu-panel');
  var backdrop = document.getElementById('shop-menu-backdrop');
  if (!btn || !panel || !backdrop) return;

  function close() {
    panel.classList.remove('is-open');
    backdrop.classList.remove('is-visible');
    btn.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }
  function open() {
    panel.classList.add('is-open');
    backdrop.classList.add('is-visible');
    btn.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    if (panel.classList.contains('is-open')) close(); else open();
  });

  backdrop.addEventListener('click', close);

  document.addEventListener('click', function (e) {
    if (panel.classList.contains('is-open') && !panel.contains(e.target) && e.target !== btn) {
      close();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close();
  });
})();

// ---- main hamburger menu ----
(function () {
  var btn = document.getElementById('main-menu-btn');
  var drawer = document.getElementById('main-menu-drawer');
  var backdrop = document.getElementById('main-menu-backdrop');
  var closeBtn = document.getElementById('main-menu-close');
  var exitLink = document.getElementById('main-menu-exit');
  if (!btn || !drawer || !backdrop) return;

  function close() {
    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-visible');
    btn.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }
  function open() {
    drawer.classList.add('is-open');
    backdrop.classList.add('is-visible');
    btn.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    if (drawer.classList.contains('is-open')) close(); else open();
  });

  if (closeBtn) closeBtn.addEventListener('click', close);
  backdrop.addEventListener('click', close);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close();
  });

  if (exitLink) {
    exitLink.addEventListener('click', function (e) {
      e.preventDefault();
      var ok = window.confirm('Are you sure you want to exit?');
      if (ok) {
        if (window.history.length > 1) {
          window.history.back();
        } else {
          window.close();
        }
      }
    });
  }
})();

// Flash-deal cards: tapping anywhere on the card image/title goes to the
// full flash-deals listing instead of the individual product page.
document.querySelectorAll('.offer-slide a[href*="product.php"]').forEach(function (a) {
  a.setAttribute('href', 'flash-deals.php');
});

// Remember scroll position before "Add to cart" submits, and restore it
// after the page reloads, so you land back exactly where you were.
(function () {
  var KEY = 'ianova_scroll_' + location.pathname + location.search;

  document.querySelectorAll('form[action="cart_action.php"]').forEach(function (form) {
    form.addEventListener('submit', function () {
      sessionStorage.setItem(KEY, window.scrollY);
    });
  });

  var saved = sessionStorage.getItem(KEY);
  if (saved !== null) {
    sessionStorage.removeItem(KEY);
    // Wait a tick for images/layout to settle before scrolling.
    window.addEventListener('load', function () {
      window.scrollTo(0, parseInt(saved, 10));
    });
  }
})();

// Admin-controlled video ad: interrupts the banner carousel on a timer,
// plays for at least the configured minimum, then resumes and reshuffles banners.
(function () {
  var ad = document.getElementById('videoAd');
  if (!ad) return;
  var video = document.getElementById('videoAdEl');
  var bannerStrip = document.querySelector('.banner-strip');
  var hero = document.querySelector('.hero');
  var intervalMin = parseFloat(ad.getAttribute('data-interval-min')) || 3;
  var minSeconds = parseFloat(ad.getAttribute('data-min-seconds')) || 30;
  var noBanners = ad.getAttribute('data-no-banners') === '1';

  // No banners configured: the video ad fills that slot permanently, looping
  // on its own. No interval-based interruption, nothing to toggle back to.
  if (noBanners) {
    ad.classList.add('is-visible');
    video.muted = true;
    video.loop = true;
    try { video.play(); } catch (e) {}
    return;
  }

  function toggleBanners(hide) {
    if (bannerStrip) bannerStrip.style.display = hide ? 'none' : '';
    if (hero) hero.style.display = hide ? 'none' : '';
  }

  function reshuffleBanners() {
    var track = document.querySelector('.banner-track');
    if (!track) return;
    var cards = Array.prototype.slice.call(track.children);
    cards.sort(function () { return Math.random() - 0.5; });
    cards.forEach(function (c) { track.appendChild(c); });
    track.scrollTo({ left: 0 });
  }

  function playAd() {
    toggleBanners(true);
    ad.classList.add('is-visible');
    video.muted = true; // always start muted; a viewer's unmute from last time doesn't carry over
    try { video.currentTime = 0; video.play(); } catch (e) {}
    var started = Date.now();
    var finished = false;
    function finish() {
      if (finished) return;
      finished = true;
      var elapsedMs = Date.now() - started;
      var waitMs = Math.max(0, minSeconds * 1000 - elapsedMs);
      setTimeout(function () {
        video.pause();
        video.muted = true;
        ad.classList.remove('is-visible');
        toggleBanners(false);
        reshuffleBanners();
      }, waitMs);
    }
    video.addEventListener('ended', finish, { once: true });
    // Safety net in case 'ended' never fires (e.g. looping video).
    setTimeout(finish, minSeconds * 1000);
  }

  setInterval(playAd, intervalMin * 60 * 1000);
})();
