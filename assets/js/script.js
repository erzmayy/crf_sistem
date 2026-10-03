/* =====================================================================
   CRF Prototype - script.js
   Validasi client-side ringan + interaksi form.
   Validasi asli tetap dilakukan di server (lihat actions/*.php).
   ===================================================================== */

document.addEventListener('DOMContentLoaded', function () {

  function formatSlaDuration(totalSeconds) {
    var totalMinutes = Math.max(0, Math.floor(totalSeconds / 60));
    var days = Math.floor(totalMinutes / 1440);
    var hours = Math.floor((totalMinutes % 1440) / 60);
    var minutes = totalMinutes % 60;
    var parts = [];

    if (days > 0) { parts.push(days + ' Hari'); }
    if (hours > 0) { parts.push(hours + ' Jam'); }
    if (minutes > 0 || parts.length === 0) { parts.push(minutes + ' Menit'); }
    return parts.join(' ');
  }

  function updateLiveSlaStatus() {
    document.querySelectorAll('[data-sla-live="true"]').forEach(function (slaGrid) {
      var dueAt = Number(slaGrid.getAttribute('data-sla-due-at'));
      var remaining = dueAt - Math.floor(Date.now() / 1000);
      var statusBadge = slaGrid.querySelector('[data-sla-status-label]');
      var statusDetail = slaGrid.querySelector('[data-sla-status-detail]');
      var alertBox = slaGrid.parentElement.querySelector('[data-sla-alert]');
      var alertIcon = alertBox ? alertBox.querySelector('[data-sla-alert-icon]') : null;
      var alertMessage = alertBox ? alertBox.querySelector('[data-sla-alert-message]') : null;
      var state = '';

      if (!Number.isFinite(dueAt) || !statusBadge || !statusDetail) { return; }

      if (remaining >= 0) {
        var approaching = remaining <= 3600;
        statusBadge.textContent = 'Masih dalam SLA';
        statusDetail.textContent = 'Sisa waktu ' + formatSlaDuration(remaining) + '.';
        statusBadge.className = 'badge text-bg-' + (approaching ? 'warning' : 'success');
        state = approaching ? 'approaching' : '';
      } else {
        statusBadge.textContent = 'Melewati SLA';
        statusDetail.textContent = 'Terlambat ' + formatSlaDuration(Math.abs(remaining)) + '.';
        statusBadge.className = 'badge text-bg-danger';
        state = 'overdue';
      }

      if (!alertBox) { return; }
      alertBox.classList.remove('alert-warning', 'alert-danger');
      if (!state) {
        alertBox.classList.add('d-none');
        alertBox.setAttribute('data-sla-alert-state', '');
        return;
      }

      alertBox.classList.remove('d-none');
      alertBox.classList.add(state === 'overdue' ? 'alert-danger' : 'alert-warning');
      alertBox.setAttribute('data-sla-alert-state', state);
      if (alertIcon) {
        alertIcon.className = 'bi bi-' + (state === 'overdue' ? 'exclamation-triangle-fill' : 'clock-fill');
      }
      if (alertMessage) {
        alertMessage.textContent = state === 'overdue'
          ? 'Peringatan: batas SLA telah terlewati.'
          : 'Perhatian: batas waktu SLA tinggal 1 jam atau kurang.';
      }
    });

    document.querySelectorAll('[data-sla-countdown="true"]').forEach(function (slaCell) {
      var dueAt = Number(slaCell.getAttribute('data-sla-due-at'));
      var remaining = dueAt - Math.floor(Date.now() / 1000);
      var statusBadge = slaCell.querySelector('[data-sla-status-label]');
      var statusDetail = slaCell.querySelector('[data-sla-status-detail]');

      if (!Number.isFinite(dueAt) || !statusBadge || !statusDetail) { return; }

      if (remaining >= 0) {
        var approaching = remaining <= 3600;
        statusBadge.textContent = 'Masih dalam SLA';
        statusBadge.className = 'badge text-bg-' + (approaching ? 'warning' : 'success');
        statusDetail.textContent = 'Sisa waktu ' + formatSlaDuration(remaining) + '.';
        return;
      }

      statusBadge.textContent = 'SLA Terlewati';
      statusBadge.className = 'badge text-bg-danger';
      statusDetail.textContent = 'Sudah melewati SLA selama '
        + formatSlaDuration(Math.abs(remaining)) + '.';
    });
  }

  updateLiveSlaStatus();
  if (
    document.querySelector('[data-sla-live="true"]')
    || document.querySelector('[data-sla-countdown="true"]')
  ) {
    window.setInterval(updateLiveSlaStatus, 60000);
  }

  /* -----------------------------------------------------------------
   * 1. Tampilkan field "Detail Kategori" sesuai kategori yang dipilih
   * ----------------------------------------------------------------- */
  var categorySelect = document.getElementById('change_category');
  var categoryDetailWrap = document.getElementById('category-detail-wrap');
  var categoryDetailLabel = document.getElementById('category-detail-label');
  var categoryDetailInput = document.getElementById('change_category_detail');

  var categoryHints = {
    'Aplikasi': 'Nama Aplikasi / Modul (contoh: CL, PKS, PKWT, Absensi)',
    'Infrastruktur': 'Jenis Infrastruktur (contoh: LAN, WAN)',
    'Proses': 'Proses yang dimaksud (contoh: Payroll)',
    'Security': 'Jenis permintaan (contoh: User ID, Password, Kewenangan Menu)',
    'Lainnya': 'Jelaskan kategori perubahan yang dimaksud'
  };

  // Kategori CRF dinamis: nilai option = id kategori, kelompok lama ada di data-legacy.
  function legacyCategoryValue(select) {
    if (!select || select.selectedIndex < 0) { return ''; }
    var option = select.options[select.selectedIndex];
    return option.getAttribute('data-legacy') || option.value || '';
  }

  function updateCategoryDetail() {
    if (!categorySelect) { return; }
    var val = legacyCategoryValue(categorySelect);

    if (val && categoryHints[val]) {
      categoryDetailWrap.classList.remove('d-none');
      categoryDetailLabel.textContent = 'Detail Kategori - '
        + categorySelect.options[categorySelect.selectedIndex].text.trim();
      categoryDetailInput.placeholder = categoryHints[val];
      // Sesuai brief butir 14: hanya kategori "Lainnya" yang wajib diisi.
      categoryDetailInput.required = (val === 'Lainnya');
    } else {
      categoryDetailWrap.classList.add('d-none');
      categoryDetailInput.required = false;
    }
  }

  if (categorySelect) {
    categorySelect.addEventListener('change', updateCategoryDetail);
    updateCategoryDetail(); // set kondisi awal (misalnya saat edit draft)
  }

  /* -----------------------------------------------------------------
   * 2. Nominal anggaran hanya wajib jika salah satu pilihan biaya dipilih
   * ----------------------------------------------------------------- */
  var budgetRadios = document.querySelectorAll('input[name="budget_type"]');
  var budgetAmountInput = document.getElementById('budget_amount');

  function toggleBudgetAmount() {
    var anySelected = false;
    budgetRadios.forEach(function (radio) {
      if (radio.checked) { anySelected = true; }
    });
    if (budgetAmountInput) {
      budgetAmountInput.disabled = !anySelected;
    }
  }

  if (budgetRadios.length) {
    budgetRadios.forEach(function (radio) {
      radio.addEventListener('change', toggleBudgetAmount);
    });
    toggleBudgetAmount();
  }

  /* -----------------------------------------------------------------
   * 3. Tampilkan nama file yang dipilih pada input upload
   * ----------------------------------------------------------------- */
 // SESUDAH
var fileInput = document.getElementById('attachments');
var fileList = document.getElementById('file-list-preview');
var selectedFiles = [];

var CRF_MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB, samakan dgn includes/functions.php
var CRF_ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

function getFileExt(name) {
  return name.split('.').pop().toLowerCase();
}

function syncInputFromSelectedFiles() {
  var dt = new DataTransfer();
  selectedFiles.forEach(function (file) {
    dt.items.add(file);
  });
  fileInput.files = dt.files;
}

function renderFileList() {
  fileList.innerHTML = '';
  if (selectedFiles.length === 0) { return; }

  var ul = document.createElement('ul');
  ul.className = 'mb-0 ps-3 list-unstyled';

  selectedFiles.forEach(function (file, index) {
    var li = document.createElement('li');
    li.className = 'd-flex justify-content-between align-items-center py-1';

    var sizeKb = Math.round(file.size / 1024);
    var ext = getFileExt(file.name);
    var isTooBig = file.size > CRF_MAX_FILE_SIZE;
    var isBadExt = CRF_ALLOWED_EXT.indexOf(ext) === -1;
    var hasError = isTooBig || isBadExt;

    var label = document.createElement('span');
    label.className = hasError ? 'text-danger' : '';
    label.textContent = file.name + ' (' + sizeKb + ' KB)'
      + (isTooBig ? ' — melebihi 5 MB' : '')
      + (isBadExt ? ' — format tidak didukung' : '');

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-sm btn-outline-danger py-0 px-2 ms-2';
    removeBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
    removeBtn.setAttribute('aria-label', 'Hapus file ' + file.name);
    removeBtn.addEventListener('click', function () {
      selectedFiles.splice(index, 1);
      syncInputFromSelectedFiles();
      renderFileList();
    });

    li.appendChild(label);
    li.appendChild(removeBtn);
    ul.appendChild(li);
  });

  fileList.appendChild(ul);
}

if (fileInput && fileList) {
  fileInput.addEventListener('change', function () {
    // Tambahkan file baru ke daftar yang sudah ada (bukan replace),
    // supaya user bisa menambah lampiran bertahap.
    Array.prototype.forEach.call(fileInput.files, function (file) {
      selectedFiles.push(file);
    });
    syncInputFromSelectedFiles();
    renderFileList();
  });
}

  /* -----------------------------------------------------------------
   * 4. Validasi Bootstrap standar untuk form yang butuh validasi
   * ----------------------------------------------------------------- */
  var formsToValidate = document.querySelectorAll('.needs-validation');

  formsToValidate.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!form.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
      }
      form.classList.add('was-validated');
    }, false);
  });

  /* -----------------------------------------------------------------
   * 5. Konfirmasi sebelum admin mengubah status ke Solve / Cancel
   * ----------------------------------------------------------------- */
  var statusSelect = document.getElementById('status');

  if (statusSelect) {
    statusSelect.addEventListener('change', function () {
      if (statusSelect.value === 'Solve') {
        if (!confirm('Yakin ingin menandai pengajuan ini sebagai Solve (Selesai)?')) {
          statusSelect.value = statusSelect.dataset.previous || 'Dalam Proses';
        }
      } else if (statusSelect.value === 'Cancel') {
        if (!confirm('Yakin ingin membatalkan pengajuan ini?')) {
          statusSelect.value = statusSelect.dataset.previous || 'Dalam Proses';
        }
      }
    });
    statusSelect.dataset.previous = statusSelect.value;
  }

  var crfForm = document.getElementById('crfForm');

  /* -----------------------------------------------------------------
   * 7. Konfirmasi sebelum CRF diajukan
   * ----------------------------------------------------------------- */
  var btnSubmitCrf = document.getElementById('btnSubmitCrf');
  var btnSaveDraft = document.getElementById('btnSaveDraft');
  var validationAlert = document.getElementById('validationAlert');
  var validationList = document.getElementById('validationList');
  var requireCompleteForm = false;

  function setSubmitButtonsDisabled(disabled) {
    var actions = crfForm ? crfForm.querySelector('.crf-sticky-actions') : null;
    if (actions) {
      actions.classList.toggle('crf-form-ready', requireCompleteForm && !disabled);
    }

    [btnSaveDraft, btnSubmitCrf].forEach(function (button) {
      if (button) {
        button.disabled = disabled;
      }
    });
  }

  function isCrfFormComplete() {
    var requiredFieldIds = [
      'full_name',
      'phone',
      'email',
      'from_department',
      'from_division',
      'request_type',
      'change_description',
      'benefit',
      'impact_category',
      'impact',
      'reason',
      'alternative_suggestion'
    ];
    var isComplete = requiredFieldIds.every(function (id) {
      var field = document.getElementById(id);
      return field && field.value.trim() !== '';
    });

    var email = document.getElementById('email');
    if (isComplete && email
      && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
      isComplete = false;
    }

    var category = document.getElementById('change_category');
    if (isComplete && category && category.value === '') {
      isComplete = false;
    }

    var categoryDetail = document.getElementById('change_category_detail');
    if (isComplete && category && legacyCategoryValue(category) === 'Lainnya'
      && categoryDetail && categoryDetail.value.trim() === '') {
      isComplete = false;
    }

    var selectedBudget = document.querySelector('input[name="budget_type"]:checked');
    if (isComplete && !selectedBudget) {
      isComplete = false;
    }

    if (isComplete && selectedBudget) {
      var budgetAmount = document.getElementById('budget_amount');
      if (!budgetAmount || budgetAmount.value.trim() === ''
        || Number(budgetAmount.value) < 0) {
        isComplete = false;
      }
    }

    return isComplete;
  }

  function clearInlineErrors() {
    crfForm.querySelectorAll('.crf-inline-error').forEach(function (error) {
      error.remove();
    });
    crfForm.querySelectorAll('.is-invalid').forEach(function (field) {
      field.classList.remove('is-invalid');
      field.removeAttribute('aria-invalid');
    });
  }

  function addInlineError(field, message, errors) {
    if (!field) { return; }

    field.classList.add('is-invalid');
    field.setAttribute('aria-invalid', 'true');

    var error = document.createElement('div');
    error.className = 'invalid-feedback crf-inline-error';
    error.textContent = message;
    field.insertAdjacentElement('afterend', error);
    errors.push({ field: field, message: message });
  }

  function validateCrfForm() {
    clearInlineErrors();
    var errors = [];

    [
      ['full_name', 'Nama lengkap wajib diisi.'],
      ['phone', 'Nomor handphone/WA wajib diisi.'],
      ['email', 'Email wajib diisi.'],
      ['from_department', 'Departemen wajib diisi.'],
      ['from_division', 'Divisi wajib diisi.'],
      ['request_type', 'Tipe pengajuan wajib dipilih.'],
      ['change_description', 'Rincian permohonan perubahan wajib diisi.'],
      ['benefit', 'Benefit perubahan wajib diisi.'],
      ['impact_category', 'Dampak jika tidak dilakukan perubahan wajib dipilih.'],
      ['impact', 'Penjelasan dampak wajib diisi.'],
      ['reason', 'Alasan permohonan perubahan wajib diisi.'],
      ['alternative_suggestion', 'Saran alternatif wajib diisi.']
    ].forEach(function (item) {
      var field = document.getElementById(item[0]);
      if (field && field.value.trim() === '') {
        addInlineError(field, item[1], errors);
      }
    });

    var email = document.getElementById('email');
    if (email && email.value.trim() !== ''
      && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
      addInlineError(email, 'Format email belum valid.', errors);
    }

    var category = document.getElementById('change_category');
    if (category && category.value === '') {
      addInlineError(category, 'Kategori perubahan wajib dipilih.', errors);
    }

    var categoryDetail = document.getElementById('change_category_detail');
    if (category && legacyCategoryValue(category) === 'Lainnya'
      && categoryDetail && categoryDetail.value.trim() === '') {
      addInlineError(categoryDetail, 'Detail kategori wajib diisi.', errors);
    }

    var selectedBudget = document.querySelector('input[name="budget_type"]:checked');
    if (!selectedBudget) {
      var budgetOptions = document.getElementById('budgetOptions');
      var firstBudgetOption = document.querySelector('input[name="budget_type"]');
      if (budgetOptions && firstBudgetOption) {
        firstBudgetOption.setAttribute('aria-invalid', 'true');
        var budgetError = document.createElement('div');
        budgetError.className = 'invalid-feedback crf-inline-error';
        budgetError.textContent = 'Biaya / anggaran wajib dipilih.';
        budgetOptions.insertAdjacentElement('afterend', budgetError);
        errors.push({ field: firstBudgetOption, message: budgetError.textContent });
      }
    } else {
      var budgetAmount = document.getElementById('budget_amount');
      if (!budgetAmount || budgetAmount.value.trim() === '') {
        addInlineError(budgetAmount, 'Nominal biaya / anggaran wajib diisi.', errors);
      } else if (Number(budgetAmount.value) < 0) {
        addInlineError(budgetAmount, 'Nominal biaya / anggaran tidak valid.', errors);
      }
    }

    if (validationAlert && validationList) {
      validationList.innerHTML = '';
      errors.forEach(function (item) {
        var listItem = document.createElement('li');
        listItem.textContent = item.message;
        validationList.appendChild(listItem);
      });
      validationAlert.classList.toggle('d-none', errors.length === 0);
    }

    if (errors.length) {
      errors[0].field.focus();
      errors[0].field.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return false;
    }

    return true;
  }

  if (btnSubmitCrf && crfForm) {
    btnSubmitCrf.addEventListener('click', function (event) {
      var isResubmission = btnSubmitCrf.dataset.resubmission === '1';
      var message = isResubmission
        ? 'Pengajuan akan dikirim ulang setelah diperbaiki. Lanjutkan?'
        : 'Setelah diajukan, CRF akan masuk ke proses pemeriksaan. Pastikan data sudah benar. Lanjutkan?';

      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });

    crfForm.addEventListener('submit', function (event) {
      var submitter = event.submitter;
      var isDraftAction = submitter && submitter.value === 'draft';

      if (!event.defaultPrevented && !isDraftAction && !validateCrfForm()) {
        event.preventDefault();
        requireCompleteForm = true;
        setSubmitButtonsDisabled(true);
      }
    });

    crfForm.addEventListener('input', updateSubmitButtons);
    crfForm.addEventListener('change', updateSubmitButtons);
  }

  function updateSubmitButtons() {
    if (!requireCompleteForm || !crfForm) {
      return;
    }

    var isComplete = isCrfFormComplete();
    setSubmitButtonsDisabled(!isComplete);

    if (isComplete) {
      clearInlineErrors();
      if (validationAlert) {
        validationAlert.classList.add('d-none');
      }
    }
  }

  /* -----------------------------------------------------------------
   * 8. Toggle sidebar mobile (hamburger + overlay)
   * ----------------------------------------------------------------- */
  var appShell = document.querySelector('.crf-app-shell');
  var sidebarToggle = document.querySelector('.crf-sidebar-toggle');
  var sidebarOverlay = document.querySelector('.crf-sidebar-overlay');

  function closeSidebar() {
    if (appShell) { appShell.classList.remove('crf-sidebar-open'); }
  }

  if (sidebarToggle && appShell) {
    sidebarToggle.addEventListener('click', function () {
      appShell.classList.toggle('crf-sidebar-open');
    });
  }

  if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeSidebar);
  }

  // Tutup sidebar otomatis kalau salah satu link menu diklik (mobile)
  document.querySelectorAll('.crf-sidebar-nav a, .crf-sidebar-footer a').forEach(function (link) {
    link.addEventListener('click', closeSidebar);
  });

  /* -----------------------------------------------------------------
   * 9. Cegah double-submit pada form CRF (submit & simpan draft)
   * ----------------------------------------------------------------- */
  if (crfForm) {
    crfForm.addEventListener('submit', function (event) {
      if (event.defaultPrevented) {
        return;
      }

      var clickedButton = event.submitter || document.activeElement;
      var submitButtons = crfForm.querySelectorAll('button[type="submit"]');

      submitButtons.forEach(function (btn) {
        btn.disabled = true;
      });

      if (clickedButton && clickedButton.tagName === 'BUTTON') {
        clickedButton.innerHTML =
          '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Memproses...';
      }
    });
  }

});