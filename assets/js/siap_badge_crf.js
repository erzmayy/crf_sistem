/* =====================================================================
   Badge merah antrean CRF di menu SIAP (navbar atas).
   Dimuat dari layout SIAP (footer.php) lewat satu tag <script src>.
   - Mengambil angka dari actions/queue_counts.php (hanya baca).
   - Memberi badge pada: item submenu CRF, "Change Request Form" dan
     menu induknya (total). Angka 0 disembunyikan.
   - Hanya menyentuh tautan yang href-nya memuat mod=crf.
   - Tanpa CSS eksternal: gaya badge dipasang langsung (inline).
   ===================================================================== */
(function () {
  'use strict';

  var scriptEl = document.currentScript;
  if (!scriptEl || !scriptEl.src) { return; }
  // .../crf/assets/js/siap_badge_crf.js  ->  .../crf/actions/queue_counts.php
  var endpoint = scriptEl.src.replace(/assets\/js\/[^?]*(\?.*)?$/, 'actions/queue_counts.php');
  var REFRESH_MS = 60000;
  var BADGE_CLASS = 'crf-menu-badge';
  // Hanya tautan di navbar atas SIAP; isi halaman (tab, tombol, kartu) tidak disentuh.
  var NAV_LINKS = '#navigation .main-nav a[href*="mod=crf"]';

  function submodOf(a) {
    var href = a.getAttribute('href') || '';
    if (!/[?&]mod=crf(&|#|$)/.test(href)) { return null; }
    var m = href.match(/[?&]submod=([^&#]+)/);
    return m ? decodeURIComponent(m[1]) : null;
  }

  function setBadge(a, count, title, beforeEl) {
    var old = a.querySelector('.' + BADGE_CLASS);
    if (!count || count < 1) {
      if (old) { old.parentNode.removeChild(old); }
      return;
    }
    var b = old;
    if (!b) {
      b = document.createElement('span');
      b.className = BADGE_CLASS;
      b.style.cssText = 'display:inline-block;min-width:18px;height:18px;padding:0 5px;margin-left:6px;' +
        'border-radius:9px;background:#e0262d;color:#fff;font:700 11px/18px Arial,sans-serif;' +
        'text-align:center;vertical-align:middle;box-sizing:border-box;';
      if (beforeEl && beforeEl.parentNode === a) { a.insertBefore(b, beforeEl); } else { a.appendChild(b); }
    }
    b.textContent = count > 99 ? '99+' : String(count);
    b.title = title || '';
  }

  function apply(data) {
    var counts = data.counts || {};
    var total = data.total || 0;
    var links = document.querySelectorAll(NAV_LINKS);
    var parents = [];

    Array.prototype.forEach.call(links, function (a) {
      var sub = submodOf(a);
      if (!sub) { return; }
      setBadge(a, counts[sub] || 0, (counts[sub] || 0) + ' menunggu');

      // Induk: "Change Request Form" (li.dropdown-submenu) dan menu atas (a.dropdown-toggle).
      var li = a.closest ? a.closest('li.dropdown-submenu') : null;
      if (li) {
        var head = li.querySelector(':scope > a');
        if (head && parents.indexOf(head) < 0) { parents.push(head); }
        var top = li.parentNode && li.parentNode.closest ? li.parentNode.closest('li') : null;
        var topA = top ? top.querySelector(':scope > a.dropdown-toggle') : null;
        if (topA && parents.indexOf(topA) < 0) { parents.push(topA); }
      }
    });

    parents.forEach(function (p) {
      setBadge(p, total, total + ' CRF menunggu tindakan Anda', p.querySelector('.caret'));
    });
  }

  function load() {
    if (document.hidden) { return; }
    if (!document.querySelector(NAV_LINKS)) { return; } // user tanpa menu CRF
    fetch(endpoint, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) { if (data && data.ok) { apply(data); } })
      .catch(function () { /* diam: badge tidak penting dibanding halaman */ });
  }

  load();
  setInterval(load, REFRESH_MS);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { load(); } });
})();
