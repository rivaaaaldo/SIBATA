<?php
require __DIR__ . '/config.php';

$KONDISI = ['baik', 'rusak ringan', 'rusak berat'];
$STATUS  = ['menunggu', 'disetujui', 'dipinjam', 'selesai', 'ditolak', 'dibatalkan'];

/* =========================================================
 * 0. HELPER BARU: toast, URL admin, paginasi
 *    (boleh dipindahkan ke config.php bila ingin dipakai ulang)
 * ========================================================= */

/** JSON aman untuk disisipkan di <script type="application/json"> */
function toastJson(array $toasts): string
{
    return json_encode($toasts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

/** Bangun URL admin.php dari query saat ini (mis. nomor halaman tetap dipertahankan). */
function adminUrl(array $set = [], array $drop = [], string $anchor = ''): string
{
    $q = $_GET;
    foreach ($drop as $k) unset($q[$k]);
    foreach ($set as $k => $v) $q[$k] = $v;
    $qs = http_build_query($q);
    return 'admin.php' . ($qs !== '' ? '?' . $qs : '') . ($anchor !== '' ? '#' . $anchor : '');
}

/** Hitung halaman aktif (otomatis dibatasi 1..jumlah halaman). */
function paginate(int $total, int $perPage, string $param): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min(max(1, (int)($_GET[$param] ?? 1)), $pages);
    return ['total' => $total, 'perPage' => $perPage, 'pages' => $pages,
            'page' => $page, 'offset' => ($page - 1) * $perPage];
}

/** Render navigasi Prev / 1 2 3 … / Next. */
function renderPagination(array $p, string $param, string $anchor, string $label): string
{
    if ($p['total'] === 0) return '';
    $dari   = $p['offset'] + 1;
    $sampai = min($p['offset'] + $p['perPage'], $p['total']);

    $h  = '<nav class="pagination" aria-label="' . e($label) . '">';
    $h .= '<span class="pg-info">Menampilkan ' . $dari . '–' . $sampai . ' dari ' . $p['total'] . '</span>';

    if ($p['pages'] > 1) {
        $url = static fn(int $n): string => e(adminUrl([$param => $n], ['edit'], $anchor));

        $nums = [1, $p['pages']];
        for ($i = $p['page'] - 2; $i <= $p['page'] + 2; $i++) {
            if ($i > 1 && $i < $p['pages']) $nums[] = $i;
        }
        $nums = array_values(array_unique($nums));
        sort($nums);

        $h .= '<ul class="pg-list">';
        $h .= $p['page'] > 1
            ? '<li><a class="pg" rel="prev" href="' . $url($p['page'] - 1) . '">&lsaquo; Sebelumnya</a></li>'
            : '<li><span class="pg off">&lsaquo; Sebelumnya</span></li>';

        $prev = 0;
        foreach ($nums as $n) {
            if ($prev && $n - $prev > 1) $h .= '<li><span class="pg gap">…</span></li>';
            $h .= $n === $p['page']
                ? '<li><span class="pg on" aria-current="page">' . $n . '</span></li>'
                : '<li><a class="pg" href="' . $url($n) . '" aria-label="Halaman ' . $n . '">' . $n . '</a></li>';
            $prev = $n;
        }

        $h .= $p['page'] < $p['pages']
            ? '<li><a class="pg" rel="next" href="' . $url($p['page'] + 1) . '">Berikutnya &rsaquo;</a></li>'
            : '<li><span class="pg off">Berikutnya &rsaquo;</span></li>';
        $h .= '</ul>';
    }
    return $h . '</nav>';
}

/* =========================================================
 * 1. LOGIN / LOGOUT
 *    Halaman ini mengizinkan upload file, jadi WAJIB diproteksi.
 *    (Untuk produksi, tambahkan pembatasan percobaan login.)
 * ========================================================= */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'login') {
    if (!csrfValid($_POST['csrf'] ?? null)) {
        $loginError = 'Sesi tidak valid, muat ulang halaman.';
    } elseif (password_verify((string)($_POST['password'] ?? ''), ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $loginError = 'Password salah.';
    }
}

if (empty($_SESSION['admin'])) {
    $loginToasts = $loginError ? [['type' => 'err', 'msg' => $loginError]] : [];
    ?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login Admin</title>
<link rel="stylesheet" href="assets/css/style.css">
<script src="assets/js/script.js" defer></script>
</head><body class="login">
<form method="post" class="card box">
  <div class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3h6M10 3v6L4.5 19a1.5 1.5 0 0 0 1.3 2.2h12.4a1.5 1.5 0 0 0 1.3-2.2L14 9V3"/><path d="M7.5 15h9"/></svg></div>
  <h1>Login Admin Lab</h1>
  <p>Masuk untuk mengelola inventaris dan peminjaman.</p>
  <input type="hidden" name="aksi" value="login">
  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
  <label>Password
    <input type="password" name="password" placeholder="Masukkan password" required autofocus>
  </label>
  <button type="submit" class="btn block">Masuk</button>
  <a class="back" href="index.php">&larr; Kembali ke katalog</a>
</form>
<?php if ($loginToasts): ?><script type="application/json" id="toast-data"><?= toastJson($loginToasts) ?></script><?php endif; ?>
</body></html>

    <?php
    exit;
}

/* =========================================================
 * 2. FUNGSI UPLOAD FOTO
 * ========================================================= */
function simpanFoto(array $file): ?string
{
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;            // tidak ada foto baru
    if ($file['error'] !== UPLOAD_ERR_OK)       throw new RuntimeException('Upload foto gagal.');
    if ($file['size'] > MAX_FOTO_BYTES)         throw new RuntimeException('Ukuran foto maksimal 2 MB.');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ekstensi = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($ekstensi[$mime]) || @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('Foto harus berformat JPG, PNG, atau WEBP.');
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true)) {
        throw new RuntimeException('Folder uploads tidak dapat dibuat.');
    }
    // Nama acak + ekstensi dari MIME (bukan dari nama file pengguna) -> aman
    $nama = bin2hex(random_bytes(12)) . '.' . $ekstensi[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $nama)) {
        throw new RuntimeException('Gagal menyimpan foto. Periksa izin folder uploads/.');
    }
    return $nama;
}

function hapusFile(?string $nama): void
{
    if ($nama) {
        $path = UPLOAD_DIR . basename($nama);
        if (is_file($path)) @unlink($path);
    }
}

/* =========================================================
 * 3. AKSI POST (tambah/ubah/hapus alat, ubah status transaksi)
 *    Pola Post-Redirect-Get dengan pesan flash.
 *    Redirect mempertahankan nomor halaman paginasi (tanpa ?edit=).
 * ========================================================= */
$pdo = pdo();
$errors = [];
$formData = null;   // isian form alat yang dikirim ulang saat validasi gagal (agar modal tidak kosong)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') !== 'login') {
    $aksi = (string)($_POST['aksi'] ?? '');

    if (!csrfValid($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesi tidak valid, muat ulang halaman.';
    } else {
        try {
            /* ---- Tambah / ubah alat ---- */
            if ($aksi === 'simpan_barang') {
                $id       = (int)($_POST['id_barang'] ?? 0);
                $kode     = trim((string)($_POST['kode_aset'] ?? ''));
                $nama     = trim((string)($_POST['nama_barang'] ?? ''));
                $kat      = trim((string)($_POST['kategori'] ?? ''));
                $kondisi  = (string)($_POST['kondisi'] ?? 'baik');
                $aktif    = isset($_POST['status_aktif']) ? 1 : 0;

                if (!preg_match('/^[A-Za-z0-9._-]{2,30}$/', $kode)) throw new RuntimeException('Kode aset 2-30 karakter (huruf, angka, . _ -).');
                if ($nama === '' || mb_strlen($nama) > 120)         throw new RuntimeException('Nama barang wajib diisi (maks 120 karakter).');
                if (mb_strlen($kat) > 60)                            throw new RuntimeException('Kategori maksimal 60 karakter.');
                if (!in_array($kondisi, $KONDISI, true))             throw new RuntimeException('Kondisi tidak valid.');

                $fotoBaru = simpanFoto($_FILES['foto_barang'] ?? ['error' => UPLOAD_ERR_NO_FILE]);

                try {
                    if ($id > 0) {
                        $st = $pdo->prepare('SELECT foto_barang FROM barang WHERE id_barang = :id');
                        $st->execute([':id' => $id]);
                        $fotoLama = $st->fetchColumn();
                        if ($fotoLama === false) throw new RuntimeException('Data alat tidak ditemukan.');

                        $pdo->prepare('UPDATE barang SET kode_aset=:k, nama_barang=:n, kategori=:c, kondisi=:d,
                                       status_aktif=:a, foto_barang=:f WHERE id_barang=:id')
                            ->execute([':k' => $kode, ':n' => $nama, ':c' => $kat ?: null, ':d' => $kondisi,
                                       ':a' => $aktif, ':f' => $fotoBaru ?? $fotoLama, ':id' => $id]);
                        if ($fotoBaru) hapusFile($fotoLama);
                        $_SESSION['flash'] = 'Data alat diperbarui.';
                    } else {
                        $pdo->prepare('INSERT INTO barang (kode_aset, nama_barang, kategori, kondisi, status_aktif, foto_barang)
                                       VALUES (:k,:n,:c,:d,:a,:f)')
                            ->execute([':k' => $kode, ':n' => $nama, ':c' => $kat ?: null, ':d' => $kondisi,
                                       ':a' => $aktif, ':f' => $fotoBaru]);
                        $_SESSION['flash'] = 'Alat baru ditambahkan.';
                    }
                } catch (PDOException $ex) {
                    hapusFile($fotoBaru);   // batalkan file yang sudah terunggah
                    if ($ex->getCode() === '23000') throw new RuntimeException('Kode aset sudah dipakai unit lain.');
                    throw $ex;
                }
                header('Location: ' . adminUrl([], ['edit'], 'inventaris'));
                exit;
            }

            /* ---- Hapus alat ---- */
            if ($aksi === 'hapus_barang') {
                $id = (int)($_POST['id_barang'] ?? 0);
                $st = $pdo->prepare('SELECT foto_barang FROM barang WHERE id_barang = :id');
                $st->execute([':id' => $id]);
                $foto = $st->fetchColumn();
                try {
                    $pdo->prepare('DELETE FROM barang WHERE id_barang = :id')->execute([':id' => $id]);
                    hapusFile($foto ?: null);
                    $_SESSION['flash'] = 'Alat dihapus.';
                } catch (PDOException $ex) {
                    if ($ex->getCode() === '23000') {
                        throw new RuntimeException('Alat ini punya riwayat peminjaman sehingga tidak bisa dihapus. Nonaktifkan saja (hilangkan centang "Tampil di katalog").');
                    }
                    throw $ex;
                }
                header('Location: ' . adminUrl([], ['edit'], 'inventaris'));
                exit;
            }

            /* ---- Ubah status transaksi ---- */
            if ($aksi === 'ubah_status') {
                $id = (int)($_POST['id_transaksi'] ?? 0);
                $status = (string)($_POST['status'] ?? '');
                if (!in_array($status, $STATUS, true)) throw new RuntimeException('Status tidak valid.');
                $pdo->prepare('UPDATE transaksi SET status = :s WHERE id_transaksi = :id')
                    ->execute([':s' => $status, ':id' => $id]);
                $_SESSION['flash'] = 'Status transaksi #' . $id . ' diubah menjadi "' . $status . '".';
                header('Location: ' . adminUrl([], ['edit'], 'transaksi'));
                exit;
            }
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        } catch (Throwable $ex) {
            error_log($ex->getMessage());
            $errors[] = 'Terjadi kesalahan sistem.';
        }
    }

    // Gagal menyimpan alat -> simpan isian agar modal terbuka kembali dengan data yang sama
    if ($errors && $aksi === 'simpan_barang') {
        $formData = [
            'id_barang'    => (int)($_POST['id_barang'] ?? 0),
            'kode_aset'    => trim((string)($_POST['kode_aset'] ?? '')),
            'nama_barang'  => trim((string)($_POST['nama_barang'] ?? '')),
            'kategori'     => trim((string)($_POST['kategori'] ?? '')),
            'kondisi'      => (string)($_POST['kondisi'] ?? 'baik'),
            'status_aktif' => isset($_POST['status_aktif']) ? 1 : 0,
        ];
    }
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

/* =========================================================
 * 4. DATA TAMPILAN
 * ========================================================= */
$editId = (int)($_GET['edit'] ?? 0);
if ($formData !== null && $formData['id_barang'] > 0) $editId = $formData['id_barang'];
$edit = null;
if ($editId > 0) {
    $st = $pdo->prepare('SELECT * FROM barang WHERE id_barang = :id');
    $st->execute([':id' => $editId]);
    $edit = $st->fetch() ?: null;
    if (!$edit && $formData === null) $errors[] = 'Data alat tidak ditemukan.';
}
$bukaModal = ($edit !== null || $formData !== null);   // modal terbuka otomatis (?edit=ID atau validasi gagal)

// Nilai isian form: prioritas data POST yang gagal, lalu data DB (mode ubah), lalu default
$fv = static function (string $k, $default = '') use ($formData, $edit) {
    if ($formData !== null && array_key_exists($k, $formData)) return $formData[$k];
    return $edit[$k] ?? $default;
};
$aktifChecked = $formData !== null ? (bool)$formData['status_aktif'] : (!$edit || $edit['status_aktif']);

// Statistik (dihitung via COUNT karena tabel sekarang dipaginasi)
$stat       = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(status_aktif = 1), 0) AS aktif FROM barang')->fetch();
$totalAlat  = (int)$stat['total'];
$nAktif     = (int)$stat['aktif'];
$totalTrx   = (int)$pdo->query('SELECT COUNT(*) FROM transaksi')->fetchColumn();
$nMenunggu  = (int)$pdo->query("SELECT COUNT(*) FROM transaksi WHERE status = 'menunggu'")->fetchColumn();

// Paginasi: Daftar Alat 10/halaman, Transaksi 20/halaman
$pgAlat = paginate($totalAlat, 10, 'hal_alat');
$pgTrx  = paginate($totalTrx, 20, 'hal_trx');

$st = $pdo->prepare('SELECT * FROM barang ORDER BY nama_barang, kode_aset LIMIT :lim OFFSET :off');
$st->bindValue(':lim', $pgAlat['perPage'], PDO::PARAM_INT);
$st->bindValue(':off', $pgAlat['offset'],  PDO::PARAM_INT);
$st->execute();
$daftar = $st->fetchAll();

$st = $pdo->prepare(
    'SELECT t.id_transaksi, t.waktu_ambil, t.waktu_kembali, t.status,
            p.nama, p.nim, p.no_hp, b.nama_barang, b.kode_aset
     FROM transaksi t
     JOIN peminjam p ON p.id_peminjam = t.id_peminjam
     JOIN barang b   ON b.id_barang   = t.id_barang
     ORDER BY t.created_at DESC, t.id_transaksi DESC
     LIMIT :lim OFFSET :off'
);
$st->bindValue(':lim', $pgTrx['perPage'], PDO::PARAM_INT);
$st->bindValue(':off', $pgTrx['offset'],  PDO::PARAM_INT);
$st->execute();
$transaksi = $st->fetchAll();

// Kumpulkan pesan untuk toast
$toasts = [];
if ($flash)  $toasts[] = ['type' => 'ok',  'msg' => $flash];
if ($errors) $toasts[] = ['type' => 'err', 'msg' => $errors];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Alat Laboratorium</title>
<link rel="stylesheet" href="assets/css/style.css">
<script src="assets/js/script.js" defer></script>
</head>
<body>
<header class="topbar">
  <div class="in">
    <a class="brand" href="admin.php"><span class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3h6M10 3v6L4.5 19a1.5 1.5 0 0 0 1.3 2.2h12.4a1.5 1.5 0 0 0 1.3-2.2L14 9V3"/><path d="M7.5 15h9"/></svg></span><span>Lab Admin</span></a>
    <nav class="nav"><a href="#inventaris" data-modal-open>+ Tambah Alat</a><a href="#inventaris">Inventaris</a><a href="#transaksi">Transaksi</a><a href="index.php">Lihat katalog</a><a href="?logout=1">Keluar</a></nav>
  </div>
</header>

<main class="container">
  <div class="page-h">
    <h1>Dashboard Admin</h1>
    <p>Kelola inventaris alat dan pantau pengajuan peminjaman laboratorium.</p>
  </div>

  <div class="stats">
    <div class="card stat"><div class="n"><?= $totalAlat ?></div><div class="t">Total alat terdaftar</div></div>
    <div class="card stat"><div class="n"><?= $nAktif ?></div><div class="t">Tampil di katalog</div></div>
    <div class="card stat"><div class="n"><?= $nMenunggu ?></div><div class="t">Menunggu persetujuan</div></div>
  </div>

  <!-- ===== Daftar alat ===== -->
  <section class="card panel" id="inventaris">
    <div class="panel-h">
      <div><h2>Daftar Alat (<?= $totalAlat ?>)</h2></div>
      <button type="button" class="btn" data-modal-open>+ Tambah Alat Baru</button>
    </div>
    <div class="scroll">
    <table>
      <thead><tr><th>Foto</th><th>Kode Aset</th><th>Nama</th><th>Kategori</th><th>Kondisi</th><th>Katalog</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($daftar as $b): ?>
        <tr>
          <td><?php if ($b['foto_barang']): ?><img src="<?= e(UPLOAD_URL . $b['foto_barang']) ?>" alt=""><?php else: ?><small>—</small><?php endif; ?></td>
          <td><strong><?= e($b['kode_aset']) ?></strong></td>
          <td><?= e($b['nama_barang']) ?></td>
          <td><?= e($b['kategori']) ?></td>
          <td><span class="badge b-<?= e(str_replace(' ', '-', $b['kondisi'])) ?>"><?= e($b['kondisi']) ?></span></td>
          <td><span class="badge <?= $b['status_aktif'] ? 'b-tampil' : 'b-hidden' ?>"><?= $b['status_aktif'] ? 'Tampil' : 'Disembunyikan' ?></span></td>
          <td class="aksi">
            <a class="btn sec sm" href="<?= e(adminUrl(['edit' => (int)$b['id_barang']], [], 'inventaris')) ?>">Ubah</a>
            <form class="inline" method="post" onsubmit="return confirm('Hapus alat <?= e($b['kode_aset']) ?>?')">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aksi" value="hapus_barang">
              <input type="hidden" name="id_barang" value="<?= (int)$b['id_barang'] ?>">
              <button type="submit" class="btn danger sm merah">Hapus</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$daftar): ?><div class="empty">Belum ada alat terdaftar.</div><?php endif; ?>
    </div>
    <?= renderPagination($pgAlat, 'hal_alat', 'inventaris', 'Paginasi daftar alat') ?>
  </section>

  <!-- ===== Transaksi terbaru ===== -->
  <section class="card panel" id="transaksi">
    <div class="panel-h"><div>
      <h2>Transaksi Terbaru (<?= $totalTrx ?>)</h2>
      <small>Pengajuan baru berstatus "menunggu" dan sudah memblokir jadwal unit. Setelah Aslab membalas "Ya" di WhatsApp, ubah ke "disetujui"; saat alat diambil ke "dipinjam"; saat kembali ke "selesai". "ditolak"/"dibatalkan" membebaskan jadwal.</small>
    </div></div>
    <div class="scroll">
    <table>
      <thead><tr><th>#</th><th>Peminjam</th><th>Alat</th><th>Ambil</th><th>Kembali</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($transaksi as $t): ?>
        <tr>
          <td><?= (int)$t['id_transaksi'] ?></td>
          <td><strong><?= e($t['nama']) ?></strong><br><small><?= e($t['nim']) ?> · <?= e($t['no_hp']) ?></small></td>
          <td><?= e($t['nama_barang']) ?><br><small><?= e($t['kode_aset']) ?></small></td>
          <td><?= e(fmt($t['waktu_ambil'])) ?></td>
          <td><?= e(fmt($t['waktu_kembali'])) ?></td>
          <td>
            <div class="stcell">
              <span class="badge b-<?= e($t['status']) ?>"><?= e($t['status']) ?></span>
              <form class="inline" method="post">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="aksi" value="ubah_status">
                <input type="hidden" name="id_transaksi" value="<?= (int)$t['id_transaksi'] ?>">
                <select name="status">
                  <?php foreach ($STATUS as $s): ?>
                    <option value="<?= e($s) ?>" <?= $t['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn sm">Simpan</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$transaksi): ?><div class="empty">Belum ada transaksi.</div><?php endif; ?>
    </div>
    <?= renderPagination($pgTrx, 'hal_trx', 'transaksi', 'Paginasi transaksi') ?>
  </section>
</main>

<!-- ===== Modal: Tambah / Ubah Alat ===== -->
<dialog class="modal" id="modalAlat" aria-labelledby="modalAlatJudul"
        data-autoopen="<?= $bukaModal ? '1' : '0' ?>"
        data-reset="<?= $bukaModal ? '1' : '0' ?>"
        data-clean-url="<?= e(adminUrl([], ['edit'], 'inventaris')) ?>">
  <div class="modal-h">
    <div>
      <h2 id="modalAlatJudul"><?= $edit ? 'Ubah Alat: ' . e($edit['kode_aset']) : 'Tambah Alat Baru' ?></h2>
      <small>1 unit fisik = 1 kode aset</small>
    </div>
    <button type="button" class="modal-x" data-modal-close aria-label="Tutup">&times;</button>
  </div>

  <form class="modal-b" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <input type="hidden" name="aksi" value="simpan_barang">
    <input type="hidden" name="id_barang" value="<?= (int)$fv('id_barang', 0) ?>">

    <div class="grid2">
      <label>Kode Aset (unik)
        <input type="text" name="kode_aset" required maxlength="30" placeholder="LAPTOP-003" autofocus value="<?= e($fv('kode_aset')) ?>">
      </label>
      <label>Nama Barang
        <input type="text" name="nama_barang" required maxlength="120" value="<?= e($fv('nama_barang')) ?>">
      </label>
      <label>Kategori
        <input type="text" name="kategori" maxlength="60" placeholder="Komputer / Jaringan / ..." value="<?= e($fv('kategori')) ?>">
      </label>
      <label>Kondisi
        <select name="kondisi">
          <?php foreach ($KONDISI as $k): ?>
            <option value="<?= e($k) ?>" <?= $fv('kondisi', 'baik') === $k ? 'selected' : '' ?>><?= e($k) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Foto Barang <small>(JPG/PNG/WEBP, maks 2 MB<?= $edit ? '; kosongkan jika tidak diganti' : '' ?>)</small>
        <input type="file" name="foto_barang" accept="image/jpeg,image/png,image/webp">
      </label>
      <div>
        <?php if ($edit && $edit['foto_barang']): ?>
          <div class="cur">
            <img src="<?= e(UPLOAD_URL . $edit['foto_barang']) ?>" alt="">
            <small>Foto saat ini</small>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <label class="cek switch">
      <input type="checkbox" name="status_aktif" value="1" <?= $aktifChecked ? 'checked' : '' ?>>
      <span class="track"></span>
      Tampil di katalog
    </label>

    <div class="form-actions">
      <button type="submit" class="btn"><?= $edit ? 'Simpan Perubahan' : 'Tambah Alat' ?></button>
      <button type="button" class="btn sec" data-modal-close>Batal</button>
    </div>
  </form>
</dialog>

<?php if ($toasts): ?><script type="application/json" id="toast-data"><?= toastJson($toasts) ?></script><?php endif; ?>

<script>
/* Modal Tambah / Ubah Alat (native <dialog>) */
(function () {
  const dlg = document.getElementById('modalAlat');
  if (!dlg || typeof dlg.showModal !== 'function') return;

  function openModal() {
    if (dlg.open) return;
    dlg.showModal();
    document.body.classList.add('modal-open');
  }

  // Tombol / link pembuka
  document.querySelectorAll('[data-modal-open]').forEach(function (el) {
    el.addEventListener('click', function (e) { e.preventDefault(); openModal(); });
  });

  // Tombol X & Batal
  dlg.querySelectorAll('[data-modal-close]').forEach(function (el) {
    el.addEventListener('click', function () { dlg.close(); });
  });

  // Klik area gelap (backdrop). Hanya menutup bila tekan & lepas mouse sama-sama di backdrop,
  // supaya drag-select teks di dalam input tidak menutup modal secara tidak sengaja.
  let downOnBackdrop = false;
  dlg.addEventListener('mousedown', function (e) { downOnBackdrop = (e.target === dlg); });
  dlg.addEventListener('click', function (e) {
    if (e.target === dlg && downOnBackdrop) dlg.close();
  });

  // Dipanggil untuk semua cara menutup (X, Batal, backdrop, tombol Esc)
  dlg.addEventListener('close', function () {
    document.body.classList.remove('modal-open');
    // Bila modal sempat terisi data ?edit= / data gagal-validasi, muat ulang URL bersih
    // agar tombol "Tambah Alat Baru" berikutnya membuka form kosong.
    if (dlg.dataset.reset === '1') window.location.replace(dlg.dataset.cleanUrl);
  });

  // Buka otomatis: ?edit=ID ada, atau validasi simpan gagal
  if (dlg.dataset.autoopen === '1') openModal();
})();
</script>
</body>
</html>