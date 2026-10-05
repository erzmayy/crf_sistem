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

  /* 6. Petunjuk SLA standar kategori untuk urgensi yang dipilih. */
  var urgency = document.getElementById('final-urgency-level');
  var standardBox = document.getElementById('forum-standard-sla');
  if (urgency && standardBox) {
    var slaValue = document.getElementById('final-sla-value');
    var slaUnit = document.getElementById('final-sla-unit');
    var useStandard = document.getElementById('forum-use-standard-sla');

    var showStandard = function () {
      var option = urgency.options[urgency.selectedIndex];
      var value = option && option.dataset.slaValue;
      var unit = option && option.dataset.slaUnit;

      if (!value || !unit) {
        standardBox.hidden = true;
        return;
      }
      standardBox.querySelector('span').textContent =
        'SLA standar kategori untuk urgensi ' + option.value + ': ' + value + ' ' + unit + ' (hari kerja).';
      useStandard.hidden = slaValue.value === value && slaUnit.value === unit;
      standardBox.hidden = false;
    };

    urgency.addEventListener('change', showStandard);
    slaValue.addEventListener('input', showStandard);
    slaUnit.addEventListener('change', showStandard);
    useStandard.addEventListener('click', function () {
      var option = urgency.options[urgency.selectedIndex];
      slaValue.value = option.dataset.slaValue;
      slaUnit.value = option.dataset.slaUnit;
      showStandard();
    });
    showStandard();
  }
});
