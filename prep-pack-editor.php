<?php
/**
 * ProteinPrep Webadmin - Prep Pack Editor (Studio Builder)
 * Allows administrators to compose, edit, and publish multi-meal prep packs.
 */

require_once('includes/config.php');
require_once('includes/constant.php');
require_once('includes/strings.php');
require_once('functions.php');
require_once('includes/session.php');
require_once('includes/PublicationValidator.php');
require_once('ai/AiBootstrap.php');
require_once('ai/GeminiClient.php');

use Ai\GeminiClient;

$validator = new PublicationValidator($connect);

// =========================================================================
// AJAX ENDPOINT: AI COMPOSE STEPS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'ai_compose_steps') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $selectedRecipeIds = isset($_POST['recipe_ids']) && is_array($_POST['recipe_ids']) ? array_map('intval', $_POST['recipe_ids']) : [];
    if (count($selectedRecipeIds) < 2) {
        echo json_encode(['success' => false, 'error' => 'Pilih minimal 2 resep konstituen sebelum merangkai langkah dengan AI.']);
        exit;
    }

    $idList = implode(',', $selectedRecipeIds);
    $q = "SELECT recipe_id, recipe_title, recipe_time, recipe_description FROM tbl_recipes WHERE recipe_id IN ($idList)";
    $res = $connect->query($q);
    $recipesContext = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rId = (int)$r['recipe_id'];
            $stepsRes = $connect->query("SELECT step_number, step_title, instruction, timer_seconds FROM tbl_recipe_steps WHERE recipe_id = $rId ORDER BY step_number ASC");
            $stepList = [];
            if ($stepsRes) {
                while ($st = $stepsRes->fetch_assoc()) {
                    $stepList[] = $st['step_number'] . '. ' . $st['step_title'] . ': ' . $st['instruction'];
                }
            }
            $recipesContext[] = [
                'title' => $r['recipe_title'],
                'time' => $r['recipe_time'],
                'description' => $r['recipe_description'],
                'steps' => implode("\n", $stepList)
            ];
        }
    }

    $prompt = "Kamu adalah Chef dan Meal Prep Specialist untuk ProteinPrep.
Tugasmu adalah menggabungkan beberapa resep memasak menjadi SATU ALUR MEMASAK SIMULTAN/SEKUENSIAL yang terkoordinasi (Coordinated Batch Cooking Flow).

Resep yang dimasak bersamaan:
" . json_encode($recipesContext, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "

Instruksi Khusus:
1. Rangkai menjadi 5 hingga 8 langkah logis yang meminimalkan waktu dan cucian wajan (misal: memotong seluruh bahan sekaligus, memulai memasak karbohidrat/nasi yang butuh waktu lama, lalu memasak protein di wajan).
2. Tentukan timer_seconds untuk langkah yang butuh waktu tunggu (contoh rebus nasi: 720, tumis daging: 360). Jika persiapan bahan, set timer 0.
3. Berikan timer_label singkat (contoh: 'Rebus Nasi', 'Tumis Ayam Lemon').
4. Berikan next_step_hint dan tip praktis hemat waktu.
5. Kembalikan HANYA format JSON murni array of objects:
[
  {
    \"step_title\": \"Judul langkah singkat\",
    \"instruction\": \"Petunjuk detail langkah terkoordinasi\",
    \"timer_seconds\": 300,
    \"timer_label\": \"Label Timer\",
    \"next_step_hint\": \"Petunjuk langkah setelah ini\",
    \"tip\": \"Tips praktis memasak\"
  }
]";

    try {
        $gemini = new GeminiClient();
        $aiRes = $gemini->generateContent($connect, $prompt, [
            'response_mime_type' => 'application/json',
            'temperature' => 0.4
        ]);

        if (!$aiRes['success']) {
            echo json_encode(['success' => false, 'error' => $aiRes['error'] ?? 'Gagal menghubungi Gemini AI.']);
            exit;
        }

        $rawText = trim($aiRes['text']);
        $rawText = preg_replace('/^```json\s*/i', '', $rawText);
        $rawText = preg_replace('/\s*```$/', '', $rawText);
        $parsedSteps = json_decode($rawText, true);

        if (!is_array($parsedSteps) || count($parsedSteps) === 0) {
            echo json_encode(['success' => false, 'error' => 'Gagal memparsing respons JSON langkah dari AI.']);
            exit;
        }

        echo json_encode(['success' => true, 'steps' => $parsedSteps]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Exception AI: ' . $e->getMessage()]);
        exit;
    }
}

// =========================================================================
// FORM SUBMIT HANDLER (SAVE PREP PACK)
// =========================================================================
$pack_id = (int)($_GET['id'] ?? 0);
$msg = '';
$err_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prep_pack'])) {
    $packName = trim($_POST['pack_name'] ?? '');
    $packDesc = trim($_POST['pack_description'] ?? '');
    $labels = trim($_POST['labels'] ?? '');
    $toolsSummary = trim($_POST['tools_summary'] ?? '1 pan + 1 pot');
    $activeTime = (int)($_POST['active_time_minutes'] ?? 30);
    $totalTime = (int)($_POST['total_time_minutes'] ?? 45);
    $portionsSummary = trim($_POST['total_portions_summary'] ?? '4 portions · 2 recipes');
    $infoCallout = trim($_POST['info_callout'] ?? '');
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
    $status = in_array($_POST['status'] ?? '', ['draft', 'published', 'withdrawn']) ? $_POST['status'] : 'draft';

    $selectedRecipes = isset($_POST['recipes']) && is_array($_POST['recipes']) ? array_map('intval', $_POST['recipes']) : [];
    $optionsInput = isset($_POST['options']) && is_array($_POST['options']) ? $_POST['options'] : [];
    $stepsInput = isset($_POST['steps']) && is_array($_POST['steps']) ? $_POST['steps'] : [];

    // Basic Validation
    if (empty($packName)) {
        $err_msg = 'Nama Prep Pack wajib diisi.';
    } else if (count($selectedRecipes) < 2) {
        $err_msg = 'Prep Pack harus memiliki minimal 2 resep konstituen.';
    } else {
        // Handle Cover Image Upload
        $packImage = trim($_POST['existing_image'] ?? '');
        if (!empty($_FILES['pack_image']['name']) && $_FILES['pack_image']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['pack_image']['name'], PATHINFO_EXTENSION);
            $newImageName = time() . '_pack_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $uploadDest = __DIR__ . '/upload/' . $newImageName;
            if (move_uploaded_file($_FILES['pack_image']['tmp_name'], $uploadDest)) {
                if (class_exists('ImageOptimizer')) {
                    \ImageOptimizer::optimize($uploadDest);
                }
                $packImage = $newImageName;
            }
        }

        $connect->begin_transaction();
        try {
            if ($pack_id > 0) {
                // Update
                $stmt = $connect->prepare("UPDATE tbl_prep_packs SET 
                    pack_name = ?,
                    pack_description = ?,
                    pack_image = ?,
                    total_portions_summary = ?,
                    active_time_minutes = ?,
                    total_time_minutes = ?,
                    tools_summary = ?,
                    info_callout = ?,
                    labels = ?,
                    is_featured = ?,
                    status = ?,
                    content_version = IF(status = 'published', content_version + 1, content_version)
                    WHERE pack_id = ?");
                $stmt->bind_param("ssssiisssisi", 
                    $packName, $packDesc, $packImage, $portionsSummary, 
                    $activeTime, $totalTime, $toolsSummary, $infoCallout, 
                    $labels, $isFeatured, $status, $pack_id
                );
                $stmt->execute();
                $stmt->close();
            } else {
                // Insert
                $stmt = $connect->prepare("INSERT INTO tbl_prep_packs 
                    (product_code, pack_name, pack_description, pack_image, total_portions_summary, active_time_minutes, total_time_minutes, tools_summary, info_callout, labels, is_featured, status, content_version)
                    VALUES ('proteinprep', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->bind_param("ssssiisssis", 
                    $packName, $packDesc, $packImage, $portionsSummary, 
                    $activeTime, $totalTime, $toolsSummary, $infoCallout, 
                    $labels, $isFeatured, $status
                );
                $stmt->execute();
                $pack_id = $connect->insert_id;
                $stmt->close();
            }

            // 1. Sync Constituent Recipes
            $connect->query("DELETE FROM tbl_prep_pack_recipes WHERE pack_id = $pack_id");
            $sortR = 0;
            $stmtR = $connect->prepare("INSERT INTO tbl_prep_pack_recipes (pack_id, recipe_id, sort_order) VALUES (?, ?, ?)");
            foreach ($selectedRecipes as $rId) {
                if ($rId > 0) {
                    $stmtR->bind_param("iii", $pack_id, $rId, $sortR);
                    $stmtR->execute();
                    $sortR++;
                }
            }
            $stmtR->close();

            // 2. Sync Portion Options
            $connect->query("DELETE FROM tbl_prep_pack_options WHERE pack_id = $pack_id");
            $stmtOpt = $connect->prepare("INSERT INTO tbl_prep_pack_options (pack_id, option_name, total_portions, portions_per_recipe_json, multiplier, is_default, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $sortOpt = 0;
            $defaultIndex = (int)($_POST['default_option_index'] ?? 0);

            if (!empty($optionsInput)) {
                foreach ($optionsInput as $idx => $opt) {
                    $optName = trim($opt['name'] ?? '');
                    if (empty($optName)) continue;
                    $portions = (int)($opt['portions'] ?? 4);
                    $mult = (float)($opt['multiplier'] ?? 1.0);
                    $isDef = ($idx === $defaultIndex) ? 1 : 0;
                    $jsonMap = json_encode(array_fill_keys(array_map('strval', $selectedRecipes), (int)($portions / max(1, count($selectedRecipes)))));
                    $stmtOpt->bind_param("isisdii", $pack_id, $optName, $portions, $jsonMap, $mult, $isDef, $sortOpt);
                    $stmtOpt->execute();
                    $sortOpt++;
                }
            } else {
                // Default options fallback
                $defaultOpts = [
                    ['name' => '2 porsi (1 porsi per resep)', 'portions' => 2, 'mult' => 0.50, 'is_default' => 0],
                    ['name' => '4 porsi (2 porsi per resep - Standar)', 'portions' => 4, 'mult' => 1.00, 'is_default' => 1],
                    ['name' => '6 porsi (3 porsi per resep)', 'portions' => 6, 'mult' => 1.50, 'is_default' => 0]
                ];
                foreach ($defaultOpts as $dIdx => $dOpt) {
                    $dJson = json_encode(array_fill_keys(array_map('strval', $selectedRecipes), (int)($dOpt['portions'] / count($selectedRecipes))));
                    $stmtOpt->bind_param("isisdii", $pack_id, $dOpt['name'], $dOpt['portions'], $dJson, $dOpt['mult'], $dOpt['is_default'], $dIdx);
                    $stmtOpt->execute();
                }
            }
            $stmtOpt->close();

            // 3. Sync Steps
            $connect->query("DELETE FROM tbl_prep_pack_steps WHERE pack_id = $pack_id");
            $stmtS = $connect->prepare("INSERT INTO tbl_prep_pack_steps 
                (pack_id, step_number, step_title, instruction, timer_seconds, timer_label, next_step_hint, tip) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stepNum = 1;
            foreach ($stepsInput as $s) {
                $sTitle = trim($s['title'] ?? '');
                $sInst = trim($s['instruction'] ?? '');
                if (empty($sTitle) && empty($sInst)) continue;
                $sTimer = (int)($s['timer_seconds'] ?? 0);
                $sLabel = trim($s['timer_label'] ?? '');
                $sHint = trim($s['next_step_hint'] ?? '');
                $sTip = trim($s['tip'] ?? '');

                $stmtS->bind_param("iississs", $pack_id, $stepNum, $sTitle, $sInst, $sTimer, $sLabel, $sHint, $sTip);
                $stmtS->execute();
                $stepNum++;
            }
            $stmtS->close();

            $connect->commit();

            // If published, validate
            if ($status === 'published') {
                $valRes = $validator->validatePack($pack_id);
                if (!$valRes['is_valid']) {
                    // Revert to draft if critical errors
                    $connect->query("UPDATE tbl_prep_packs SET status = 'draft' WHERE pack_id = $pack_id");
                    header("Location: prep-packs.php?err=" . urlencode("Pack tersimpan sebagai Draf. Syarat belum lengkap: " . implode(', ', $valRes['errors'])));
                    exit;
                }
            }

            header("Location: prep-packs.php?msg=" . urlencode("Prep Pack '{$packName}' berhasil disimpan!"));
            exit;

        } catch (Throwable $e) {
            $connect->rollback();
            $err_msg = "Gagal menyimpan ke database: " . $e->getMessage();
        }
    }
}

// =========================================================================
// LOAD DATA FOR EDIT MODE
// =========================================================================
$pack = [
    'pack_id' => 0,
    'pack_name' => '',
    'pack_description' => '',
    'pack_image' => '',
    'total_portions_summary' => '4 portions · 2 recipes',
    'active_time_minutes' => 35,
    'total_time_minutes' => 45,
    'tools_summary' => '1 wajan anti-lengket + 1 panci',
    'info_callout' => 'Siapkan kotak makan kedap udara (meal prep container) untuk penyimpanan.',
    'labels' => 'High Protein, Under 45 Min, Chicken',
    'is_featured' => 0,
    'status' => 'draft'
];

$packRecipes = [];
$packOptions = [];
$packSteps = [];

if ($pack_id > 0) {
    $stmt = $connect->prepare("SELECT * FROM tbl_prep_packs WHERE pack_id = ? LIMIT 1");
    $stmt->bind_param("i", $pack_id);
    $stmt->execute();
    $pRes = $stmt->get_result();
    if ($pRes && $row = $pRes->fetch_assoc()) {
        $pack = $row;
    }
    $stmt->close();

    // Recipes
    $resR = $connect->query("SELECT recipe_id FROM tbl_prep_pack_recipes WHERE pack_id = $pack_id ORDER BY sort_order ASC");
    if ($resR) {
        while ($r = $resR->fetch_assoc()) {
            $packRecipes[] = (int)$r['recipe_id'];
        }
    }

    // Options
    $resOpt = $connect->query("SELECT * FROM tbl_prep_pack_options WHERE pack_id = $pack_id ORDER BY sort_order ASC");
    if ($resOpt) {
        while ($o = $resOpt->fetch_assoc()) {
            $packOptions[] = $o;
        }
    }

    // Steps
    $resStep = $connect->query("SELECT * FROM tbl_prep_pack_steps WHERE pack_id = $pack_id ORDER BY step_number ASC");
    if ($resStep) {
        while ($s = $resStep->fetch_assoc()) {
            $packSteps[] = $s;
        }
    }
}

// Default 3 options if empty
if (empty($packOptions)) {
    $packOptions = [
        ['option_name' => '2 porsi (1 porsi per resep)', 'total_portions' => 2, 'multiplier' => 0.50, 'is_default' => 0],
        ['option_name' => '4 porsi (2 porsi per resep - Standar)', 'total_portions' => 4, 'multiplier' => 1.00, 'is_default' => 1],
        ['option_name' => '6 porsi (3 porsi per resep)', 'total_portions' => 6, 'multiplier' => 1.50, 'is_default' => 0]
    ];
}

// Load all recipes for constituent picker
$allRecipesRes = $connect->query("SELECT r.recipe_id, r.recipe_title, r.recipe_image, r.recipe_time, 
    n.calories, n.protein_g 
    FROM tbl_recipes r 
    LEFT JOIN tbl_recipe_nutrition n ON r.recipe_id = n.recipe_id 
    WHERE r.product_code = 'proteinprep' 
    ORDER BY r.recipe_id DESC");
$allRecipes = [];
if ($allRecipesRes) {
    while ($r = $allRecipesRes->fetch_assoc()) {
        $allRecipes[] = $r;
    }
}

include_once('includes/header.php');
?>

<section class="content">
    <div class="container-fluid">

        <!-- Breadcrumb -->
        <ol class="breadcrumb" style="background:#FFFFFF; border-radius:10px; padding:12px 18px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
            <li><a href="dashboard.php"><i class="material-icons" style="font-size:14px; vertical-align:text-bottom;">dashboard</i> Dashboard</a></li>
            <li><a href="prep-packs.php">Manage Prep Packs</a></li>
            <li class="active"><?php echo $pack_id > 0 ? 'Edit Prep Pack #' . $pack_id : 'Buat Prep Pack Baru'; ?></li>
        </ol>

        <?php if (!empty($err_msg)): ?>
            <div class="alert alert-danger alert-dismissible" role="alert">
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <strong>Gagal Menyimpan:</strong> <?php echo $err_msg; ?>
            </div>
        <?php endif; ?>

        <!-- MAIN FORM -->
        <form method="POST" enctype="multipart/form-data" id="formPrepPack">
            <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars($pack['pack_image']); ?>">

            <!-- TOP BANNER -->
            <div class="hero-banner" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                <div>
                    <h1 style="margin:0 0 6px 0; font-size:22px; font-weight:800; color:#FFFFFF;">
                        <?php echo $pack_id > 0 ? 'Edit Prep Pack: ' . htmlspecialchars($pack['pack_name']) : 'Studio Builder Prep Pack Baru'; ?>
                    </h1>
                    <div style="font-size:13px; color:rgba(255,255,255,0.85);">
                        Rangkai kombinasi multi-meal, konfigurasi porsi bertahap, dan panduan langkah memasak paralel.
                    </div>
                </div>
                <div style="display:flex; gap:10px;">
                    <a href="prep-packs.php" class="btn-action-secondary" style="color:#FFFFFF; border-color:rgba(255,255,255,0.4); text-decoration:none;">
                        <i class="material-icons" style="font-size:16px;">arrow_back</i> Kembali
                    </a>
                    <button type="submit" name="save_prep_pack" class="btn-action-primary" style="background:var(--pp-teal);">
                        <i class="material-icons" style="font-size:16px;">save</i> Simpan Prep Pack
                    </button>
                </div>
            </div>

            <!-- TABS NAVIGATION -->
            <div class="card corner-radius" style="margin-bottom:24px;">
                <div class="body" style="padding:0;">
                    <ul class="nav nav-tabs tab-nav-right" role="tablist" style="margin:0; border-bottom:1px solid #E2E8F0; padding:10px 16px 0 16px;">
                        <li role="presentation" class="active">
                            <a href="#tab-info" data-toggle="tab" style="font-weight:700; display:flex; align-items:center; gap:6px;">
                                <i class="material-icons" style="font-size:18px;">tune</i> 1. Identitas &amp; Media
                            </a>
                        </li>
                        <li role="presentation">
                            <a href="#tab-recipes" data-toggle="tab" style="font-weight:700; display:flex; align-items:center; gap:6px;">
                                <i class="material-icons" style="font-size:18px;">restaurant_menu</i> 2. Resep Konstituen (<span id="badgeRecipeCount"><?php echo count($packRecipes); ?></span>)
                            </a>
                        </li>
                        <li role="presentation">
                            <a href="#tab-options" data-toggle="tab" style="font-weight:700; display:flex; align-items:center; gap:6px;">
                                <i class="material-icons" style="font-size:18px;">pie_chart</i> 3. Pilihan Porsi &amp; Skala
                            </a>
                        </li>
                        <li role="presentation">
                            <a href="#tab-steps" data-toggle="tab" style="font-weight:700; display:flex; align-items:center; gap:6px;">
                                <i class="material-icons" style="font-size:18px;">timer</i> 4. Alur Masak &amp; Timer (<span id="badgeStepCount"><?php echo count($packSteps); ?></span>)
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content" style="padding:24px;">

                        <!-- ================= TAB 1: IDENTITAS & MEDIA ================= -->
                        <div role="tabpanel" class="tab-pane fade in active" id="tab-info">
                            <div class="row clearfix">
                                <div class="col-md-8 col-sm-12">
                                    <div class="form-group">
                                        <label class="form-label" for="pack_name"><strong>Nama Prep Pack:</strong> *</label>
                                        <input type="text" name="pack_name" id="pack_name" class="form-control" value="<?php echo htmlspecialchars($pack['pack_name']); ?>" placeholder="Contoh: Beginner Chicken Prep (High Protein)" required>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="pack_description"><strong>Deskripsi Prep Pack:</strong></label>
                                        <textarea name="pack_description" id="pack_description" class="form-control" rows="4" placeholder="Jelaskan keunggulan dan kemudahan memasak paket ini..."><?php echo htmlspecialchars($pack['pack_description']); ?></textarea>
                                    </div>

                                    <div class="row clearfix">
                                        <div class="col-sm-6">
                                            <div class="form-group">
                                                <label class="form-label"><strong>Waktu Aktif (Menit):</strong></label>
                                                <input type="number" name="active_time_minutes" class="form-control" value="<?php echo (int)$pack['active_time_minutes']; ?>" min="5">
                                                <span class="help-block" style="font-size:11px; color:#64748B;">Waktu tangan bergerak aktif memotong/menumis.</span>
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <div class="form-group">
                                                <label class="form-label"><strong>Waktu Total (Menit):</strong></label>
                                                <input type="number" name="total_time_minutes" class="form-control" value="<?php echo (int)$pack['total_time_minutes']; ?>" min="10">
                                                <span class="help-block" style="font-size:11px; color:#64748B;">Termasuk waktu menunggu rebusan / oven.</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label"><strong>Ringkasan Alat Masak (Tools Summary):</strong></label>
                                        <input type="text" name="tools_summary" class="form-control" value="<?php echo htmlspecialchars($pack['tools_summary']); ?>" placeholder="Contoh: 1 wajan anti-lengket + 1 panci (less washing)">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label"><strong>Ringkasan Porsi (Header Summary):</strong></label>
                                        <input type="text" name="total_portions_summary" class="form-control" value="<?php echo htmlspecialchars($pack['total_portions_summary']); ?>" placeholder="Contoh: 4 portions · 2 recipes">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label"><strong>Info Callout / Panduan Khusus:</strong></label>
                                        <textarea name="info_callout" class="form-control" rows="2" placeholder="Contoh: Siapkan kotak makan bersekat agar sayuran tetap renyah..."><?php echo htmlspecialchars($pack['info_callout']); ?></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label"><strong>Label / Tags (Dipisahkan Koma):</strong></label>
                                        <input type="text" name="labels" class="form-control" value="<?php echo htmlspecialchars($pack['labels']); ?>" placeholder="Chicken, High Protein, Meal Prep, Under 45 Min">
                                    </div>
                                </div>

                                <div class="col-md-4 col-sm-12">
                                    <!-- Cover Image Dropify -->
                                    <div class="form-group" style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; padding:16px;">
                                        <label class="form-label" style="margin-bottom:8px; display:block;"><strong>Gambar Cover Prep Pack:</strong></label>
                                        <?php 
                                            $currImg = !empty($pack['pack_image']) ? 'upload/' . $pack['pack_image'] : '';
                                        ?>
                                        <input type="file" name="pack_image" class="dropify" data-default-file="<?php echo $currImg; ?>" data-max-file-size="5M" data-allowed-file-extensions="jpg jpeg png webp">
                                        <span class="help-block" style="font-size:11px; color:#64748B; margin-top:8px;">Disarankan rasio 16:9 atau 4:3 dengan pencahayaan appetizing.</span>
                                    </div>

                                    <!-- Status & Featured -->
                                    <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; padding:16px; margin-top:16px;">
                                        <div class="form-group">
                                            <label class="form-label"><strong>Status Publikasi:</strong></label>
                                            <select name="status" class="form-control" style="font-weight:700;">
                                                <option value="draft" <?php echo $pack['status'] === 'draft' ? 'selected' : ''; ?>>Draft (Belum Tampil di App)</option>
                                                <option value="published" <?php echo $pack['status'] === 'published' ? 'selected' : ''; ?>>Published (Aktif di App Android)</option>
                                                <option value="withdrawn" <?php echo $pack['status'] === 'withdrawn' ? 'selected' : ''; ?>>Withdrawn (Ditarik Sementara)</option>
                                            </select>
                                        </div>

                                        <div class="checkbox" style="margin:0;">
                                            <label>
                                                <input type="checkbox" name="is_featured" value="1" <?php echo !empty($pack['is_featured']) ? 'checked' : ''; ?>>
                                                <strong style="color:var(--pp-navy);">Jadikan Paket Unggulan (Featured)</strong>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ================= TAB 2: RESEP KONSTITUEN ================= -->
                        <div role="tabpanel" class="tab-pane fade" id="tab-recipes">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                                <div>
                                    <h4 style="margin:0; font-size:16px; font-weight:700; color:var(--pp-navy);">Pilih Resep Konstituen (Minimal 2 Resep)</h4>
                                    <p style="margin:4px 0 0 0; font-size:12px; color:#64748B;">Resep-resep ini akan dimasak bersamaan secara sekuensial dalam satu sesi.</p>
                                </div>
                                <div id="selectedCountBadge" class="label label-primary" style="font-size:12px; padding:6px 12px; border-radius:6px; background:var(--pp-teal);">
                                    <?php echo count($packRecipes); ?> Resep Terpilih
                                </div>
                            </div>

                            <!-- Filter & Search Box -->
                            <div class="form-group">
                                <input type="text" id="recipeSearchInput" class="form-control" placeholder="Cari judul resep atau kata kunci..." onkeyup="filterRecipeCards()">
                            </div>

                            <!-- Recipe Grid -->
                            <div id="recipePickerGrid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:16px; max-height:480px; overflow-y:auto; padding:8px 4px;">
                                <?php foreach ($allRecipes as $rec): ?>
                                    <?php 
                                        $isChecked = in_array((int)$rec['recipe_id'], $packRecipes, true);
                                        $thumb = !empty($rec['recipe_image']) ? 'upload/' . $rec['recipe_image'] : 'assets/images/ic_launcher.png';
                                    ?>
                                    <label class="recipe-select-card <?php echo $isChecked ? 'selected' : ''; ?>" style="display:flex; gap:12px; align-items:center; background:#FFFFFF; border:2px solid <?php echo $isChecked ? 'var(--pp-teal)' : '#E2E8F0'; ?>; border-radius:10px; padding:10px; cursor:pointer; margin:0; transition:all 0.2s;">
                                        <input type="checkbox" name="recipes[]" value="<?php echo $rec['recipe_id']; ?>" <?php echo $isChecked ? 'checked' : ''; ?> onchange="onRecipeCheckboxChange(this)" style="width:18px; height:18px;">
                                        <img src="<?php echo $thumb; ?>" alt="<?php echo htmlspecialchars($rec['recipe_title']); ?>" style="width:54px; height:54px; border-radius:8px; object-fit:cover;">
                                        <div style="flex:1; min-width:0;">
                                            <div class="recipe-card-title" style="font-size:13px; font-weight:700; color:var(--pp-navy); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                <?php echo htmlspecialchars($rec['recipe_title']); ?>
                                            </div>
                                            <div style="font-size:11px; color:#64748B; margin-top:2px;">
                                                <strong style="color:var(--pp-teal);"><?php echo (int)($rec['protein_g'] ?? 0); ?>g Protein</strong> • <?php echo (int)($rec['calories'] ?? 0); ?> kcal
                                            </div>
                                            <div style="font-size:11px; color:#94A3B8;">#<?php echo $rec['recipe_id']; ?> • <?php echo htmlspecialchars($rec['recipe_time'] ?: '30 min'); ?></div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- ================= TAB 3: OPSI PORSI & MULTIPLIER ================= -->
                        <div role="tabpanel" class="tab-pane fade" id="tab-options">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                                <div>
                                    <h4 style="margin:0; font-size:16px; font-weight:700; color:var(--pp-navy);">Opsi Porsi Masak (Portion Scaling Options)</h4>
                                    <p style="margin:4px 0 0 0; font-size:12px; color:#64748B;">Pilihan porsi fleksibel bagi pengguna aplikasi (bahan &amp; bumbu akan otomatis dikalikan multiplier).</p>
                                </div>
                                <button type="button" class="btn btn-default waves-effect" onclick="generateDefaultOptions()" style="font-size:12px; border-radius:6px;">
                                    <i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">auto_awesome</i> Reset ke Opsi Standar (2, 4, 6 Porsi)
                                </button>
                            </div>

                            <table class="table table-bordered table-striped" id="tablePortionOptions">
                                <thead>
                                    <tr style="background:#F8FAFC;">
                                        <th style="width:40%;">Nama Opsi Porsi</th>
                                        <th style="width:20%;">Total Porsi</th>
                                        <th style="width:20%;">Multiplier (Faktor Pengali)</th>
                                        <th style="width:10%; text-align:center;">Pilihan Bawaan (Default)</th>
                                        <th style="width:10%; text-align:center;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="portionOptionsBody">
                                    <?php foreach ($packOptions as $idx => $opt): ?>
                                        <tr>
                                            <td>
                                                <input type="text" name="options[<?php echo $idx; ?>][name]" class="form-control" value="<?php echo htmlspecialchars($opt['option_name']); ?>" required>
                                            </td>
                                            <td>
                                                <input type="number" name="options[<?php echo $idx; ?>][portions]" class="form-control" value="<?php echo (int)$opt['total_portions']; ?>" min="1" required>
                                            </td>
                                            <td>
                                                <input type="number" step="0.05" name="options[<?php echo $idx; ?>][multiplier]" class="form-control" value="<?php echo (float)$opt['multiplier']; ?>" min="0.1" required>
                                            </td>
                                            <td style="text-align:center; vertical-align:middle;">
                                                <input type="radio" name="default_option_index" value="<?php echo $idx; ?>" <?php echo !empty($opt['is_default']) ? 'checked' : ''; ?>>
                                            </td>
                                            <td style="text-align:center; vertical-align:middle;">
                                                <button type="button" class="btn btn-xs btn-danger" onclick="removePortionOptionRow(this)"><i class="material-icons" style="font-size:14px;">delete</i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <button type="button" class="btn btn-secondary waves-effect" onclick="addPortionOptionRow()" style="font-size:12px;">
                                <i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">add</i> Tambah Opsi Porsi Kustom
                            </button>
                        </div>

                        <!-- ================= TAB 4: LANGKAH MEMASAK SIMULTAN & TIMER ================= -->
                        <div role="tabpanel" class="tab-pane fade" id="tab-steps">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                                <div>
                                    <h4 style="margin:0; font-size:16px; font-weight:700; color:var(--pp-navy);">Alur Memasak Terkoordinasi &amp; Timer</h4>
                                    <p style="margin:4px 0 0 0; font-size:12px; color:#64748B;">Langkah berurutan untuk memasak beberapa menu sekaligus dengan timer dan panduan hemat wajan.</p>
                                </div>
                                <div style="display:flex; gap:8px;">
                                    <button type="button" id="btnAiComposeSteps" class="btn btn-primary waves-effect" onclick="runAiComposeSteps()" style="background:#8B5CF6; border-radius:6px; font-size:12px;">
                                        <i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">auto_awesome</i>
                                        <span>Rangkai Alur Masak Otomatis (Gemini AI)</span>
                                    </button>
                                    <button type="button" class="btn btn-secondary waves-effect" onclick="addStepRow()" style="font-size:12px; border-radius:6px;">
                                        <i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">add</i> Tambah Langkah Manual
                                    </button>
                                </div>
                            </div>

                            <!-- AI Status Box -->
                            <div id="aiStepsStatusBox" style="display:none; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:13px;"></div>

                            <!-- Step Rows Container -->
                            <div id="stepsContainer" style="display:flex; flex-direction:column; gap:16px;">
                                <?php if (!empty($packSteps)): ?>
                                    <?php foreach ($packSteps as $sIdx => $st): ?>
                                        <div class="step-card" style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:16px; position:relative;">
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                                <span class="step-number-badge" style="background:var(--pp-teal); color:#FFFFFF; font-weight:800; font-size:12px; padding:4px 10px; border-radius:20px;">
                                                    LANGKAH <?php echo $sIdx + 1; ?>
                                                </span>
                                                <button type="button" class="btn btn-xs btn-danger" onclick="removeStepCard(this)" title="Hapus Langkah Ini">
                                                    <i class="material-icons" style="font-size:14px;">delete</i>
                                                </button>
                                            </div>

                                            <div class="row clearfix">
                                                <div class="col-sm-8">
                                                    <div class="form-group" style="margin-bottom:12px;">
                                                        <label style="font-size:12px; margin-bottom:4px;">Judul Langkah:</label>
                                                        <input type="text" name="steps[<?php echo $sIdx; ?>][title]" class="form-control" value="<?php echo htmlspecialchars($st['step_title']); ?>" placeholder="Contoh: Marinasi kedua porsi ayam &amp; potong brokoli" required>
                                                    </div>
                                                    <div class="form-group" style="margin-bottom:0;">
                                                        <label style="font-size:12px; margin-bottom:4px;">Instruksi Detail:</label>
                                                        <textarea name="steps[<?php echo $sIdx; ?>][instruction]" class="form-control" rows="3" placeholder="Jelaskan langkah memasak praktis..." required><?php echo htmlspecialchars($st['instruction']); ?></textarea>
                                                    </div>
                                                </div>
                                                <div class="col-sm-4">
                                                    <div class="form-group" style="margin-bottom:12px;">
                                                        <label style="font-size:12px; margin-bottom:4px;">Timer (Detik):</label>
                                                        <input type="number" name="steps[<?php echo $sIdx; ?>][timer_seconds]" class="form-control" value="<?php echo (int)$st['timer_seconds']; ?>" placeholder="Contoh: 300 (0 jika tanpa timer)">
                                                    </div>
                                                    <div class="form-group" style="margin-bottom:12px;">
                                                        <label style="font-size:12px; margin-bottom:4px;">Label Timer:</label>
                                                        <input type="text" name="steps[<?php echo $sIdx; ?>][timer_label]" class="form-control" value="<?php echo htmlspecialchars($st['timer_label'] ?? ''); ?>" placeholder="Contoh: Tumis Ayam Lemon">
                                                    </div>
                                                    <div class="form-group" style="margin-bottom:0;">
                                                        <label style="font-size:12px; margin-bottom:4px;">Petunjuk Langkah Berikutnya (Hint):</label>
                                                        <input type="text" name="steps[<?php echo $sIdx; ?>][next_step_hint]" class="form-control" value="<?php echo htmlspecialchars($st['next_step_hint'] ?? ''); ?>" placeholder="Contoh: Siapkan saus paprika">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- BOTTOM ACTION BAR -->
            <div style="display:flex; justify-content:space-between; align-items:center; background:#FFFFFF; border:1px solid #E2E8F0; border-radius:12px; padding:16px 24px; margin-bottom:40px;">
                <a href="prep-packs.php" class="btn btn-default waves-effect">
                    <i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">close</i> Batal
                </a>
                <div style="display:flex; gap:12px;">
                    <button type="submit" name="save_prep_pack" class="btn btn-primary waves-effect" style="background:var(--pp-teal); padding:10px 24px; font-weight:700;">
                        <i class="material-icons" style="font-size:18px; vertical-align:text-bottom;">save</i> Simpan Perubahan Prep Pack
                    </button>
                </div>
            </div>
        </form>

    </div>
</section>

<!-- Dropify & UI JS Scripts -->
<script src="assets/plugins/dropify/js/dropify.js"></script>
<script>
    $(document).ready(function() {
        $('.dropify').dropify({
            messages: {
                default: 'Seret dan lepas gambar di sini atau klik untuk memilih',
                replace: 'Seret dan lepas atau klik untuk mengganti gambar',
                remove:  'Hapus',
                error:   'Terjadi kesalahan saat memuat berkas'
            }
        });
        updateCounts();
    });

    function updateCounts() {
        const recipeChecked = $('input[name="recipes[]"]:checked').length;
        $('#badgeRecipeCount').text(recipeChecked);
        $('#selectedCountBadge').text(recipeChecked + ' Resep Terpilih');

        const stepCount = $('#stepsContainer .step-card').length;
        $('#badgeStepCount').text(stepCount);
    }

    function onRecipeCheckboxChange(el) {
        const card = $(el).closest('.recipe-select-card');
        if (el.checked) {
            card.addClass('selected').css('border-color', 'var(--pp-teal)');
        } else {
            card.removeClass('selected').css('border-color', '#E2E8F0');
        }
        updateCounts();
    }

    function filterRecipeCards() {
        const val = $('#recipeSearchInput').val().toLowerCase();
        $('#recipePickerGrid .recipe-select-card').each(function() {
            const title = $(this).find('.recipe-card-title').text().toLowerCase();
            if (title.indexOf(val) !== -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    }

    // Portion Options
    let optionRowCounter = <?php echo count($packOptions); ?>;
    function addPortionOptionRow(name = '', portions = 4, mult = 1.0, isDefault = false) {
        const idx = optionRowCounter++;
        const tr = `
            <tr>
                <td><input type="text" name="options[${idx}][name]" class="form-control" value="${name}" placeholder="Contoh: 4 porsi (2 porsi per resep)" required></td>
                <td><input type="number" name="options[${idx}][portions]" class="form-control" value="${portions}" min="1" required></td>
                <td><input type="number" step="0.05" name="options[${idx}][multiplier]" class="form-control" value="${mult}" min="0.1" required></td>
                <td style="text-align:center; vertical-align:middle;">
                    <input type="radio" name="default_option_index" value="${idx}" ${isDefault ? 'checked' : ''}>
                </td>
                <td style="text-align:center; vertical-align:middle;">
                    <button type="button" class="btn btn-xs btn-danger" onclick="removePortionOptionRow(this)"><i class="material-icons" style="font-size:14px;">delete</i></button>
                </td>
            </tr>
        `;
        $('#portionOptionsBody').append(tr);
    }

    function removePortionOptionRow(btn) {
        if ($('#portionOptionsBody tr').length <= 1) {
            alert('Minimal harus ada satu opsi porsi.');
            return;
        }
        $(btn).closest('tr').remove();
    }

    function generateDefaultOptions() {
        $('#portionOptionsBody').empty();
        optionRowCounter = 0;
        addPortionOptionRow('2 porsi (1 porsi per resep)', 2, 0.50, false);
        addPortionOptionRow('4 porsi (2 porsi per resep - Standar)', 4, 1.00, true);
        addPortionOptionRow('6 porsi (3 porsi per resep)', 6, 1.50, false);
    }

    // Step Cards
    let stepRowCounter = <?php echo count($packSteps); ?>;
    function addStepRow(title = '', instruction = '', timer = 0, timerLabel = '', hint = '', tip = '') {
        const idx = stepRowCounter++;
        const count = $('#stepsContainer .step-card').length + 1;
        const card = `
            <div class="step-card" style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:16px; position:relative;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <span class="step-number-badge" style="background:var(--pp-teal); color:#FFFFFF; font-weight:800; font-size:12px; padding:4px 10px; border-radius:20px;">
                        LANGKAH ${count}
                    </span>
                    <button type="button" class="btn btn-xs btn-danger" onclick="removeStepCard(this)" title="Hapus Langkah Ini">
                        <i class="material-icons" style="font-size:14px;">delete</i>
                    </button>
                </div>
                <div class="row clearfix">
                    <div class="col-sm-8">
                        <div class="form-group" style="margin-bottom:12px;">
                            <label style="font-size:12px; margin-bottom:4px;">Judul Langkah:</label>
                            <input type="text" name="steps[${idx}][title]" class="form-control" value="${title}" placeholder="Contoh: Persiapan &amp; pemotongan bahan" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label style="font-size:12px; margin-bottom:4px;">Instruksi Detail:</label>
                            <textarea name="steps[${idx}][instruction]" class="form-control" rows="3" placeholder="Jelaskan langkah memasak praktis..." required>${instruction}</textarea>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="form-group" style="margin-bottom:12px;">
                            <label style="font-size:12px; margin-bottom:4px;">Timer (Detik):</label>
                            <input type="number" name="steps[${idx}][timer_seconds]" class="form-control" value="${timer}" placeholder="Contoh: 300 (0 jika tanpa timer)">
                        </div>
                        <div class="form-group" style="margin-bottom:12px;">
                            <label style="font-size:12px; margin-bottom:4px;">Label Timer:</label>
                            <input type="text" name="steps[${idx}][timer_label]" class="form-control" value="${timerLabel}" placeholder="Contoh: Tumis Ayam Lemon">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label style="font-size:12px; margin-bottom:4px;">Petunjuk Langkah Berikutnya (Hint):</label>
                            <input type="text" name="steps[${idx}][next_step_hint]" class="form-control" value="${hint}" placeholder="Contoh: Siapkan wadah saus">
                        </div>
                    </div>
                </div>
            </div>
        `;
        $('#stepsContainer').append(card);
        updateCounts();
    }

    function removeStepCard(btn) {
        $(btn).closest('.step-card').remove();
        // Re-number badges
        $('#stepsContainer .step-card').each(function(i) {
            $(this).find('.step-number-badge').text('LANGKAH ' + (i + 1));
        });
        updateCounts();
    }

    // AI Step Composer
    function runAiComposeSteps() {
        const selectedRecipes = [];
        $('input[name="recipes[]"]:checked').each(function() {
            selectedRecipes.push($(this).val());
        });

        if (selectedRecipes.length < 2) {
            alert('Pilih minimal 2 resep pada Tab "2. Resep Konstituen" sebelum menjalankan AI Composer.');
            $('a[href="#tab-recipes"]').tab('show');
            return;
        }

        const btn = $('#btnAiComposeSteps');
        const box = $('#aiStepsStatusBox');
        btn.prop('disabled', true).html('<i class="material-icons" style="font-size:16px; animation:spin 1s infinite linear;">sync</i> Menyusun Alur Masak dengan AI...');
        box.show().css({
            'background': '#EFF6FF',
            'color': '#1D4ED8',
            'border': '1px solid #BFDBFE'
        }).html('<i class="material-icons" style="font-size:16px; vertical-align:text-bottom; animation:spin 1s infinite linear;">sync</i> <strong>Gemini AI sedang menganalisis kedua resep...</strong> Menggabungkan alur persiapan bahan, wajan, dan timer simultan.');

        $.ajax({
            url: 'prep-pack-editor.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'ai_compose_steps',
                recipe_ids: selectedRecipes
            },
            success: function(resp) {
                btn.prop('disabled', false).html('<i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">auto_awesome</i> Rangkai Alur Masak Otomatis (Gemini AI)');
                if (resp && resp.success && resp.steps && resp.steps.length > 0) {
                    $('#stepsContainer').empty();
                    stepRowCounter = 0;
                    resp.steps.forEach(function(s) {
                        addStepRow(s.step_title || '', s.instruction || '', s.timer_seconds || 0, s.timer_label || '', s.next_step_hint || '', s.tip || '');
                    });
                    box.css({
                        'background': '#D1FAE5',
                        'color': '#047857',
                        'border': '1px solid #A7F3D0'
                    }).html('<i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">check_circle</i> <strong>Alur Masak Berhasil Disintesis!</strong> Dihasilkan ' + resp.steps.length + ' langkah memasak efisien.');
                } else {
                    box.css({
                        'background': '#FEE2E2',
                        'color': '#B91C1C',
                        'border': '1px solid #FECACA'
                    }).html('<i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">error</i> Gagal: ' + (resp.error || 'Terjadi kesalahan sistem'));
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).html('<i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">auto_awesome</i> Rangkai Alur Masak Otomatis (Gemini AI)');
                box.css({
                    'background': '#FEE2E2',
                    'color': '#B91C1C',
                    'border': '1px solid #FECACA'
                }).html('<i class="material-icons" style="font-size:16px; vertical-align:text-bottom;">error</i> Gagal menghubungi server: ' + error);
            }
        });
    }
</script>

<?php include_once('includes/footer.php'); ?>
