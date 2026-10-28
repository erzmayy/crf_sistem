<?php
/**
 * Partial: Informasi SLA (hanya untuk peran internal).
 * Dua panel berdampingan: Respon (sampai PIC menekan "Mulai kerjakan") dan
 * Resolusi (sampai eksekusi selesai). Panel Respon hanya tampil bila CRF punya
 * target respon atau sudah direspons. Rincian waktu bisa dibuka.
 * Atribut data-sla-* dipertahankan karena dipakai script.js untuk hitung mundur.
 *
 * Variabel yang HARUS sudah ada di scope pemanggil:
 *   array $crf
 */

$slaValueDisplay = (!empty($crf['sla_nilai']) && !empty($crf['sla_satuan']))
    ? rtrim(rtrim(number_format((float) $crf['sla_nilai'], 2, '.', ''), '0'), '.') . ' ' . $crf['sla_satuan']
    : null;
$slaStatus = getCrfSlaStatus($crf);
// Standar matriks Kategori x Urgensi, untuk menandai SLA yang disesuaikan.
$slaStandard = empty($crf['level_urgensi_final'])
    ? crfStandardSla(getConnection(), (int) ($crf['kategori_id'] ?? 0), crfEffectiveUrgency($crf))
    : null;
$slaAdjusted = $slaValueDisplay !== null && $slaStandard !== null
    && !crfSlaEquals($slaStandard, $crf['sla_nilai'], $crf['sla_satuan']);
$slaIsLive = !empty($slaStatus['live']);
$slaStartedEpoch = !empty($crf['sla_mulai'])
    ? strtotime($crf['sla_mulai'])
    : false;
$slaDueEpoch = !empty($crf['sla_tenggat'])
    ? strtotime($crf['sla_tenggat'])
    : false;
$slaDate = static fn(?string $v): string => !empty($v) ? date('d-m-Y H:i', strtotime($v)) : '-';

// SLA respon PIC: hanya tampil bila CRF punya target respon atau sudah direspons.
$responStatus = getCrfResponStatus($crf);
$responLive = $responStatus !== null && !empty($responStatus['live']);
$responTargetDisplay = (!empty($crf['sla_respon_nilai']) && !empty($crf['sla_respon_satuan']))
    ? slaLabel($crf['sla_respon_nilai'], $crf['sla_respon_satuan'])
    : null;
$responDueEpoch = !empty($crf['sla_respon_tenggat']) ? strtotime($crf['sla_respon_tenggat']) : false;
$responPercent = $responStatus !== null
    ? crfSlaProgressPercent($crf['sla_mulai'] ?? null, $crf['sla_respon_tenggat'] ?? null, $crf['sla_respon_at'] ?? null)
    : null;
$slaPercent = crfSlaProgressPercent($crf['sla_mulai'] ?? null, $crf['sla_tenggat'] ?? null, $crf['otomasi_selesai'] ?? null);
?>
<section class="crf-section crf-detail-card crf-sla-card mb-4" aria-labelledby="crf-sla-title">
    <div class="crf-section-header">
        <span class="crf-section-number"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
        <h2 id="crf-sla-title">Informasi SLA</h2>
    </div>

    <div class="crf-section-body">
        <div class="crf-sla-panels<?= $responStatus !== null ? ' has-respon' : '' ?>">

            <?php if ($responStatus !== null): ?>
                <!-- RESPON -->
                <div
                    class="crf-sla-panel"
                    <?= $responLive && $responDueEpoch !== false
                        ? 'data-sla-respon-live="true" data-sla-started-at="' . (int) $slaStartedEpoch . '" data-sla-due-at="' . (int) $responDueEpoch . '"'
                        : '' ?>
                >
                    <div class="crf-sla-panel-head" title="Dihitung sampai PIC menekan Mulai kerjakan">
                        <h3 class="crf-sla-panel-title">Response Time</h3>
                        <span class="badge text-bg-<?= h($responStatus['class']) ?>" data-sla-respon-label><?= h($responStatus['label']) ?></span>
                    </div>

                    <dl class="crf-sla-metrics">
                        <div><dt>Target</dt><dd><?= $responTargetDisplay !== null ? h($responTargetDisplay) . ' <small>jam kerja</small>' : '-' ?></dd></div>
                        <div><dt>Batas</dt><dd><?= h($slaDate($crf['sla_respon_tenggat'] ?? null)) ?></dd></div>
                        <div><dt>Direspons</dt><dd><?= !empty($crf['sla_respon_at']) ? h($slaDate($crf['sla_respon_at'])) : 'Belum' ?></dd></div>
                    </dl>

                    <?php if ($responPercent !== null): ?>
                        <div class="crf-sla-bar is-<?= h($responStatus['class']) ?>" role="progressbar" aria-label="Response time terpakai" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $responPercent ?>" data-sla-bar>
                            <span style="width: <?= (int) $responPercent ?>%"></span>
                        </div>
                    <?php endif; ?>
                    <p class="crf-sla-status-detail" data-sla-respon-detail><?= h($responStatus['detail']) ?></p>

                    <?php if ($responLive): ?>
                        <div
                            class="alert <?= $responStatus['alert'] === null ? 'd-none' : 'alert-' . ($responStatus['alert'] === 'overdue' ? 'danger' : 'warning') ?> crf-sla-alert"
                            role="alert"
                            data-sla-respon-alert
                        >
                            <i class="bi bi-<?= $responStatus['alert'] === 'overdue' ? 'exclamation-triangle-fill' : 'clock-fill' ?>" data-sla-respon-alert-icon aria-hidden="true"></i>
                            <span data-sla-respon-alert-message><?= $responStatus['alert'] === 'overdue' ? 'Batas response time terlewati. PIC CRF belum mulai mengerjakan.' : 'Batas response time hampir terlewati.' ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- RESOLUSI -->
            <div
                class="crf-sla-panel"
                <?= $slaIsLive && $slaDueEpoch !== false
                    ? 'data-sla-live="true" data-sla-started-at="' . (int) $slaStartedEpoch . '" data-sla-due-at="' . (int) $slaDueEpoch . '"'
                    : '' ?>
            >
                <div class="crf-sla-panel-head" title="Dihitung sampai eksekusi selesai">
                    <h3 class="crf-sla-panel-title">Resolution Time</h3>
                    <span class="badge text-bg-<?= h($slaStatus['class']) ?>" data-sla-status-label><?= h($slaStatus['label']) ?></span>
                </div>

                <dl class="crf-sla-metrics">
                    <div><dt>Target</dt><dd><?= $slaValueDisplay !== null ? h($slaValueDisplay) . ' <small>jam kerja</small>' : '<span class="crf-sla-empty">Belum ditentukan</span>' ?></dd></div>
                    <div><dt>Batas</dt><dd><?= h($slaDate($crf['sla_tenggat'] ?? null)) ?></dd></div>
                    <div><dt>Selesai</dt><dd><?= !empty($crf['otomasi_selesai']) ? h($slaDate($crf['otomasi_selesai'])) : 'Belum' ?></dd></div>
                </dl>

                <?php if ($slaPercent !== null): ?>
                    <div class="crf-sla-bar is-<?= h($slaStatus['class']) ?>" role="progressbar" aria-label="Resolution time terpakai" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $slaPercent ?>" data-sla-bar>
                        <span style="width: <?= (int) $slaPercent ?>%"></span>
                    </div>
                <?php endif; ?>
                <p class="crf-sla-status-detail" data-sla-status-detail><?= h($slaStatus['detail']) ?></p>

                <?php if ($slaIsLive): ?>
                    <div
                        class="alert <?= $slaStatus['alert'] === null ? 'd-none' : 'alert-' . ($slaStatus['alert'] === 'overdue' ? 'danger' : 'warning') ?> crf-sla-alert"
                        role="alert"
                        data-sla-alert
                        data-sla-alert-state="<?= h($slaStatus['alert'] ?? '') ?>"
                    >
                        <i class="bi bi-<?= $slaStatus['alert'] === 'overdue' ? 'exclamation-triangle-fill' : 'clock-fill' ?>" data-sla-alert-icon aria-hidden="true"></i>
                        <span data-sla-alert-message><?= $slaStatus['alert'] === 'overdue' ? 'Peringatan: batas SLA telah terlewati.' : 'Perhatian: batas waktu SLA hampir terlewati.' ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($slaAdjusted): ?>
            <p class="crf-sla-note">
                <i class="bi bi-pencil" aria-hidden="true"></i>
                Disesuaikan dari standar <?= h(slaLabel($slaStandard['value'], $slaStandard['unit'])) ?> (alasan di riwayat).
            </p>
        <?php endif; ?>

        <details class="crf-sla-details">
            <summary>Rincian waktu</summary>
            <p class="crf-sla-legend">
                Response Time dihitung sejak persetujuan Kadep sampai PIC menekan Mulai kerjakan.
                Resolution Time dihitung sampai eksekusi selesai. Keduanya memakai jam kerja 08:00-17:00.
            </p>
            <dl class="crf-meta-row">
                <div><dt>Level urgensi</dt><dd><?= h($crf['level'] ?? 'Belum ditentukan') ?></dd></div>
                <div><dt>Mulai SLA</dt><dd><?= h($slaDate($crf['sla_mulai'] ?? null)) ?></dd></div>
                <?php if ($responStatus !== null): ?>
                    <div><dt>Batas response time</dt><dd><?= h($slaDate($crf['sla_respon_tenggat'] ?? null)) ?></dd></div>
                    <div><dt>Waktu response</dt><dd><?= !empty($crf['sla_respon_at']) ? h($slaDate($crf['sla_respon_at'])) : 'Belum direspons' ?></dd></div>
                <?php endif; ?>
                <div><dt>Batas SLA</dt><dd><?= h($slaDate($crf['sla_tenggat'] ?? null)) ?></dd></div>
                <div><dt>Waktu selesai</dt><dd><?= !empty($crf['otomasi_selesai']) ? h($slaDate($crf['otomasi_selesai'])) : 'Belum selesai' ?></dd></div>
                <div><dt>Durasi pengerjaan</dt><dd><?= $slaStatus['elapsed'] !== null ? h($slaStatus['elapsed']) : '-' ?></dd></div>
            </dl>
        </details>

        <?php if (!empty($crf['sla_hasil'])): ?>
            <?php $slaOnTime = $crf['sla_hasil'] === 'Sesuai SLA'; ?>
            <div class="crf-sla-result <?= $slaOnTime ? 'is-success' : 'is-danger' ?>">
                <div><span class="crf-detail-label">Durasi SLA</span><strong><?= h(formatSlaDuration(((int) $crf['sla_menit_aktual']) * 60)) ?></strong></div>
                <div><span class="crf-detail-label">Target SLA</span><strong><?= h($slaValueDisplay ?? '-') ?></strong></div>
                <div><span class="crf-detail-label">Status</span><strong><?= $slaOnTime ? '✓ Sesuai SLA' : '⚠ Melebihi SLA' ?></strong></div>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php
unset($slaValueDisplay, $slaStatus, $slaStandard, $slaAdjusted, $slaIsLive, $slaStartedEpoch, $slaDueEpoch, $slaDate, $slaOnTime, $responStatus, $responLive, $responTargetDisplay, $responDueEpoch, $responPercent, $slaPercent);
