<?php include_once('includes/header.php'); ?>
<?php
require_once('ai/SyncClient.php');

$syncClient = new \Ai\SyncClient($connect);
$destinations = $syncClient->getAllDestinations();

$msg = '';
if (isset($_POST['save_destinations'])) {
    foreach ($_POST['dest'] as $id => $d) {
        $id = (int)$id;
        $url = $connect->real_escape_string(trim($d['url']));
        $tok = $connect->real_escape_string(trim($d['token']));
        $active = isset($d['is_active']) ? 1 : 0;

        $connect->query("UPDATE tbl_sync_destinations SET target_api_url = '$url', sync_token = '$tok', is_active = $active WHERE id = $id");
    }
    $msg = "Pengaturan server sinkronisasi berhasil disimpan!";
    $destinations = $syncClient->getAllDestinations();
}
?>

<section class="content">
    <ol class="breadcrumb breadcrumb-offset">
        <li><a href="dashboard.php">Dashboard</a></li>
        <li><a href="settings.php">Settings</a></li>
        <li class="active">Live Sync Destinations</li>
    </ol>

    <div class="container-fluid" id="fade-in">
        <div class="row clearfix">
            <div class="col-xs-12">
                <div class="card corner-radius">
                    <div class="header">
                        <h2>
                            <i class="material-icons" style="color:#00BCD4; vertical-align:middle;">cloud_sync</i>
                            KONFIGURASI LIVE HOSTING SINKRONISASI
                        </h2>
                        <small>Atur URL server live hosting (cPanel/VPS) dan Secret Token untuk masing-masing aplikasi (AirFryer, Nusa Saji, ProteinPrep).</small>
                    </div>

                    <div class="body">
                        <?php if (!empty($msg)): ?>
                        <div class="alert alert-success alert-dismissible corner-radius" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            <?= htmlspecialchars($msg) ?>
                        </div>
                        <?php endif; ?>

                        <form method="post" action="">
                            <div class="row clearfix">
                                <?php foreach ($destinations as $d): 
                                    $pCode = $d['product_code'];
                                    $borderColor = ($pCode === 'nusasaji') ? '#10B981' : (($pCode === 'proteinprep') ? '#6366F1' : '#F59E0B');
                                    $iconName = ($pCode === 'nusasaji') ? 'ramen_dining' : (($pCode === 'proteinprep') ? 'fitness_center' : 'local_fire_department');
                                ?>
                                <div class="col-md-4 col-sm-6 col-xs-12">
                                    <div style="background:#F8FAFC; border:2px solid <?= $borderColor ?>; border-radius:12px; padding:18px; margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <i class="material-icons" style="color:<?= $borderColor ?>;"><?= $iconName ?></i>
                                                <strong style="color:#1E293B; font-size:14px;"><?= htmlspecialchars($d['label']) ?></strong>
                                            </div>
                                            <span style="font-size:10px; font-weight:800; background:<?= $borderColor ?>; color:#FFF; padding:2px 6px; border-radius:4px; text-transform:uppercase;">
                                                <?= htmlspecialchars($d['product_code']) ?>
                                            </span>
                                        </div>

                                        <div class="form-group form-float" style="margin-bottom:12px;">
                                            <label class="form-label" style="font-size:11px; color:#64748B;">Target Endpoint URL:</label>
                                            <div class="form-line">
                                                <input type="url" name="dest[<?= $d['id'] ?>][url]" class="form-control" value="<?= htmlspecialchars($d['target_api_url']) ?>" required style="font-size:12px;">
                                            </div>
                                        </div>

                                        <div class="form-group form-float" style="margin-bottom:12px;">
                                            <label class="form-label" style="font-size:11px; color:#64748B;">Secret Token (X-SYNC-TOKEN):</label>
                                            <div class="form-line">
                                                <input type="text" name="dest[<?= $d['id'] ?>][token]" class="form-control" value="<?= htmlspecialchars($d['sync_token']) ?>" required style="font-size:12px; font-family:monospace;">
                                            </div>
                                        </div>

                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px;">
                                            <label style="margin:0; font-size:12px; color:#475569; display:flex; align-items:center; gap:6px; cursor:pointer;">
                                                <input type="checkbox" name="dest[<?= $d['id'] ?>][is_active]" value="1" <?= $d['is_active'] ? 'checked' : '' ?> style="accent-color:<?= $borderColor ?>;">
                                                <span>Aktifkan Sinkronisasi</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="margin-top:10px; display:flex; gap:10px;">
                                <button type="submit" name="save_destinations" class="button button-rounded waves-effect" style="background:#00BCD4; color:#FFF; font-weight:700; height:42px; padding:0 24px;">
                                    <i class="material-icons" style="font-size:16px; vertical-align:middle;">save</i> SIMPAN SEMUA PENGATURAN
                                </button>
                                <a href="recipes.php" class="button button-rounded waves-effect" style="background:#94A3B8; color:#FFF; font-weight:700; height:42px; padding:0 20px; line-height:42px; text-decoration:none;">
                                    KEMBALI KE RESEP
                                </a>
                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include_once('includes/footer.php'); ?>
