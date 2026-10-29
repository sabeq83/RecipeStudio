<?php
ob_start();
if (defined('E_DEPRECATED')) {
    error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE);
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once('includes/config.php');
require_once('includes/constant.php');
require_once('includes/strings.php');
require_once('functions.php');
require_once('ai/AiBootstrap.php');
require_once('ai/RecipeGeneratorService.php');

// Session authentication check
$currentTime = time() + 25200;
if (!isset($_SESSION['user']) || (isset($_SESSION['timeout']) && $currentTime > $_SESSION['timeout'])) {
    if (isset($_REQUEST['ajax_action'])) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Sesi login telah berakhir. Silakan login kembali.']);
        exit;
    }
    session_destroy();
    header("location:index.php");
    exit();
}
unset($_SESSION['timeout']);
$_SESSION['timeout'] = $currentTime + 86400;

// Ensure tables are migrated
if (!Ai\AiBootstrap::isMigrated($connect)) {
    Ai\AiBootstrap::runAllMigrations($connect);
}

$recipeService = new Ai\RecipeGeneratorService($connect);

// Fetch centralized AI Config
$aiConfigRes = $connect->query("SELECT gemini_model FROM `tbl_ai_config` WHERE id = 1 LIMIT 1");
$aiConfig = ($aiConfigRes && $aiConfigRes->num_rows > 0) ? $aiConfigRes->fetch_assoc() : [];
$primaryModel = (!empty($aiConfig['gemini_model']) && !in_array($aiConfig['gemini_model'], ['gemini-2.0-flash', 'gemini-1.5-flash'])) ? $aiConfig['gemini_model'] : 'gemini-3.8-flash';

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ============================================================================
// AJAX HANDLERS
// ============================================================================
if (isset($_REQUEST['ajax_action'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $action = $_REQUEST['ajax_action'];
    $providedCsrf = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $providedCsrf)) {
        echo json_encode(['success' => false, 'error' => 'Validasi keamanan (CSRF) gagal. Silakan muat ulang halaman.']);
        exit;
    }

    if ($action === 'generate_batch') {
        @set_time_limit(180);
        @ini_set('max_execution_time', '180');
        $params = [
            'theme' => trim($_POST['theme'] ?? ''),
            'primary_protein' => trim($_POST['primary_protein'] ?? 'Chicken'),
            'recipe_count' => (int)($_POST['recipe_count'] ?? 3),
            'target_portions' => (int)($_POST['target_portions'] ?? 4),
            'max_prep_minutes' => (int)($_POST['max_prep_minutes'] ?? 45),
            'language' => strtolower(trim($_POST['language'] ?? 'en')),
            'dietary_preferences' => trim($_POST['dietary_preferences'] ?? ''),
            'excluded_ingredients' => trim($_POST['excluded_ingredients'] ?? ''),
            'admin_notes' => trim($_POST['admin_notes'] ?? '')
        ];
        $result = $recipeService->generateBatch($params);
        echo json_encode($result);
        exit;
    }

    if ($action === 'get_batch_detail') {
        $batchId = (int)($_GET['batch_id'] ?? 0);
        $batch = $recipeService->getBatchDetail($batchId);
        if ($batch) {
            echo json_encode(['success' => true, 'batch' => $batch]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Batch tidak ditemukan.']);
        }
        exit;
    }

    if ($action === 'get_draft_detail') {
        $draftId = (int)($_GET['draft_id'] ?? 0);
        $draft = $recipeService->getDraftDetail($draftId);
        if ($draft) {
            echo json_encode(['success' => true, 'draft' => $draft]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Draft tidak ditemukan.']);
        }
        exit;
    }

    if ($action === 'update_draft') {
        $draftId = (int)($_POST['draft_id'] ?? 0);
        $result = $recipeService->updateDraft($draftId, $_POST);
        echo json_encode($result);
        exit;
    }

    if ($action === 'verify_nutrition') {
        $recipeId = (int)($_POST['recipe_id'] ?? 0);
        $result = $recipeService->verifyNutrition($recipeId, $_POST);
        echo json_encode($result);
        exit;
    }

    if ($action === 'publish_recipe') {
        $recipeId = (int)($_POST['recipe_id'] ?? 0);
        $draftId = (int)($_POST['draft_id'] ?? 0);
        $result = $recipeService->publishRecipe($recipeId, $draftId);
        echo json_encode($result);
        exit;
    }

    if ($action === 'withdraw_recipe') {
        $recipeId = (int)($_POST['recipe_id'] ?? 0);
        $draftId = (int)($_POST['draft_id'] ?? 0);
        $result = $recipeService->withdrawRecipe($recipeId, $draftId);
        echo json_encode($result);
        exit;
    }

    if ($action === 'bundle_batch_to_prep_pack') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $forceRecreate = !empty($_POST['force_recreate']);
        $result = $recipeService->bundleBatchToPrepPack($batchId, $forceRecreate);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Aksi tidak dikenal.']);
    exit;
}

// Data for initial page render
$batches = $recipeService->getBatchList(10, 0);

// Global Stats
$statBatches = $connect->query("SELECT COUNT(*) as c FROM tbl_ai_recipe_batches")->fetch_assoc()['c'] ?? 0;
$statDrafts = $connect->query("SELECT COUNT(*) as c FROM tbl_ai_recipe_drafts")->fetch_assoc()['c'] ?? 0;
$statPublished = $connect->query("SELECT COUNT(*) as c FROM tbl_ai_recipe_drafts WHERE is_published = 1")->fetch_assoc()['c'] ?? 0;
$statPending = $connect->query("SELECT COUNT(*) as c FROM tbl_recipes r JOIN tbl_recipe_nutrition n ON r.recipe_id = n.recipe_id WHERE r.product_code = 'proteinprep' AND n.verified_by_human = 0")->fetch_assoc()['c'] ?? 0;

include_once('includes/header.php');
include_once('includes/sidebar.php');
?>

<style>
/* Modern styling tokens */
.rg-card {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
    border: 1px solid #e2e8f0;
    margin-bottom: 24px;
    overflow: hidden;
}
.rg-card-header {
    padding: 16px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.rg-card-title {
    font-size: 16px;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.rg-card-body {
    padding: 24px;
}
.rg-stat-box {
    background: #ffffff;
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 20px;
}
.rg-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.rg-stat-val {
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
}
.rg-stat-label {
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.badge-verified {
    background: #10b981;
    color: white;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 4px;
}
.badge-unverified {
    background: #f59e0b;
    color: white;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 4px;
}
.badge-published {
    background: #0284c7;
    color: white;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 4px;
}
.badge-draft {
    background: #64748b;
    color: white;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 4px;
}
.nav-tabs-rg {
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 24px;
}
.nav-tabs-rg > li > a {
    font-weight: 600;
    color: #64748b;
    border: none !important;
    padding: 12px 20px;
    border-bottom: 3px solid transparent !important;
    border-radius: 0;
}
.nav-tabs-rg > li.active > a,
.nav-tabs-rg > li.active > a:focus,
.nav-tabs-rg > li.active > a:hover {
    color: #008D98 !important;
    border-bottom: 3px solid #008D98 !important;
    background: transparent !important;
}
.draft-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 18px;
    margin-bottom: 16px;
    background: #ffffff;
    transition: all 0.2s ease;
}
.draft-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    border-color: #cbd5e1;
}

/* 1-COLUMN CENTERED CONTAINER (Standard 1040px) */
.rg-vertical-container {
    max-width: 1040px;
    margin: 0 auto;
}

/* HERO BANNER GRADIENT */
.studio-hero {
    background: linear-gradient(135deg, #008D98 0%, #006D75 60%, #0B1E39 100%);
    border-radius: 16px;
    padding: 26px 32px;
    color: #FFFFFF;
    margin-bottom: 24px;
    box-shadow: 0 10px 25px -5px rgba(0, 141, 152, 0.25);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
    position: relative;
    overflow: hidden;
}
.studio-hero::after {
    content: "";
    position: absolute;
    right: -40px;
    bottom: -40px;
    width: 220px;
    height: 220px;
    background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
    border-radius: 50%;
    pointer-events: none;
}
.hero-title {
    font-size: 22px;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 6px;
    color: #FFFFFF;
}
.hero-desc {
    font-size: 13.5px;
    color: rgba(255, 255, 255, 0.9);
    max-width: 580px;
    line-height: 1.5;
    margin: 0;
}
.stats-strip {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.stat-badge {
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.25);
    padding: 8px 14px;
    border-radius: 10px;
    text-align: center;
    min-width: 80px;
}
.stat-num {
    font-size: 18px;
    font-weight: 800;
    line-height: 1.1;
    color: #FFFFFF;
}
.stat-lbl {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.85);
    font-weight: 600;
}

/* STEPPED FLOW CARDS */
.flow-step-card {
    background: #FFFFFF;
    border-radius: 14px;
    border: 1px solid #E2E8F0;
    padding: 22px 26px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
    transition: all 0.2s ease;
}
.flow-step-card:hover {
    border-color: #CBD5E1;
}
.step-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px dashed #E2E8F0;
}
.step-label-group {
    display: flex;
    align-items: center;
    gap: 12px;
}
.step-circle {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #E7F4F3;
    color: #008D98;
    font-weight: 800;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1.5px solid rgba(0, 141, 152, 0.3);
    flex-shrink: 0;
}
.step-title {
    font-size: 15px;
    font-weight: 700;
    color: #0F172A;
    margin: 0;
}
.step-subtitle {
    font-size: 12px;
    color: #64748B;
    margin-top: 2px;
}

/* PROTEIN CHOICE CARDS */
.protein-choice-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    margin-bottom: 18px;
}
.protein-choice-card {
    border: 1.5px solid #E2E8F0;
    border-radius: 12px;
    padding: 14px 10px;
    text-align: center;
    cursor: pointer;
    background: #FFFFFF;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    user-select: none;
}
.protein-choice-card:hover {
    border-color: #008D98;
    background: #F8FAFC;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 141, 152, 0.1);
}
.protein-choice-card.selected {
    border-color: #008D98;
    background: #E7F4F3;
    box-shadow: 0 0 0 1px #008D98, 0 4px 14px rgba(0, 141, 152, 0.15);
}
.protein-choice-card .icon {
    font-size: 26px;
    margin-bottom: 6px;
    display: block;
}
.protein-choice-card .name {
    font-size: 13px;
    font-weight: 700;
    color: #0F172A;
    display: block;
}
.protein-choice-card .sub {
    font-size: 11px;
    color: #64748B;
    display: block;
    margin-top: 2px;
}
.protein-choice-card.selected .name {
    color: #00737C;
}
.protein-choice-card.selected::after {
    content: "✓";
    position: absolute;
    top: 6px;
    right: 8px;
    font-size: 11px;
    font-weight: 800;
    color: #008D98;
}

/* SEGMENTED CONTROLS */
.segmented-control {
    display: flex;
    background: #F1F5F9;
    padding: 4px;
    border-radius: 10px;
    gap: 4px;
}
.segment-btn {
    flex: 1;
    border: none;
    background: transparent;
    padding: 9px 12px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 600;
    color: #64748B;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
}
.segment-btn:hover {
    color: #0F172A;
}
.segment-btn.active {
    background: #FFFFFF;
    color: #008D98;
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
    font-weight: 700;
}

/* LIVE SUMMARY CARD */
.live-summary-card {
    background: #E7F4F3;
    border: 1px dashed rgba(0, 141, 152, 0.4);
    border-radius: 10px;
    padding: 14px 18px;
    margin-top: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.live-summary-text {
    font-size: 12.5px;
    color: #00737C;
    line-height: 1.5;
}

/* GRAND BUTTON */
.btn-generate-grand {
    width: 100%;
    padding: 16px 24px;
    background: linear-gradient(135deg, #008D98 0%, #00737C 100%);
    color: #FFFFFF;
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    box-shadow: 0 6px 18px rgba(0, 141, 152, 0.3);
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.btn-generate-grand:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 141, 152, 0.4);
    color: #FFFFFF;
}

@media (max-width: 768px) {
    .protein-choice-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>
<section class="content">
    <div class="container-fluid">
        <div class="rg-vertical-container">

            <!-- Top Breadcrumb -->
            <ol class="breadcrumb" style="background:transparent; padding:0 0 14px 0; margin-bottom:8px;">
                <li><a href="dashboard.php" style="color:#64748B;"><i class="material-icons" style="font-size:14px; vertical-align:middle;">dashboard</i> Dashboard</a></li>
                <li><a href="ai-generator.php" style="color:#64748B;">AI Studio</a></li>
                <li class="active" style="color:#008D98; font-weight:600;">Weekly Recipe Generator</li>
            </ol>

            <!-- Studio Hero Banner -->
            <div class="studio-hero">
                <div>
                    <div class="hero-title">
                        <i class="material-icons" style="font-size:26px;">restaurant_menu</i>
                        AI Recipe Generator &amp; Batch Publisher
                    </div>
                    <p class="hero-desc">
                        Buat batch resep tinggi protein mingguan secara otomatis, verifikasi keakuratan nutrisi &amp; foto, lalu publikasikan langsung ke aplikasi Android ProteinPrep atau rangkai menjadi Prep Pack.
                    </p>
                </div>
                <div class="stats-strip">
                    <div class="stat-badge">
                        <div class="stat-num"><?php echo number_format($statBatches); ?></div>
                        <div class="stat-lbl">Total Batch</div>
                    </div>
                    <div class="stat-badge">
                        <div class="stat-num"><?php echo number_format($statDrafts); ?></div>
                        <div class="stat-lbl">Draft Resep</div>
                    </div>
                    <div class="stat-badge">
                        <div class="stat-num"><?php echo number_format($statPublished); ?></div>
                        <div class="stat-lbl">Resep Terbit</div>
                    </div>
                    <div class="stat-badge">
                        <div class="stat-num"><?php echo number_format($statPending); ?></div>
                        <div class="stat-lbl">Review Nutrisi</div>
                    </div>
                </div>
            </div>

            <!-- Main Tabs Navigation -->
            <ul class="nav nav-tabs nav-tabs-rg" role="tablist">
                <li role="presentation" class="active">
                    <a href="#tab-generate" aria-controls="tab-generate" role="tab" data-toggle="tab">
                        <i class="material-icons" style="font-size:16px; vertical-align:middle;">auto_awesome</i> 1. Form Batch Generator (Alur Bertingkat)
                    </a>
                </li>
                <li role="presentation">
                    <a href="#tab-review" aria-controls="tab-review" role="tab" data-toggle="tab" id="nav-tab-review">
                        <i class="material-icons" style="font-size:16px; vertical-align:middle;">assignment</i> 2. Review &amp; Editor Draft
                    </a>
                </li>
                <li role="presentation">
                    <a href="#tab-history" aria-controls="tab-history" role="tab" data-toggle="tab">
                        <i class="material-icons" style="font-size:16px; vertical-align:middle;">history</i> 3. Riwayat Batch
                    </a>
                </li>
            </ul>

            <div class="tab-content">
                <!-- TAB 1: FORM BATCH GENERATOR (1-KOLOM BERTINGKAT) -->
                <div role="tabpanel" class="tab-pane active" id="tab-generate">
                    <form id="form-recipe-batch">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="primary_protein" id="primary_protein" value="Chicken">
                        <input type="hidden" name="theme" id="theme" value="Asian Savory Garlic &amp; Soy Glazed Chicken">
                        <input type="hidden" name="recipe_count" id="recipe_count" value="3">
                        <input type="hidden" name="target_portions" id="target_portions" value="4">
                        <input type="hidden" name="language" id="recipe_language" value="en">
                        <input type="hidden" name="dietary_preferences" id="dietary_preferences" value="">

                        <!-- LANGKAH 1: SUMBER PROTEIN & TEMA CITA RASA -->
                        <div class="flow-step-card">
                            <div class="step-header">
                                <div class="step-label-group">
                                    <div class="step-circle">1</div>
                                    <div>
                                        <h3 class="step-title">Sumber Protein &amp; Tema Cita Rasa Kuliner</h3>
                                        <div class="step-subtitle">Tentukan bahan dasar protein hewani/nabati dan profil bumbu dunia terkurasi.</div>
                                    </div>
                                </div>
                                <span class="badge" style="background:rgba(0,141,152,0.08); color:var(--pp-teal-dark); font-weight:700; border:1px solid rgba(0,141,152,0.2); font-size:11.5px; padding:4px 10px; border-radius:6px; display:inline-flex; align-items:center; gap:5px;">
                                    <i class="material-icons" style="font-size:14px;">dns</i> Engine: <?php echo htmlspecialchars($primaryModel); ?>
                                </span>
                            </div>

                            <label style="font-weight:700; color:#0F172A; font-size:13px; margin-bottom:8px; display:block;">Pilih Bahan Dasar Protein:</label>
                            <div class="protein-choice-grid">
                                <div class="protein-choice-card selected" onclick="selectProtein('Chicken', this)">
                                    <span class="icon">🍗</span>
                                    <span class="name">Ayam</span>
                                    <span class="sub">Dada/Paha Fillet</span>
                                </div>
                                <div class="protein-choice-card" onclick="selectProtein('Beef', this)">
                                    <span class="icon">🥩</span>
                                    <span class="name">Daging Sapi</span>
                                    <span class="sub">Lean Steak / Mince</span>
                                </div>
                                <div class="protein-choice-card" onclick="selectProtein('Fish & Seafood', this)">
                                    <span class="icon">🐟</span>
                                    <span class="name">Seafood</span>
                                    <span class="sub">Salmon, Tuna, Udang</span>
                                </div>
                                <div class="protein-choice-card" onclick="selectProtein('Eggs & Dairy', this)">
                                    <span class="icon">🍳</span>
                                    <span class="name">Telur</span>
                                    <span class="sub">Olahan Tinggi Protein</span>
                                </div>
                                <div class="protein-choice-card" onclick="selectProtein('Plant-Based', this)">
                                    <span class="icon">🌱</span>
                                    <span class="name">Nabati</span>
                                    <span class="sub">Tahu, Tempe High Pro</span>
                                </div>
                            </div>

                            <div>
                                <label style="font-weight:700; color:#0F172A; font-size:13px; margin-bottom:6px; display:block;">Tema Cita Rasa Kuliner Dunia (Terkurasi Otomatis):</label>
                                <select class="form-control ms" id="theme_select" style="height:44px; border-radius:8px; font-weight:600; color:#0F172A; display:block !important;">
                                    <!-- Populated dynamically by JS -->
                                </select>
                                <div id="theme_custom_container" style="display:none; margin-top:10px;">
                                    <input type="text" class="form-control" id="theme_custom_input" placeholder="Tulis tema kustom sendiri, contoh: Balado Pedas Manis Daun Jeruk..." style="height:42px; border-radius:8px;">
                                </div>
                                <small class="text-muted" style="display:block; margin-top:6px; font-size:12px;">Bumbu dan cita rasa internasional yang ramah chiller dan lezat saat dipanaskan ulang.</small>
                            </div>
                        </div>

                        <!-- LANGKAH 2: SKALA BATCH & TARGET PORSI -->
                        <div class="flow-step-card">
                            <div class="step-header">
                                <div class="step-label-group">
                                    <div class="step-circle">2</div>
                                    <div>
                                        <h3 class="step-title">Skala Batch &amp; Target Porsi Meal Prep</h3>
                                        <div class="step-subtitle">Atur jumlah variasi hidangan, porsi wadah meal prep, dan bahasa instruksi resep.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 col-xs-12" style="margin-bottom:16px;">
                                    <label style="font-weight:700; color:#0F172A; font-size:13px; margin-bottom:6px; display:block;">Jumlah Resep dalam Batch Ini:</label>
                                    <div class="segmented-control">
                                        <button type="button" class="segment-btn" onclick="setSegment(this, 'recipe_count', 2)">2 Resep</button>
                                        <button type="button" class="segment-btn active" onclick="setSegment(this, 'recipe_count', 3)">3 Resep (Ideal)</button>
                                        <button type="button" class="segment-btn" onclick="setSegment(this, 'recipe_count', 4)">4 Resep</button>
                                        <button type="button" class="segment-btn" onclick="setSegment(this, 'recipe_count', 5)">5 Resep</button>
                                    </div>
                                </div>
                                <div class="col-md-6 col-xs-12" style="margin-bottom:16px;">
                                    <label style="font-weight:700; color:#0F172A; font-size:13px; margin-bottom:6px; display:block;">Target Porsi per Resep (Meal Prep):</label>
                                    <div class="segmented-control">
                                        <button type="button" class="segment-btn" onclick="setSegment(this, 'target_portions', 2)">2 Porsi</button>
                                        <button type="button" class="segment-btn active" onclick="setSegment(this, 'target_portions', 4)">4 Porsi (Standar)</button>
                                        <button type="button" class="segment-btn" onclick="setSegment(this, 'target_portions', 5)">5 Porsi (W-Day)</button>
                                        <button type="button" class="segment-btn" onclick="setSegment(this, 'target_portions', 6)">6 Porsi</button>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 col-xs-12" style="margin-bottom:8px;">
                                    <label style="font-weight:700; color:#0F172A; font-size:13px; margin-bottom:6px; display:block;">Maksimal Waktu Memasak (Prep + Cook):</label>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <input type="number" class="form-control" name="max_prep_minutes" id="max_prep_minutes" value="45" min="15" max="180" style="width:110px; height:42px; border-radius:8px; font-weight:700;">
                                        <span style="font-size:13px; color:#64748B; font-weight:600;">Menit (Efisien)</span>
                                    </div>
                                </div>
                                <div class="col-md-6 col-xs-12">
                                    <label style="font-weight:700; color:#0F172A; font-size:13px; margin-bottom:6px; display:block;">Bahasa Output Resep:</label>
                                    <div class="segmented-control">
                                        <button type="button" class="segment-btn" onclick="setSegment(this, 'recipe_language', 'id')">🇮🇩 Bahasa Indonesia</button>
                                        <button type="button" class="segment-btn active" onclick="setSegment(this, 'recipe_language', 'en')">🇬🇧 English</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- LANGKAH 3: KARAKTERISTIK NUTRISI & PANTANGAN -->
                        <div class="flow-step-card">
                            <div class="step-header">
                                <div class="step-label-group">
                                    <div class="step-circle">3</div>
                                    <div>
                                        <h3 class="step-title">Karakteristik Nutrisi &amp; Bahan Pantangan</h3>
                                        <div class="step-subtitle">Pilih profil diet internasional untuk memastikan makro nutrisi selaras kebutuhan atlet.</div>
                                    </div>
                                </div>
                                <span id="diet-count-indicator" style="color:#64748B; font-size:12px; font-weight:700;">0 Preset Dipilih (Opsional)</span>
                            </div>

                            <label style="font-weight:700; color:#0F172A; font-size:13px; margin-bottom:8px; display:block;">Pilih Karakteristik Nutrisi (Klik untuk Mengaktifkan):</label>
                            <div class="diet-pills-container" id="dietPillsGrid">
                                <!-- Generated via JS -->
                            </div>

                            <div style="margin-top:14px;">
                                <label style="font-weight:600; color:#1e293b; font-size:12.5px;">Tambahan Label Diet Kustom (Opsional):</label>
                                <input type="text" class="form-control" id="diet_custom_input" placeholder="contoh: Low Sodium, Tanpa Minyak Berlebih, Ramah Chiller..." style="height:40px; border-radius:8px;">
                            </div>

                            <div style="margin-top:14px;">
                                <label style="font-weight:600; color:#1e293b; font-size:12.5px;">Bahan yang Dilarang / Alergen (Excluded):</label>
                                <input type="text" class="form-control" name="excluded_ingredients" id="excluded_ingredients" placeholder="contoh: Kacang tanah, MSG berlebihan, Olahan babi..." style="height:40px; border-radius:8px;">
                                <small class="text-muted" style="display:block; margin-top:4px; font-size:12px;">Gemini AI tidak akan menggunakan bahan-bahan di atas sama sekali dalam bumbu maupun olahan.</small>
                            </div>

                            <!-- LIVE BATCH SUMMARY -->
                            <div class="live-summary-card">
                                <i class="material-icons" style="color:#008D98; font-size:24px; flex-shrink:0;">preview</i>
                                <div class="live-summary-text" id="liveBatchSummary">
                                    Mempersiapkan <strong>3 Resep Chicken</strong> bertema <strong>Asian Savory Garlic &amp; Soy Glazed Chicken</strong> &bull; 4 Porsi &bull; Bahasa: <strong>🇬🇧 English</strong> &bull; Diet: <em>Standar Nutrisi Seimbang (Tanpa Batasan)</em>.
                                </div>
                            </div>
                        </div>

                        <!-- LANGKAH 4: KONFIRMASI & CATATAN KHUSUS -->
                        <div class="flow-step-card">
                            <div class="step-header">
                                <div class="step-label-group">
                                    <div class="step-circle">4</div>
                                    <div>
                                        <h3 class="step-title">Konfirmasi &amp; Instruksi Tambahan (Opsional)</h3>
                                        <div class="step-subtitle">Berikan arahan chef atau instruksi bumbu khusus sebelum batch di-generate AI.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group" style="margin-bottom:16px;">
                                <label style="font-weight:600; color:#1e293b; font-size:12.5px;">Catatan Khusus untuk Gemini AI:</label>
                                <textarea class="form-control" rows="2" name="admin_notes" id="admin_notes" placeholder="contoh: Pastikan menggunakan bahan yang mudah ditemukan di supermarket dan bumbu aromatik yang tahan disimpan 4 hari di kulkas chiller." style="border-radius:8px;"></textarea>
                            </div>

                            <div class="alert alert-info" style="border-radius:10px; font-size:12px; margin-bottom:20px; border-left:4px solid #008D98;">
                                <i class="material-icons" style="font-size:16px; vertical-align:middle;">info</i>
                                <strong>Ketentuan Integritas Data ProteinPrep:</strong> Seluruh resep hasil AI akan otomatis disimpan sebagai <strong>Draft</strong> dengan nutrisi <code>verified_by_human = 0</code>. Publikasi resmi ke aplikasi Android hanya dapat dilakukan setelah admin memeriksa dan mengonfirmasi verifikasi nutrisi.
                            </div>

                            <div>
                                <button type="submit" id="btn-submit-batch" class="btn-generate-grand">
                                    <i class="material-icons" style="font-size:20px;">auto_awesome</i>
                                    <span id="btn-generate-label">Generate Batch Resep AI</span>
                                </button>
                                <div id="batch-spinner" style="display:none; text-align:center; margin-top:14px; color:#008D98; font-weight:700; font-size:13px;">
                                    <i class="material-icons" style="animation:spin 1s infinite linear; vertical-align:middle;">refresh</i> AI sedang merancang resep terstruktur &amp; menyusun makro nutrisi... (±10-25 detik)
                                </div>
                            </div>
                        </div>

                    </form>
                </div>

                <!-- TAB 2: REVIEW & EDITOR DRAFT -->B 2: REVIEW & EDITOR DRAFT -->
            <div role="tabpanel" class="tab-pane" id="tab-review">
                <div class="rg-card">
                    <div class="rg-card-header">
                        <div class="rg-card-title">
                            <i class="material-icons" style="color:#008D98;">assignment</i>
                            <span id="review-batch-title">Review Draft Resep</span>
                        </div>
                        <div id="batch-meta-actions">
                            <!-- Populated dynamically -->
                        </div>
                    </div>
                    <div class="rg-card-body" id="review-batch-content">
                        <div style="text-align:center; padding:40px 20px; color:#64748b;">
                            <i class="material-icons" style="font-size:48px; color:#cbd5e1; margin-bottom:12px;">receipt_long</i>
                            <h4>Belum ada batch yang dipilih</h4>
                            <p>Generate batch baru pada tab 1 atau pilih batch dari tab Riwayat untuk meninjau dan menerbitkan resep.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: RIWAYAT BATCH -->
            <div role="tabpanel" class="tab-pane" id="tab-history">
                <div class="rg-card">
                    <div class="rg-card-header">
                        <div class="rg-card-title">
                            <i class="material-icons" style="color:#008D98;">history</i>
                            Riwayat Batch Recipe Generator
                        </div>
                    </div>
                    <div class="rg-card-body">
                        <?php if (empty($batches)): ?>
                            <div class="alert alert-warning">Belum ada riwayat batch yang dibuat. Silakan buat batch pertama Anda pada Tab 1!</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr style="background:#f8fafc; color:#475569; font-size:12px;">
                                            <th>KODE BATCH</th>
                                            <th>TEMA & PROTEIN</th>
                                            <th>BAHASA</th>
                                            <th>PREP PACK</th>
                                            <th>JUMLAH DRAFT</th>
                                            <th>TERBIT (PUBLISHED)</th>
                                            <th>MODEL</th>
                                            <th>TANGGAL</th>
                                            <th style="text-align:right;">AKSI</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($batches as $b): ?>
                                            <tr>
                                                <td><strong style="color:#008D98;"><?php echo htmlspecialchars($b['batch_code']); ?></strong></td>
                                                <td>
                                                    <div style="font-weight:600; color:#1e293b;"><?php echo htmlspecialchars($b['theme']); ?></div>
                                                    <small class="text-muted">Protein: <?php echo htmlspecialchars($b['primary_protein']); ?></small>
                                                </td>
                                                <td>
                                                    <?php if (($b['language'] ?? 'id') === 'en'): ?>
                                                        <span class="badge" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; font-size:11px;">🇬🇧 English</span>
                                                    <?php else: ?>
                                                        <span class="badge" style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca; font-size:11px;">🇮🇩 Indonesia</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($b['prep_pack_id'])): ?>
                                                        <a href="prep-pack-editor.php?id=<?php echo (int)$b['prep_pack_id']; ?>" class="badge bg-teal" style="text-decoration:none; display:inline-flex; align-items:center; gap:4px; padding:4px 8px;" title="<?php echo htmlspecialchars($b['pack_name'] ?? ''); ?>">
                                                            <i class="material-icons" style="font-size:12px;">layers</i> Pack #<?php echo (int)$b['prep_pack_id']; ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="badge" style="background:#f1f5f9; color:#64748b; font-size:11px; border:1px solid #e2e8f0;">Belum Ada</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="badge bg-blue"><?php echo (int)$b['total_drafts']; ?> Resep</span></td>
                                                <td>
                                                    <?php if ((int)$b['published_count'] > 0): ?>
                                                        <span class="badge-verified"><i class="material-icons" style="font-size:12px; vertical-align:middle;">check</i> <?php echo (int)$b['published_count']; ?> Terbit</span>
                                                    <?php else: ?>
                                                        <span class="badge-draft">0 Terbit</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><small><?php echo htmlspecialchars($b['model_used']); ?></small></td>
                                                <td><small><?php echo date('d M Y H:i', strtotime($b['created_at'])); ?></small></td>
                                                <td style="text-align:right;">
                                                    <button type="button" class="btn btn-xs bg-teal waves-effect" onclick="loadBatch(<?php echo (int)$b['id']; ?>)">
                                                        <i class="material-icons" style="font-size:14px; vertical-align:middle;">visibility</i> Buka Batch
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div><!-- .tab-content -->

        </div><!-- .rg-vertical-container -->
    </div><!-- .container-fluid -->
</section>

<!-- MODAL: EDIT DRAFT RESEP -->
<div class="modal fade" id="modalEditDraft" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius:10px; overflow:hidden;">
            <div class="modal-header" style="background:#008D98; color:#fff; padding:16px 20px;">
                <h4 class="modal-title" style="font-weight:700;"><i class="material-icons" style="vertical-align:middle;">edit</i> Edit Detail Resep Draft</h4>
            </div>
            <form id="form-edit-draft">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="draft_id" id="edit-draft-id">
                <div class="modal-body" style="padding:20px; max-height:calc(100vh - 200px); overflow-y:auto;">
                    
                    <div class="form-group">
                        <label style="font-weight:600;">Judul Resep</label>
                        <input type="text" class="form-control" name="recipe_title" id="edit-recipe-title" required>
                        <div id="alt-titles-container" style="display:none; margin-top:8px;">
                            <small style="color:#008D98; font-weight:700;"><i class="material-icons" style="font-size:13px; vertical-align:middle;">auto_awesome</i> Alternatif Judul High-CTR (Klik untuk gunakan):</small>
                            <div id="alt-titles-list" style="display:flex; flex-wrap:wrap; gap:6px; margin-top:4px;"></div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label style="font-weight:600;">Porsi (Servings)</label>
                                <input type="number" class="form-control" name="serving_size" id="edit-serving-size" min="1" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label style="font-weight:600;">Waktu Memasak</label>
                                <input type="text" class="form-control" name="recipe_time" id="edit-recipe-time" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label style="font-weight:600;">Sumber Protein Utama</label>
                                <input type="text" class="form-control" name="primary_protein_source" id="edit-protein-source" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label style="font-weight:600;">Deskripsi Resep</label>
                        <textarea class="form-control" rows="2" name="recipe_description" id="edit-recipe-desc"></textarea>
                    </div>

                    <div class="form-group">
                        <label style="font-weight:600;">Label Resep</label>
                        <input type="text" class="form-control" name="recipe_labels" id="edit-recipe-labels">
                    </div>

                    <div class="form-group">
                        <label style="font-weight:600;">Nama File Foto</label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="recipe_image" id="edit-recipe-image" placeholder="recipe_lemon_chicken.png">
                            <span class="input-group-btn">
                                <a href="ai-generator.php" target="_blank" class="btn bg-purple waves-effect">
                                    <i class="material-icons" style="font-size:14px; vertical-align:middle;">auto_awesome</i> Buat di AI Studio
                                </a>
                            </span>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label style="font-weight:600;">Panduan Penyimpanan (Store Guidance)</label>
                                <textarea class="form-control" rows="3" name="store_guidance" id="edit-store-guidance" required></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label style="font-weight:600;">Panduan Pemanasan (Reheat Guidance)</label>
                                <textarea class="form-control" rows="3" name="reheat_guidance" id="edit-reheat-guidance" required></textarea>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer" style="background:#f8fafc; padding:12px 20px;">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn bg-teal waves-effect" style="font-weight:600;">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: VERIFIKASI NUTRISI MANUSIA -->
<div class="modal fade" id="modalVerifyNutrition" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content" style="border-radius:10px; overflow:hidden;">
            <div class="modal-header" style="background:#10b981; color:#fff; padding:16px 20px;">
                <h4 class="modal-title" style="font-weight:700;"><i class="material-icons" style="vertical-align:middle;">fact_check</i> Verifikasi Nilai Nutrisi (Manusia)</h4>
            </div>
            <form id="form-verify-nutrition">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="recipe_id" id="verify-recipe-id">
                <input type="hidden" name="draft_id" id="verify-draft-id">
                <div class="modal-body" style="padding:20px;">
                    <div class="alert alert-info" style="font-size:12px; margin-bottom:16px;">
                        <i class="material-icons" style="font-size:16px; vertical-align:middle;">info</i>
                        Tinjau estimasi nutrisi dari AI berikut. Sesuaikan angka jika diperlukan dan centang kotak persetujuan untuk mengubah status menjadi <strong>Terverifikasi Manusia</strong>.
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label style="font-weight:600;">Kalori (kcal)</label>
                                <input type="number" class="form-control" name="calories" id="verify-calories" min="1" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label style="font-weight:600; color:#0284c7;">Protein (gram) *Wajib > 0</label>
                                <input type="number" step="0.1" class="form-control" name="protein_g" id="verify-protein" min="0.1" required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label style="font-weight:600;">Karbohidrat (gram)</label>
                                <input type="number" step="0.1" class="form-control" name="carbs_g" id="verify-carbs" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label style="font-weight:600;">Lemak / Fat (gram)</label>
                                <input type="number" step="0.1" class="form-control" name="fat_g" id="verify-fat" min="0" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label style="font-weight:600;">Info Takaran Porsi</label>
                        <input type="text" class="form-control" name="serving_size_info" id="verify-serving-info">
                    </div>

                    <div class="form-group">
                        <label style="font-weight:600;">Catatan Verifikasi</label>
                        <textarea class="form-control" rows="2" name="calculation_notes" id="verify-notes"></textarea>
                    </div>

                    <div style="background:#f1f5f9; padding:12px; border-radius:6px; margin-top:10px;">
                        <input type="checkbox" id="chk-verified" name="verified_by_human" value="1" class="filled-in chk-col-teal">
                        <label for="chk-verified" style="font-weight:700; color:#0f172a; margin-bottom:0;">
                            Saya mengonfirmasi bahwa nilai nutrisi ini telah saya periksa dan valid untuk disajikan kepada pengguna.
                        </label>
                    </div>

                </div>
                <div class="modal-footer" style="background:#f8fafc; padding:12px 20px;">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn bg-green waves-effect" style="font-weight:600;">Simpan Verifikasi Nutrisi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?php echo $csrfToken; ?>';

// =========================================================================
// WORLD FLAVOR PRESETS & DIETARY PILLS
// =========================================================================
const FLAVOR_PRESETS = {
    'Chicken': [
        { label: '🍗 Asian Savory Garlic & Soy Glazed', value: 'Asian Savory Garlic & Soy Glazed Chicken' },
        { label: '🍋 Mediterranean Herb & Charred Lemon', value: 'Mediterranean Herb & Charred Lemon Chicken' },
        { label: '🔥 Smoky American BBQ Glaze', value: 'Smoky American BBQ Glazed Chicken' },
        { label: '🌮 Latin Fajita & Smoky Chipotle', value: 'Latin Fajita & Smoky Chipotle Spiced Chicken' },
        { label: '🍱 Japanese Teriyaki & Roasted Sesame', value: 'Japanese Teriyaki & Roasted Sesame Chicken' },
        { label: '🥙 Middle Eastern Shawarma Spiced', value: 'Middle Eastern Shawarma Spiced Chicken' },
        { label: '🍛 Indian Tandoori & Aromatic Spices', value: 'Indian Tandoori & Aromatic Spiced Chicken' },
        { label: '🧄 Creamy Tuscan Garlic & Sun-Dried Tomato', value: 'Creamy Tuscan Garlic & Sun-Dried Tomato Chicken' }
    ],
    'Beef': [
        { label: '🥩 Classic Black Pepper & Garlic Steak', value: 'Classic Black Pepper & Garlic Lean Beef Steak' },
        { label: '🥢 Korean Bulgogi & Sweet Sesame', value: 'Korean Bulgogi & Sweet Sesame Beef' },
        { label: '🌮 Mexican Barbacoa & Cumin Lime', value: 'Mexican Barbacoa & Cumin Lime Shredded Beef' },
        { label: '🌿 Argentinian Herb Chimichurri', value: 'Argentinian Chimichurri Grilled Beef' },
        { label: '🍷 French Burgundy Garlic & Rosemary', value: 'French Burgundy Garlic & Rosemary Beef' },
        { label: '🧅 Japanese Yakiniku & Sweet Onion', value: 'Japanese Yakiniku & Sweet Caramelized Onion Beef' },
        { label: '🌶️ Moroccan Spiced Minced Beef (Kefta)', value: 'Moroccan Aromatic Spiced Minced Beef Kefta' }
    ],
    'Fish & Seafood': [
        { label: '🧈 Garlic Butter & Fresh Herb Emulsion', value: 'Garlic Butter & Fresh Herb Emulsion Fish' },
        { label: '🍯 Japanese Miso & Honey Glazed', value: 'Japanese Miso & Honey Glazed Salmon' },
        { label: '🍅 Mediterranean Tomato & Kalamata Olive', value: 'Mediterranean Tomato & Kalamata Olive Fish' },
        { label: '🔥 Louisiana Cajun & Blackened Spice', value: 'Louisiana Cajun & Blackened Spice Seafood' },
        { label: '🌶️ Thai Sweet Chili & Lemongrass', value: 'Thai Sweet Chili & Lemongrass Seafood' },
        { label: '🫚 Ginger Scallion & Sesame Steamed', value: 'Ginger Scallion & Toasted Sesame Steamed Fish' }
    ],
    'Eggs & Dairy': [
        { label: '🍳 Mediterranean Shakshuka Style', value: 'Mediterranean Shakshuka Spiced Eggs' },
        { label: '🥔 Spanish Frittata & Caramelized Onion', value: 'Spanish Frittata & Caramelized Onion High Protein' },
        { label: '🍱 Japanese Tamagoyaki & Dashi Sweet Umami', value: 'Japanese Tamagoyaki & Dashi Sweet Umami Eggs' },
        { label: '🍅 Mexican Scramble Rancheros', value: 'Mexican Scramble Rancheros Salsa Eggs' },
        { label: '🌿 Italian Herb & Pesto Cottage Blend', value: 'Italian Herb & Pesto Cottage Cheese High Protein' }
    ],
    'Plant-Based': [
        { label: '🌶️ Indonesian Sambal Balado & Lemongrass', value: 'Indonesian Sambal Balado & Lemongrass Tempeh Tofu' },
        { label: '🥜 Szechuan Kung Pao & Roasted Peanut', value: 'Szechuan Kung Pao & Roasted Peanut Plant Protein' },
        { label: '💛 Indonesian Ungkep Rempah Kuning', value: 'Indonesian Ungkep Rempah Kuning Tahu Tempe' },
        { label: '🥢 Japanese Sweet Ginger Teriyaki', value: 'Japanese Sweet Ginger Teriyaki Tofu' },
        { label: '🌿 Middle Eastern Zaatar & Olive Oil', value: 'Middle Eastern Zaatar & Extra Virgin Olive Oil Tempeh' }
    ]
};

const DIET_PRESETS = [
    { label: 'High Protein Lean Muscle', icon: '💪' },
    { label: 'Meal Prep Friendly', icon: '📦' },
    { label: 'Low Carb & Calorie Deficit', icon: '📉' },
    { label: 'Clean Eating & Low Sodium', icon: '🥗' },
    { label: 'Gluten-Free Certified', icon: '🌾' },
    { label: 'Dairy-Free (Lactose-Free)', icon: '🥛' },
    { label: 'Ketogenic (High Fat Low Carb)', icon: '🥑' },
    { label: 'Mass Gain & High Carb Complex', icon: '⚡' },
    { label: 'Low Glycemic Index (Diabetic-Friendly)', icon: '🩺' },
    { label: 'One-Pan / Skillet Friendly', icon: '🍳' }
];

let selectedDietPills = [];

function selectProtein(protein, el) {
    $('.protein-choice-card').removeClass('selected');
    $(el).addClass('selected');
    $('#primary_protein').val(protein);
    updateFlavorThemeDropdown(protein);
    updateLiveSummary();
}

function setSegment(btn, inputId, val) {
    const parent = $(btn).closest('.segmented-control');
    parent.find('.segment-btn').removeClass('active');
    $(btn).addClass('active');
    $('#' + inputId).val(val);
    updateLiveSummary();
}

function renderDietPills() {
    const grid = $('#dietPillsGrid');
    grid.empty();
    DIET_PRESETS.forEach(function(item) {
        const isSelected = selectedDietPills.includes(item.label);
        const pill = $(`
            <div class="diet-pill ${isSelected ? 'active' : ''}" onclick="toggleDietPill(this, '${escapeHtml(item.label)}')">
                <span class="pill-check">✓</span>
                <span>${item.icon} ${escapeHtml(item.label)}</span>
            </div>
        `);
        grid.append(pill);
    });
    updateDietaryValue();
}

function toggleDietPill(btn, label) {
    const idx = selectedDietPills.indexOf(label);
    if (idx !== -1) {
        selectedDietPills.splice(idx, 1);
        $(btn).removeClass('active');
    } else {
        selectedDietPills.push(label);
        $(btn).addClass('active');
    }
    updateDietaryValue();
}

function updateDietaryValue() {
    const custom = $('#diet_custom_input').val() ? $('#diet_custom_input').val().trim() : '';
    let combined = [...selectedDietPills];
    if (custom.length > 0) {
        const customItems = custom.split(',').map(s => s.trim()).filter(s => s.length > 0);
        combined = [...combined, ...customItems];
    }
    const finalVal = combined.join(', ');
    $('#dietary_preferences').val(finalVal);

    const countIndicator = $('#diet-count-indicator');
    if (selectedDietPills.length > 0) {
        countIndicator.text(selectedDietPills.length + ' preset dipilih' + (custom.length > 0 ? ' (+kustom)' : ''));
        countIndicator.css('color', 'var(--pp-teal)');
    } else {
        countIndicator.text('0 Preset Dipilih (Opsional)' + (custom.length > 0 ? ' (kustom aktif)' : ''));
        countIndicator.css('color', '#64748B');
    }
    updateLiveSummary();
}

function updateLiveSummary() {
    const protein = $('#primary_protein').val() || 'Chicken';
    const theme = $('#theme').val() || 'Resep Terstruktur';
    const count = $('#recipe_count').val() || '3';
    const portions = $('#target_portions').val() || '4';
    const lang = $('#recipe_language').val() || 'en';
    const langText = lang === 'en' ? '🇬🇧 English' : '🇮🇩 Bahasa Indonesia';
    const excluded = $('#excluded_ingredients').val() ? $('#excluded_ingredients').val().trim() : '';
    
    let dietDesc = selectedDietPills.length > 0 ? selectedDietPills.join(', ') : 'Standar Nutrisi Seimbang (Tanpa Batasan)';
    const custom = $('#diet_custom_input').val() ? $('#diet_custom_input').val().trim() : '';
    if (custom) dietDesc += (selectedDietPills.length > 0 ? ' + ' : '') + custom;

    let text = `Mempersiapkan <strong>${count} Resep ${escapeHtml(protein)}</strong> bertema <strong>${escapeHtml(theme)}</strong> &bull; ${portions} Porsi &bull; Bahasa: <strong>${langText}</strong> &bull; Diet: <em>${escapeHtml(dietDesc)}</em>.`;
    if (excluded) {
        text += ` <span style="color:#EF4444;">(Tanpa: ${escapeHtml(excluded)})</span>`;
    }

    $('#liveBatchSummary').html(text);
}

function updateFlavorThemeDropdown(protein) {
    const sel = $('#theme_select');
    sel.empty();
    const presets = FLAVOR_PRESETS[protein] || FLAVOR_PRESETS['Chicken'];
    presets.forEach(function(p, i) {
        sel.append(new Option(p.label, p.value, i === 0, i === 0));
    });
    sel.append(new Option('✍️ Tulis Tema Kustom Lainnya...', '__CUSTOM__', false, false));

    // If selectpicker was initialized on this select, refresh it
    if ($.fn.selectpicker && sel.data('selectpicker')) {
        sel.selectpicker('refresh');
    }

    onThemeSelectChange();
}

function onThemeSelectChange() {
    const val = $('#theme_select').val();
    if (val === '__CUSTOM__') {
        $('#theme_custom_container').show();
        $('#theme_custom_input').focus();
        const customVal = $('#theme_custom_input').val().trim();
        $('#theme').val(customVal.length > 0 ? customVal : 'High Protein Clean Prep');
    } else {
        $('#theme_custom_container').hide();
        $('#theme').val(val);
    }
    updateLiveSummary();
}

$(document).ready(function() {
    // Destroy selectpicker on theme_select if AdminBSB auto-initialized it
    if ($.fn.selectpicker && $('#theme_select').data('selectpicker')) {
        $('#theme_select').selectpicker('destroy');
    }

    // Initialize Flavor Theme Dropdown
    $('#primary_protein').on('change', function() {
        updateFlavorThemeDropdown($(this).val());
    });
    $('#theme_select').on('change', onThemeSelectChange);
    $('#theme_custom_input').on('keyup input', function() {
        if ($('#theme_select').val() === '__CUSTOM__') {
            $('#theme').val($(this).val().trim());
            updateLiveSummary();
        }
    });

    updateFlavorThemeDropdown($('#primary_protein').val() || 'Chicken');

    // Initialize Diet Pills (default empty)
    renderDietPills();
    $('#diet_custom_input').on('keyup input', updateDietaryValue);
    $('#excluded_ingredients').on('keyup input', updateLiveSummary);

    // Initial Live Summary
    updateLiveSummary();
});

// Submit Batch Form
$('#form-recipe-batch').on('submit', function(e) {
    e.preventDefault();
    const btn = $('#btn-submit-batch');
    const spinner = $('#batch-spinner');

    btn.prop('disabled', true).css('opacity', '0.7');
    spinner.show();

    const formData = $(this).serialize() + '&ajax_action=generate_batch';

    $.ajax({
        url: 'recipe-generator.php',
        type: 'POST',
        data: formData,
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).css('opacity', '1');
            spinner.hide();
            if (res.success) {
                swal({
                    title: "Batch Berhasil Dibuat!",
                    text: res.message + "\n\nIngin langsung membundel seluruh resep batch ini menjadi 1 Paket Prep Pack?",
                    type: "success",
                    showCancelButton: true,
                    confirmButtonColor: "#8B5CF6",
                    confirmButtonText: "Ya, Rangkai Prep Pack!",
                    cancelButtonText: "Buka Review Saja",
                    closeOnConfirm: false
                }, function(isConfirm) {
                    if (isConfirm) {
                        bundleBatchToPrepPack(res.batch_id, false);
                    } else {
                        swal.close();
                        loadBatch(res.batch_id);
                    }
                });
            } else {
                swal("Gagal Generate Batch", res.error || "Terjadi kesalahan saat memproses permintaan.", "error");
            }
        },
        error: function(xhr, status, err) {
            btn.prop('disabled', false).css('opacity', '1');
            spinner.hide();
            swal("Server Error", "Koneksi ke server gagal: " + err, "error");
        }
    });
});

// Load Batch Detail into Tab 2
function loadBatch(batchId) {
    $('#nav-tab-review').tab('show');
    $('#review-batch-content').html('<div style="text-align:center; padding:50px;"><i class="material-icons" style="font-size:36px; animation:spin 1s infinite linear; color:#008D98;">refresh</i><p style="margin-top:10px;">Memuat draft batch...</p></div>');

    $.getJSON('recipe-generator.php?ajax_action=get_batch_detail&csrf_token=' + CSRF_TOKEN + '&batch_id=' + batchId, function(res) {
        if (!res.success || !res.batch) {
            $('#review-batch-content').html('<div class="alert alert-danger">' + (res.error || 'Gagal memuat batch.') + '</div>');
            return;
        }

        const b = res.batch;
        const langBadge = (b.language === 'en')
            ? '<span class="badge" style="background:#fef3c7; color:#92400e; font-size:11px; margin-left:8px; border:1px solid #fde68a;">🇬🇧 English</span>'
            : '<span class="badge" style="background:#fee2e2; color:#991b1b; font-size:11px; margin-left:8px; border:1px solid #fecaca;">🇮🇩 Indonesia</span>';
        $('#review-batch-title').html('Batch: <strong>' + escapeHtml(b.theme) + '</strong> ' + langBadge + ' <small style="color:#64748b;">(' + escapeHtml(b.batch_code) + ')</small>');
        
        // Render Batch Header Actions (Prep Pack Bundler)
        if (b.prep_pack_id && parseInt(b.prep_pack_id) > 0) {
            $('#batch-meta-actions').html(`
                <div style="display:flex; align-items:center; gap:8px;">
                    <a href="prep-pack-editor.php?id=${b.prep_pack_id}" class="btn bg-teal waves-effect" style="border-radius:6px; font-weight:700; color:#fff; display:inline-flex; align-items:center; gap:6px; padding:6px 14px;">
                        <i class="material-icons" style="font-size:16px;">layers</i> Buka Prep Pack #${b.prep_pack_id}
                    </a>
                    <button type="button" class="btn btn-default waves-effect" onclick="bundleBatchToPrepPack(${b.id}, true)" style="border-radius:6px; font-size:12px; padding:6px 10px;" title="Rangkai ulang prep pack baru dari batch ini">
                        <i class="material-icons" style="font-size:14px; vertical-align:middle;">refresh</i> Re-bundle
                    </button>
                </div>
            `);
        } else {
            $('#batch-meta-actions').html(`
                <button type="button" class="btn waves-effect" onclick="bundleBatchToPrepPack(${b.id}, false)" style="background:#8B5CF6; color:#fff; border-radius:6px; font-weight:700; padding:8px 16px; display:inline-flex; align-items:center; gap:6px;">
                    <i class="material-icons" style="font-size:16px;">auto_awesome</i> Bundle Batch Jadi Prep Pack
                </button>
            `);
        }

        let html = '';
        if (!b.drafts || b.drafts.length === 0) {
            html = '<div class="alert alert-warning">Tidak ada draft resep di dalam batch ini.</div>';
        } else {
            b.drafts.forEach(function(d, idx) {
                const isPublished = d.is_published == 1;
                const isVerified = d.verified_by_human == 1;

                html += '<div class="draft-card" id="draft-card-' + d.id + '">';
                html += '  <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">';
                html += '    <div>';
                html += '      <span style="font-weight:700; color:#64748b; font-size:12px; margin-right:8px;">#' + (idx + 1) + '</span>';
                html += '      <h4 style="margin:0 0 6px 0; color:#0f172a; font-weight:700; font-size:18px; display:inline;">' + escapeHtml(d.draft_title) + '</h4>';
                html += '      <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">';
                
                if (isPublished) {
                    html += '        <span class="badge-published"><i class="material-icons" style="font-size:12px; vertical-align:middle;">check_circle</i> Published</span>';
                } else {
                    html += '        <span class="badge-draft">Draft</span>';
                }

                if (isVerified) {
                    html += '        <span class="badge-verified"><i class="material-icons" style="font-size:12px; vertical-align:middle;">verified</i> Nutrisi Terverifikasi</span>';
                } else {
                    html += '        <span class="badge-unverified"><i class="material-icons" style="font-size:12px; vertical-align:middle;">warning</i> Nutrisi Belum Diverifikasi</span>';
                }

                if (d.similarity_warning) {
                    html += '        <span class="badge bg-amber" title="' + escapeHtml(d.similarity_warning) + '"><i class="material-icons" style="font-size:12px; vertical-align:middle;">warning</i> Periksa Duplikasi</span>';
                }

                html += '      </div>';
                html += '    </div>';
                html += '    <div style="text-align:right;">';
                html += '      <div style="font-weight:800; font-size:16px; color:#0284c7;">' + parseFloat(d.protein_g || 0) + 'g protein</div>';
                html += '      <small class="text-muted">' + (d.calories || 0) + ' kcal / ' + (d.serving_size || 4) + ' porsi</small>';
                html += '    </div>';
                html += '  </div>';

                if (d.similarity_warning) {
                    html += '  <div class="alert alert-warning" style="padding:8px 12px; font-size:12px; margin-bottom:12px;">' + escapeHtml(d.similarity_warning) + '</div>';
                }

                html += '  <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #f1f5f9; padding-top:12px; margin-top:8px;">';
                html += '    <div>';
                html += '      <button type="button" class="btn btn-default btn-sm waves-effect" onclick="openEditDraft(' + d.id + ')" style="font-weight:600; margin-right:6px;">';
                html += '        <i class="material-icons" style="font-size:14px; vertical-align:middle;">edit</i> Edit Resep';
                html += '      </button>';
                html += '      <button type="button" class="btn btn-default btn-sm waves-effect" onclick="openVerifyNutrition(' + d.recipe_id + ', ' + d.id + ')" style="font-weight:600; margin-right:6px;">';
                html += '        <i class="material-icons" style="font-size:14px; vertical-align:middle; color:#10b981;">fact_check</i> Verifikasi Nutrisi';
                html += '      </button>';
                html += '      <a href="ai-generator.php" target="_blank" class="btn btn-default btn-sm waves-effect" style="font-weight:600;">';
                html += '        <i class="material-icons" style="font-size:14px; vertical-align:middle; color:#6366f1;">photo_camera</i> Pilih/Buat Foto';
                html += '      </a>';
                html += '    </div>';
                html += '    <div>';
                
                if (isPublished) {
                    html += '      <button type="button" class="btn btn-warning btn-sm waves-effect" onclick="withdrawRecipe(' + d.recipe_id + ', ' + d.id + ')" style="font-weight:600;">';
                    html += '        <i class="material-icons" style="font-size:14px; vertical-align:middle;">undo</i> Tarik (Withdraw)';
                    html += '      </button>';
                } else {
                    html += '      <button type="button" class="btn bg-teal btn-sm waves-effect" onclick="publishRecipe(' + d.recipe_id + ', ' + d.id + ')" style="font-weight:700;">';
                    html += '        <i class="material-icons" style="font-size:14px; vertical-align:middle;">publish</i> Terbitkan ke Android';
                    html += '      </button>';
                }

                html += '    </div>';
                html += '  </div>';
                html += '</div>';
            });
        }

        $('#review-batch-content').html(html);
    });
}

// Bundle Batch into unified Prep Pack
function bundleBatchToPrepPack(batchId, forceRecreate = false) {
    swal({
        title: forceRecreate ? "Rangkai Ulang Prep Pack?" : "Bundle Batch Jadi Prep Pack?",
        text: "Gemini AI akan merangkai resep dalam batch ini menjadi 1 alur memasak simultan lengkap dengan timer dan opsi porsi.",
        type: "info",
        showCancelButton: true,
        closeOnConfirm: false,
        showLoaderOnConfirm: true,
        confirmButtonColor: "#8B5CF6",
        confirmButtonText: "Ya, Mulai Rangkai!",
        cancelButtonText: "Batal"
    }, function() {
        $.ajax({
            url: 'recipe-generator.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_action: 'bundle_batch_to_prep_pack',
                csrf_token: CSRF_TOKEN,
                batch_id: batchId,
                force_recreate: forceRecreate ? 1 : 0
            },
            success: function(res) {
                if (res.success) {
                    swal({
                        title: "Prep Pack Siap!",
                        text: res.message || "Prep Pack berhasil dirangkai. Membuka studio editor...",
                        type: "success",
                        timer: 1500,
                        showConfirmButton: false
                    });
                    setTimeout(function() {
                        window.location.href = res.redirect_url;
                    }, 1200);
                } else {
                    swal("Gagal Bundling", res.error || "Terjadi kesalahan sistem.", "error");
                }
            },
            error: function(xhr, status, err) {
                swal("Koneksi Error", "Gagal menghubungi server: " + err, "error");
            }
        });
    });
}

// Open Edit Draft Modal
function openEditDraft(draftId) {
    $.getJSON('recipe-generator.php?ajax_action=get_draft_detail&csrf_token=' + CSRF_TOKEN + '&draft_id=' + draftId, function(res) {
        if (!res.success || !res.draft) {
            swal("Error", res.error || "Gagal memuat detail draft.", "error");
            return;
        }
        const d = res.draft;
        $('#edit-draft-id').val(d.id);
        $('#edit-recipe-title').val(d.recipe_title);
        $('#edit-serving-size').val(d.serving_size || 4);
        $('#edit-recipe-time').val(d.recipe_time);
        $('#edit-protein-source').val(d.primary_protein_source);
        $('#edit-recipe-desc').val(d.recipe_description);
        $('#edit-recipe-labels').val(d.recipe_labels);
        $('#edit-recipe-image').val(d.recipe_image);
        $('#edit-store-guidance').val(d.store_guidance);
        $('#edit-reheat-guidance').val(d.reheat_guidance);

        // Render alternative High-CTR titles if available
        const altContainer = $('#alt-titles-container');
        const altList = $('#alt-titles-list');
        altList.empty();
        if (d.draft_data && d.draft_data.alternative_titles && Array.isArray(d.draft_data.alternative_titles) && d.draft_data.alternative_titles.length > 0) {
            d.draft_data.alternative_titles.forEach(function(alt) {
                const btn = $('<button type="button" class="btn btn-xs waves-effect" style="background:#E7F4F3; color:#00737C; border:1px solid rgba(0,141,152,0.3); border-radius:6px; font-size:11.5px; font-weight:600; text-align:left; white-space:normal; padding:4px 8px; margin-bottom:4px;"></button>')
                    .text(alt)
                    .on('click', function() {
                        $('#edit-recipe-title').val(alt);
                    });
                altList.append(btn);
            });
            altContainer.show();
        } else {
            altContainer.hide();
        }

        $('#modalEditDraft').modal('show');
    });
}

// Save Draft Edit
$('#form-edit-draft').on('submit', function(e) {
    e.preventDefault();
    const data = $(this).serialize() + '&ajax_action=update_draft';
    $.post('recipe-generator.php', data, function(res) {
        if (res.success) {
            $('#modalEditDraft').modal('hide');
            swal("Tersimpan!", res.message, "success");
            const draftId = $('#edit-draft-id').val();
            // Refresh batch view
            const activeBatch = $('#review-batch-content').data('batch-id');
            if (activeBatch) loadBatch(activeBatch);
        } else {
            swal("Gagal Menyimpan", res.error || "Terjadi kesalahan.", "error");
        }
    }, 'json');
});

// Open Verify Nutrition Modal
function openVerifyNutrition(recipeId, draftId) {
    $.getJSON('recipe-generator.php?ajax_action=get_draft_detail&csrf_token=' + CSRF_TOKEN + '&draft_id=' + draftId, function(res) {
        if (!res.success || !res.draft) {
            swal("Error", res.error || "Gagal memuat detail nutrisi.", "error");
            return;
        }
        const d = res.draft;
        $('#verify-recipe-id').val(recipeId);
        $('#verify-draft-id').val(draftId);
        $('#verify-calories').val(d.calories || 400);
        $('#verify-protein').val(d.protein_g || 40);
        $('#verify-carbs').val(d.carbs_g || 20);
        $('#verify-fat').val(d.fat_g || 10);
        $('#verify-serving-info').val(d.serving_size_info || '');
        $('#verify-notes').val(d.calculation_notes || '');
        $('#chk-verified').prop('checked', d.verified_by_human == 1);
        $('#modalVerifyNutrition').modal('show');
    });
}

// Save Verified Nutrition
$('#form-verify-nutrition').on('submit', function(e) {
    e.preventDefault();
    const data = $(this).serialize() + '&ajax_action=verify_nutrition';
    $.post('recipe-generator.php', data, function(res) {
        if (res.success) {
            $('#modalVerifyNutrition').modal('hide');
            swal("Berhasil!", res.message, "success");
            const draftId = $('#verify-draft-id').val();
            // Update UI card badge
            $('#draft-card-' + draftId).find('.badge-unverified').replaceWith('<span class="badge-verified"><i class="material-icons" style="font-size:12px; vertical-align:middle;">verified</i> Nutrisi Terverifikasi</span>');
        } else {
            swal("Gagal Verifikasi", res.error || "Terjadi kesalahan.", "error");
        }
    }, 'json');
});

// Publish Recipe
function publishRecipe(recipeId, draftId) {
    swal({
        title: "Terbitkan Resep Ini?",
        text: "Sistem akan menjalankan PublicationValidator untuk memeriksa kelengkapan bahan, panduan simpan/panaskan, dan verifikasi nutrisi.",
        type: "info",
        showCancelButton: true,
        confirmButtonText: "Ya, Terbitkan!",
        cancelButtonText: "Batal",
        closeOnConfirm: false
    }, function() {
        $.post('recipe-generator.php', {
            ajax_action: 'publish_recipe',
            csrf_token: CSRF_TOKEN,
            recipe_id: recipeId,
            draft_id: draftId
        }, function(res) {
            if (res.success) {
                swal("Berhasil Terbit!", res.message, "success");
                const card = $('#draft-card-' + draftId);
                card.find('.badge-draft').replaceWith('<span class="badge-published"><i class="material-icons" style="font-size:12px; vertical-align:middle;">check_circle</i> Published</span>');
                card.find('button:contains("Terbitkan")').replaceWith('<button type="button" class="btn btn-warning btn-sm waves-effect" onclick="withdrawRecipe(' + recipeId + ', ' + draftId + ')" style="font-weight:600;"><i class="material-icons" style="font-size:14px; vertical-align:middle;">undo</i> Tarik (Withdraw)</button>');
            } else {
                let errHtml = res.error || "Validasi gagal.";
                if (res.validation_errors && res.validation_errors.length > 0) {
                    errHtml += "\n\nAlasan penolakan:\n- " + res.validation_errors.join("\n- ");
                }
                swal("Publikasi Ditolak", errHtml, "error");
            }
        }, 'json');
    });
}

// Withdraw Recipe
function withdrawRecipe(recipeId, draftId) {
    swal({
        title: "Tarik Resep dari Publikasi?",
        text: "Resep akan ditarik (withdrawn) dan tidak lagi tampil di aplikasi pengguna.",
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#f59e0b",
        confirmButtonText: "Ya, Tarik Resep",
        cancelButtonText: "Batal",
        closeOnConfirm: false
    }, function() {
        $.post('recipe-generator.php', {
            ajax_action: 'withdraw_recipe',
            csrf_token: CSRF_TOKEN,
            recipe_id: recipeId,
            draft_id: draftId
        }, function(res) {
            if (res.success) {
                swal("Resep Ditarik", res.message, "success");
                const card = $('#draft-card-' + draftId);
                card.find('.badge-published').replaceWith('<span class="badge-draft">Draft</span>');
                card.find('button:contains("Tarik")').replaceWith('<button type="button" class="btn bg-teal btn-sm waves-effect" onclick="publishRecipe(' + recipeId + ', ' + draftId + ')" style="font-weight:700;"><i class="material-icons" style="font-size:14px; vertical-align:middle;">publish</i> Terbitkan ke Android</button>');
            } else {
                swal("Gagal", res.error || "Gagal menarik resep.", "error");
            }
        }, 'json');
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}
</script>

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<?php include_once('includes/footer.php'); ?>
