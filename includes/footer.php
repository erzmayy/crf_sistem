<?php
if (crfLayoutIsModule()) {
    // Mode module: tutup .crf-content-shell dan .crf-module, lalu muat script CRF saja.
    ?>
</div>
</div>
<?php if (defined('CRF_LOAD_BOOTSTRAP') && CRF_LOAD_BOOTSTRAP): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>
<script src="<?= h($appBasePath ?? '') ?>/assets/js/script.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/script.js') ?>"></script>
<script src="<?= h($appBasePath ?? '') ?>/assets/js/integration.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/integration.js') ?>"></script>
<?php
    return;
}
?>
    <footer class="crf-footer">
      <small>Change Request Form &middot; PT Persona Prima Utama</small>
    </footer>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= h($appBasePath ?? '') ?>/assets/js/script.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/script.js') ?>"></script>
<script src="<?= h($appBasePath ?? '') ?>/assets/js/integration.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/integration.js') ?>"></script>
</body>
</html>
