/* =====================================================================
   Integrasi Helpdesk & CRF - interaksi UI
   - Dialog konfirmasi untuk form ber-atribut data-confirm
   - Loading state tombol submit (data-loading-form / data-confirm)
   - Isi otomatis modal kategori (data-category-form)
   - Pencarian user untuk Handler / PIC (data-user-picker)
   - Formulir Helpdesk: alihkan ke Form CRF untuk kategori "Butuh CRF"
   Semua aturan akses tetap divalidasi di server.
   ===================================================================== */

document.addEventListener('DOMContentLoaded', function () {

  /* -----------------------------------------------------------------
   * Sidebar gaya SIAP: flyout (desktop) / akordeon (mobile)
   * ----------------------------------------------------------------- */
  var isMobileNav = function () { return window.matchMedia('(max-width: 768px)').matches; };
  var siapItems = document.querySelectorAll('.siap-menu-item');

  function placeFlyout(item) {
    var submenu = item.querySelector('.siap-submenu');
    if (!submenu || isMobileNav()) { return; }
    var rect = item.getBoundingClientRect();
    submenu.style.top = rect.top + 'px';
    // Jangan sampai keluar layar bawah.
    window.requestAnimationFrame(function () {
      var overflow = submenu.getBoundingClientRect().bottom - window.innerHeight + 8;
      if (overflow > 0) { submenu.style.top = Math.max(8, rect.top - overflow) + 'px'; }
    });
  }

  function closeFlyouts(except) {
    siapItems.forEach(function (item) {
      if (item !== except) { item.classList.remove('is-flyout'); }
    });
  }

  siapItems.forEach(function (item) {
    var button = item.querySelector('.siap-menu-link');
    item.addEventListener('mouseenter', function () { placeFlyout(item); });
    item.addEventListener('focusin', function () { placeFlyout(item); });
    if (!button) { return; }
    button.addEventListener('click', function () {
      if (isMobileNav()) {
        var open = item.classList.toggle('is-open');
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        return;
      }
      placeFlyout(item);
      closeFlyouts(item);
      item.classList.toggle('is-flyout');
    });
  });

  document.addEventListener('click', function (event) {
    if (!event.target.closest('.siap-menu-item')) { closeFlyouts(null); }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { closeFlyouts(null); }
  });
  var siapSidebar = document.querySelector('.siap-sidebar');
  if (siapSidebar) {
    siapSidebar.addEventListener('scroll', function () { closeFlyouts(null); });
  }
  document.querySelectorAll('.siap-submenu a, .siap-menu-main a').forEach(function (link) {
    link.addEventListener('click', function () {
      var shell = document.querySelector('.crf-app-shell');
      if (shell) { shell.classList.remove('crf-sidebar-open'); }
    });
  });

  /* -----------------------------------------------------------------
   * Loading state
   * ----------------------------------------------------------------- */
  function setLoading(form) {
    form.querySelectorAll('button[type="submit"]').forEach(function (button) {
      button.disabled = true;
      if (!button.querySelector('.spinner-border')) {
        var spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm me-1';
        spinner.setAttribute('aria-hidden', 'true');
        button.prepend(spinner);
      }
    });
  }

  document.querySelectorAll('form[data-loading-form]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (form.dataset.submitting === '1') {
        event.preventDefault();
        return;
      }
      if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
        return;
      }
      form.dataset.submitting = '1';
      window.setTimeout(function () { setLoading(form); }, 0);
    });
  });

  /* -----------------------------------------------------------------
   * Dialog konfirmasi (Bootstrap modal, fallback window.confirm)
   * ----------------------------------------------------------------- */
  var confirmModalEl = null;
  var confirmTarget = null;

  function ensureConfirmModal() {
    if (confirmModalEl || typeof bootstrap === 'undefined') {
      return confirmModalEl;
    }
    confirmModalEl = document.createElement('div');
    confirmModalEl.className = 'modal fade';
    confirmModalEl.tabIndex = -1;
    confirmModalEl.setAttribute('aria-hidden', 'true');
    confirmModalEl.innerHTML =
      '<div class="modal-dialog modal-dialog-centered modal-sm">' +
        '<div class="modal-content">' +
          '<div class="modal-header"><h5 class="modal-title">Konfirmasi</h5>' +
          '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>' +
          '<div class="modal-body" data-confirm-message></div>' +
          '<div class="modal-footer">' +
            '<button type="button" class="btn btn-crf-outline" data-bs-dismiss="modal">Batal</button>' +
            '<button type="button" class="btn btn-crf-primary" data-confirm-ok>Ya, lanjutkan</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(confirmModalEl);
    confirmModalEl.querySelector('[data-confirm-ok]').addEventListener('click', function () {
      var form = confirmTarget;
      bootstrap.Modal.getInstance(confirmModalEl).hide();
      if (form) {
        form.dataset.confirmed = '1';
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit(form._confirmSubmitter || undefined);
        } else {
          form.submit();
        }
      }
    });
    return confirmModalEl;
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) {
      return;
    }
    if (form.dataset.confirmed === '1') {
      form.dataset.confirmed = '';
      setLoading(form);
      return;
    }
    event.preventDefault();
    var message = form.getAttribute('data-confirm') || 'Lanjutkan aksi ini?';
    var modal = ensureConfirmModal();
    if (!modal) {
      if (window.confirm(message)) {
        setLoading(form);
        form.submit();
      }
      return;
    }
    confirmTarget = form;
    form._confirmSubmitter = event.submitter || null;
    modal.querySelector('[data-confirm-message]').textContent = message;
    bootstrap.Modal.getOrCreateInstance(modal).show();
  }, true);

  /* -----------------------------------------------------------------
   * Form CRF: status penyimpanan + konfirmasi browser bila meninggalkan
   * halaman yang punya isian belum disimpan.
   *   new      : belum ada isian
   *   unsaved  : isian dibawa dari Helpdesk, belum pernah disimpan
   *   saved    : draft tersimpan, belum ada perubahan
   *   revision : perlu revisi, belum dikirim ulang
   *   dirty    : ada perubahan yang belum disimpan
   * ----------------------------------------------------------------- */
  document.querySelectorAll('form[data-unsaved-guard]').forEach(function (form) {
    var initialState = form.getAttribute('data-save-state') || 'new';
    var status = form.querySelector('[data-save-status]');
    var statusText = status ? status.querySelector('[data-save-status-text]') : null;
    var dirty = initialState === 'unsaved';
    var submitting = false;

    function markDirty() {
      if (dirty) { return; }
      dirty = true;
      if (!status || !statusText) { return; }
      status.className = 'crf-save-status is-dirty';
      statusText.textContent = initialState === 'revision'
        ? 'Ada perubahan yang belum dikirim ulang'
        : 'Ada perubahan yang belum disimpan';
    }

    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);
    form.addEventListener('submit', function () { submitting = true; });

    window.addEventListener('beforeunload', function (event) {
      if (!dirty || submitting) { return; }
      event.preventDefault();
      event.returnValue = '';
    });
  });

  /* -----------------------------------------------------------------
   * Handler: alasan wajib bila SLA berbeda dari standar kategori
   * ----------------------------------------------------------------- */
  document.querySelectorAll('[data-sla-standard-value]').forEach(function (row) {
    var form = row.closest('form');
    var reasonBox = form ? form.querySelector('[data-sla-reason]') : null;
    var valueInput = row.querySelector('[name="sla_value"]');
    var unitSelect = row.querySelector('[name="sla_unit"]');
    if (!reasonBox || !valueInput || !unitSelect) { return; }
    var reason = reasonBox.querySelector('textarea');
    var standardValue = parseFloat(row.getAttribute('data-sla-standard-value'));
    var standardUnit = row.getAttribute('data-sla-standard-unit');

    function sync() {
      var deviates = Math.abs(parseFloat(valueInput.value) - standardValue) >= 0.001
        || isNaN(parseFloat(valueInput.value))
        || unitSelect.value !== standardUnit;
      reasonBox.classList.toggle('d-none', !deviates);
      reason.required = deviates;
    }

    valueInput.addEventListener('input', sync);
    unitSelect.addEventListener('change', sync);
    sync();
  });

  /* -----------------------------------------------------------------
   * Modal kategori: isi field dari data-category-form
   * ----------------------------------------------------------------- */
  document.querySelectorAll('[data-category-form]').forEach(function (button) {
    button.addEventListener('click', function () {
      var modal = document.querySelector(button.getAttribute('data-bs-target'));
      if (!modal) { return; }
      var form = modal.querySelector('form');
      var data = {};
      try { data = JSON.parse(button.getAttribute('data-category-form') || '{}'); } catch (e) { data = {}; }
      var isEdit = !!data.id;

      form.reset();
      form.querySelector('[name="id"]').value = isEdit ? data.id : '';
      Object.keys(data).forEach(function (key) {
        var field = form.querySelector('[name="' + key + '"]');
        if (!field || key === 'id') { return; }
        if (field.type === 'checkbox') {
          field.checked = String(data[key]) === '1';
        } else {
          field.value = data[key] === null ? '' : data[key];
        }
      });
      if (!isEdit) {
        var active = form.querySelector('[name="is_active"]');
        if (active) { active.checked = true; }
      }
      var title = modal.querySelector('.modal-title');
      if (title) {
        title.textContent = isEdit ? title.dataset.titleEdit : title.dataset.titleNew;
      }
    });
  });

  /* -----------------------------------------------------------------
   * Master data: tab tersimpan di URL + pencarian kategori
   * ----------------------------------------------------------------- */
  document.querySelectorAll('.crf-master-tabs [data-bs-toggle="tab"]').forEach(function (tabLink) {
    tabLink.addEventListener('shown.bs.tab', function () {
      var url = new URL(window.location.href);
      url.searchParams.set('tab', tabLink.getAttribute('data-bs-target').replace('#tab-', ''));
      url.hash = '';
      window.history.replaceState(null, '', url.toString());
    });
  });

  document.querySelectorAll('[data-master-filter]').forEach(function (input) {
    var pane = document.querySelector(input.getAttribute('data-master-filter'));
    if (!pane) { return; }
    input.addEventListener('input', function () {
      var query = input.value.trim().toLowerCase();
      var visible = 0;
      pane.querySelectorAll('[data-master-name]').forEach(function (row) {
        var match = query === '' || row.getAttribute('data-master-name').indexOf(query) !== -1;
        row.classList.toggle('d-none', !match);
        if (match) { visible++; }
      });
      var empty = pane.querySelector('[data-master-empty]');
      if (empty) { empty.classList.toggle('d-none', visible > 0 || query === ''); }
    });
  });

  /* -----------------------------------------------------------------
   * Pencarian user (Handler / PIC)
   * ----------------------------------------------------------------- */
  var appBase = (document.body.getAttribute('data-app-base') || '');

  document.querySelectorAll('[data-user-picker]').forEach(function (form) {
    var input = form.querySelector('[data-user-picker-input]');
    var hidden = form.querySelector('[data-user-picker-id]');
    var results = form.querySelector('[data-user-picker-results]');
    var submit = form.querySelector('[data-user-picker-submit]');
    var timer = null;
    var lastQuery = '';

    function clearSelection() {
      hidden.value = '';
      submit.disabled = true;
    }

    function hideResults() {
      results.classList.add('d-none');
      results.innerHTML = '';
    }

    function render(users) {
      results.innerHTML = '';
      if (!users.length) {
        results.innerHTML = '<div class="crf-member-result-empty">User tidak ditemukan.</div>';
      }
      users.forEach(function (user) {
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'crf-member-result';
        item.setAttribute('role', 'option');
        var name = document.createElement('strong');
        name.textContent = user.nama || user.userid;
        var meta = document.createElement('small');
        meta.textContent = [user.userid, user.dept, user.email].filter(Boolean).join(' · ');
        item.appendChild(name);
        item.appendChild(meta);
        item.addEventListener('click', function () {
          hidden.value = user.id;
          input.value = user.nama || user.userid;
          submit.disabled = false;
          hideResults();
        });
        results.appendChild(item);
      });
      results.classList.remove('d-none');
    }

    input.addEventListener('input', function () {
      clearSelection();
      var query = input.value.trim();
      window.clearTimeout(timer);
      if (query.length < 2) {
        hideResults();
        return;
      }
      timer = window.setTimeout(function () {
        lastQuery = query;
        results.innerHTML = '<div class="crf-member-result-empty"><span class="spinner-border spinner-border-sm"></span> Mencari...</div>';
        results.classList.remove('d-none');
        fetch(appBase + '/actions/user_search.php?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
          .then(function (response) {
            if (!response.ok) { throw new Error('HTTP ' + response.status); }
            return response.json();
          })
          .then(function (users) {
            if (lastQuery === query) { render(Array.isArray(users) ? users : []); }
          })
          .catch(function () {
            results.innerHTML = '<div class="crf-member-result-empty text-danger">Gagal memuat data user.</div>';
          });
      }, 250);
    });

    document.addEventListener('click', function (event) {
      if (!form.contains(event.target)) { hideResults(); }
    });

    form.addEventListener('submit', function (event) {
      if (!hidden.value) {
        event.preventDefault();
        input.focus();
      }
    });
  });

  /* -----------------------------------------------------------------
   * Formulir Helpdesk: Permintaan Baru + kategori Butuh CRF
   * ----------------------------------------------------------------- */
  var helpdeskForm = document.getElementById('helpdeskForm');
  if (helpdeskForm) {
    var categorySelect = document.getElementById('helpdesk_category_id');
    var crfNotice = document.getElementById('helpdeskCrfNotice');
    var submitLabel = document.getElementById('helpdeskSubmitLabel');
    var newRequestSection = document.getElementById('helpdeskNewRequest');
    var existingSection = document.getElementById('helpdeskExisting');
    var attachmentsSection = document.getElementById('helpdeskAttachmentSection');

    function currentMode() {
      var checked = helpdeskForm.querySelector('input[name="request_mode"]:checked');
      return checked ? checked.value : 'new';
    }

    function updateHelpdeskForm() {
      var mode = currentMode();
      var option = categorySelect ? categorySelect.options[categorySelect.selectedIndex] : null;
      // Diteruskan ke CRF hanya bila kategori "via CRF" DAN jenis = Request/Permintaan.
      var kindInput = helpdeskForm.querySelector('input[name="request_kind"]:checked');
      var requiresCrf = mode === 'new' && option && option.getAttribute('data-requires-crf') === '1'
        && kindInput && kindInput.value === 'request';

      if (newRequestSection) { newRequestSection.classList.toggle('d-none', mode !== 'new'); }
      if (existingSection) { existingSection.classList.toggle('d-none', mode !== 'existing'); }
      if (crfNotice) { crfNotice.classList.toggle('d-none', !requiresCrf); }
      if (attachmentsSection) { attachmentsSection.classList.toggle('d-none', requiresCrf || mode !== 'new'); }
      if (submitLabel) {
        submitLabel.textContent = requiresCrf ? 'Lanjut ke Form CRF' : 'Kirim Permintaan';
      }
      helpdeskForm.querySelectorAll('[data-new-only]').forEach(function (field) {
        field.disabled = mode !== 'new';
      });
      // Request via CRF: Jam Mulai Laporan tidak dipakai.
      helpdeskForm.querySelectorAll('[data-hide-for-crf]').forEach(function (block) {
        block.classList.toggle('d-none', requiresCrf);
        block.querySelectorAll('input').forEach(function (field) {
          field.disabled = mode !== 'new' || requiresCrf;
        });
      });
    }

    helpdeskForm.querySelectorAll('input[name="request_mode"], input[name="request_kind"]').forEach(function (radio) {
      radio.addEventListener('change', updateHelpdeskForm);
    });
    if (categorySelect) { categorySelect.addEventListener('change', updateHelpdeskForm); }
    updateHelpdeskForm();
  }
});
