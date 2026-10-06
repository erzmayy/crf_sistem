/**
 * assets/js/forum.js
 * Interaksi halaman Forum CRF. Semua fitur tetap berjalan tanpa JavaScript
 * (balas lewat link ?reply_to=, kirim lewat submit biasa); script ini hanya
 * mempercepat dan merapikan pengalaman pengguna.
 */
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var list = document.getElementById('forum-message-list');
  var form = document.getElementById('forum-form');
  var textarea = document.getElementById('forum-comment');

  /* 1. Posisi awal daftar komentar: komentar tujuan (#comment-x), komentar baru pertama, atau paling bawah. */
  if (list) {
    var target = null;
    if (location.hash && location.hash.indexOf('#comment-') === 0) {
      target = document.getElementById(location.hash.slice(1));
    }
    target = target || document.getElementById('forum-first-unread');

    if (target) {
      list.scrollTop = target.offsetTop - 16;
      if (target.classList.contains('crf-forum-message') || target.classList.contains('crf-forum-system')) {
        highlight(target);
      }
    } else {
      list.scrollTop = list.scrollHeight;
    }
  }

  function highlight(element) {
    element.classList.remove('is-highlight');
    void element.offsetWidth;
    element.classList.add('is-highlight');
  }

  /* 2. Klik konteks balasan: gulir ke komentar asal di dalam daftar. */
  if (list) {
    list.addEventListener('click', function (event) {
      var link = event.target.closest('.crf-forum-reply-context');
      if (!link) {
        return;
      }
      var parent = document.getElementById(link.getAttribute('href').slice(1));
      if (parent) {
        event.preventDefault();
        list.scrollTo({ top: parent.offsetTop - 16, behavior: 'smooth' });
        highlight(parent);
      }
    });
  }

  if (!form || !textarea) {
    return;
  }

  /* 3. Tanggapi tanpa memuat ulang halaman. */
  var replyId = document.getElementById('forum-reply-id');
  var replying = document.getElementById('forum-replying');
  var replyingName = document.getElementById('forum-replying-name');
  var replyingText = document.getElementById('forum-replying-text');
  var replyCancel = document.getElementById('forum-reply-cancel');

  document.querySelectorAll('.crf-forum-reply-link[data-reply-id]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      event.preventDefault();
      replyId.value = link.dataset.replyId;
      replyingName.textContent = link.dataset.replyName;
      replyingText.textContent = link.dataset.replyText;
      replying.hidden = false;
      textarea.focus();
    });
  });

  if (replyCancel) {
    replyCancel.addEventListener('click', function (event) {
      event.preventDefault();
      replyId.value = '';
      replying.hidden = true;
      textarea.focus();
    });
  }

  /* 4. Penghitung karakter, tinggi textarea otomatis, Ctrl/Cmd+Enter untuk kirim. */
  var counter = document.getElementById('forum-comment-count');
  function updateTextarea() {
    if (counter) {
      counter.textContent = textarea.value.length.toLocaleString('id-ID');
    }
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight + 2, 280) + 'px';
  }
  textarea.addEventListener('input', updateTextarea);

  textarea.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
      event.preventDefault();
      form.requestSubmit();
    }
  });

  /* 5. Cegah kirim ganda. */
  form.addEventListener('submit', function (event) {
    if (textarea.value.trim() === '') {
      event.preventDefault();
      textarea.focus();
      return;
    }
    var button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Mengirim...';
  });

  /* 6. Form usulan/keputusan: petunjuk SLA standar kategori + perilaku tombol Tolak. */
  document.querySelectorAll('form[data-sla-form]').forEach(function (sla) {
    var urgency = sla.querySelector('select[name="urgency"]');
    var valueInput = sla.querySelector('input[name="sla_value"]');
    var unitSelect = sla.querySelector('select[name="sla_unit"]');
    var hint = sla.querySelector('[data-standard-hint]');
    var useButton = sla.querySelector('[data-use-standard]');

    if (urgency && valueInput && unitSelect && hint && useButton) {
      var showStandard = function () {
        var option = urgency.options[urgency.selectedIndex];
        var value = option && option.dataset.slaValue;
        var unit = option && option.dataset.slaUnit;

        if (!value || !unit) {
          hint.hidden = true;
          return;
        }
        hint.querySelector('span').textContent =
          'SLA standar kategori untuk urgensi ' + option.value + ': ' + value + ' ' + unit + ' (hari kerja).';
        useButton.hidden = valueInput.value === value && unitSelect.value === unit;
        hint.hidden = false;
      };

      urgency.addEventListener('change', showStandard);
      valueInput.addEventListener('input', showStandard);
      unitSelect.addEventListener('change', showStandard);
      useButton.addEventListener('click', function () {
        var option = urgency.options[urgency.selectedIndex];
        valueInput.value = option.dataset.slaValue;
        unitSelect.value = option.dataset.slaUnit;
        showStandard();
      });
      showStandard();
    }

    // Tolak: catatan wajib; Setujui: catatan opsional.
    var note = sla.querySelector('textarea[name="note"]');
    var reject = sla.querySelector('[data-reject]');
    if (note && reject) {
      reject.addEventListener('click', function (event) {
        note.required = true;
        if (!note.value.trim()) {
          event.preventDefault();
          note.reportValidity();
        }
      });
      sla.querySelectorAll('button[value="approve"]').forEach(function (button) {
        button.addEventListener('click', function () { note.required = false; });
      });
    }
  });

  /* 7. Cegah kirim ganda pada form usulan/keputusan. */
  document.querySelectorAll('form[action*="forum_proposal.php"]').forEach(function (proposalForm) {
    if (proposalForm.hasAttribute('data-confirm')) { return; }
    proposalForm.addEventListener('submit', function (event) {
      if (event.defaultPrevented || proposalForm.dataset.submitting === '1') {
        if (proposalForm.dataset.submitting === '1') { event.preventDefault(); }
        return;
      }
      proposalForm.dataset.submitting = '1';
      window.setTimeout(function () {
        proposalForm.querySelectorAll('button[type="submit"]').forEach(function (button) { button.disabled = true; });
      }, 0);
    });
  });

  /* 8. Dibuka dari tautan #forum-proposal / ?propose=1: gulir ke seksi usulan. */
  if (location.hash === '#forum-proposal' || /[?&]propose=1/.test(location.search)) {
    var proposal = document.getElementById('forum-proposal');
    if (proposal) {
      window.setTimeout(function () { proposal.scrollIntoView({ block: 'start' }); }, 50);
    }
  }
});

/**
 * Panel "Detail CRF" (read-only) di samping diskusi. Isi dimuat saat tombol
 * diklik dari forum/detail.php?partial=1; tanpa JavaScript tombol membuka
 * halaman detail penuh.
 */
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var panelEl = document.getElementById('forum-detail-panel');
  var body = document.getElementById('forum-detail-panel-body');
  if (!panelEl || !body || typeof bootstrap === 'undefined') {
    return;
  }

  var panel = bootstrap.Offcanvas.getOrCreateInstance(panelEl);
  var loadedUrl = null;

  document.querySelectorAll('[data-forum-detail]').forEach(function (trigger) {
    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      var url = trigger.getAttribute('data-forum-detail');
      panel.show();

      if (loadedUrl === url) {
        return;
      }

      body.innerHTML = '<div class="crf-forum-detail-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Memuat detail CRF...</div>';
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (response) {
          return response.text().then(function (html) {
            if (!response.ok && html.indexOf('alert') === -1) {
              throw new Error('HTTP ' + response.status);
            }
            return html;
          });
        })
        .then(function (html) {
          body.innerHTML = html;
          loadedUrl = url;
        })
        .catch(function () {
          loadedUrl = null;
          body.innerHTML = '<div class="alert alert-danger m-3">Detail CRF gagal dimuat. '
            + '<a href="' + trigger.getAttribute('href') + '">Buka di halaman penuh</a>.</div>';
        });
    });
  });
});
