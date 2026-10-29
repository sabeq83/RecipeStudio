<?php
include_once('includes/header.php');
require_once('includes/PublicationValidator.php');

$validator = new PublicationValidator($connect);
$msg = '';
$err_msg = '';

// Handle actions (publish, draft, withdraw)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $pack_id = (int)$_GET['id'];

    if ($action === 'publish') {
        $check = $validator->validatePack($pack_id);
        if ($check['is_valid']) {
            $stmt = $connect->prepare("UPDATE tbl_prep_packs SET status = 'published', content_version = content_version + 1 WHERE pack_id = ?");
            $stmt->bind_param("i", $pack_id);
            $stmt->execute();
            $stmt->close();
            $msg = "Prep Pack #$pack_id successfully published! Content version incremented.";
        } else {
            $err_msg = "Cannot publish Prep Pack #$pack_id! Errors:<br>• " . implode("<br>• ", $check['errors']);
        }
    } else if ($action === 'draft') {
        $stmt = $connect->prepare("UPDATE tbl_prep_packs SET status = 'draft' WHERE pack_id = ?");
        $stmt->bind_param("i", $pack_id);
        $stmt->execute();
        $stmt->close();
        $msg = "Prep Pack #$pack_id set to Draft.";
    } else if ($action === 'withdraw') {
        $stmt = $connect->prepare("UPDATE tbl_prep_packs SET status = 'withdrawn' WHERE pack_id = ?");
        $stmt->bind_param("i", $pack_id);
        $stmt->execute();
        $stmt->close();
        $msg = "Prep Pack #$pack_id withdrawn from public API.";
    } else if ($action === 'delete') {
        $connect->begin_transaction();
        try {
            $connect->query("DELETE FROM tbl_prep_pack_steps WHERE pack_id = $pack_id");
            $connect->query("DELETE FROM tbl_prep_pack_options WHERE pack_id = $pack_id");
            $connect->query("DELETE FROM tbl_prep_pack_recipes WHERE pack_id = $pack_id");
            $connect->query("DELETE FROM tbl_prep_packs WHERE pack_id = $pack_id");
            $connect->commit();
            $msg = "Prep Pack #$pack_id dan seluruh komponennya berhasil dihapus.";
        } catch (Throwable $e) {
            $connect->rollback();
            $err_msg = "Gagal menghapus Prep Pack: " . $e->getMessage();
        }
    }
}

// Fetch all prep packs
$sql = "SELECT p.*, COUNT(pr.recipe_id) as recipes_count,
        (SELECT COUNT(*) FROM tbl_prep_pack_steps s WHERE s.pack_id = p.pack_id) as steps_count
        FROM tbl_prep_packs p
        LEFT JOIN tbl_prep_pack_recipes pr ON p.pack_id = pr.pack_id
        WHERE p.product_code = 'proteinprep'
        GROUP BY p.pack_id
        ORDER BY p.is_featured DESC, p.pack_id ASC";
$result = $connect->query($sql);
?>

<section class="content">
    <ol class="breadcrumb">
        <li><a href="dashboard.php">Dashboard</a></li>
        <li class="active">Manage Prep Packs</li>
    </ol>

    <div class="container-fluid">
        <?php if (!empty($msg)): ?>
            <div class="alert alert-success alert-dismissible" role="alert">
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <?php echo $msg; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($err_msg)): ?>
            <div class="alert alert-danger alert-dismissible" role="alert">
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <strong>Publication Rejected (AC-09):</strong><br>
                <?php echo $err_msg; ?>
            </div>
        <?php endif; ?>

        <div class="row clearfix">
            <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
                <div class="card corner-radius">
                    <div class="header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                        <div>
                            <h2 style="margin:0;">MANAGE PREP PACKS (PROTEINPREP)</h2>
                            <small>Curated multi-meal prep packs with sequential cooking order, portion scaling, and post-cook storage guidance.</small>
                        </div>
                        <a href="prep-pack-editor.php" class="btn btn-primary waves-effect" style="display:flex; align-items:center; gap:6px; background:var(--pp-teal); padding:8px 16px; border-radius:8px;">
                            <i class="material-icons" style="font-size:18px;">add_circle</i>
                            <span>Buat Prep Pack Baru</span>
                        </a>
                    </div>

                    <div class="body table-responsive">
                        <table class="table table-hover table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Pack Name</th>
                                    <th>Portions / Summary</th>
                                    <th>Time</th>
                                    <th>Recipes</th>
                                    <th>Steps</th>
                                    <th>Status</th>
                                    <th>Version</th>
                                    <th>Validation Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result && $result->num_rows > 0): ?>
                                    <?php while ($row = $result->fetch_assoc()): ?>
                                        <?php 
                                            $vCheck = $validator->validatePack((int)$row['pack_id']);
                                        ?>
                                        <tr>
                                            <td><?php echo $row['pack_id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['pack_name']); ?></strong>
                                                <?php if ($row['is_featured']): ?>
                                                    <span class="label label-warning">Featured</span>
                                                <?php endif; ?>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($row['labels']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['total_portions_summary']); ?></td>
                                            <td><?php echo $row['active_time_minutes']; ?>m active / <?php echo $row['total_time_minutes']; ?>m total</td>
                                            <td><span class="badge bg-teal"><?php echo $row['recipes_count']; ?> recipes</span></td>
                                            <td><span class="badge bg-blue-grey"><?php echo $row['steps_count']; ?> steps</span></td>
                                            <td>
                                                <?php if ($row['status'] === 'published'): ?>
                                                    <span class="label label-success">Published</span>
                                                <?php elseif ($row['status'] === 'draft'): ?>
                                                    <span class="label label-default">Draft</span>
                                                <?php else: ?>
                                                    <span class="label label-danger">Withdrawn</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>v<?php echo $row['content_version']; ?></td>
                                            <td>
                                                <?php if ($vCheck['is_valid']): ?>
                                                    <span class="label label-success"><i class="material-icons" style="font-size:12px;">check_circle</i> Siap Publish</span>
                                                <?php else: ?>
                                                    <span class="label label-warning" title="<?php echo htmlspecialchars(implode(', ', $vCheck['errors'])); ?>">
                                                        <i class="material-icons" style="font-size:12px;">error_outline</i> Incomplete (<?php echo count($vCheck['errors']); ?>)
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="white-space:nowrap;">
                                                <a href="prep-pack-editor.php?id=<?php echo $row['pack_id']; ?>" class="btn btn-xs btn-primary waves-effect" title="Edit / Rangkai Pack" style="background:var(--pp-teal);">
                                                    <i class="material-icons" style="font-size:13px; vertical-align:text-bottom;">edit</i> Edit
                                                </a>
                                                <?php if ($row['status'] !== 'published'): ?>
                                                    <a href="prep-packs.php?action=publish&id=<?php echo $row['pack_id']; ?>" class="btn btn-xs btn-success waves-effect" title="Publish Pack">Publish</a>
                                                <?php else: ?>
                                                    <a href="prep-packs.php?action=draft&id=<?php echo $row['pack_id']; ?>" class="btn btn-xs btn-default waves-effect">Draft</a>
                                                    <a href="prep-packs.php?action=withdraw&id=<?php echo $row['pack_id']; ?>" class="btn btn-xs btn-warning waves-effect" onclick="return confirm('Withdraw this prep pack? Users with active sessions will see a withdrawal notification.')">Withdraw</a>
                                                <?php endif; ?>
                                                <a href="prep-packs.php?action=delete&id=<?php echo $row['pack_id']; ?>" class="btn btn-xs btn-danger waves-effect" onclick="return confirm('Yakin ingin menghapus Prep Pack #<?php echo $row['pack_id']; ?> beserta seluruh resep dan langkahnya?')" title="Hapus Pack">
                                                    <i class="material-icons" style="font-size:13px; vertical-align:text-bottom;">delete</i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" class="text-center">No prep packs found. Run migrations to seed fixtures.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include_once('includes/footer.php'); ?>
