/* =====================================================================
   CRF Prototype - script.js
   Validasi client-side ringan + interaksi form.
   Validasi asli tetap dilakukan di server (lihat actions/*.php).
   ===================================================================== */

document.addEventListener('DOMContentLoaded', function () {

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

  function updateCategoryDetail() {
    if (!categorySelect) { return; }
    var val = categorySelect.value;

    if (val && categoryHints[val]) {
      categoryDetailWrap.classList.remove('d-none');
      categoryDetailLabel.textContent = 'Detail Kategori - ' + val;
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

    /* -----------------------------------------------------------------
   * 6. Simpan Draft
   * ----------------------------------------------------------------- */
  var btnSaveDraft = document.getElementById('btnSaveDraft');
  var crfForm = document.getElementById('crfForm');

  if (btnSaveDraft && crfForm) {
    btnSaveDraft.addEventListener('click', function () {

      // Arahkan form ke proses save draft
      crfForm.action = '../actions/save_draft.php';

      // Kirim form tanpa menjalankan validasi browser / JS
      crfForm.submit();
    });
  }

  /* -----------------------------------------------------------------
   * 7. Konfirmasi sebelum CRF diajukan
   * ----------------------------------------------------------------- */
  var btnSubmitCrf = document.getElementById('btnSubmitCrf');

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
    crfForm.addEventListener('submit', function () {
      var clickedButton = document.activeElement;
      var submitButtons = crfForm.querySelectorAll('button[type="submit"]');

      submitButtons.forEach(function (btn) {
        btn.disabled = true;
      });

      if (clickedButton && clickedButton.tagName === 'BUTTON') {
        var originalHtml = clickedButton.innerHTML;
        clickedButton.innerHTML =
          '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Memproses...';
      }
    });
  }

});