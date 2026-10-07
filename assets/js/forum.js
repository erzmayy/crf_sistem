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

  (document.querySelector('.crf-module') || document).querySelectorAll('.crf-forum-reply-link[data-reply-id]').forEach(function (link) {
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

  /* 6. Form hasil pembahasan (Admin): Tetap / Diubah + petunjuk SLA standar. */
  (document.querySelector('.crf-module') || document).querySelectorAll('form[data-result-form]').forEach(function (form) {
    var radios = form.querySelectorAll('input[name="outcome"]');
    var values = form.querySelector('[data-result-values]');
    var urgency = form.querySelector('select[name="urgency"]');
    var valueInput = form.querySelector('input[name="sla_value"]');
    var unitSelect = form.querySelector('select[name="sla_unit"]');
    var hint = form.querySelector('[data-standard-hint]');
    var useButton = form.querySelector('[data-use-standard]');

    function isChanged() {
      var checked = form.querySelector('input[name="outcome"]:checked');
      return !!checked && checked.value === 'diubah';
    }

    // Nilai baru hanya diisi dan wajib bila hasilnya "Diubah".
    function syncOutcome() {
      var changed = isChanged();
      if (values) { values.hidden = !changed; }
      [urgency, valueInput, unitSelect].forEach(function (field) {
        if (field) {
          field.disabled = !changed;
          field.required = changed;
        }
      });
    }

    function showStandard() {
      if (!urgency || !valueInput || !unitSelect || !hint || !useButton) { return; }
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
    }

    radios.forEach(function (radio) { radio.addEventListener('change', syncOutcome); });
    if (urgency) { urgency.addEventListener('change', showStandard); }
    if (valueInput) { valueInput.addEventListener('input', showStandard); }
    if (unitSelect) { unitSelect.addEventListener('change', showStandard); }
    if (useButton) {
      useButton.addEventListener('click', function () {
        var option = urgency.options[urgency.selectedIndex];
        valueInput.value = option.dataset.slaValue;
        unitSelect.value = option.dataset.slaUnit;
        showStandard();
      });
    }
    syncOutcome();
    showStandard();
  });

  /* 7. Cegah kirim ganda pada form pembahasan. */
  (document.querySelector('.crf-module') || document).querySelectorAll('form[action*="forum_discussion.php"]').forEach(function (discussionForm) {
    if (discussionForm.hasAttribute('data-confirm')) { return; }
    discussionForm.addEventListener('submit', function (event) {
      if (event.defaultPrevented || discussionForm.dataset.submitting === '1') {
        if (discussionForm.dataset.submitting === '1') { event.preventDefault(); }
        return;
      }
      discussionForm.dataset.submitting = '1';
      window.setTimeout(function () {
        discussionForm.querySelectorAll('button[type="submit"]').forEach(function (button) { button.disabled = true; });
      }, 0);
    });
  });

  /* 8. Dibuka dari tautan #forum-pembahasan: gulir ke seksi pembahasan. */
  if (location.hash === '#forum-pembahasan') {
    var discussion = document.getElementById('forum-pembahasan');
    if (discussion) {
      window.setTimeout(function () { discussion.scrollIntoView({ block: 'start' }); }, 50);
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

  (document.querySelector('.crf-module') || document).querySelectorAll('[data-forum-detail]').forEach(function (trigger) {
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
