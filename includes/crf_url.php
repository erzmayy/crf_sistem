<?php
/**
 * includes/crf_url.php
 * ---------------------------------------------------------------
 * URL dan pengalihan CRF yang sadar mode tampilan.
 *
 *   standalone : halaman diakses langsung, mis. /cmo/detail.php?id=5
 *   module     : halaman dimuat di dalam layout SIAP lewat wrapper
 *                pages/crf/<submod>.php, mis. /index.php?mod=crf&submod=cmo_detail&id=5
 *
 * Aturan pakai:
 *   - Link ke halaman CRF   : crfUrl('cmo.detail', ['id' => 5])
 *   - Proses simpan/unduhan : crfActionUrl('cmo_action.php') (selalu file langsung)
 *   - Pengalihan            : crfRedirect(crfUrl(...)) - header Location bila masih
 *                             bisa, JavaScript + meta refresh bila layout SIAP sudah tercetak
 *
 * Mode diatur lewat config/siap.php (CRF_LAYOUT_MODE, CRF_BASE_URL, CRF_SIAP_INDEX).
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../config/siap.php';

/** True bila CRF ditampilkan di dalam layout SIAP (tanpa header/sidebar sendiri). */
function crfLayoutIsModule(): bool
{
    return (defined('CRF_LAYOUT_MODE') ? CRF_LAYOUT_MODE : '') === 'module' || getenv('CRF_LAYOUT_MODE') === 'module';
}

/**
 * Daftar halaman CRF: kunci rute => [file relatif akar modul, nilai submod di SIAP].
 * Nilai submod harus sama dengan nama file wrapper di pages/crf/ dan link sub modul
 * yang didaftarkan di Menu Managemen SIAP. submod null = halaman milik SIAP (login).
 *
 * @return array<string,array{0:string,1:?string}>
 */
function crfRoutes(): array
{
    return [
        'login'               => ['login.php', null],
        'admin.dashboard'     => ['admin/dashboard.php', 'dashboard'],
        'admin.kategori'      => ['admin/master_data.php', 'kategori'],
        'admin.detail'        => ['admin/detail.php', 'admin_detail'],
        'admin.edit'          => ['admin/edit.php', 'admin_edit'],
        'cmo.index'           => ['cmo/index.php', 'index'],
        'cmo.detail'          => ['cmo/detail.php', 'cmo_detail'],
        'otomasi.index'       => ['otomasi/index.php', 'tindak_lanjut_otm'],
        'otomasi.detail'      => ['otomasi/detail.php', 'otomasi_detail'],
        'otomasi.view'        => ['otomasi/view_detail.php', 'otomasi_view_detail'],
        'kadep.index'         => ['kadep_operasional/index.php', 'kadep_operasional'],
        'kadep.detail'        => ['kadep_operasional/detail.php', 'kadep_operasional_detail'],
        'handling'            => ['crf/handling.php', 'handling'],
        'forum.index'         => ['forum/index.php', 'forum'],
        'forum.detail'        => ['forum/detail.php', 'forum_detail'],
        'user.form'           => ['user/form_crf.php', 'form_crf'],
        'user.pengajuan'      => ['user/pengajuan_saya.php', 'pengajuan_saya'],
        'user.detail'         => ['user/detail.php', 'pengajuan_detail'],
        'user.dashboard'      => ['user/dashboard.php', 'dashboard_saya'],
        'notifications.index' => ['notifications/index.php', 'notifikasi'],
        'notifications.open'  => ['notifications/open.php', 'notifikasi_buka'],
        'crf.open'            => ['crf/open.php', 'crf_open'],
    ];
}

/** Alamat pintu masuk SIAP (index.php yang memuat pages/<mod>/<submod>.php). */
function crfSiapIndex(): string
{
    return defined('CRF_SIAP_INDEX') ? (string) CRF_SIAP_INDEX : '/index.php';
}

/**
 * Awalan URL akar modul CRF (tanpa garis miring akhir).
 * Dipakai untuk file yang selalu diakses langsung: actions/*, assets/*.
 */
function crfAppBase(): string
{
    if (defined('CRF_BASE_URL') && CRF_BASE_URL !== '') {
        return rtrim((string) CRF_BASE_URL, '/');
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = preg_replace('#/(?:admin|user|cmo|otomasi|kadep_operasional|forum|crf|notifications|actions)/[^/]+$#', '', $script);
    if ($base === $script) {
        $base = str_replace('\\', '/', dirname($script)); // skrip di akar modul (login.php, index.php); dirname() di Windows memakai '\'
    }

    return rtrim($base === '.' ? '' : (string) $base, '/');
}

/**
 * URL halaman CRF. Hasilnya belum di-escape: bungkus dengan h() saat dicetak ke HTML.
 *
 * @param array<string,mixed> $query
 */
function crfUrl(string $route, array $query = [], string $fragment = ''): string
{
    $routes = crfRoutes();
    if (!isset($routes[$route])) {
        throw new InvalidArgumentException('Rute CRF tidak dikenal: ' . $route);
    }

    [$file, $submod] = $routes[$route];
    $suffix = $fragment !== '' ? '#' . ltrim($fragment, '#') : '';

    if (crfLayoutIsModule()) {
        $params = $submod === null ? [] : ['mod' => 'crf', 'submod' => $submod];
        $queryString = http_build_query($params + $query);

        return crfSiapIndex() . ($queryString !== '' ? '?' . $queryString : '') . $suffix;
    }

    return crfAppBase() . '/' . $file . ($query ? '?' . http_build_query($query) : '') . $suffix;
}

/**
 * URL file di actions/ (proses simpan, ekspor, unduhan). Selalu file langsung,
 * baik di mode standalone maupun module.
 *
 * @param array<string,mixed> $query
 */
function crfActionUrl(string $file, array $query = []): string
{
    return crfDirectUrl('actions/' . $file, $query);
}

/**
 * URL file CRF yang selalu diakses langsung (tanpa layout SIAP), mis. potongan HTML
 * untuk fetch (forum/detail.php?partial=1). Path relatif terhadap akar modul CRF.
 *
 * @param array<string,mixed> $query
 */
function crfDirectUrl(string $path, array $query = []): string
{
    return crfAppBase() . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
}

/**
 * Mengubah path internal tersimpan (mis. 'forum/index.php?crf_id=5#comment-9' dari
 * notifikasi) menjadi URL sesuai mode tampilan. Path yang tidak dikenal dikembalikan
 * sebagai file langsung.
 */
function crfUrlFromPath(string $path): string
{
    $path = preg_replace('#^(?:\.\./)+#', '', ltrim($path, '/'));
    $fragment = '';
    $queryString = '';

    if (($pos = strpos($path, '#')) !== false) {
        $fragment = substr($path, $pos + 1);
        $path = substr($path, 0, $pos);
    }
    if (($pos = strpos($path, '?')) !== false) {
        $queryString = substr($path, $pos + 1);
        $path = substr($path, 0, $pos);
    }
    parse_str($queryString, $query);

    foreach (crfRoutes() as $route => $definition) {
        if ($definition[0] === $path) {
            return crfUrl($route, $query, $fragment);
        }
    }

    return crfDirectUrl($path, $query) . ($fragment !== '' ? '#' . $fragment : '');
}

/** Submod halaman yang sedang dibuka (hanya huruf, angka, garis bawah). */
function crfCurrentSubmod(): string
{
    return preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_GET['submod'] ?? ''));
}

/**
 * URL halaman yang sedang dibuka dengan query baru (mis. tombol Reset filter).
 * Mode module mempertahankan mod & submod supaya tetap berada di layout SIAP.
 *
 * @param array<string,mixed> $query
 */
function crfSelfUrl(array $query = []): string
{
    if (crfLayoutIsModule()) {
        return crfSiapIndex() . '?' . http_build_query(['mod' => 'crf', 'submod' => crfCurrentSubmod()] + $query);
    }

    return basename($_SERVER['PHP_SELF'] ?? '') . ($query ? '?' . http_build_query($query) : '');
}

/**
 * Input hidden mod & submod untuk form method GET. Tanpa ini, mengirim form GET
 * membuang query string dan pengguna terlempar ke dashboard SIAP. Kosong di mode standalone.
 */
function crfRouteFields(): string
{
    if (!crfLayoutIsModule()) {
        return '';
    }

    return '<input type="hidden" name="mod" value="crf">'
        . '<input type="hidden" name="submod" value="' . htmlspecialchars(crfCurrentSubmod(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Mengalihkan pengguna. header() hanya bekerja sebelum ada output; bila layout SIAP
 * sudah tercetak, dipakai JavaScript + meta refresh + tautan manual sebagai cadangan.
 */
function crfRedirect(string $url): void
{
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }

    $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    echo '<meta http-equiv="refresh" content="0;url=' . $safe . '">'
        . '<script>window.location.replace(' . json_encode($url) . ');</script>'
        . '<p><a href="' . $safe . '">Lanjutkan</a></p>';
    exit;
}
