<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Polyfills for PHP < 8.0 compatibility on shared hosting
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle !== '' && substr($haystack, -strlen($needle)) === (string)$needle;
    }
}

// Safe JSON encoder that handles invalid UTF-8 and never produces blank output
function safe_json_encode($data): string {
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($data, $flags);
    if ($json === false) {
        $json = json_encode([
            'success' => false,
            'error' => 'Gagal encode JSON: ' . json_last_error_msg()
        ]);
    }
    return $json;
}

// Catch fatal errors during AJAX calls and return JSON instead of 0-byte blank page
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (isset($_REQUEST['ajax_action'])) {
            while (ob_get_level()) { ob_end_clean(); }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Fatal Error Server (' . $error['message'] . ') di ' . basename($error['file']) . ' baris ' . $error['line']
            ]);
        }
    }
});

require_once('includes/config.php');
require_once('includes/constant.php');
require_once('includes/strings.php');
require_once('functions.php');

// Session check
$currentTime = time() + 25200;
if (!isset($_SESSION['user']) || (isset($_SESSION['timeout']) && $currentTime > $_SESSION['timeout'])) {
    if (isset($_REQUEST['ajax_action'])) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo safe_json_encode(['success' => false, 'error' => 'Sesi login telah berakhir. Silakan muat ulang halaman dan login kembali.']);
        exit;
    }
    session_destroy();
    header("location:index.php");
    exit();
}

if (defined('ENABLE_STUDIO_FEATURES') && !ENABLE_STUDIO_FEATURES) {
    if (isset($_REQUEST['ajax_action'])) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo safe_json_encode(['success' => false, 'error' => 'Fitur Impor Resep hanya dapat dijalankan melalui Local Studio Mac Mini.']);
        exit;
    }
    include_once('includes/header.php');
    ?>
    <section class="content">
        <div class="container-fluid">
            <div class="card corner-radius" style="margin-top:20px; padding:40px 20px; text-align:center;">
                <div style="margin-bottom:15px;"><i class="material-icons" style="font-size:64px; color:#6366f1;">cloud_off</i></div>
                <h3 style="color:#1e293b; margin-bottom:10px;">Fitur Local Studio Mac Mini</h3>
                <p style="color:#64748b; font-size:14px; max-width:600px; margin:0 auto 20px auto; line-height:1.6;">
                    Fitur <strong>Impor Resep</strong> dinonaktifkan di server shared web hosting publik.<br>
                    Silakan gunakan webadmin di <strong>Server Mac Mini (Local Studio)</strong> untuk mengimpor resep, lalu gunakan tombol <strong>1-Click Push</strong> untuk menerbitkan resep ke hosting ini.
                </p>
                <a href="recipes.php" class="btn bg-blue waves-effect" style="border-radius:6px; font-weight:600;">Kembali ke Manajemen Resep</a>
            </div>
        </div>
    </section>
    <?php
    include_once('includes/footer.php');
    exit;
}

require_once('importer/UrlValidator.php');
require_once('importer/parsers/ParserInterface.php');
require_once('importer/parsers/CookpadParser.php');
require_once('importer/parsers/BbcFoodParser.php');
require_once('importer/parsers/JsonLdRecipeParser.php');
require_once('importer/HtmlSanitizer.php');
require_once('importer/ImageDownloader.php');
require_once('importer/DatabaseMigration.php');
require_once('importer/RecipeImporterService.php');
require_once('ai/AiDatabaseMigration.php');
require_once('ai/GeminiClient.php');
require_once('ai/GlabsClient.php');
require_once('ai/AiImageService.php');
require_once('ai/SyncClient.php');

$importerService = new Importer\RecipeImporterService($connect);
$migration = new Importer\DatabaseMigration($connect);

// Ensure migration is run
if (!$migration->isMigrated()) {
    $migration->runMigration();
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Handle AJAX actions
if (isset($_REQUEST['ajax_action'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    // Verify CSRF
    $providedCsrf = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($csrfToken, $providedCsrf)) {
        echo safe_json_encode(['success' => false, 'error' => 'Token keamanan (CSRF) tidak valid. Muat ulang halaman.']);
        exit;
    }

    $action = $_REQUEST['ajax_action'];

    try {
        switch ($action) {
            case 'fetch_url':
                $url = trim($_POST['url'] ?? '');
                if (empty($url)) {
                    throw new Exception('URL tidak boleh kosong.');
                }
                $res = $importerService->importUrl($url);
                echo safe_json_encode($res);
                exit;

            case 'save_draft':
                $id = (int)($_POST['import_id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('ID draf tidak valid.');
                }
                $rawIng = $_POST['ingredients'] ?? [];
                $ingredients = is_string($rawIng) ? (json_decode($rawIng, true) ?: []) : (is_array($rawIng) ? $rawIng : []);
                $rawInst = $_POST['instructions'] ?? [];
                $instructions = is_string($rawInst) ? (json_decode($rawInst, true) ?: []) : (is_array($rawInst) ? $rawInst : []);

                $saveData = [
                    'recipe_title' => trim($_POST['recipe_title'] ?? ''),
                    'prep_time' => trim($_POST['prep_time'] ?? ''),
                    'cook_time' => trim($_POST['cook_time'] ?? ''),
                    'servings' => trim($_POST['servings'] ?? ''),
                    'summary' => trim($_POST['summary'] ?? ''),
                    'ingredients_json' => $ingredients,
                    'instructions_json' => $instructions,
                    'selected_image_url' => trim($_POST['selected_image_url'] ?? ''),
                    'cat_id' => (int)($_POST['cat_id'] ?? 0),
                    'product_code' => trim($_POST['product_code'] ?? ($_SESSION['active_product'] ?? 'airfry')),
                ];
                $importerService->saveDraft($id, $saveData);
                echo safe_json_encode(['success' => true, 'message' => 'Draf berhasil disimpan.']);
                exit;

            case 'reject_draft':
                $id = (int)($_POST['import_id'] ?? 0);
                $reason = trim($_POST['reason'] ?? 'Ditolak oleh admin');
                $importerService->rejectDraft($id, $reason);
                echo safe_json_encode(['success' => true, 'message' => 'Hasil resep berhasil ditolak.']);
                exit;

            case 'publish_recipe':
                $id = (int)($_POST['import_id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('ID draf tidak valid.');
                }

                $rawIng = $_POST['ingredients'] ?? [];
                $ingredients = is_string($rawIng) ? (json_decode($rawIng, true) ?: []) : (is_array($rawIng) ? $rawIng : []);
                $rawInst = $_POST['instructions'] ?? [];
                $instructions = is_string($rawInst) ? (json_decode($rawInst, true) ?: []) : (is_array($rawInst) ? $rawInst : []);

                $publishData = [
                    'recipe_title' => trim($_POST['recipe_title'] ?? ''),
                    'prep_time' => trim($_POST['prep_time'] ?? ''),
                    'cook_time' => trim($_POST['cook_time'] ?? ''),
                    'servings' => trim($_POST['servings'] ?? ''),
                    'summary' => trim($_POST['summary'] ?? ''),
                    'ingredients_json' => $ingredients,
                    'instructions_json' => $instructions,
                    'selected_image_url' => trim($_POST['selected_image_url'] ?? ''),
                    'cat_id' => (int)($_POST['cat_id'] ?? 0),
                    'product_code' => trim($_POST['product_code'] ?? ($_SESSION['active_product'] ?? 'airfry')),
                ];

                $filePost = (!empty($_FILES['custom_image']['name']) && $_FILES['custom_image']['error'] === UPLOAD_ERR_OK) ? $_FILES['custom_image'] : null;

                $recipeId = $importerService->publishRecipe($id, $publishData, 'upload', $filePost);
                echo safe_json_encode([
                    'success' => true,
                    'recipe_id' => $recipeId,
                    'message' => 'Resep berhasil dipublikasikan! Recipe ID #' . $recipeId . '.',
                ]);
                exit;

            case 'retry_import':
                $id = (int)($_POST['import_id'] ?? 0);
                $res = $importerService->retryImport($id);
                echo safe_json_encode($res);
                exit;

            case 'test_db':
                $res = $importerService->testDatabaseConnection();
                echo safe_json_encode($res);
                exit;

            case 'test_upload':
                $res = $importerService->testUploadFolder('upload');
                echo safe_json_encode($res);
                exit;

            case 'ai_enhance_draft':
                @set_time_limit(300);
                @ini_set('max_execution_time', '300');
                $aiService = new Ai\AiImageService($connect);

                $rawIng = $_POST['ingredients'] ?? [];
                $ingredients = is_string($rawIng) ? (json_decode($rawIng, true) ?: preg_split('/\r\n|\r|\n/', $rawIng)) : (is_array($rawIng) ? $rawIng : []);
                $rawInst = $_POST['instructions'] ?? [];
                $instructions = is_string($rawInst) ? (json_decode($rawInst, true) ?: preg_split('/\r\n|\r|\n/', $rawInst)) : (is_array($rawInst) ? $rawInst : []);

                $recipeData = [
                    'recipe_title' => trim($_POST['recipe_title'] ?? ''),
                    'prep_time' => trim($_POST['prep_time'] ?? ''),
                    'cook_time' => trim($_POST['cook_time'] ?? ''),
                    'servings' => trim($_POST['servings'] ?? ''),
                    'summary' => trim($_POST['summary'] ?? ''),
                    'ingredients' => $ingredients,
                    'instructions' => $instructions,
                ];

                $options = [
                    'style_preset' => trim($_POST['style_preset'] ?? 'Modern Fine Dining'),
                    'aspect_ratio' => trim($_POST['aspect_ratio'] ?? '4:3'),
                    'target_language' => trim($_POST['target_language'] ?? 'auto'),
                    'prompt_count' => max(1, min(4, (int)($_POST['prompt_count'] ?? 1))),
                ];

                // Bebaskan kunci session file agar worker PHP tidak memblokir request lain
                if (session_status() === PHP_SESSION_ACTIVE) {
                    @session_write_close();
                }

                $res = $aiService->enhanceRecipeData($recipeData, $options);
                echo safe_json_encode($res);
                exit;

            case 'submit_glabs_task':
                @set_time_limit(180);
                @ini_set('max_execution_time', '180');
                $aiService = new Ai\AiImageService($connect);
                $importId = (int)($_POST['import_id'] ?? 0);
                $prompt = trim($_POST['prompt'] ?? '');
                $style = trim($_POST['style_preset'] ?? 'Modern Fine Dining');
                $aspectRatio = trim($_POST['aspect_ratio'] ?? '4:3');
                $model = trim($_POST['model'] ?? '');

                if (empty($prompt)) {
                    throw new Exception('Prompt foto tidak boleh kosong.');
                }
                if (session_status() === PHP_SESSION_ACTIVE) {
                    @session_write_close();
                }
                $res = $aiService->submitImageTask($importId, $prompt, $style, $aspectRatio, $model);
                echo safe_json_encode($res);
                exit;

            case 'poll_glabs_task':
                $aiService = new Ai\AiImageService($connect);
                $taskId = trim($_POST['task_id'] ?? '');
                $genId = (int)($_POST['generation_id'] ?? 0);
                if (empty($taskId)) {
                    throw new Exception('Task ID tidak valid.');
                }
                $res = $aiService->pollAndDownload($taskId, $genId);
                echo safe_json_encode($res);
                exit;

            case 'apply_ai_image_draft':
                $importId = (int)($_POST['import_id'] ?? 0);
                $imageFilename = trim($_POST['image_filename'] ?? '');
                if ($importId <= 0 || empty($imageFilename)) {
                    throw new Exception('Data draf atau nama file gambar tidak valid.');
                }
                // Update tbl_recipe_imports selected_image_url
                $stmt = $connect->prepare("UPDATE tbl_recipe_imports SET selected_image_url = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('si', $imageFilename, $importId);
                    $stmt->execute();
                    $stmt->close();
                }
                echo safe_json_encode([
                    'success' => true,
                    'message' => 'Gambar AI berhasil dipasang ke draf resep.',
                    'selected_image_url' => $imageFilename
                ]);
                exit;

            case 'orchestrate_scrape_and_polish':
            case 'orchestrate_single_url':
                @set_time_limit(180);
                @ini_set('max_execution_time', '180');
                $url = trim($_POST['url'] ?? '');
                if (empty($url)) {
                    throw new Exception('URL sumber tidak boleh kosong.');
                }

                $defaultCatId = (int)($_POST['default_cat_id'] ?? 0);
                $stylePreset = trim($_POST['style_preset'] ?? 'Modern Fine Dining');
                $aspectRatio = trim($_POST['aspect_ratio'] ?? '4:3');
                $targetLanguage = trim($_POST['target_language'] ?? 'auto');
                $productCode = trim($_POST['product_code'] ?? ($_SESSION['active_product'] ?? 'airfry'));
                if (session_status() === PHP_SESSION_ACTIVE) {
                    @session_write_close();
                }

                // Step 1: Scrape & Parse Draf Awal
                $importRes = $importerService->importUrl($url);
                if (empty($importRes['import_id'])) {
                    throw new Exception('Gagal memproses URL: ' . ($importRes['message'] ?? 'Respons scraper kosong'));
                }
                $importId = (int)$importRes['import_id'];
                $draft = $importerService->getImportById($importId);
                if (!$draft) {
                    throw new Exception('Draf resep #' . $importId . ' tidak ditemukan.');
                }

                // Step 2: AI Polish Teks & Prompt Engineering (Gaya Foto & Rasio disertakan ke Gemini)
                $aiService = new Ai\AiImageService($connect);
                $rawIngredients = [];
                if (!empty($draft['ingredients_json'])) {
                    $rawIngredients = is_array($draft['ingredients_json']) ? $draft['ingredients_json'] : (json_decode($draft['ingredients_json'], true) ?: []);
                } elseif (!empty($draft['ingredients'])) {
                    $rawIngredients = is_array($draft['ingredients']) ? $draft['ingredients'] : (json_decode($draft['ingredients'], true) ?: []);
                }

                $rawInstructions = [];
                if (!empty($draft['instructions_json'])) {
                    $rawInstructions = is_array($draft['instructions_json']) ? $draft['instructions_json'] : (json_decode($draft['instructions_json'], true) ?: []);
                } elseif (!empty($draft['instructions'])) {
                    $rawInstructions = is_array($draft['instructions']) ? $draft['instructions'] : (json_decode($draft['instructions'], true) ?: []);
                }

                $recipeData = [
                    'recipe_title' => $draft['recipe_title'] ?? '',
                    'prep_time' => $draft['prep_time'] ?? '',
                    'cook_time' => $draft['cook_time'] ?? '',
                    'servings' => $draft['servings'] ?? '',
                    'summary' => $draft['summary'] ?? '',
                    'ingredients' => $rawIngredients,
                    'instructions' => $rawInstructions,
                ];

                $polishRes = $aiService->enhanceRecipeData($recipeData, [
                    'style_preset' => $stylePreset,
                    'aspect_ratio' => $aspectRatio,
                    'target_language' => $targetLanguage,
                    'prompt_count' => 1
                ]);

                $polishedTitle = $draft['recipe_title'];
                $polishedSummary = $draft['summary'];
                $polishedIngredients = $rawIngredients;
                $polishedInstructions = $rawInstructions;
                $imagePrompt = 'Commercial food photography of ' . $polishedTitle . ', ' . $stylePreset . ', ' . $aspectRatio . ' composition, 8k resolution, authentic culinary lighting';

                if (!empty($polishRes['success']) && !empty($polishRes['data'])) {
                    $pData = $polishRes['data'];
                    if (!empty($pData['titles']['primary'])) {
                        $polishedTitle = $pData['titles']['primary'];
                    }
                    if (!empty($pData['summary'])) {
                        $polishedSummary = $pData['summary'];
                    }
                    if (!empty($pData['ingredients']) && is_array($pData['ingredients'])) {
                        $polishedIngredients = $pData['ingredients'];
                    }
                    if (!empty($pData['instructions']) && is_array($pData['instructions'])) {
                        $polishedInstructions = $pData['instructions'];
                    }
                    if (!empty($pData['image_prompts'][0]['prompt'])) {
                        $imagePrompt = $pData['image_prompts'][0]['prompt'];
                    }
                }

                // Target category
                $finalCatId = ($defaultCatId > 0) ? $defaultCatId : ($draft['cat_id'] ?: 0);

                // Update draf dengan hasil polish
                $saveData = [
                    'recipe_title' => $polishedTitle,
                    'prep_time' => $draft['prep_time'] ?? '',
                    'cook_time' => $draft['cook_time'] ?? '',
                    'servings' => $draft['servings'] ?? '',
                    'summary' => $polishedSummary,
                    'ingredients_json' => $polishedIngredients,
                    'instructions_json' => $polishedInstructions,
                    'selected_image_url' => $draft['selected_image_url'] ?? '',
                    'cat_id' => $finalCatId,
                    'product_code' => $productCode,
                ];
                $importerService->saveDraft($importId, $saveData);

                // Ambil draf terbaru
                $updatedDraft = $importerService->getImportById($importId);

                // Dapatkan nama kategori
                $catName = 'Uncategorized';
                if (!empty($updatedDraft['cat_id'])) {
                    $cStmt = $connect->prepare("SELECT category_name FROM tbl_category WHERE cid = ? LIMIT 1");
                    if ($cStmt) {
                        $cStmt->bind_param('i', $updatedDraft['cat_id']);
                        $cStmt->execute();
                        $cRow = $cStmt->get_result()->fetch_assoc();
                        if ($cRow) {
                            $catName = $cRow['category_name'];
                        }
                        $cStmt->close();
                    }
                }

                $originalImageUrl = !empty($updatedDraft['selected_image_url']) ? 'upload/' . basename($updatedDraft['selected_image_url']) : '';

                echo safe_json_encode([
                    'success' => true,
                    'import_id' => $importId,
                    'recipe_title' => $updatedDraft['recipe_title'],
                    'summary' => $updatedDraft['summary'],
                    'cat_id' => (int)$updatedDraft['cat_id'],
                    'category_name' => $catName,
                    'prep_time' => $updatedDraft['prep_time'],
                    'cook_time' => $updatedDraft['cook_time'],
                    'servings' => $updatedDraft['servings'],
                    'ingredients' => !empty($updatedDraft['ingredients_json']) 
                        ? (is_array($updatedDraft['ingredients_json']) ? $updatedDraft['ingredients_json'] : (json_decode($updatedDraft['ingredients_json'], true) ?: [])) 
                        : $polishedIngredients,
                    'instructions' => !empty($updatedDraft['instructions_json']) 
                        ? (is_array($updatedDraft['instructions_json']) ? $updatedDraft['instructions_json'] : (json_decode($updatedDraft['instructions_json'], true) ?: [])) 
                        : $polishedInstructions,
                    'selected_image_url' => $updatedDraft['selected_image_url'],
                    'image_url' => $originalImageUrl,
                    'image_prompt' => $imagePrompt,
                    'style_preset' => $stylePreset,
                    'aspect_ratio' => $aspectRatio,
                    'status' => $updatedDraft['status'],
                    'source_url' => $url,
                ]);
                exit;

            case 'quick_add_category':
                $catName = trim($_POST['category_name'] ?? '');
                $prodCode = trim($_POST['product_code'] ?? ($_SESSION['active_product'] ?? 'airfry'));
                if (empty($catName)) {
                    throw new Exception('Nama kategori tidak boleh kosong.');
                }

                // Cek apakah sudah ada
                $stmt = $connect->prepare("SELECT cid, category_name FROM tbl_category WHERE LOWER(category_name) = LOWER(?) LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $catName);
                    $stmt->execute();
                    $exist = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($exist) {
                        echo safe_json_encode([
                            'success' => true,
                            'cid' => (int)$exist['cid'],
                            'category_name' => $exist['category_name'],
                            'message' => 'Kategori sudah ada, dipilih otomatis.'
                        ]);
                        exit;
                    }
                }

                // Cek kolom product_code di tbl_category
                $hasProdCol = false;
                $colCheck = $connect->query("SHOW COLUMNS FROM `tbl_category` LIKE 'product_code'");
                if ($colCheck && $colCheck->num_rows > 0) {
                    $hasProdCol = true;
                }

                if ($hasProdCol) {
                    $stmtIns = $connect->prepare("INSERT INTO tbl_category (category_name, category_image, product_code) VALUES (?, '', ?)");
                    $stmtIns->bind_param('ss', $catName, $prodCode);
                } else {
                    $stmtIns = $connect->prepare("INSERT INTO tbl_category (category_name, category_image) VALUES (?, '')");
                    $stmtIns->bind_param('s', $catName);
                }

                if (!$stmtIns || !$stmtIns->execute()) {
                    throw new Exception('Gagal menambahkan kategori: ' . $connect->error);
                }

                $newCid = $stmtIns->insert_id;
                $stmtIns->close();

                echo safe_json_encode([
                    'success' => true,
                    'cid' => $newCid,
                    'category_name' => $catName,
                    'message' => 'Kategori baru berhasil ditambahkan.'
                ]);
                exit;

            case 'update_draft_detail':
                $id = (int)($_POST['import_id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('ID draf tidak valid.');
                }
                $rawIng = $_POST['ingredients'] ?? [];
                $ingredients = is_string($rawIng) ? (json_decode($rawIng, true) ?: preg_split('/\r\n|\r|\n/', trim($rawIng))) : (is_array($rawIng) ? $rawIng : []);
                $ingredients = array_values(array_filter(array_map('trim', $ingredients)));

                $rawInst = $_POST['instructions'] ?? [];
                $instructions = is_string($rawInst) ? (json_decode($rawInst, true) ?: preg_split('/\r\n|\r|\n/', trim($rawInst))) : (is_array($rawInst) ? $rawInst : []);
                $instructions = array_values(array_filter(array_map('trim', $instructions)));

                $saveData = [
                    'recipe_title' => trim($_POST['recipe_title'] ?? ''),
                    'prep_time' => trim($_POST['prep_time'] ?? ''),
                    'cook_time' => trim($_POST['cook_time'] ?? ''),
                    'servings' => trim($_POST['servings'] ?? ''),
                    'summary' => trim($_POST['summary'] ?? ''),
                    'ingredients_json' => $ingredients,
                    'instructions_json' => $instructions,
                    'selected_image_url' => trim($_POST['selected_image_url'] ?? ''),
                    'cat_id' => (int)($_POST['cat_id'] ?? 0),
                    'product_code' => trim($_POST['product_code'] ?? ($_SESSION['active_product'] ?? 'airfry')),
                ];
                $importerService->saveDraft($id, $saveData);
                $updatedDraft = $importerService->getImportById($id);

                echo safe_json_encode([
                    'success' => true,
                    'message' => 'Detail draf berhasil diperbarui.',
                    'draft' => $updatedDraft
                ]);
                exit;

            case 'bulk_publish_drafts':
                $draftIds = $_POST['draft_ids'] ?? [];
                if (is_string($draftIds)) {
                    $draftIds = json_decode($draftIds, true) ?: [];
                }
                if (!is_array($draftIds) || empty($draftIds)) {
                    throw new Exception('Tidak ada resep yang dipilih untuk diterbitkan.');
                }

                $autoPush = !empty($_POST['auto_push']) && ($_POST['auto_push'] === 'true' || $_POST['auto_push'] === '1' || $_POST['auto_push'] === 1);
                $syncClient = null;
                if ($autoPush) {
                    $syncClient = new Ai\SyncClient($connect);
                }

                $publishedList = [];
                $errorList = [];

                foreach ($draftIds as $rawId) {
                    $importId = (int)$rawId;
                    if ($importId <= 0) continue;

                    try {
                        $record = $importerService->getImportById($importId);
                        if (!$record) {
                            $errorList[] = "Draf #{$importId} tidak ditemukan.";
                            continue;
                        }
                        if ($record['status'] === 'published') {
                            $publishedList[] = [
                                'import_id' => $importId,
                                'recipe_id' => $record['recipe_id'],
                                'title' => $record['recipe_title'],
                                'note' => 'Sudah dipublish sebelumnya'
                            ];
                            continue;
                        }

                        $catId = (int)($record['cat_id'] ?? 0);
                        if ($catId <= 0) {
                            $errorList[] = "Draf #{$importId} ('{$record['recipe_title']}'): Kategori belum dipilih.";
                            continue;
                        }

                        $publishData = [
                            'recipe_title' => $record['recipe_title'],
                            'prep_time' => $record['prep_time'],
                            'cook_time' => $record['cook_time'],
                            'servings' => $record['servings'],
                            'summary' => $record['summary'],
                            'ingredients_json' => $record['ingredients_json'] ?? ($record['ingredients'] ?? '[]'),
                            'instructions_json' => $record['instructions_json'] ?? ($record['instructions'] ?? '[]'),
                            'selected_image_url' => $record['selected_image_url'],
                            'cat_id' => $catId,
                            'product_code' => $record['product_code'] ?? ($_SESSION['active_product'] ?? 'airfry')
                        ];

                        $recipeId = $importerService->publishRecipe($importId, $publishData, 'upload');
                        
                        $syncStatus = null;
                        if ($autoPush && $syncClient) {
                            $targetPCode = $publishData['product_code'] ?? ($_SESSION['active_product'] ?? null);
                            $syncRes = $syncClient->pushRecipeToLive($recipeId, $targetPCode);
                            $syncStatus = !empty($syncRes['success']) ? 'Synced' : ('Sync failed: ' . ($syncRes['error'] ?? 'Unknown'));
                        }

                        $publishedList[] = [
                            'import_id' => $importId,
                            'recipe_id' => $recipeId,
                            'title' => $record['recipe_title'],
                            'sync_status' => $syncStatus
                        ];
                    } catch (Exception $pe) {
                        $errorList[] = "Draf #{$importId}: " . $pe->getMessage();
                    }
                }

                echo safe_json_encode([
                    'success' => count($publishedList) > 0,
                    'published_count' => count($publishedList),
                    'total_requested' => count($draftIds),
                    'published_list' => $publishedList,
                    'errors' => $errorList,
                    'message' => count($publishedList) . ' resep berhasil diterbitkan ke database!' . ($autoPush ? ' (Sinkronisasi live telah dijalankan).' : '')
                ]);
                exit;

            case 'run_migration':
                $res = $migration->runMigration();
                echo safe_json_encode($res);
                exit;

            default:
                echo safe_json_encode(['success' => false, 'error' => 'Aksi tidak dikenal.']);
                exit;
        }
    } catch (Exception $e) {
        echo safe_json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

$tab = $_GET['tab'] ?? 'import';
$importId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$currentDraft = null;

if ($importId > 0) {
    $currentDraft = $importerService->getImportById($importId);
}

// Counts for badges
$queueCount = $importerService->countImports('draft');
$historyCount = $importerService->countImports();
$categories = $importerService->getCategories();
$syncDestClient = new Ai\SyncClient($connect);
$syncDestinations = $syncDestClient->getActiveDestinations();

include_once('includes/header.php');
?>

<style>
    /* Styling for Recipe Importer module */
    .importer-nav {
        display: flex;
        gap: 8px;
        margin-bottom: 20px;
        flex-wrap: wrap;
        border-bottom: 2px solid #e0e0e0;
        padding-bottom: 10px;
    }
    .importer-nav .nav-link-btn {
        padding: 8px 16px;
        border-radius: 20px;
        font-weight: 600;
        text-decoration: none;
        color: #555;
        background: #f5f5f5;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .importer-nav .nav-link-btn:hover {
        background: #e9ecef;
        color: #1e88e5;
    }
    .importer-nav .nav-link-btn.active {
        background: #1e88e5;
        color: #fff;
    }
    .importer-nav .nav-link-btn .badge {
        background: rgba(0,0,0,0.15);
        color: inherit;
        border-radius: 10px;
        padding: 2px 8px;
        font-size: 11px;
    }
    .importer-nav .nav-link-btn.active .badge {
        background: rgba(255,255,255,0.3);
    }

    .step-circle {
        display: inline-flex;
        width: 26px;
        height: 26px;
        background: #1e88e5;
        color: #fff;
        border-radius: 50%;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 13px;
        margin-right: 8px;
    }
    .card-step-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-bottom: 10px;
        border-bottom: 1px solid #f0f0f0;
        margin-bottom: 16px;
    }
    .card-step-header h4 {
        margin: 0;
        display: inline-flex;
        align-items: center;
        font-size: 16px;
        font-weight: 600;
    }

    /* Live Preview Sheet */
    .preview-phone-frame {
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        border: 1px solid #e2e8f0;
        overflow: hidden;
    }
    .preview-header-bar {
        background: #f8fafc;
        padding: 10px 14px;
        border-bottom: 1px solid #edf2f7;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.5px;
        color: #64748b;
        text-transform: uppercase;
        display: flex;
        justify-content: space-between;
    }
    .preview-image-wrap {
        width: 100%;
        height: 220px;
        background: #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        overflow: hidden;
    }
    .preview-image-wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .preview-image-wrap .no-image-text {
        color: #94a3b8;
        font-size: 13px;
        text-align: center;
        padding: 20px;
    }
    .preview-content {
        padding: 18px;
    }
    .preview-content h3 {
        font-size: 18px;
        font-weight: 700;
        margin-top: 0;
        margin-bottom: 8px;
        color: #1e293b;
        line-height: 1.3;
    }
    .preview-meta-chips {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }
    .preview-chip {
        background: #f1f5f9;
        color: #475569;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
    }
    .preview-summary {
        font-size: 13px;
        color: #475569;
        line-height: 1.5;
        margin-bottom: 14px;
        font-style: italic;
    }
    .preview-section-title {
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
        margin-top: 14px;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        border-bottom: 1px dashed #e2e8f0;
        padding-bottom: 4px;
    }
    .preview-list {
        margin: 0;
        padding-left: 20px;
        font-size: 12.5px;
        color: #334155;
        line-height: 1.55;
    }
    .preview-source-link {
        display: inline-block;
        margin-top: 16px;
        font-size: 12px;
        color: #1e88e5;
        text-decoration: underline;
    }

    /* Candidate Image Picker */
    .image-candidate-thumb {
        width: 80px;
        height: 60px;
        border-radius: 6px;
        object-fit: cover;
        cursor: pointer;
        border: 2px solid transparent;
        transition: transform 0.15s ease, border-color 0.15s ease;
    }
    .image-candidate-thumb:hover {
        transform: scale(1.05);
    }
    .image-candidate-thumb.selected {
        border-color: #1e88e5;
        box-shadow: 0 0 0 2px rgba(30,136,229,0.3);
    }

    .badge-status {
        font-size: 11px;
        padding: 4px 8px;
        border-radius: 4px;
        font-weight: 600;
        text-transform: uppercase;
    }
    .badge-status.draft { background: #fff3cd; color: #856404; }
    .badge-status.published { background: #d4edda; color: #155724; }
    .badge-status.rejected { background: #f8d7da; color: #721c24; }
    .badge-status.failed { background: #f8d7da; color: #721c24; }

    /* ==========================================================================
       BATCH ORCHESTRATOR STYLES (Nordic Warm Theme)
       ========================================================================== */
    .orchestrator-input-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 20px;
        margin-bottom: 24px;
    }
    @media (max-width: 991px) {
        .orchestrator-input-grid {
            grid-template-columns: 1fr;
        }
    }
    .panel-nordic-card {
        background: #FFFFFF;
        border: 1px solid #E7E5E4;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 16px -2px rgba(41, 37, 36, 0.05);
    }
    .panel-nordic-card .panel-heading {
        font-size: 14px;
        font-weight: 700;
        color: #1C1917;
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
        border-bottom: 1px solid #F5F1EB;
        padding-bottom: 10px;
    }
    .url-textarea {
        width: 100%;
        height: 195px;
        background: #FAF8F5;
        border: 1px solid #D6D3D1;
        border-radius: 8px;
        padding: 12px 14px;
        font-family: 'SF Mono', Menlo, Consolas, monospace;
        font-size: 12px;
        color: #1C1917;
        resize: vertical;
        line-height: 1.6;
        transition: all 0.2s;
    }
    .url-textarea:focus {
        outline: none;
        border-color: #C2410C;
        background: #FFFFFF;
        box-shadow: 0 0 0 3px rgba(194, 65, 12, 0.15);
    }
    .textarea-meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 8px;
        font-size: 11.5px;
        color: #78716C;
    }
    .notice-box {
        background: #FFF1EB;
        border: 1px solid #FED7AA;
        border-radius: 8px;
        padding: 12px;
        margin-top: 14px;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        font-size: 11.5px;
        color: #9A3412;
        line-height: 1.45;
    }
    .btn-start-orchestrator {
        background: linear-gradient(135deg, #C2410C 0%, #9A3412 100%);
        color: white;
        border: none;
        padding: 12px 20px;
        border-radius: 8px;
        font-size: 13.5px;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        margin-top: 16px;
        box-shadow: 0 4px 14px rgba(194, 65, 12, 0.25);
        transition: all 0.2s ease;
    }
    .btn-start-orchestrator:hover {
        background: linear-gradient(135deg, #9A3412 0%, #7C2D12 100%);
        box-shadow: 0 6px 18px rgba(194, 65, 12, 0.35);
        color: #fff;
    }
    .btn-start-orchestrator:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    /* Stepper & Progress */
    .progress-bar-track {
        height: 10px;
        background: #E7E5E4;
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 14px;
    }
    .progress-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #C2410C 0%, #EA580C 100%);
        width: 0%;
        transition: width 0.3s ease;
    }
    .step-tracker-flex {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 14px;
        flex-wrap: wrap;
    }
    .step-item-pill {
        flex: 1;
        min-width: 110px;
        background: #F5F1EB;
        padding: 6px 10px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
        color: #78716C;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
    }
    .step-item-pill.active {
        background: #FFF1EB;
        color: #C2410C;
        border: 1px solid #FDBA74;
    }
    .step-item-pill.done {
        background: #DCFCE7;
        color: #15803D;
        border: 1px solid #86EFAC;
    }
    .terminal-box {
        background: #1C1917;
        color: #E7E5E4;
        border-radius: 8px;
        padding: 12px 14px;
        font-family: 'SF Mono', Menlo, Consolas, monospace;
        font-size: 11px;
        height: 110px;
        overflow-y: auto;
        line-height: 1.5;
        border: 1px solid #292524;
    }
    .terminal-line { margin-bottom: 2px; }
    .terminal-line.success { color: #4ADE80; }
    .terminal-line.info { color: #38BDF8; }
    .terminal-line.warn { color: #FBBF24; }
    .terminal-line.error { color: #F87171; }

    /* Review Table */
    .table-batch-review {
        width: 100%;
        border-collapse: collapse;
    }
    .table-batch-review th {
        background: #FAF8F5;
        color: #44403C;
        font-size: 11.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 10px 12px;
        border-bottom: 2px solid #E7E5E4;
    }
    .table-batch-review td {
        padding: 12px;
        vertical-align: middle;
        border-bottom: 1px solid #F5F1EB;
        font-size: 12.5px;
    }
    .table-batch-review tr:hover {
        background: #FAF8F5;
    }
    .batch-thumb-img {
        width: 68px;
        height: 52px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid #E7E5E4;
        cursor: pointer;
        transition: transform 0.2s;
    }
    .batch-thumb-img:hover {
        transform: scale(1.08);
    }
    .inline-category-select {
        padding: 5px 8px;
        border-radius: 6px;
        border: 1px solid #D6D3D1;
        background: #FFFFFF;
        font-size: 11.5px;
        font-weight: 600;
        color: #1C1917;
        width: 100%;
        max-width: 170px;
        transition: border-color 0.2s, background-color 0.2s;
    }
    .inline-category-select:focus {
        outline: none;
        border-color: #C2410C;
    }

    /* Floating Action Bar */
    .floating-batch-bar {
        position: fixed;
        bottom: 20px;
        left: 310px;
        right: 30px;
        background: rgba(28, 25, 23, 0.95);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid #44403C;
        border-radius: 12px;
        padding: 14px 22px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        z-index: 1040;
        color: #FFFFFF;
        transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @media (max-width: 768px) {
        .floating-batch-bar {
            left: 15px;
            right: 15px;
            bottom: 15px;
            flex-direction: column;
            gap: 10px;
            align-items: stretch;
        }
    }
    .floating-batch-bar .info-side {
        display: flex;
        align-items: center;
        gap: 14px;
        font-size: 13px;
        font-weight: 600;
    }
    .floating-batch-bar .action-side {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .btn-publish-all {
        background: linear-gradient(135deg, #15803D 0%, #166534 100%);
        color: white;
        border: none;
        padding: 9px 20px;
        border-radius: 8px;
        font-size: 13.5px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        box-shadow: 0 4px 12px rgba(21, 128, 61, 0.3);
        transition: all 0.2s;
    }
    .btn-publish-all:hover {
        background: linear-gradient(135deg, #166534 0%, #14532D 100%);
        color: #fff;
    }
    .btn-publish-all:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
</style>

<section class="content">
    <ol class="breadcrumb">
        <li><a href="dashboard.php">Dashboard</a></li>
        <li><a href="recipes.php">Manage Recipes</a></li>
        <li class="active">Impor Resep</li>
    </ol>

    <div class="container-fluid">

        <!-- Importer Sub Navigation -->
        <div class="importer-nav">
            <a href="importer.php?tab=import" class="nav-link-btn <?php echo ($tab === 'import') ? 'active' : ''; ?>">
                <i class="material-icons" style="font-size:18px;">cloud_download</i>
                <span>Impor Manual</span>
            </a>
            <a href="importer.php?tab=batch" class="nav-link-btn <?php echo ($tab === 'batch') ? 'active' : ''; ?>" style="<?php echo ($tab === 'batch') ? 'background:#C2410C; color:#fff;' : ''; ?>">
                <i class="material-icons" style="font-size:18px;">auto_mode</i>
                <span>Batch Orchestrator</span>
                <span class="badge" style="background:rgba(255,255,255,0.25); color:#fff; font-weight:700;">AUTO</span>
            </a>
            <a href="importer.php?tab=queue" class="nav-link-btn <?php echo ($tab === 'queue') ? 'active' : ''; ?>">
                <i class="material-icons" style="font-size:18px;">rate_review</i>
                <span>Antrean Tinjauan</span>
                <?php if ($queueCount > 0): ?>
                    <span class="badge"><?php echo $queueCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="importer.php?tab=history" class="nav-link-btn <?php echo ($tab === 'history') ? 'active' : ''; ?>">
                <i class="material-icons" style="font-size:18px;">history</i>
                <span>Riwayat Impor</span>
                <span class="badge"><?php echo $historyCount; ?></span>
            </a>
            <a href="importer.php?tab=settings" class="nav-link-btn <?php echo ($tab === 'settings') ? 'active' : ''; ?>">
                <i class="material-icons" style="font-size:18px;">tune</i>
                <span>Pengaturan &amp; Status</span>
            </a>
        </div>

        <?php if ($tab === 'import'): ?>
            <!-- TAB: IMPOR RESEP (MANUAL) -->
            <div class="row clearfix">

                <!-- Step 1: Input URL -->
                <div class="col-xs-12">
                    <div class="card corner-radius">
                        <div class="body">
                            <div class="card-step-header">
                                <h4><span class="step-circle">1</span> Masukkan URL Resep</h4>
                                <span class="label" style="background:#C2410C; color:#fff; font-weight:700;">COOKPAD &amp; BBC FOOD SUPPORTED</span>
                            </div>
                            <p class="text-muted" style="margin-bottom: 12px;">
                                Masukkan alamat URL resep publik dari <strong>Cookpad</strong> (cookpad.com) atau <strong>BBC Food</strong>. Sistem akan memvalidasi, mengekstrak data resep, dan menyiapkannya untuk tinjauan.
                            </p>

                            <form id="fetchForm" onsubmit="handleFetchUrl(event);">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <div class="row clearfix" style="margin-bottom: 10px;">
                                    <div class="col-md-5 col-sm-6 col-xs-12">
                                        <label class="form-label" style="font-size:11.5px; font-weight:700; color:#475569; margin-bottom:4px;">Target Aplikasi / Katalog:</label>
                                        <select id="targetProductSelect" name="product_code" class="form-control" style="height:38px; border-radius:8px; font-weight:700; border-color:#CBD5E1;">
                                            <option value="airfry" <?= ($activeProduct === 'airfry') ? 'selected' : '' ?>>🍳 AirFryer (English)</option>
                                            <option value="nusasaji" <?= ($activeProduct === 'nusasaji') ? 'selected' : '' ?>>🇮🇩 Nusa Saji (Indonesia)</option>
                                            <option value="proteinprep" <?= ($activeProduct === 'proteinprep') ? 'selected' : '' ?>>🥩 ProteinPrep (MealPrep)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="row clearfix" style="margin-bottom: 0;">
                                    <div class="col-md-9 col-sm-8 col-xs-12">
                                        <div class="form-group form-float" style="margin-bottom: 8px;">
                                            <div class="form-line">
                                                <input type="url" id="sourceUrl" name="url" class="form-control"
                                                       placeholder="https://cookpad.com/id/resep/... atau https://www.bbc.co.uk/food/recipes/..."
                                                       value="<?php echo htmlspecialchars($currentDraft['source_url'] ?? 'https://cookpad.com/id/resep/26787228'); ?>" required>
                                            </div>
                                            <small class="help-block">
                                                Contoh teruji: 
                                                <a href="javascript:void(0);" onclick="document.getElementById('sourceUrl').value='https://cookpad.com/id/resep/26787228';" style="font-weight:700; color:#C2410C; text-decoration:underline;">Cookpad (Balado Terong)</a> | 
                                                <a href="javascript:void(0);" onclick="document.getElementById('sourceUrl').value='https://www.bbc.co.uk/food/recipes/loaded_jacket_potatoes_15248';" style="font-weight:600; color:#475569; text-decoration:underline;">BBC Food (Jacket Potatoes)</a>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-sm-4 col-xs-12">
                                        <button type="submit" id="btnFetch" class="btn btn-primary btn-block waves-effect" style="height: 40px; font-weight:600;">
                                            <i class="material-icons" style="font-size:18px; vertical-align:middle;">arrow_forward</i>
                                            <span>AMBIL DATA</span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <div id="fetchAlert" style="display:none; margin-top:12px;"></div>
                        </div>
                    </div>
                </div>

                <!-- Editor & Live Preview Container -->
                <div id="editorWorkspace" class="col-xs-12" style="<?php echo $currentDraft ? '' : 'display:none;'; ?>">
                    <div class="row clearfix">

                        <!-- Step 2: Editor Card -->
                        <div class="col-md-7 col-xs-12">
                            <div class="card corner-radius">
                                <div class="body">
                                    <div class="card-step-header">
                                        <h4><span class="step-circle">2</span> Periksa &amp; Susun</h4>
                                        <span id="draftStatusBadge" class="badge-status draft">DRAF</span>
                                    </div>

                                    <!-- AI Magic Polish & Photo Prompts Component (1-Single-Call) -->
                                    <div class="ai-polish-banner" style="background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%); border: 1px solid #c7d2fe; border-radius: 12px; padding: 16px 18px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(99,102,241,0.08);">
                                        <!-- Header & Pill Toggle -->
                                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px;">
                                            <div style="flex:1;">
                                                <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                                                    <span style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:8px; background:linear-gradient(135deg, #6366f1, #8b5cf6); color:#fff; font-size:16px; box-shadow:0 2px 6px rgba(99,102,241,0.3);">
                                                        <i class="material-icons" style="font-size:18px;">auto_awesome</i>
                                                    </span>
                                                    <strong style="color:#1e1b4b; font-size:14px; font-weight:700;">AI Magic Polish (1-Call Title, Paraphrase &amp; Photo)</strong>
                                                    <span class="badge" style="background:#4338ca; font-size:10px; padding:3px 7px; border-radius:4px;">Gemini Unified</span>
                                                </div>
                                                <p style="margin:0 0 0 36px; font-size:12px; color:#475569; line-height:1.5;">
                                                    Sempurnakan judul menjadi <em>click-worthy</em> unik, parafrase instruksi &amp; summary (nutrisi &amp; takaran bahan tetap 100% terjaga), serta buatkan prompt foto T2I fotorealistik.
                                                </p>
                                            </div>
                                            <div style="flex-shrink:0;">
                                                <button type="button" id="btnToggleAiPanel" onclick="toggleAiPolishPanel();" style="border-radius:20px; font-weight:600; font-size:12px; padding:6px 14px; background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe; display:inline-flex; align-items:center; gap:5px; cursor:pointer; transition:all 0.2s ease; outline:none;">
                                                    <i class="material-icons" style="font-size:16px;">tune</i> <span id="toggleAiText">Buka Studio AI</span>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Collapsible Polish Controls & Results -->
                                        <div id="aiPolishContainer" style="display:none; margin-top:14px; padding-top:14px; border-top:1px dashed #c7d2fe;">
                                            <!-- Row 1: 3 Spacious Dropdown Inputs -->
                                            <div class="row clearfix" style="margin-bottom:12px;">
                                                <div class="col-sm-4 col-xs-12">
                                                    <label style="font-size:11px; text-transform:uppercase; color:#475569; font-weight:700; margin-bottom:5px; display:block;">
                                                        <i class="material-icons" style="font-size:13px; vertical-align:middle; color:#6366f1;">translate</i> Bahasa Hasil Polish
                                                    </label>
                                                    <select id="aiLanguage" class="form-control" style="border-radius:8px; border:1px solid #cbd5e1; height:38px; font-size:12.5px; font-weight:600; background:#fff; box-shadow:none;">
                                                        <option value="auto" selected>Otomatis (Ikuti Resep Asli)</option>
                                                        <option value="id">Bahasa Indonesia</option>
                                                        <option value="en">English (Inggris)</option>
                                                    </select>
                                                </div>
                                                <div class="col-sm-5 col-xs-12">
                                                    <label style="font-size:11px; text-transform:uppercase; color:#475569; font-weight:700; margin-bottom:5px; display:block;">
                                                        <i class="material-icons" style="font-size:13px; vertical-align:middle; color:#6366f1;">camera_alt</i> Preset Gaya Foto
                                                    </label>
                                                    <select id="aiStylePreset" class="form-control" style="border-radius:8px; border:1px solid #cbd5e1; height:38px; font-size:12.5px; font-weight:500; background:#fff; box-shadow:none;">
                                                        <option value="Modern Fine Dining" selected>Modern Fine Dining (Default)</option>
                                                        <option value="Rustic Kitchen Table">Rustic Kitchen Table</option>
                                                        <option value="Street Food Close-up">Street Food Close-up</option>
                                                        <option value="Editorial Culinary Magazine">Editorial Culinary Magazine</option>
                                                        <option value="Air Fryer Crisp Focus">Air Fryer Crisp Focus</option>
                                                    </select>
                                                </div>
                                                <div class="col-sm-3 col-xs-12">
                                                    <label style="font-size:11px; text-transform:uppercase; color:#475569; font-weight:700; margin-bottom:5px; display:block;">
                                                        <i class="material-icons" style="font-size:13px; vertical-align:middle; color:#6366f1;">aspect_ratio</i> Aspek Rasio
                                                    </label>
                                                    <select id="aiAspectRatio" class="form-control" style="border-radius:8px; border:1px solid #cbd5e1; height:38px; font-size:12.5px; font-weight:600; background:#fff; box-shadow:none;">
                                                        <option value="4:3" selected>4:3 (Default Standar)</option>
                                                        <option value="1:1">1:1 (Square)</option>
                                                        <option value="16:9">16:9 (Landscape)</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <!-- Row 2: Full-Width Prominent Polish Button -->
                                            <div style="margin-top:2px;">
                                                <button type="button" id="btnRunAiPolish" onclick="runAiPolish();" style="width:100%; border-radius:8px; font-weight:700; font-size:13px; height:42px; background:linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color:#fff; border:none; box-shadow:0 3px 8px rgba(79,70,229,0.3); cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; transition:all 0.2s ease;">
                                                    <i class="material-icons" style="font-size:18px;">auto_awesome</i>
                                                    <span>MULAI POLISH RESEP (1-SINGLE-CALL)</span>
                                                </button>
                                                <div style="text-align:center; margin-top:6px; font-size:11px; color:#64748b;">
                                                    *Menghasilkan 3 alternatif judul click-worthy, parafrase ringkasan &amp; instruksi, serta prompt foto T2I fotorealistik dalam sekali jalan.
                                                </div>
                                            </div>

                                            <!-- Loading Progress Indicator -->
                                            <div id="aiPolishLoading" style="display:none; text-align:center; padding:15px; background:#fff; border-radius:8px; border:1px solid #e2e8f0; margin-top:10px;">
                                                <div class="preloader pl-size-xs" style="margin-bottom:8px;">
                                                    <div class="spinner-layer pl-indigo"><div class="circle-clipper left"><div class="circle"></div></div><div class="circle-clipper right"><div class="circle"></div></div></div>
                                                </div>
                                                <div style="font-weight:600; color:#334155; font-size:13px;" id="aiPolishStatusText">Gemini sedang meracik judul unik, memparafrase resep &amp; merancang prompt foto...</div>
                                                <small style="color:#64748b;">1-Single-Call: Menghemat kuota API Gemini dengan waterfall multi-kunci</small>
                                            </div>

                                            <!-- Results Box -->
                                            <div id="aiPolishResults" style="display:none; margin-top:12px; background:#fff; border-radius:8px; border:1px solid #c7d2fe; padding:14px;">
                                                <!-- Title Alternatives -->
                                                <div style="margin-bottom:14px;">
                                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                                        <label style="font-size:12px; font-weight:700; color:#1e1b4b; margin:0;"><i class="material-icons" style="font-size:15px; vertical-align:middle; color:#6366f1;">stars</i> Rekomendasi Judul Click-Worthy:</label>
                                                        <small style="color:#64748b;">(Klik tombol judul di bawah untuk langsung mengganti judul)</small>
                                                    </div>
                                                    <div id="aiTitleList" style="display:flex; flex-direction:column; gap:6px;">
                                                        <!-- Dynamic Title Buttons -->
                                                    </div>
                                                </div>

                                                <!-- Paraphrase Overview -->
                                                <div style="margin-bottom:14px; padding:12px; background:#f8fafc; border-radius:6px; border:1px solid #e2e8f0;">
                                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                                        <span style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase;">Hasil Parafrase Summary (Termasuk Nutrisi) &amp; Instruksi</span>
                                                        <button type="button" id="btnApplyAiText" class="btn btn-info btn-xs waves-effect" onclick="applyAiTextToForm();" style="border-radius:4px; font-weight:600;">
                                                            <i class="material-icons" style="font-size:13px; vertical-align:middle;">assignment_turned_in</i> Terapkan Teks Hasil AI ke Form Resep
                                                        </button>
                                                    </div>
                                                    <div id="aiSummaryPreview" style="font-size:12px; color:#1e293b; line-height:1.5; margin-bottom:8px; white-space:pre-line; background:#fff; padding:8px 10px; border-radius:4px; border:1px solid #cbd5e1;"></div>
                                                    <div id="aiTipsBadge" style="display:none; font-size:11px; background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:6px 10px; border-radius:4px; margin-bottom:8px;"></div>
                                                    <div id="aiInstructionsCount" style="font-size:11px; color:#64748b;"></div>
                                                </div>

                                                <!-- AI Image Prompts -->
                                                <div>
                                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                                        <label style="font-size:12px; font-weight:700; color:#1e1b4b; margin:0;"><i class="material-icons" style="font-size:15px; vertical-align:middle; color:#ec4899;">camera_alt</i> Prompt Foto AI T2I (4:3 Modern Fine Dining):</label>
                                                        <div style="display:flex; gap:6px;">
                                                            <button type="button" class="btn btn-default btn-xs waves-effect" onclick="copyAiPrompt();" title="Salin Prompt ke Clipboard">
                                                                <i class="material-icons" style="font-size:13px; vertical-align:middle;">content_copy</i> Salin
                                                            </button>
                                                            <a href="ai-generator.php" target="_blank" class="btn btn-primary btn-xs waves-effect" style="border-radius:4px;">
                                                                <i class="material-icons" style="font-size:13px; vertical-align:middle;">open_in_new</i> Buka AI Studio
                                                            </a>
                                                        </div>
                                                    </div>
                                                    <textarea id="aiPromptPreview" rows="3" class="form-control" style="font-size:11px; font-family:monospace; background:#fff; border:1px solid #cbd5e1; border-radius:4px; line-height:1.4;" readonly></textarea>

                                                    <!-- Direct G-Labs Image Generation Action Container -->
                                                    <div style="margin-top:10px; padding:12px 14px; background:#fff5f7; border:1px solid #fbcfe8; border-radius:6px;">
                                                        <!-- Trigger Bar -->
                                                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                                                            <div>
                                                                <span style="font-size:12px; font-weight:700; color:#9d174d;">
                                                                    <i class="material-icons" style="font-size:16px; vertical-align:middle; color:#db2777;">camera_enhance</i> Generasi Foto Menggunakan AI
                                                                </span>
                                                                <div style="font-size:11px; color:#9f1239; margin-top:2px;">
                                                                    Model: <strong>Nano Banana</strong>
                                                                </div>
                                                            </div>
                                                            <button type="button" id="btnGenerateGlabsDirect" class="btn bg-pink waves-effect" onclick="runDirectGlabsGeneration();" style="border-radius:4px; font-weight:700; padding:7px 14px; box-shadow:0 2px 6px rgba(233,30,99,0.35);">
                                                                <i class="material-icons" style="font-size:15px; vertical-align:middle;">bolt</i> GENERATE GAMBAR SEKARANG
                                                            </button>
                                                        </div>

                                                        <!-- 1. Progress Bar Polling Status (Muncul saat proses render) -->
                                                        <div id="glabsDirectProgressBox" style="display:none; margin-top:12px; padding:12px; background:#fff; border:1px solid #f472b6; border-radius:6px;">
                                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                                                <strong id="glabsDirectStatusTitle" style="font-size:12px; color:#1e293b;">Menyiapkan proses render foto...</strong>
                                                                <span id="glabsDirectTaskId" class="badge bg-grey" style="font-family:monospace; font-size:10px;">Task #pending</span>
                                                            </div>
                                                            <div class="progress" style="height:10px; margin-bottom:6px; border-radius:4px; background:#f1f5f9;">
                                                                <div id="glabsDirectProgressBar" class="progress-bar progress-bar-striped active bg-pink" style="width: 20%; transition: width 0.4s ease;"></div>
                                                            </div>
                                                            <div style="display:flex; justify-content:space-between; font-size:11px; color:#64748b;">
                                                                <span id="glabsDirectStatusDesc">Menghubungkan ke sistem AI...</span>
                                                                <span><i class="material-icons" style="font-size:12px; vertical-align:middle;">timer</i> <span id="glabsDirectTimer">0s</span></span>
                                                            </div>
                                                        </div>

                                                        <!-- 2. Showcase Hasil Foto & Aksi Terapkan ke Resep -->
                                                        <div id="glabsDirectResultBox" style="display:none; margin-top:12px; padding:14px; background:#fff; border:2px solid #10b981; border-radius:6px; box-shadow:0 3px 10px rgba(16,185,129,0.12);">
                                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">
                                                                <span style="font-size:12px; font-weight:700; color:#065f46;">
                                                                    <i class="material-icons" style="font-size:16px; vertical-align:middle; color:#10b981;">check_circle</i> FOTO BERHASIL DI-RENDER &amp; DIOPTIMASI (1080px)
                                                                </span>
                                                                <span id="glabsDirectMetaBadge" class="label label-success">1080x810 • Optimized</span>
                                                            </div>
                                                            <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
                                                                <!-- Thumbnail Foto (Tanpa Label AI GPU) -->
                                                                <div style="position:relative; width:130px; height:98px; border-radius:6px; overflow:hidden; border:1px solid #cbd5e1; box-shadow:0 2px 4px rgba(0,0,0,0.1); background:#f1f5f9;">
                                                                    <img id="glabsDirectResultImg" src="" style="width:100%; height:100%; object-fit:cover;" alt="AI Generated Recipe">
                                                                </div>
                                                                <!-- Detail & Tombol Aksi -->
                                                                <div style="flex:1; min-width:240px;">
                                                                    <div id="glabsDirectFilenameText" style="font-family:monospace; font-size:11px; font-weight:700; color:#1e293b; margin-bottom:4px;">ai_xxxx.jpg</div>
                                                                    <p style="font-size:11px; color:#64748b; margin-bottom:10px; line-height:1.4;">
                                                                        Foto telah dioptimasi otomatis (1080px, ringan &amp; cepat dimuat di aplikasi) dan siap digunakan sebagai gambar utama resep ini.
                                                                    </p>
                                                                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                                                        <button type="button" id="btnApplyAiImageToRecipe" class="btn btn-success waves-effect" onclick="applyAiImageToRecipeForm();" style="border-radius:4px; font-weight:700;">
                                                                            <i class="material-icons" style="font-size:15px; vertical-align:middle;">star</i> GUNAKAN GAMBAR INI UNTUK RESEP
                                                                        </button>
                                                                        <button type="button" class="btn btn-default btn-xs waves-effect" onclick="viewAiImageFull();" style="border-radius:4px;">
                                                                            <i class="material-icons" style="font-size:13px; vertical-align:middle;">zoom_in</i> Lihat Detail
                                                                        </button>
                                                                        <button type="button" class="btn btn-default btn-xs waves-effect" onclick="runDirectGlabsGeneration();" style="border-radius:4px;">
                                                                            <i class="material-icons" style="font-size:13px; vertical-align:middle;">refresh</i> Render Ulang
                                                                        </button>
                                                                    </div>

                                                                    <!-- Notifikasi Sukses Terapkan -->
                                                                    <div id="glabsDirectAppliedNotice" style="display:none; margin-top:8px; font-size:11px; color:#065f46; background:#ecfdf5; padding:6px 10px; border-radius:4px; border:1px solid #a7f3d0;">
                                                                        <i class="material-icons" style="font-size:14px; vertical-align:middle;">verified</i> <strong>Foto AI Aktif:</strong> Gambar telah terpasang ke form draf dan tampil di Pratinjau HP!
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <form id="recipeEditForm" onsubmit="handlePublish(event);">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" id="importId" name="import_id" value="<?php echo (int)($currentDraft['id'] ?? 0); ?>">
                                        <input type="hidden" id="selectedImageUrl" name="selected_image_url" value="<?php echo htmlspecialchars($currentDraft['selected_image_url'] ?? ''); ?>">

                                        <!-- Title -->
                                        <div class="form-group">
                                            <label for="recipeTitle">Judul Resep <span class="text-danger">*</span></label>
                                            <div class="form-line">
                                                <input type="text" id="recipeTitle" name="recipe_title" class="form-control"
                                                       value="<?php echo htmlspecialchars($currentDraft['recipe_title'] ?? ''); ?>" required oninput="syncPreview();">
                                            </div>
                                        </div>

                                        <!-- Times & Servings -->
                                        <div class="row clearfix">
                                            <div class="col-sm-4 col-xs-12">
                                                <div class="form-group">
                                                    <label for="prepTime">Waktu Persiapan</label>
                                                    <div class="form-line">
                                                        <input type="text" id="prepTime" name="prep_time" class="form-control"
                                                               value="<?php echo htmlspecialchars($currentDraft['prep_time'] ?? ''); ?>" oninput="syncPreview();">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-4 col-xs-12">
                                                <div class="form-group">
                                                    <label for="cookTime">Waktu Memasak</label>
                                                    <div class="form-line">
                                                        <input type="text" id="cookTime" name="cook_time" class="form-control"
                                                               value="<?php echo htmlspecialchars($currentDraft['cook_time'] ?? ''); ?>" oninput="syncPreview();">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-4 col-xs-12">
                                                <div class="form-group">
                                                    <label for="servings">Porsi</label>
                                                    <div class="form-line">
                                                        <input type="text" id="servings" name="servings" class="form-control"
                                                               value="<?php echo htmlspecialchars($currentDraft['servings'] ?? ''); ?>" oninput="syncPreview();">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Category -->
                                        <div class="form-group">
                                            <label for="catId">Kategori Webadmin <span class="text-danger">*</span></label>
                                            <select id="catId" name="cat_id" class="form-control show-tick" required onchange="syncPreview();">
                                                <option value="">-- Pilih Kategori --</option>
                                                <?php foreach ($categories as $c): ?>
                                                    <option value="<?php echo $c['cid']; ?>" <?php echo (($currentDraft['cat_id'] ?? 0) == $c['cid']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($c['category_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <!-- Summary -->
                                        <div class="form-group">
                                            <label for="summary">Ringkasan Resep</label>
                                            <div class="form-line">
                                                <textarea id="summary" name="summary" rows="3" class="form-control" oninput="syncPreview();"><?php echo htmlspecialchars($currentDraft['summary'] ?? ''); ?></textarea>
                                            </div>
                                        </div>

                                        <!-- Ingredients Grouped/List -->
                                        <div class="form-group">
                                            <label for="ingredientsRaw">Daftar Bahan (JSON atau Baris Baru per Bahan) <span class="text-danger">*</span></label>
                                            <div class="form-line">
                                                <textarea id="ingredientsRaw" rows="6" class="form-control" style="font-family:monospace; font-size:12px;" oninput="syncPreview();"><?php
                                                    if (!empty($currentDraft['ingredients_json'])) {
                                                        echo htmlspecialchars($currentDraft['ingredients_json']);
                                                    }
                                                ?></textarea>
                                            </div>
                                            <small class="help-block">Mendukung format JSON terstruktur (kelompok bahan) atau satu bahan per baris.</small>
                                        </div>

                                        <!-- Instructions -->
                                        <div class="form-group">
                                            <label for="instructionsRaw">Langkah-langkah Memasak (JSON atau Satu Langkah per Baris) <span class="text-danger">*</span></label>
                                            <div class="form-line">
                                                <textarea id="instructionsRaw" rows="6" class="form-control" style="font-family:monospace; font-size:12px;" oninput="syncPreview();"><?php
                                                    if (!empty($currentDraft['instructions_json'])) {
                                                        echo htmlspecialchars($currentDraft['instructions_json']);
                                                    }
                                                ?></textarea>
                                            </div>
                                        </div>

                                        <!-- Image Candidates / Custom Upload -->
                                        <div class="form-group">
                                            <label>Kandidat Gambar Sumber</label>
                                            <div id="imageCandidatesList" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
                                                <!-- Dynamic candidate thumbs -->
                                            </div>
                                            <div style="margin-top:6px;">
                                                <label for="customImage" style="font-weight:normal; font-size:12px;">Atau unggah gambar alternatif milik sendiri:</label>
                                                <input type="file" id="customImage" name="custom_image" accept="image/*" class="form-control" onchange="handleCustomImage(event);">
                                            </div>
                                        </div>


                                        <!-- Action Buttons -->
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; flex-wrap:wrap; gap:10px;">
                                            <button type="button" class="btn btn-default waves-effect" onclick="handleReject();">
                                                <i class="material-icons" style="font-size:16px; vertical-align:middle;">close</i> Tolak Hasil
                                            </button>
                                            <div style="display:flex; gap:10px;">
                                                <button type="button" class="btn btn-warning waves-effect" onclick="handleSaveDraft();">
                                                    <i class="material-icons" style="font-size:16px; vertical-align:middle;">save</i> Simpan Draf
                                                </button>
                                                <button type="submit" id="btnPublish" class="btn btn-success waves-effect" style="font-weight:700;">
                                                    <i class="material-icons" style="font-size:16px; vertical-align:middle;">publish</i> PUBLIKASIKAN RESEP
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Live Preview Column -->
                        <div class="col-md-5 col-xs-12">
                            <div class="card corner-radius" style="position: sticky; top: 80px;">
                                <div class="body" style="padding: 16px;">
                                    <div class="card-step-header">
                                        <h4><span class="step-circle">3</span> Pratinjau Aplikasi</h4>
                                        <span class="label label-success">POST TYPE</span>
                                    </div>

                                    <div class="preview-phone-frame">
                                        <div class="preview-header-bar">
                                            <span id="previewCategory">Main Dish</span>
                                            <span>Pratinjau Mobile</span>
                                        </div>
                                        <div class="preview-image-wrap" id="previewImageWrap">
                                            <img id="previewImg" src="" style="display:none;" alt="Preview Resep">
                                            <div id="previewNoImg" class="no-image-text">
                                                <i class="material-icons" style="font-size:48px; display:block; margin-bottom:4px;">image</i>
                                                <span>Gambar belum dipilih</span>
                                            </div>
                                        </div>
                                        <div class="preview-content">
                                            <h3 id="previewTitle">Judul Resep</h3>
                                            <div class="preview-meta-chips">
                                                <span class="preview-chip" id="previewPrep">Prep: —</span>
                                                <span class="preview-chip" id="previewCook">Cook: —</span>
                                                <span class="preview-chip" id="previewServings">Porsi: —</span>
                                            </div>
                                            <div class="preview-summary" id="previewSummary">Ringkasan resep akan muncul di sini...</div>

                                            <div class="preview-section-title">Susunan Bahan</div>
                                            <div id="previewIngredientsContainer">
                                                <ul class="preview-list">
                                                    <li>Bahan belum diisi</li>
                                                </ul>
                                            </div>

                                            <div class="preview-section-title">Susunan Langkah</div>
                                            <div id="previewInstructionsContainer">
                                                <ol class="preview-list">
                                                    <li>Langkah belum diisi</li>
                                                </ol>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

        <?php elseif ($tab === 'batch'): ?>
            <!-- TAB: BATCH RECIPE ORCHESTRATOR -->
            <div class="row clearfix">
                <div class="col-xs-12">
                    <!-- Page Header Card -->
                    <div class="card corner-radius" style="margin-bottom:20px; border:1px solid #E7E5E4; box-shadow:0 4px 18px -2px rgba(41, 37, 36, 0.05);">
                        <div class="body" style="padding:22px 24px;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
                                <div>
                                    <span style="display:inline-flex; align-items:center; gap:6px; background:#FFF1EB; color:#C2410C; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; margin-bottom:8px;">
                                        <i class="material-icons" style="font-size:14px;">auto_mode</i> MASS RECIPE IMPORT &amp; AUTO-POLISH ENGINE
                                    </span>
                                    <h3 style="margin:0 0 6px 0; font-family:'Outfit', sans-serif; font-size:24px; font-weight:800; color:#1C1917; letter-spacing:-0.3px;">
                                        Batch Recipe Orchestrator
                                    </h3>
                                    <p style="margin:0; font-size:13px; color:#78716C; max-width:850px; line-height:1.5;">
                                        Tempel hingga 50 URL resep sekaligus. Sistem akan mengekstrak data dari web asal, memoles teks secara otomatis (100% menjaga takaran bahan &amp; panduan air fryer), membuat foto masakan AI photorealistic baru yang unik, dan menampilkannya di tabel review untuk Anda periksa atau edit sebelum diterbitkan.
                                    </p>
                                </div>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-default waves-effect" onclick="loadSampleCookpadUrls();" style="border-radius:8px; font-size:12px; font-weight:700; color:#C2410C; border-color:#FED7AA; background:#FFF1EB;">
                                        <i class="material-icons" style="font-size:15px; vertical-align:middle;">restaurant</i> Contoh Cookpad
                                    </button>
                                    <button type="button" class="btn btn-default waves-effect" onclick="loadSampleUrls();" style="border-radius:8px; font-size:12px; font-weight:600;">
                                        <i class="material-icons" style="font-size:15px; vertical-align:middle; color:#44403C;">playlist_add</i> Contoh BBC Food
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Grid: URL Input (Kiri) & Konfigurasi (Kanan) -->
                    <div class="orchestrator-input-grid">
                        <!-- Kolom Kiri: Input Multi-URL -->
                        <div class="panel-nordic-card">
                            <div class="panel-heading">
                                <i class="material-icons" style="font-size:18px; color:#C2410C;">link</i>
                                <span>1. Masukkan Daftar URL Resep Sumber</span>
                            </div>
                            <textarea id="batchUrlsInput" class="url-textarea" placeholder="Tempel URL resep di sini (satu URL per baris), contoh:&#10;https://www.bbc.co.uk/food/recipes/air_fryer_chicken_thighs&#10;https://www.bbc.co.uk/food/recipes/crispy_air_fryer_chips&#10;https://www.bbc.co.uk/food/recipes/air_fryer_salmon" oninput="updateUrlCounter();"></textarea>
                            <div class="textarea-meta">
                                <span id="urlCounterText"><strong>0</strong> URL terdeteksi</span>
                                <span class="text-muted"><i class="material-icons" style="font-size:12px; vertical-align:middle;">info</i> Satu URL per baris (Maks. 50 URL)</span>
                            </div>
                        </div>

                        <!-- Kolom Kanan: Parameter Batch & AI Generator -->
                        <div class="panel-nordic-card">
                            <div class="panel-heading">
                                <i class="material-icons" style="font-size:18px; color:#C2410C;">tune</i>
                                <span>2. Pengaturan Orkestrasi &amp; Kategori</span>
                            </div>

                            <!-- Target Product Code -->
                            <div style="margin-bottom:12px;">
                                <label style="display:block; font-size:11.5px; font-weight:700; color:#44403C; margin-bottom:4px;">Aplikasi Target:</label>
                                <select id="batchProductCode" class="form-control" style="border-radius:6px; font-size:12.5px; font-weight:600; border-color:#D6D3D1;">
                                    <?php if (empty($syncDestinations)): ?>
                                        <option value="airfry" <?php echo (($_SESSION['active_product'] ?? '') === 'airfry') ? 'selected' : ''; ?>>Air Fryer Recipes (airfry)</option>
                                        <option value="protein" <?php echo (($_SESSION['active_product'] ?? '') === 'protein') ? 'selected' : ''; ?>>Protein Prep (protein)</option>
                                    <?php else: ?>
                                        <?php foreach ($syncDestinations as $dest): 
                                            $destCode = htmlspecialchars($dest['product_code']);
                                            $destLabel = htmlspecialchars($dest['label']);
                                            $isSel = (($_SESSION['active_product'] ?? '') === $dest['product_code']) ? 'selected' : '';
                                        ?>
                                            <option value="<?php echo $destCode; ?>" <?php echo $isSel; ?>>
                                                <?php echo $destLabel; ?> (<?php echo $destCode; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <!-- Default Category -->
                            <div style="margin-bottom:12px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                    <label style="margin:0; font-size:11.5px; font-weight:700; color:#44403C;">Kategori Default Batch:</label>
                                    <a href="javascript:void(0);" onclick="quickAddCategoryPrompt('batchDefaultCatId');" style="font-size:11px; font-weight:700; color:#C2410C; text-decoration:none;">
                                        + Tambah Cepat
                                    </a>
                                </div>
                                <select id="batchDefaultCatId" class="form-control" style="border-radius:6px; font-size:12.5px; font-weight:600; border-color:#D6D3D1;">
                                    <option value="0">-- Deteksi Otomatis dari Judul --</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo (int)$cat['cid']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- AI Output Language -->
                            <div style="margin-bottom:12px;">
                                <label style="display:block; font-size:11.5px; font-weight:700; color:#44403C; margin-bottom:4px;">Bahasa Hasil Polish:</label>
                                <select id="batchLanguage" class="form-control" style="border-radius:6px; font-size:12px; font-weight:600; border-color:#D6D3D1;">
                                    <option value="auto" selected>Otomatis (Ikuti Resep Asli)</option>
                                    <option value="id">Bahasa Indonesia</option>
                                    <option value="en">English (Inggris)</option>
                                </select>
                            </div>

                            <!-- AI Photo Style & Ratio -->
                            <div class="row clearfix" style="margin-bottom:0;">
                                <div class="col-xs-7" style="padding-right:6px;">
                                    <label style="display:block; font-size:11px; font-weight:700; color:#44403C; margin-bottom:4px;">Gaya Foto AI:</label>
                                    <select id="batchStylePreset" class="form-control" style="border-radius:6px; font-size:11.5px; border-color:#D6D3D1;">
                                        <option value="Modern Fine Dining" selected>Modern Fine Dining</option>
                                        <option value="Rustic Comfort Food">Rustic Comfort Food</option>
                                        <option value="Editorial Studio Overhead">Editorial Studio</option>
                                        <option value="Dark Moody Culinary">Dark Moody</option>
                                    </select>
                                </div>
                                <div class="col-xs-5" style="padding-left:6px;">
                                    <label style="display:block; font-size:11px; font-weight:700; color:#44403C; margin-bottom:4px;">Rasio:</label>
                                    <select id="batchAspectRatio" class="form-control" style="border-radius:6px; font-size:11.5px; border-color:#D6D3D1;">
                                        <option value="4:3" selected>4:3 (Utama)</option>
                                        <option value="1:1">1:1 (Persegi)</option>
                                        <option value="16:9">16:9 (Landscape)</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Human Delay (Anti-Bot / Anti-Scraping Ban) -->
                            <div style="margin-top:12px; margin-bottom:12px;">
                                <label style="display:flex; justify-content:space-between; align-items:center; font-size:11.5px; font-weight:700; color:#44403C; margin-bottom:4px;">
                                    <span>Jeda Antrean (Anti-Bot / Human-Like):</span>
                                    <span style="font-size:10.5px; color:#16A34A; font-weight:600;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">shield</i> Aman</span>
                                </label>
                                <select id="batchDelayMode" class="form-control" style="border-radius:6px; font-size:12px; font-weight:600; border-color:#D6D3D1;">
                                    <option value="random_2_5" selected>Acak 2 - 5 Detik (Sangat Direkomendasikan)</option>
                                    <option value="random_5_10">Acak 5 - 10 Detik (Sangat Aman / Batch Besar)</option>
                                    <option value="fixed_1">Cepat (1 Detik)</option>
                                    <option value="none">Tanpa Jeda Tambahan (0.6 Detik)</option>
                                </select>
                            </div>

                            <!-- Notice Box: Wajib Foto Baru -->
                            <div class="notice-box">
                                <i class="material-icons" style="font-size:17px; color:#C2410C;">verified</i>
                                <div>
                                    <strong>Wajib Foto Baru &amp; Unik:</strong> Setiap resep otomatis dibuatkan foto masakan AI photorealistic baru resolusi tinggi (1080px) dan disimpan ke folder <code>upload/</code> lokal tanpa gambar duplikat.
                                </div>
                            </div>

                            <!-- Start Button -->
                            <button type="button" id="btnStartBatch" class="btn-start-orchestrator" onclick="startBatchOrchestrator();">
                                <i class="material-icons" style="font-size:18px;">bolt</i>
                                <span>JALANKAN BATCH ORCHESTRATOR</span>
                            </button>
                        </div>
                    </div>

                    <!-- Live Progress Monitor (Hidden initially) -->
                    <div id="batchProgressContainer" class="panel-nordic-card" style="display:none; margin-bottom:24px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div class="preloader pl-size-xs" style="margin:0;">
                                    <div class="spinner-layer pl-amber">
                                        <div class="circle-clipper left"><div class="circle"></div></div>
                                        <div class="circle-clipper right"><div class="circle"></div></div>
                                    </div>
                                </div>
                                <span id="batchProgressStatusText" style="font-size:13.5px; font-weight:700; color:#1C1917;">Memulai antrean batch...</span>
                            </div>
                            <span id="batchProgressPercent" style="font-family:'Outfit', sans-serif; font-size:15px; font-weight:800; color:#C2410C;">0%</span>
                        </div>

                        <!-- Progress Bar -->
                        <div class="progress-bar-track">
                            <div id="batchProgressBarFill" class="progress-bar-fill"></div>
                        </div>

                        <!-- Step Tracker -->
                        <div class="step-tracker-flex">
                            <div id="stepPill1" class="step-item-pill">
                                <span class="badge" style="background:#78716C; color:#fff; font-size:10px;">1</span>
                                <span>Scrape Web</span>
                            </div>
                            <div id="stepPill2" class="step-item-pill">
                                <span class="badge" style="background:#78716C; color:#fff; font-size:10px;">2</span>
                                <span>AI Polish &amp; Resep</span>
                            </div>
                            <div id="stepPill3" class="step-item-pill">
                                <span class="badge" style="background:#78716C; color:#fff; font-size:10px;">3</span>
                                <span>Auto Photo Studio</span>
                            </div>
                            <div id="stepPill4" class="step-item-pill">
                                <span class="badge" style="background:#78716C; color:#fff; font-size:10px;">4</span>
                                <span>Siap Review</span>
                            </div>
                        </div>

                        <!-- Terminal Output Box -->
                        <div id="batchTerminalBox" class="terminal-box">
                            <div class="terminal-line info">[Sistem] Siap menerima antrean URL resep...</div>
                        </div>
                    </div>

                    <!-- Batch Review Table Card -->
                    <div id="batchReviewCard" class="card corner-radius" style="display:none; border:1px solid #E7E5E4; box-shadow:0 4px 18px -2px rgba(41, 37, 36, 0.05); margin-bottom:80px;">
                        <div class="body" style="padding:0;">
                            <div style="padding:16px 20px; border-bottom:1px solid #E7E5E4; display:flex; justify-content:space-between; align-items:center; background:#FAF8F5;">
                                <div>
                                    <h4 style="margin:0; font-size:15px; font-weight:800; color:#1C1917;">
                                        <i class="material-icons" style="font-size:18px; vertical-align:middle; color:#15803D;">check_circle</i> Draf Resep Siap Tinjau
                                    </h4>
                                    <small style="color:#78716C;">Periksa judul hasil polish AI, ganti kategori secara langsung pada dropdown, atau klik tombol Preview untuk mengedit isi resep.</small>
                                </div>
                                <div>
                                    <span id="batchTableCountBadge" class="badge" style="background:#C2410C; font-size:12px; padding:4px 10px;">0 Resep</span>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table-batch-review">
                                    <thead>
                                        <tr>
                                            <th style="width:40px; text-align:center;">
                                                <input type="checkbox" id="checkAllBatch" checked onchange="toggleSelectAllBatch(this);">
                                                <label for="checkAllBatch" style="margin:0; padding:0; height:18px;"></label>
                                            </th>
                                            <th style="width:80px; text-align:center;">Foto AI</th>
                                            <th>Judul Resep Ter-Polish</th>
                                            <th style="width:190px;">Kategori Resep</th>
                                            <th style="width:140px;">Waktu &amp; Porsi</th>
                                            <th style="width:120px; text-align:center;">Status</th>
                                            <th style="width:150px; text-align:center;">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody id="batchReviewTableBody">
                                        <!-- Dynamic rows generated by JavaScript -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Fixed Floating Action Bar -->
            <div id="floatingBatchBar" class="floating-batch-bar" style="display:none;">
                <div class="info-side">
                    <label style="margin:0; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" id="batchAutoPushCheckbox" checked style="width:16px; height:16px; accent-color:#C2410C;">
                        <span>Otomatis Sinkronisasi (1-Click Push) ke Aplikasi / Live Hosting</span>
                    </label>
                </div>
                <div class="action-side">
                    <span id="selectedBatchCountText" style="font-size:13px; font-weight:700; color:#FDBA74;">0 Resep Terpilih</span>
                    <button type="button" class="btn-publish-all" id="btnPublishSelected" onclick="publishAllSelectedBatch();">
                        <i class="material-icons" style="font-size:18px;">publish</i>
                        <span>TERBITKAN SEMUA YANG DIPILIH</span>
                    </button>
                </div>
            </div>

            <!-- Modal Preview Draf Lengkap -->
            <div class="modal fade" id="recipePreviewModal" tabindex="-1" role="dialog" data-backdrop="static">
                <div class="modal-dialog modal-lg" role="document" style="max-width:920px;">
                    <div class="modal-content" style="border-radius:12px; overflow:hidden; border:none; box-shadow:0 12px 36px rgba(0,0,0,0.25);">
                        <div class="modal-header" style="background:#1C1917; color:#FFFFFF; padding:16px 22px;">
                            <button type="button" class="close" data-dismiss="modal" style="color:#FFF; opacity:0.8;">&times;</button>
                            <h4 class="modal-title" style="font-family:'Outfit', sans-serif; font-size:17px; font-weight:700; display:flex; align-items:center; gap:8px;">
                                <i class="material-icons" style="color:#C2410C;">preview</i> Pratinjau &amp; Edit Draf Resep
                            </h4>
                        </div>
                        <div class="modal-body" style="padding:22px; background:#FAF8F5;">
                            <input type="hidden" id="modalImportId" value="">

                            <div class="row clearfix">
                                <!-- Kolom Kiri: Foto AI & Prompt -->
                                <div class="col-md-5 col-sm-12">
                                    <div style="background:#FFF; border:1px solid #E7E5E4; border-radius:10px; padding:12px; margin-bottom:14px; text-align:center;">
                                        <div style="position:relative; width:100%; height:230px; border-radius:8px; overflow:hidden; background:#1C1917;">
                                            <img id="modalPreviewImg" src="" style="width:100%; height:100%; object-fit:cover;" alt="AI Generated Photo">
                                            <span id="modalImgBadge" style="position:absolute; bottom:8px; right:8px; background:rgba(0,0,0,0.65); color:#fff; font-size:10px; padding:2px 8px; border-radius:4px; font-weight:700;">
                                                1080px Optimized
                                            </span>
                                        </div>
                                        <div style="margin-top:10px; text-align:left;">
                                            <label style="font-size:11px; font-weight:700; color:#78716C;">File Gambar:</label>
                                            <input type="text" id="modalSelectedImageUrl" class="form-control" style="font-size:11px; font-family:monospace; background:#FAF8F5;" readonly>
                                        </div>
                                    </div>

                                    <!-- Prompt AI Food Photo -->
                                    <div style="background:#FFF; border:1px solid #E7E5E4; border-radius:10px; padding:12px;">
                                        <label style="font-size:11px; font-weight:700; color:#78716C; margin-bottom:4px; display:block;">
                                            <i class="material-icons" style="font-size:13px; vertical-align:middle; color:#C2410C;">auto_awesome</i> Prompt Foto AI Terpakai:
                                        </label>
                                        <div id="modalPhotoPromptText" style="font-size:11px; color:#44403C; line-height:1.4; max-height:80px; overflow-y:auto; background:#FAF8F5; padding:8px; border-radius:6px; font-style:italic;">
                                            -
                                        </div>
                                    </div>
                                </div>

                                <!-- Kolom Kanan: Form Field Edit Resep -->
                                <div class="col-md-7 col-sm-12">
                                    <div style="background:#FFF; border:1px solid #E7E5E4; border-radius:10px; padding:16px;">
                                        <!-- Judul Resep -->
                                        <div class="form-group" style="margin-bottom:12px;">
                                            <label style="font-size:12px; font-weight:700; color:#1C1917;">Judul Resep <span style="color:#DC2626;">*</span></label>
                                            <input type="text" id="modalRecipeTitle" class="form-control" style="font-size:13px; font-weight:600; border-radius:6px;">
                                        </div>

                                        <!-- Kategori Resep -->
                                        <div class="form-group" style="margin-bottom:12px;">
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                                <label style="font-size:12px; font-weight:700; color:#1C1917; margin:0;">Kategori Resep <span style="color:#DC2626;">*</span></label>
                                                <a href="javascript:void(0);" onclick="quickAddCategoryPrompt('modalCatId');" style="font-size:11px; font-weight:700; color:#C2410C; text-decoration:none;">
                                                    + Tambah Kategori
                                                </a>
                                            </div>
                                            <select id="modalCatId" class="form-control" style="border-radius:6px; font-size:12.5px; font-weight:600;">
                                                <option value="0">-- Pilih Kategori --</option>
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?php echo (int)$cat['cid']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <!-- Waktu & Porsi -->
                                        <div class="row clearfix" style="margin-bottom:12px;">
                                            <div class="col-xs-4" style="padding-right:4px;">
                                                <label style="font-size:11px; font-weight:700; color:#78716C;">Prep Time</label>
                                                <input type="text" id="modalPrepTime" class="form-control" style="font-size:11.5px; border-radius:6px;" placeholder="10 mins">
                                            </div>
                                            <div class="col-xs-4" style="padding-left:4px; padding-right:4px;">
                                                <label style="font-size:11px; font-weight:700; color:#78716C;">Cook Time</label>
                                                <input type="text" id="modalCookTime" class="form-control" style="font-size:11.5px; border-radius:6px;" placeholder="18 mins">
                                            </div>
                                            <div class="col-xs-4" style="padding-left:4px;">
                                                <label style="font-size:11px; font-weight:700; color:#78716C;">Servings</label>
                                                <input type="text" id="modalServings" class="form-control" style="font-size:11.5px; border-radius:6px;" placeholder="4">
                                            </div>
                                        </div>

                                        <!-- Summary & Nutrition Facts -->
                                        <div class="form-group" style="margin-bottom:12px;">
                                            <label style="font-size:12px; font-weight:700; color:#1C1917;">Ringkasan &amp; Fakta Nutrisi</label>
                                            <textarea id="modalSummary" class="form-control" rows="3" style="font-size:12px; border-radius:6px; line-height:1.45;"></textarea>
                                        </div>

                                        <!-- Tabs Bahan & Instruksi -->
                                        <div>
                                            <ul class="nav nav-tabs tab-nav-right" role="tablist" style="margin-bottom:10px;">
                                                <li role="presentation" class="active">
                                                    <a href="#modalTabIngredients" data-toggle="tab" style="font-size:12px; font-weight:700;">
                                                        <i class="material-icons" style="font-size:14px; vertical-align:middle;">restaurant</i> Bahan-Bahan
                                                    </a>
                                                </li>
                                                <li role="presentation">
                                                    <a href="#modalTabInstructions" data-toggle="tab" style="font-size:12px; font-weight:700;">
                                                        <i class="material-icons" style="font-size:14px; vertical-align:middle;">format_list_numbered</i> Instruksi Memasak
                                                    </a>
                                                </li>
                                            </ul>
                                            <div class="tab-content">
                                                <div role="tabpanel" class="tab-pane fade in active" id="modalTabIngredients">
                                                    <textarea id="modalIngredients" class="form-control" rows="5" style="font-size:12px; font-family:monospace; line-height:1.5; border-radius:6px;" placeholder="1 baris per bahan"></textarea>
                                                    <small class="text-muted" style="font-size:10.5px;">* Satu baris per bahan resep.</small>
                                                </div>
                                                <div role="tabpanel" class="tab-pane fade" id="modalTabInstructions">
                                                    <textarea id="modalInstructions" class="form-control" rows="5" style="font-size:12px; font-family:monospace; line-height:1.5; border-radius:6px;" placeholder="1 baris per langkah instruksi"></textarea>
                                                    <small class="text-muted" style="font-size:10.5px;">* Satu baris per langkah instruksi memasak.</small>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer" style="padding:14px 22px; background:#F5F1EB; border-top:1px solid #E7E5E4;">
                            <button type="button" class="btn btn-default waves-effect" data-dismiss="modal" style="border-radius:6px; font-weight:600;">Batal</button>
                            <button type="button" id="btnSaveModalDraft" class="btn waves-effect" onclick="saveDraftFromModal();" style="background:#C2410C; color:#fff; border-radius:6px; font-weight:700;">
                                <i class="material-icons" style="font-size:15px; vertical-align:middle;">save</i> Simpan Perubahan Draf
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($tab === 'queue'): ?>
            <!-- TAB: ANTREAN TINJAUAN -->
            <?php
            $drafts = $importerService->getImports('draft', 100);
            ?>
            <div class="row clearfix">
                <div class="col-xs-12">
                    <div class="card corner-radius">
                        <div class="header">
                            <h2>ANTREAN TINJAUAN RESEP (DRAF)</h2>
                            <small>Daftar resep hasil ekstraksi yang tersimpan sebagai draf dan menunggu tinjauan admin sebelum dipublikasikan.</small>
                        </div>
                        <div class="body table-responsive">
                            <?php if (empty($drafts)): ?>
                                <div class="alert alert-info">
                                    <i class="material-icons" style="vertical-align:middle;">info</i>
                                    Tidak ada resep dalam antrean tinjauan saat ini. <a href="importer.php?tab=import" class="alert-link">Impor resep baru &rarr;</a>
                                </div>
                            <?php else: ?>
                                <table class="table table-hover table-striped">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Judul Resep</th>
                                            <th>Sumber Domain</th>
                                            <th>Waktu Ekstraksi</th>
                                            <th>Status</th>
                                            <th style="text-align:right;">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($drafts as $idx => $d): ?>
                                            <tr>
                                                <td><?php echo $idx + 1; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($d['recipe_title'] ?: 'Tanpa Judul'); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><a href="<?php echo htmlspecialchars($d['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars(substr($d['source_url'], 0, 70)); ?>...</a></small>
                                                </td>
                                                <td><span class="label label-info"><?php echo htmlspecialchars($d['source_domain']); ?></span></td>
                                                <td><?php echo date('d M Y H:i', strtotime($d['created_at'])); ?></td>
                                                <td><span class="badge-status draft">DRAF</span></td>
                                                <td style="text-align:right;">
                                                    <a href="importer.php?tab=import&id=<?php echo $d['id']; ?>" class="btn btn-sm btn-primary waves-effect">
                                                        <i class="material-icons" style="font-size:14px; vertical-align:middle;">edit</i> Tinjau &amp; Edit
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-default waves-effect" onclick="quickReject(<?php echo $d['id']; ?>)">
                                                        <i class="material-icons" style="font-size:14px; vertical-align:middle;">close</i> Tolak
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($tab === 'history'): ?>
            <!-- TAB: RIWAYAT IMPOR -->
            <?php
            $history = $importerService->getImports(null, 100);
            ?>
            <div class="row clearfix">
                <div class="col-xs-12">
                    <div class="card corner-radius">
                        <div class="header">
                            <h2>RIWAYAT IMPOR RESEP</h2>
                            <small>Catatan semua proses impor resep: status, waktu proses, alasan kegagalan, dan tautan ke resep terbit.</small>
                        </div>
                        <div class="body table-responsive">
                            <?php if (empty($history)): ?>
                                <div class="alert alert-info">Belum ada riwayat proses impor.</div>
                            <?php else: ?>
                                <table class="table table-hover table-striped">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Resep / URL Sumber</th>
                                            <th>Domain</th>
                                            <th>Status</th>
                                            <th>Waktu Proses</th>
                                            <th>Catatan / Alasan</th>
                                            <th>Tanggal</th>
                                            <th style="text-align:right;">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($history as $h): ?>
                                            <tr>
                                                <td>#<?php echo $h['id']; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($h['recipe_title'] ?: '—'); ?></strong>
                                                    <br>
                                                    <small><a href="<?php echo htmlspecialchars($h['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars(substr($h['source_url'], 0, 60)); ?>...</a></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($h['source_domain']); ?></td>
                                                <td>
                                                    <span class="badge-status <?php echo htmlspecialchars($h['status']); ?>">
                                                        <?php echo htmlspecialchars(strtoupper($h['status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $h['processing_time_ms'] > 0 ? $h['processing_time_ms'] . ' ms' : '—'; ?></td>
                                                <td>
                                                    <?php if (!empty($h['failure_reason'])): ?>
                                                        <span class="text-danger" style="font-size:12px;"><?php echo htmlspecialchars($h['failure_reason']); ?></span>
                                                    <?php elseif ($h['status'] === 'published'): ?>
                                                        <span class="text-success" style="font-size:12px;">Terbit (Recipe ID #<?php echo $h['recipe_id']; ?>)</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($h['created_at'])); ?></td>
                                                <td style="text-align:right;">
                                                    <?php if ($h['status'] === 'published'): ?>
                                                        <a href="recipes-detail.php?id=<?php echo $h['recipe_id']; ?>" class="btn btn-xs btn-success waves-effect" title="Lihat detail resep">
                                                            <i class="material-icons" style="font-size:14px;">visibility</i>
                                                        </a>
                                                        <a href="recipes-edit.php?id=<?php echo $h['recipe_id']; ?>" class="btn btn-xs btn-primary waves-effect" title="Edit resep">
                                                            <i class="material-icons" style="font-size:14px;">edit</i>
                                                        </a>
                                                    <?php elseif ($h['status'] === 'draft'): ?>
                                                        <a href="importer.php?tab=import&id=<?php echo $h['id']; ?>" class="btn btn-xs btn-warning waves-effect" title="Lanjutkan draf">
                                                            <i class="material-icons" style="font-size:14px;">edit</i> Lanjutkan
                                                        </a>
                                                    <?php elseif ($h['status'] === 'failed' || $h['status'] === 'rejected'): ?>
                                                        <button type="button" class="btn btn-xs btn-default waves-effect" onclick="quickRetry(<?php echo $h['id']; ?>)" title="Coba ulang proses impor">
                                                            <i class="material-icons" style="font-size:14px;">refresh</i> Coba Ulang
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($tab === 'settings'): ?>
            <!-- TAB: PENGATURAN & STATUS -->
            <div class="row clearfix">
                <div class="col-md-6 col-xs-12">
                    <div class="card corner-radius">
                        <div class="header">
                            <h2>STATUS KONEKSI DATABASE</h2>
                            <small>Pemeriksaan server database dan ketersediaan tabel resep webadmin.</small>
                        </div>
                        <div class="body">
                            <table class="table table-bordered">
                                <tr>
                                    <th style="width:35%;">Host Server</th>
                                    <td><code><?php echo htmlspecialchars($host ?? 'localhost'); ?></code></td>
                                </tr>
                                <tr>
                                    <th>Nama Database</th>
                                    <td><code><?php echo htmlspecialchars($database ?? 'your_recipes_app_db'); ?></code></td>
                                </tr>
                                <tr>
                                    <th>User Database</th>
                                    <td><code><?php echo htmlspecialchars($user ?? 'root'); ?></code></td>
                                </tr>
                                <tr>
                                    <th>Password</th>
                                    <td><em>&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull; (Tersimpan aman di config.php server)</em></td>
                                </tr>
                                <tr>
                                    <th>Tabel Importer</th>
                                    <td>
                                        <?php if ($migration->isMigrated()): ?>
                                            <span class="label label-success">TERSEDIA (tbl_recipe_imports)</span>
                                        <?php else: ?>
                                            <span class="label label-danger">BELUM DIBUAT</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>

                            <div style="margin-top: 15px;">
                                <button type="button" id="btnTestDb" class="btn btn-primary waves-effect" onclick="handleTestDb();">
                                    <i class="material-icons" style="font-size:16px; vertical-align:middle;">speed</i>
                                    TES KONEKSI DATABASE
                                </button>
                                <?php if (!$migration->isMigrated()): ?>
                                    <button type="button" class="btn btn-warning waves-effect" onclick="handleRunMigration();">
                                        <i class="material-icons" style="font-size:16px; vertical-align:middle;">build</i>
                                        JALANKAN MIGRASI TABEL
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div id="dbTestResult" style="margin-top:15px; display:none;"></div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xs-12">
                    <div class="card corner-radius">
                        <div class="header">
                            <h2>STATUS FOLDER PENYIMPANAN GAMBAR</h2>
                            <small>Pemeriksaan akses direktori <code>upload/</code> untuk gambar resep.</small>
                        </div>
                        <div class="body">
                            <table class="table table-bordered">
                                <tr>
                                    <th style="width:35%;">Jalur Folder</th>
                                    <td><code>upload/</code> (<?php echo htmlspecialchars(realpath('upload') ?: 'upload'); ?>)</td>
                                </tr>
                                <tr>
                                    <th>Status Direktori</th>
                                    <td>
                                        <?php if (is_dir('upload')): ?>
                                            <span class="label label-success">DIREKTORI TERSEDIA</span>
                                        <?php else: ?>
                                            <span class="label label-danger">TIDAK DITEMUKAN</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Izin Tulis (Writable)</th>
                                    <td>
                                        <?php if (is_writable('upload')): ?>
                                            <span class="label label-success">DAPAT DITULISI (WRITABLE)</span>
                                        <?php else: ?>
                                            <span class="label label-danger">READ-ONLY (TIDAK BISA TULIS)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Integrasi Android</th>
                                    <td><span class="text-muted">Aplikasi Android membaca gambar dari URL API + <code>/upload/{recipe_image}</code></span></td>
                                </tr>
                            </table>

                            <div style="margin-top: 15px;">
                                <button type="button" id="btnTestUpload" class="btn btn-info waves-effect" onclick="handleTestUpload();">
                                    <i class="material-icons" style="font-size:16px; vertical-align:middle;">folder_special</i>
                                    TES FOLDER GAMBAR
                                </button>
                            </div>
                            <div id="uploadTestResult" style="margin-top:15px; display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</section>

<!-- Importer Interactive Script -->
<script type="text/javascript">
    const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
    let currentImageCandidates = [];
    let currentSelectedImageUrl = <?php echo json_encode($currentDraft['selected_image_url'] ?? ''); ?>;

    <?php if ($currentDraft): ?>
        // Pre-populate candidate thumbs if draft exists
        try {
            const rawCandidates = <?php echo json_encode($currentDraft['image_candidates_json'] ?? '[]'); ?>;
            currentImageCandidates = typeof rawCandidates === 'string' ? JSON.parse(rawCandidates) : (rawCandidates || []);
        } catch (e) {
            currentImageCandidates = [];
        }
    <?php endif; ?>

    document.addEventListener('DOMContentLoaded', function() {
        if (currentImageCandidates && currentImageCandidates.length > 0) {
            renderImageCandidates(currentImageCandidates, currentSelectedImageUrl);
        }
        syncPreview();
    });

    // Helper: Show bootstrap alert in element
    function showAlert(elementId, type, message) {
        const el = document.getElementById(elementId);
        if (!el) return;
        el.className = 'alert alert-' + type + ' alert-dismissible';
        el.innerHTML = '<button type="button" class="close" data-dismiss="alert">&times;</button>' + message;
        el.style.display = 'block';
    }

    // Safe fetch helper that reads text first and handles 403/500/empty responses gracefully
    async function safeFetchJson(url, options) {
        let res;
        try {
            res = await fetch(url, options);
        } catch (netErr) {
            throw new Error('Koneksi ke server terputus (' + (netErr.message || 'NetworkError') + '). Server lokal kemungkinan sedang sibuk atau timeout. Silakan coba kembali.');
        }
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            if (!res.ok) {
                let hint = '';
                if (res.status === 403 || res.status === 406) {
                    hint = ' (Kemungkinan permintaan diblokir oleh ModSecurity firewall cPanel).';
                } else if (res.status === 500) {
                    hint = ' (Terjadi PHP Fatal Error di hosting. Cek menu Errors di cPanel).';
                }
                throw new Error('HTTP ' + res.status + ' ' + res.statusText + hint + (text ? ' Respons: ' + text.substring(0, 200) : ' (Respons server kosong / 0 bytes).'));
            }
            throw new Error('Server mengembalikan respons bukan JSON: ' + (text ? text.substring(0, 200) : '(respons kosong / 0 bytes). Cek error_log hosting atau nonaktifkan ModSecurity.'));
        }
        return data;
    }

    // Step 1: Fetch URL
    function handleFetchUrl(e) {
        e.preventDefault();
        const urlInput = document.getElementById('sourceUrl');
        const url = urlInput.value.trim();
        if (!url) return;

        const btn = document.getElementById('btnFetch');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:18px; vertical-align:middle;">hourglass_empty</i> Memproses...';
        showAlert('fetchAlert', 'info', 'Menghubungi server sumber dan mengekstrak data resep...');

        const formData = new FormData();
        formData.append('ajax_action', 'fetch_url');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('url', url);

        safeFetchJson('importer.php', {
            method: 'POST',
            body: formData
        })
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = originalText;

            if (res.already_published) {
                showAlert('fetchAlert', 'warning', res.message + ' <a href="recipes-detail.php?id=' + res.recipe_id + '" class="alert-link" target="_blank">Lihat Resep Terbit &rarr;</a>');
                return;
            }

            if (!res.success) {
                showAlert('fetchAlert', 'danger', '<strong>Gagal mengambil data:</strong> ' + (res.error || res.message || 'Terjadi kesalahan.'));
                return;
            }

            showAlert('fetchAlert', 'success', '<strong>Berhasil!</strong> ' + res.message);

            // Populate form with extracted data
            const d = res.data;
            document.getElementById('importId').value = d.id;
            document.getElementById('recipeTitle').value = d.recipe_title || '';
            document.getElementById('prepTime').value = d.prep_time || '';
            document.getElementById('cookTime').value = d.cook_time || '';
            document.getElementById('servings').value = d.servings || '';
            document.getElementById('summary').value = d.summary || '';
            document.getElementById('catId').value = d.cat_id || '';
            $('.show-tick').selectpicker('refresh');

            document.getElementById('ingredientsRaw').value = d.ingredients_json || '';
            document.getElementById('instructionsRaw').value = d.instructions_json || '';
            document.getElementById('selectedImageUrl').value = d.selected_image_url || '';
            currentSelectedImageUrl = d.selected_image_url || '';

            // Render candidate images
            let candidates = [];
            try {
                candidates = typeof d.image_candidates_json === 'string' ? JSON.parse(d.image_candidates_json) : (d.image_candidates_json || []);
            } catch(e) {}
            currentImageCandidates = candidates;
            renderImageCandidates(candidates, currentSelectedImageUrl);

            // Open workspace & sync preview
            document.getElementById('editorWorkspace').style.display = 'block';
            syncPreview();

            // Scroll smoothly to editor
            document.getElementById('editorWorkspace').scrollIntoView({ behavior: 'smooth' });
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            showAlert('fetchAlert', 'danger', 'Terjadi kesalahan jaringan atau server: ' + err.message);
        });
    }

    // Render image candidate thumbnails
    function renderImageCandidates(candidates, selected) {
        const wrap = document.getElementById('imageCandidatesList');
        if (!wrap) return;
        wrap.innerHTML = '';

        if (!candidates || candidates.length === 0) {
            wrap.innerHTML = '<span class="text-muted" style="font-size:12px;">Tidak ada kandidat gambar dari sumber. Anda dapat mengunggah gambar sendiri di bawah.</span>';
            return;
        }

        candidates.forEach((imgUrl, i) => {
            const img = document.createElement('img');
            img.src = imgUrl;
            img.className = 'image-candidate-thumb' + (imgUrl === selected ? ' selected' : '');
            img.title = 'Pilih gambar #' + (i + 1);
            img.onclick = function() {
                document.querySelectorAll('.image-candidate-thumb').forEach(el => el.classList.remove('selected'));
                img.classList.add('selected');
                document.getElementById('selectedImageUrl').value = imgUrl;
                currentSelectedImageUrl = imgUrl;
                syncPreview();
            };
            wrap.appendChild(img);
        });
    }

    // Handle custom image file selection
    function handleCustomImage(e) {
        const file = e.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function(evt) {
            const previewImg = document.getElementById('previewImg');
            const previewNoImg = document.getElementById('previewNoImg');
            previewImg.src = evt.target.result;
            previewImg.style.display = 'block';
            previewNoImg.style.display = 'none';

            // Deselect candidate thumbnails
            document.querySelectorAll('.image-candidate-thumb').forEach(el => el.classList.remove('selected'));
            document.getElementById('selectedImageUrl').value = '';
            currentSelectedImageUrl = '';
        };
        reader.readAsDataURL(file);
    }

    // Synchronize Live Preview
    function syncPreview() {
        const title = document.getElementById('recipeTitle')?.value.trim() || 'Judul Resep';
        const prep = document.getElementById('prepTime')?.value.trim();
        const cook = document.getElementById('cookTime')?.value.trim();
        const servings = document.getElementById('servings')?.value.trim();
        const summary = document.getElementById('summary')?.value.trim();
        const catSelect = document.getElementById('catId');
        const catText = catSelect && catSelect.selectedIndex >= 0 && catSelect.options[catSelect.selectedIndex].text !== '-- Pilih Kategori --'
            ? catSelect.options[catSelect.selectedIndex].text : 'Main Dish';


        // Title and Chips
        document.getElementById('previewTitle').textContent = title;
        document.getElementById('previewPrep').textContent = 'Prep: ' + (prep || '—');
        document.getElementById('previewCook').textContent = 'Cook: ' + (cook || '—');
        document.getElementById('previewServings').textContent = 'Porsi: ' + (servings || '—');
        document.getElementById('previewCategory').textContent = catText;
        document.getElementById('previewSummary').textContent = summary || 'Ringkasan belum diisi...';

        // Image Preview
        const previewImg = document.getElementById('previewImg');
        const previewNoImg = document.getElementById('previewNoImg');
        const customFileInput = document.getElementById('customImage');
        if (customFileInput && customFileInput.files.length > 0) {
            // keep custom image
        } else if (currentSelectedImageUrl) {
            previewImg.src = currentSelectedImageUrl;
            previewImg.style.display = 'block';
            previewNoImg.style.display = 'none';
        } else {
            previewImg.style.display = 'none';
            previewNoImg.style.display = 'block';
        }

        // Ingredients
        const ingRaw = document.getElementById('ingredientsRaw')?.value.trim() || '';
        const ingContainer = document.getElementById('previewIngredientsContainer');
        if (ingContainer) {
            let parsedIng = [];
            try {
                if (ingRaw.startsWith('[') || ingRaw.startsWith('{')) {
                    parsedIng = JSON.parse(ingRaw);
                }
            } catch(e) {}

            if (Array.isArray(parsedIng) && parsedIng.length > 0 && typeof parsedIng[0] === 'object' && parsedIng[0].items) {
                let html = '';
                parsedIng.forEach(g => {
                    const gName = g.group && g.group.toLowerCase() !== 'ingredients' ? g.group : '';
                    if (gName) {
                        html += '<div style="font-weight:600; font-size:12px; margin-top:6px; color:#475569;">' + escapeHtml(gName) + ':</div>';
                    }
                    html += '<ul class="preview-list">';
                    (g.items || []).forEach(item => {
                        html += '<li>' + escapeHtml(item) + '</li>';
                    });
                    html += '</ul>';
                });
                ingContainer.innerHTML = html;
            } else {
                const lines = ingRaw.split('\n').map(s => s.trim()).filter(s => s.length > 0);
                if (lines.length > 0) {
                    let html = '<ul class="preview-list">';
                    lines.forEach(l => {
                        html += '<li>' + escapeHtml(l.replace(/^[*\-•\d.]+\s*/, '')) + '</li>';
                    });
                    html += '</ul>';
                    ingContainer.innerHTML = html;
                } else {
                    ingContainer.innerHTML = '<ul class="preview-list"><li>Bahan belum diisi</li></ul>';
                }
            }
        }

        // Instructions
        const instRaw = document.getElementById('instructionsRaw')?.value.trim() || '';
        const instContainer = document.getElementById('previewInstructionsContainer');
        if (instContainer) {
            let parsedInst = [];
            try {
                if (instRaw.startsWith('[')) {
                    parsedInst = JSON.parse(instRaw);
                }
            } catch(e) {}

            const steps = (Array.isArray(parsedInst) && parsedInst.length > 0)
                ? parsedInst
                : instRaw.split('\n').map(s => s.trim()).filter(s => s.length > 0);

            if (steps.length > 0) {
                let html = '<ol class="preview-list">';
                steps.forEach(st => {
                    const cleanStep = typeof st === 'string' ? st : (st.text || JSON.stringify(st));
                    html += '<li>' + escapeHtml(cleanStep.replace(/^[\d.)\-]+\s*/, '')) + '</li>';
                });
                html += '</ol>';
                instContainer.innerHTML = html;
            } else {
                instContainer.innerHTML = '<ol class="preview-list"><li>Langkah belum diisi</li></ol>';
            }
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Parse ingredients from textarea safely to array structure
    function getParsedIngredients() {
        const raw = document.getElementById('ingredientsRaw').value.trim();
        try {
            if (raw.startsWith('[') || raw.startsWith('{')) {
                return JSON.parse(raw);
            }
        } catch(e) {}

        const lines = raw.split('\n').map(s => s.trim()).filter(s => s.length > 0);
        return [{ group: 'Ingredients', items: lines }];
    }

    // Parse instructions from textarea safely to string array
    function getParsedInstructions() {
        const raw = document.getElementById('instructionsRaw').value.trim();
        try {
            if (raw.startsWith('[')) {
                return JSON.parse(raw);
            }
        } catch(e) {}

        return raw.split('\n').map(s => s.trim()).filter(s => s.length > 0);
    }

    // Save Draft
    function handleSaveDraft() {
        const importId = document.getElementById('importId').value;
        if (!importId || importId === '0') {
            alert('Belum ada draf yang dimuat.');
            return;
        }

        const formData = new FormData();
        formData.append('ajax_action', 'save_draft');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', importId);
        formData.append('recipe_title', document.getElementById('recipeTitle').value);
        formData.append('prep_time', document.getElementById('prepTime').value);
        formData.append('cook_time', document.getElementById('cookTime').value);
        formData.append('servings', document.getElementById('servings').value);
        formData.append('summary', document.getElementById('summary').value);
        formData.append('cat_id', document.getElementById('catId').value);
        formData.append('selected_image_url', document.getElementById('selectedImageUrl').value);
        formData.append('ingredients', JSON.stringify(getParsedIngredients()));
        formData.append('instructions', JSON.stringify(getParsedInstructions()));

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            if (res.success) {
                alert('Draf berhasil disimpan!');
            } else {
                alert('Gagal menyimpan draf: ' + (res.error || res.message));
            }
        })
        .catch(err => alert('Kesalahan jaringan: ' + err.message));
    }

    // Reject Draft
    function handleReject() {
        const importId = document.getElementById('importId').value;
        if (!importId || importId === '0') return;

        const reason = prompt('Masukkan alasan penolakan resep ini (opsional):', 'Ditolak oleh admin');
        if (reason === null) return;

        const formData = new FormData();
        formData.append('ajax_action', 'reject_draft');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', importId);
        formData.append('reason', reason);

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            if (res.success) {
                alert('Resep ditandai sebagai Ditolak.');
                window.location.href = 'importer.php?tab=queue';
            } else {
                alert('Gagal: ' + res.error);
            }
        })
        .catch(err => alert('Kesalahan: ' + err.message));
    }

    function quickReject(id) {
        if (!confirm('Tolak draf resep #' + id + '?')) return;
        const formData = new FormData();
        formData.append('ajax_action', 'reject_draft');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', id);
        formData.append('reason', 'Ditolak dari antrean');

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            if (res.success) {
                location.reload();
            } else {
                alert('Gagal: ' + res.error);
            }
        })
        .catch(err => alert('Kesalahan: ' + err.message));
    }

    function quickRetry(id) {
        if (!confirm('Coba ulang ekstraksi untuk catatan #' + id + '?')) return;
        const formData = new FormData();
        formData.append('ajax_action', 'retry_import');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', id);

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            if (res.success) {
                alert('Berhasil diekstrak ulang!');
                window.location.href = 'importer.php?tab=import&id=' + res.import_id;
            } else {
                alert('Gagal mencoba ulang: ' + (res.error || res.message));
            }
        })
        .catch(err => alert('Kesalahan: ' + err.message));
    }

    // Publish Recipe
    function handlePublish(e) {
        e.preventDefault();
        const importId = document.getElementById('importId').value;
        if (!importId || importId === '0') {
            alert('Belum ada resep yang siap dipublikasikan.');
            return;
        }

        const catId = document.getElementById('catId').value;
        if (!catId) {
            alert('Kategori resep wajib dipilih.');
            document.getElementById('catId').focus();
            return;
        }

        const btn = document.getElementById('btnPublish');
        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">hourglass_empty</i> Mempublikasikan...';

        const formData = new FormData();
        formData.append('ajax_action', 'publish_recipe');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', importId);
        formData.append('rights_confirmed', '1');
        formData.append('recipe_title', document.getElementById('recipeTitle').value);
        formData.append('prep_time', document.getElementById('prepTime').value);
        formData.append('cook_time', document.getElementById('cookTime').value);
        formData.append('servings', document.getElementById('servings').value);
        formData.append('summary', document.getElementById('summary').value);
        formData.append('cat_id', catId);
        formData.append('selected_image_url', document.getElementById('selectedImageUrl').value);
        formData.append('ingredients', JSON.stringify(getParsedIngredients()));
        formData.append('instructions', JSON.stringify(getParsedInstructions()));

        const customFile = document.getElementById('customImage');
        if (customFile && customFile.files.length > 0) {
            formData.append('custom_image', customFile.files[0]);
        }

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = origText;
            if (res.success) {
                alert(res.message);
                window.location.href = 'recipes-detail.php?id=' + res.recipe_id;
            } else {
                alert('Gagal mempublikasikan: ' + (res.error || res.message));
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = origText;
            alert('Kesalahan jaringan atau server: ' + err.message);
        });
    }

    // Test Database Connection
    function handleTestDb() {
        const btn = document.getElementById('btnTestDb');
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">hourglass_empty</i> Memeriksa...';

        const formData = new FormData();
        formData.append('ajax_action', 'test_db');
        formData.append('csrf_token', CSRF_TOKEN);

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">speed</i> TES KONEKSI DATABASE';
            const el = document.getElementById('dbTestResult');
            el.style.display = 'block';

            if (res.success) {
                el.className = 'alert alert-success';
                el.innerHTML = '<strong>' + res.message + '</strong><br>' +
                    'Server: <code>' + escapeHtml(res.stats.server_info) + '</code> | ' +
                    'Tabel Resep: <b>' + res.stats.recipes_count + '</b> resep | ' +
                    'Tabel Kategori: <b>' + res.stats.categories_count + '</b> kategori | ' +
                    'Tabel Importer: <b>' + (res.stats.importer_table_ready ? 'Siap' : 'Perlu Migrasi') + '</b>';
            } else {
                el.className = 'alert alert-danger';
                el.innerHTML = '<strong>Uji koneksi gagal:</strong> ' + escapeHtml(res.message);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">speed</i> TES KONEKSI DATABASE';
            alert('Gagal menghubungi server: ' + err.message);
        });
    }

    // Test Upload Folder
    function handleTestUpload() {
        const btn = document.getElementById('btnTestUpload');
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">hourglass_empty</i> Memeriksa...';

        const formData = new FormData();
        formData.append('ajax_action', 'test_upload');
        formData.append('csrf_token', CSRF_TOKEN);

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">folder_special</i> TES FOLDER GAMBAR';
            const el = document.getElementById('uploadTestResult');
            el.style.display = 'block';

            if (res.success) {
                el.className = 'alert alert-success';
                el.innerHTML = '<strong>' + res.message + '</strong><br>' +
                    'Jalur: <code>' + escapeHtml(res.details.path) + '</code><br>' +
                    'Ruang bebas: <b>' + res.details.free_space + '</b> | Uji tulis &amp; hapus berkas sementara: <b>Lolos</b>';
            } else {
                el.className = 'alert alert-danger';
                el.innerHTML = '<strong>Uji folder gagal:</strong> ' + escapeHtml(res.message);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">folder_special</i> TES FOLDER GAMBAR';
            alert('Gagal: ' + err.message);
        });
    }

    // Run Migration
    function handleRunMigration() {
        const formData = new FormData();
        formData.append('ajax_action', 'run_migration');
        formData.append('csrf_token', CSRF_TOKEN);

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            alert(res.message);
            location.reload();
        })
        .catch(err => alert('Gagal: ' + err.message));
    }

    /* =========================================================================
     * AI MAGIC POLISH JAVASCRIPT HANDLERS (1-Single-Call)
     * ========================================================================= */
    let currentAiResult = null;

    function toggleAiPolishPanel() {
        const container = document.getElementById('aiPolishContainer');
        const textSpan = document.getElementById('toggleAiText');
        const btn = document.getElementById('btnToggleAiPanel');
        if (!container) return;
        if (container.style.display === 'none' || container.style.display === '') {
            container.style.display = 'block';
            if (textSpan) textSpan.innerText = 'Tutup Studio AI';
            if (btn) {
                btn.style.background = '#f1f5f9';
                btn.style.color = '#475569';
                btn.style.borderColor = '#cbd5e1';
            }
        } else {
            container.style.display = 'none';
            if (textSpan) textSpan.innerText = 'Buka Studio AI';
            if (btn) {
                btn.style.background = '#e0e7ff';
                btn.style.color = '#3730a3';
                btn.style.borderColor = '#c7d2fe';
            }
        }
    }

    function runAiPolish() {
        const title = document.getElementById('recipeTitle').value.trim();
        if (!title) {
            alert('Silakan ambil data resep atau isi judul resep terlebih dahulu.');
            return;
        }

        const btn = document.getElementById('btnRunAiPolish');
        const origBtnHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle;">hourglass_empty</i> Memproses...';

        const loadingBox = document.getElementById('aiPolishLoading');
        const resultsBox = document.getElementById('aiPolishResults');
        if (loadingBox) loadingBox.style.display = 'block';
        if (resultsBox) resultsBox.style.display = 'none';

        const formData = new FormData();
        formData.append('ajax_action', 'ai_enhance_draft');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('recipe_title', title);
        formData.append('prep_time', document.getElementById('prepTime').value);
        formData.append('cook_time', document.getElementById('cookTime').value);
        formData.append('servings', document.getElementById('servings').value);
        formData.append('summary', document.getElementById('summary').value);
        formData.append('ingredients', JSON.stringify(getParsedIngredients()));
        formData.append('instructions', JSON.stringify(getParsedInstructions()));
        formData.append('style_preset', document.getElementById('aiStylePreset').value);
        formData.append('aspect_ratio', document.getElementById('aiAspectRatio').value);
        formData.append('target_language', document.getElementById('aiLanguage')?.value || 'auto');
        formData.append('prompt_count', '1');

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            if (loadingBox) loadingBox.style.display = 'none';

            if (!res.success) {
                let errMsg = res.error || 'Terjadi kesalahan tidak diketahui.';
                if (res.cascade_trail && Array.isArray(res.cascade_trail) && res.cascade_trail.length > 0) {
                    errMsg += '\n\nJejak Rotasi Pool & Model:\n• ' + res.cascade_trail.join('\n• ');
                }
                alert('Gagal memproses AI Polish:\n\n' + errMsg);
                return;
            }

            currentAiResult = res.data;
            renderAiPolishResults(res.data, res.model_used, res.key_used);
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            if (loadingBox) loadingBox.style.display = 'none';
            alert('Kesalahan saat memproses AI Polish: ' + err.message);
        });
    }

    function renderAiPolishResults(data, modelUsed, keyUsed) {
        const resultsBox = document.getElementById('aiPolishResults');
        if (!resultsBox) return;

        // Render Title List
        const titleListEl = document.getElementById('aiTitleList');
        titleListEl.innerHTML = '';

        const primaryTitle = data.titles && data.titles.primary ? data.titles.primary : '';
        const altTitles = data.titles && Array.isArray(data.titles.alternatives) ? data.titles.alternatives : [];

        if (primaryTitle) {
            const primaryBtn = document.createElement('button');
            primaryBtn.type = 'button';
            primaryBtn.className = 'btn btn-block waves-effect ai-title-btn selected-title';
            primaryBtn.style.textAlign = 'left';
            primaryBtn.style.borderRadius = '6px';
            primaryBtn.style.padding = '8px 12px';
            primaryBtn.style.background = '#e0e7ff';
            primaryBtn.style.color = '#3730a3';
            primaryBtn.style.border = '2px solid #6366f1';
            primaryBtn.style.fontWeight = '600';
            primaryBtn.style.fontSize = '12px';
            primaryBtn.innerHTML = '<span class="badge" style="background:#4338ca; margin-right:6px;">UTAMA</span> ' + escapeHtml(primaryTitle);
            primaryBtn.onclick = function() { selectAiTitle(primaryTitle, this); };
            titleListEl.appendChild(primaryBtn);
        }

        altTitles.forEach((alt, idx) => {
            const altBtn = document.createElement('button');
            altBtn.type = 'button';
            altBtn.className = 'btn btn-block waves-effect ai-title-btn';
            altBtn.style.textAlign = 'left';
            altBtn.style.borderRadius = '6px';
            altBtn.style.padding = '8px 12px';
            altBtn.style.background = '#f8fafc';
            altBtn.style.color = '#1e293b';
            altBtn.style.border = '1px solid #cbd5e1';
            altBtn.style.fontWeight = '500';
            altBtn.style.fontSize = '12px';
            altBtn.innerHTML = '<span class="badge" style="background:#64748b; margin-right:6px;">OPSI ' + (idx + 1) + '</span> ' + escapeHtml(alt);
            altBtn.onclick = function() { selectAiTitle(alt, this); };
            titleListEl.appendChild(altBtn);
        });

        // Summary Preview
        const summaryEl = document.getElementById('aiSummaryPreview');
        if (summaryEl) {
            summaryEl.textContent = data.summary || '';
        }

        // Chef's Pro Tip
        const tipsEl = document.getElementById('aiTipsBadge');
        if (tipsEl) {
            const tip = data.pro_tip || data.airfryer_tips || '';
            if (tip) {
                tipsEl.style.display = 'block';
                tipsEl.innerHTML = '<strong>💡 Chef\'s Pro Tip:</strong> ' + escapeHtml(tip);
            } else {
                tipsEl.style.display = 'none';
            }
        }

        // Instructions Count
        const countEl = document.getElementById('aiInstructionsCount');
        if (countEl) {
            const stepCount = (data.instructions && Array.isArray(data.instructions)) ? data.instructions.length : 0;
            countEl.innerHTML = '<i class="material-icons" style="font-size:13px; vertical-align:middle;">done_all</i> ' + stepCount + ' langkah memasak terstruktur &amp; takaran bahan terjaga 100%. Model: <code>' + escapeHtml(modelUsed || 'Gemini') + '</code>';
        }

        // Prompt Preview
        const promptEl = document.getElementById('aiPromptPreview');
        if (promptEl) {
            const p = (data.image_prompts && data.image_prompts[0] && data.image_prompts[0].prompt) ? data.image_prompts[0].prompt : '';
            promptEl.value = p;
        }

        resultsBox.style.display = 'block';
        resultsBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function selectAiTitle(titleText, btnElement) {
        if (!titleText) return;
        document.getElementById('recipeTitle').value = titleText;
        syncPreview();

        const buttons = document.querySelectorAll('.ai-title-btn');
        buttons.forEach(b => {
            b.style.border = '1px solid #cbd5e1';
            b.style.background = '#f8fafc';
            b.style.color = '#1e293b';
            b.style.fontWeight = '500';
        });
        if (btnElement) {
            btnElement.style.border = '2px solid #6366f1';
            btnElement.style.background = '#e0e7ff';
            btnElement.style.color = '#3730a3';
            btnElement.style.fontWeight = '700';
        }
    }

    function applyAiTextToForm() {
        if (!currentAiResult) return;

        if (currentAiResult.titles && currentAiResult.titles.primary) {
            const currVal = document.getElementById('recipeTitle').value.trim();
            if (currVal === '' || !currentAiResult.titles.alternatives.includes(currVal)) {
                document.getElementById('recipeTitle').value = currentAiResult.titles.primary;
            }
        }

        if (currentAiResult.summary) {
            document.getElementById('summary').value = currentAiResult.summary;
        }

        if (currentAiResult.instructions && Array.isArray(currentAiResult.instructions)) {
            document.getElementById('instructionsRaw').value = JSON.stringify(currentAiResult.instructions, null, 2);
        }

        syncPreview();
        alert('Teks hasil AI (Judul, Summary & Instruksi) berhasil diterapkan ke form draf!');
    }

    function copyAiPrompt() {
        const promptEl = document.getElementById('aiPromptPreview');
        if (!promptEl || !promptEl.value) {
            alert('Belum ada prompt foto yang dibuat.');
            return;
        }
        promptEl.select();
        promptEl.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(promptEl.value).then(() => {
            alert('Prompt foto AI berhasil disalin ke clipboard!');
        }).catch(() => {
            document.execCommand('copy');
            alert('Prompt foto AI disalin ke clipboard!');
        });
    }

    // ==========================================
    // DIRECT G-LABS GENERATION & POLLING ENGINE
    // ==========================================
    let glabsDirectPollInterval = null;
    let lastGeneratedAiImage = null;

    function runDirectGlabsGeneration() {
        const promptEl = document.getElementById('aiPromptPreview');
        let promptText = promptEl ? promptEl.value.trim() : '';
        if (!promptText) {
            const title = document.getElementById('recipeTitle')?.value.trim();
            if (!title) {
                alert('Silakan isi judul resep atau jalankan AI Magic Polish terlebih dahulu untuk menghasilkan prompt foto.');
                return;
            }
            promptText = 'A high-end culinary food photograph of ' + title + ', 4:3 aspect ratio, professional studio lighting, 8k resolution, food magazine cover aesthetic.';
            if (promptEl) promptEl.value = promptText;
        }

        const importId = document.getElementById('importId')?.value || 0;
        const stylePreset = document.getElementById('aiStylePreset')?.value || 'Modern Fine Dining';
        const aspectRatio = document.getElementById('aiAspectRatio')?.value || '4:3';

        const btn = document.getElementById('btnGenerateGlabsDirect');
        const origBtnText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:15px; vertical-align:middle;">hourglass_empty</i> Mengirim Task...';

        const progressBox = document.getElementById('glabsDirectProgressBox');
        const resultBox = document.getElementById('glabsDirectResultBox');
        const progressBar = document.getElementById('glabsDirectProgressBar');
        const statusTitle = document.getElementById('glabsDirectStatusTitle');
        const statusDesc = document.getElementById('glabsDirectStatusDesc');
        const taskIdBadge = document.getElementById('glabsDirectTaskId');
        const timerEl = document.getElementById('glabsDirectTimer');

        if (resultBox) resultBox.style.display = 'none';
        if (progressBox) progressBox.style.display = 'block';
        if (progressBar) progressBar.style.width = '20%';
        if (statusTitle) statusTitle.textContent = 'Menyiapkan proses render foto...';
        if (statusDesc) statusDesc.textContent = 'Menghubungkan ke sistem AI...';
        if (taskIdBadge) taskIdBadge.textContent = 'Task #connecting';

        const formData = new FormData();
        formData.append('ajax_action', 'submit_glabs_task');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', importId);
        formData.append('prompt', promptText);
        formData.append('style_preset', stylePreset);
        formData.append('aspect_ratio', aspectRatio);

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            if (!res.success || !res.task_id) {
                btn.disabled = false;
                btn.innerHTML = origBtnText;
                if (progressBox) progressBox.style.display = 'none';
                alert('Gagal mengirim task ke sistem AI: ' + (res.error || 'Server tidak merespons'));
                return;
            }

            if (taskIdBadge) taskIdBadge.textContent = 'Task #' + res.task_id;
            startDirectGlabsPolling(res.task_id, res.generation_id, btn, origBtnText);
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = origBtnText;
            if (progressBox) progressBox.style.display = 'none';
            alert('Kesalahan koneksi ke server: ' + err.message);
        });
    }

    function startDirectGlabsPolling(taskId, genId, btnElement, origBtnText) {
        if (glabsDirectPollInterval) clearInterval(glabsDirectPollInterval);

        const progressBox = document.getElementById('glabsDirectProgressBox');
        const progressBar = document.getElementById('glabsDirectProgressBar');
        const statusTitle = document.getElementById('glabsDirectStatusTitle');
        const statusDesc = document.getElementById('glabsDirectStatusDesc');
        const timerEl = document.getElementById('glabsDirectTimer');

        let pollCount = 0;
        let seconds = 0;
        if (progressBar) progressBar.style.width = '35%';
        if (statusTitle) statusTitle.textContent = 'Task dalam antrean pemrosesan AI...';
        if (statusDesc) statusDesc.textContent = 'Menginisialisasi model Nano Banana...';

        glabsDirectPollInterval = setInterval(() => {
            pollCount++;
            seconds += 3;
            if (timerEl) timerEl.textContent = seconds + 's';

            const formData = new FormData();
            formData.append('ajax_action', 'poll_glabs_task');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('task_id', taskId);
            formData.append('generation_id', genId);

            safeFetchJson('importer.php', { method: 'POST', body: formData })
            .then(res => {
                if (res.status === 'running') {
                    if (progressBar) progressBar.style.width = '70%';
                    if (statusTitle) statusTitle.textContent = 'AI sedang me-render foto makanan (running)...';
                    if (statusDesc) statusDesc.textContent = 'Memproses detail tekstur masakan & pencahayaan...';
                } else if (res.status === 'completed') {
                    clearInterval(glabsDirectPollInterval);
                    if (progressBar) progressBar.style.width = '100%';
                    if (statusTitle) statusTitle.textContent = '✓ Foto selesai dibuat! Mengunduh & mengoptimasi...';
                    if (statusDesc) statusDesc.textContent = 'Menyelesaikan optimasi 1080px untuk aplikasi...';

                    setTimeout(() => {
                        if (progressBox) progressBox.style.display = 'none';
                        displayDirectGlabsResult(res);
                        if (btnElement) {
                            btnElement.disabled = false;
                            btnElement.innerHTML = '<i class="material-icons" style="font-size:15px; vertical-align:middle;">refresh</i> GENERATE VARIASI BARU';
                        }
                    }, 800);
                } else if (res.status === 'failed' || res.success === false) {
                    clearInterval(glabsDirectPollInterval);
                    if (progressBox) progressBox.style.display = 'none';
                    if (btnElement) {
                        btnElement.disabled = false;
                        btnElement.innerHTML = origBtnText;
                    }
                    alert('G-Labs gagal memproses task: ' + (res.error || 'Terjadi kesalahan'));
                }
            })
            .catch(err => {
                console.error('Poll error:', err);
                if (pollCount > 10) {
                    clearInterval(glabsDirectPollInterval);
                    if (progressBox) progressBox.style.display = 'none';
                    if (btnElement) {
                        btnElement.disabled = false;
                        btnElement.innerHTML = origBtnText;
                    }
                    alert('Gangguan koneksi saat memeriksa status task: ' + err.message);
                }
            });

            // Safeguard timeout (120 detik)
            if (pollCount > 40) {
                clearInterval(glabsDirectPollInterval);
                if (statusDesc) statusDesc.textContent = 'Waktu tunggu habis (timeout). Periksa tab riwayat AI Studio.';
                if (btnElement) {
                    btnElement.disabled = false;
                    btnElement.innerHTML = origBtnText;
                }
            }
        }, 3000);
    }

    function displayDirectGlabsResult(res) {
        lastGeneratedAiImage = res;
        const resultBox = document.getElementById('glabsDirectResultBox');
        const imgEl = document.getElementById('glabsDirectResultImg');
        const filenameEl = document.getElementById('glabsDirectFilenameText');
        const badgeEl = document.getElementById('glabsDirectMetaBadge');
        const noticeEl = document.getElementById('glabsDirectAppliedNotice');
        const btnApply = document.getElementById('btnApplyAiImageToRecipe');

        if (imgEl && res.image_url) imgEl.src = res.image_url;
        if (filenameEl) filenameEl.textContent = res.image_filename || 'ai_result.jpg';
        if (badgeEl) {
            const kb = res.file_size ? Math.round(res.file_size / 1024) + ' KB' : 'Optimized';
            badgeEl.textContent = '1080px • ' + kb;
        }
        if (noticeEl) noticeEl.style.display = 'none';
        if (btnApply) {
            btnApply.disabled = false;
            btnApply.className = 'btn btn-success waves-effect';
            btnApply.innerHTML = '<i class="material-icons" style="font-size:15px; vertical-align:middle;">star</i> GUNAKAN GAMBAR INI UNTUK RESEP';
        }

        if (resultBox) {
            resultBox.style.display = 'block';
            resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function applyAiImageToRecipeForm() {
        if (!lastGeneratedAiImage || !lastGeneratedAiImage.image_filename) {
            alert('Belum ada gambar AI yang siap diterapkan.');
            return;
        }

        const imgUrl = lastGeneratedAiImage.image_url || ('upload/' + lastGeneratedAiImage.image_filename);
        
        // 1. Update hidden input
        document.getElementById('selectedImageUrl').value = imgUrl;
        currentSelectedImageUrl = imgUrl;

        // 2. Update emulator phone preview
        syncPreview();

        // 3. Update candidates thumbnail wrap
        const candidateWrap = document.getElementById('imageCandidatesList');
        if (candidateWrap) {
            document.querySelectorAll('.image-candidate-thumb').forEach(el => el.classList.remove('selected'));
            let existingAiThumb = document.getElementById('aiThumbCandidate');
            if (!existingAiThumb) {
                existingAiThumb = document.createElement('img');
                existingAiThumb.id = 'aiThumbCandidate';
                existingAiThumb.className = 'image-candidate-thumb selected';
                existingAiThumb.title = 'Foto Hasil AI G-Labs (1080px)';
                existingAiThumb.onclick = function() {
                    document.querySelectorAll('.image-candidate-thumb').forEach(el => el.classList.remove('selected'));
                    existingAiThumb.classList.add('selected');
                    document.getElementById('selectedImageUrl').value = imgUrl;
                    currentSelectedImageUrl = imgUrl;
                    syncPreview();
                };
                candidateWrap.insertBefore(existingAiThumb, candidateWrap.firstChild);
            }
            existingAiThumb.src = imgUrl;
            existingAiThumb.classList.add('selected');
        }

        // 4. Update UI button and notice
        const btnApply = document.getElementById('btnApplyAiImageToRecipe');
        const noticeEl = document.getElementById('glabsDirectAppliedNotice');
        if (btnApply) {
            btnApply.innerHTML = '<i class="material-icons" style="font-size:15px; vertical-align:middle;">check</i> GAMBAR AI SUDAH DIGUNAKAN';
            btnApply.className = 'btn btn-success waves-effect disabled';
            btnApply.disabled = true;
        }
        if (noticeEl) noticeEl.style.display = 'block';

        // 5. Asynchronously persist to draft in database
        const importId = document.getElementById('importId')?.value || 0;
        if (importId > 0) {
            const formData = new FormData();
            formData.append('ajax_action', 'apply_ai_image_draft');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('import_id', importId);
            formData.append('image_filename', imgUrl);
            safeFetchJson('importer.php', { method: 'POST', body: formData }).catch(e => console.warn('Draft save notice:', e));
        }
    }

    function viewAiImageFull() {
        if (lastGeneratedAiImage && lastGeneratedAiImage.image_url) {
            window.open(lastGeneratedAiImage.image_url, '_blank');
        }
    }

    /* ==========================================================================
       BATCH ORCHESTRATOR JAVASCRIPT ENGINE
       ========================================================================== */
    const ALL_CATEGORIES = <?php echo safe_json_encode($categories); ?>;
    let batchResults = [];
    let isBatchRunning = false;

    function updateUrlCounter() {
        const textarea = document.getElementById('batchUrlsInput');
        if (!textarea) return;
        const lines = textarea.value.split('\n')
            .map(l => l.trim())
            .filter(l => l.length > 0 && (l.startsWith('http://') || l.startsWith('https://')));
        const counterEl = document.getElementById('urlCounterText');
        if (counterEl) {
            counterEl.innerHTML = '<strong>' + lines.length + '</strong> URL valid terdeteksi (Maks 50)';
            if (lines.length > 50) {
                counterEl.innerHTML += ' <span style="color:#DC2626; font-weight:700;">(Melebihi batas 50 URL)</span>';
            }
        }
    }

    function loadSampleCookpadUrls() {
        const textarea = document.getElementById('batchUrlsInput');
        if (!textarea) return;
        textarea.value = "https://cookpad.com/id/resep/26787228\nhttps://cookpad.com/id/resep/26787110\nhttps://cookpad.com/id/resep/26786995";
        updateUrlCounter();
        logTerminal('[Contoh] 3 URL resep Cookpad Indonesia berhasil dimuat.', 'info');
    }

    function loadSampleUrls() {
        const textarea = document.getElementById('batchUrlsInput');
        if (!textarea) return;
        textarea.value = "https://www.bbc.co.uk/food/recipes/air_fryer_chicken_thighs\nhttps://www.bbc.co.uk/food/recipes/crispy_air_fryer_chips\nhttps://www.bbc.co.uk/food/recipes/air_fryer_salmon";
        updateUrlCounter();
        logTerminal('[Contoh] 3 URL resep BBC Food berhasil dimuat.', 'info');
    }

    function logTerminal(message, type = 'info') {
        const box = document.getElementById('batchTerminalBox');
        if (!box) return;
        const line = document.createElement('div');
        line.className = 'terminal-line ' + type;
        const now = new Date();
        const timeStr = now.toTimeString().split(' ')[0];
        line.textContent = '[' + timeStr + '] ' + message;
        box.appendChild(line);
        box.scrollTop = box.scrollHeight;
    }

    function setStepPill(activeStep) {
        for (let i = 1; i <= 4; i++) {
            const pill = document.getElementById('stepPill' + i);
            if (!pill) continue;
            pill.className = 'step-item-pill';
            if (i < activeStep) {
                pill.classList.add('done');
            } else if (i === activeStep) {
                pill.classList.add('active');
            }
        }
    }

    async function startBatchOrchestrator() {
        if (isBatchRunning) return;

        const textarea = document.getElementById('batchUrlsInput');
        if (!textarea) return;

        const rawUrls = textarea.value.split('\n')
            .map(l => l.trim())
            .filter(l => l.length > 0);

        const validUrls = rawUrls.filter(l => l.startsWith('http://') || l.startsWith('https://'));

        if (validUrls.length === 0) {
            alert('Silakan masukkan minimal 1 URL resep yang valid (diawali http:// atau https://).');
            return;
        }

        if (validUrls.length > 50) {
            alert('Maksimal URL yang dapat diproses dalam satu batch adalah 50 resep.');
            return;
        }

        isBatchRunning = true;
        const btnStart = document.getElementById('btnStartBatch');
        btnStart.disabled = true;
        btnStart.innerHTML = '<i class="material-icons" style="font-size:18px;">autorenew</i> <span>MEMPROSES BATCH...</span>';
        textarea.disabled = true;

        const progressContainer = document.getElementById('batchProgressContainer');
        const reviewCard = document.getElementById('batchReviewCard');
        const progressBarFill = document.getElementById('batchProgressBarFill');
        const statusText = document.getElementById('batchProgressStatusText');
        const percentText = document.getElementById('batchProgressPercent');
        const tableBody = document.getElementById('batchReviewTableBody');

        if (progressContainer) progressContainer.style.display = 'block';
        if (reviewCard) reviewCard.style.display = 'block';

        const defaultCatId = document.getElementById('batchDefaultCatId')?.value || '0';
        const batchLanguage = document.getElementById('batchLanguage')?.value || 'auto';
        const stylePreset = document.getElementById('batchStylePreset')?.value || 'Modern Fine Dining';
        const aspectRatio = document.getElementById('batchAspectRatio')?.value || '4:3';
        const productCode = document.getElementById('batchProductCode')?.value || 'airfry';
        const batchDelayMode = document.getElementById('batchDelayMode')?.value || 'random_2_5';

        logTerminal('Memulai Batch Orchestrator untuk ' + validUrls.length + ' URL...', 'info');

        let successCount = 0;
        let failCount = 0;

        for (let i = 0; i < validUrls.length; i++) {
            const currentUrl = validUrls[i];
            const currentIndex = i + 1;
            const pct = Math.round((i / validUrls.length) * 100);

            if (progressBarFill) progressBarFill.style.width = pct + '%';
            if (percentText) percentText.textContent = pct + '%';
            if (statusText) statusText.textContent = 'Memproses resep #' + currentIndex + ' dari ' + validUrls.length + '...';

            logTerminal('(' + currentIndex + '/' + validUrls.length + ') Mengambil data resep: ' + currentUrl, 'info');
            setStepPill(1); // Scrape

            try {
                // Step 1 & 2: Scrape & AI Polish (Gemini) dengan Gaya Foto AI dan Rasio terintegrasi
                setStepPill(2); // AI Polish
                const formData = new FormData();
                formData.append('ajax_action', 'orchestrate_scrape_and_polish');
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('url', currentUrl);
                formData.append('default_cat_id', defaultCatId);
                formData.append('style_preset', stylePreset);
                formData.append('aspect_ratio', aspectRatio);
                formData.append('target_language', batchLanguage);
                formData.append('product_code', productCode);

                const polishRes = await safeFetchJson('importer.php', { method: 'POST', body: formData });
                if (!polishRes || !polishRes.success) {
                    throw new Error(polishRes?.error || 'Gagal mengambil atau memoles data resep.');
                }

                logTerminal('✓ Draf dipoles Gemini: "' + polishRes.recipe_title + '"', 'success');
                logTerminal('  Prompt Visual AI: ' + polishRes.image_prompt, 'info');

                // Step 3: Photo Studio AI (Murni G-Labs Asinkron Polling)
                setStepPill(3); // Photo Studio
                logTerminal('⚡ Mengirim task foto masakan ke G-Labs (Rasio: ' + aspectRatio + ', Gaya: ' + stylePreset + ')...', 'info');

                const glabsForm = new FormData();
                glabsForm.append('ajax_action', 'submit_glabs_task');
                glabsForm.append('csrf_token', CSRF_TOKEN);
                glabsForm.append('import_id', polishRes.import_id);
                glabsForm.append('prompt', polishRes.image_prompt);
                glabsForm.append('style_preset', stylePreset);
                glabsForm.append('aspect_ratio', aspectRatio);
                glabsForm.append('model', ''); // Default G-Labs model

                const submitRes = await safeFetchJson('importer.php', { method: 'POST', body: glabsForm });
                let finalImageUrl = polishRes.image_url || '';
                let finalImageEngine = 'Original';

                if (submitRes && submitRes.success && submitRes.task_id) {
                    const taskId = submitRes.task_id;
                    const genId = submitRes.generation_id || 0;
                    logTerminal('  Task ID: ' + taskId + ' terdaftar. Menunggu antrean render G-Labs...', 'info');

                    // Polling berkala ke G-Labs tiap 3 detik (maksimal 40x / 120 detik)
                    let pollCount = 0;
                    let pollSuccess = false;
                    let seconds = 0;

                    while (pollCount < 40 && !pollSuccess) {
                        await new Promise(r => setTimeout(r, 3000));
                        pollCount++;
                        seconds += 3;

                        const pollForm = new FormData();
                        pollForm.append('ajax_action', 'poll_glabs_task');
                        pollForm.append('csrf_token', CSRF_TOKEN);
                        pollForm.append('task_id', taskId);
                        pollForm.append('generation_id', genId);

                        try {
                            const pollRes = await safeFetchJson('importer.php', { method: 'POST', body: pollForm });
                            if (pollRes && pollRes.status === 'completed' && pollRes.image_filename) {
                                pollSuccess = true;
                                finalImageUrl = 'upload/' + pollRes.image_filename;
                                finalImageEngine = 'G-Labs (nano_banana_2)';
                                logTerminal('✓ Foto AI G-Labs selesai (' + seconds + 's): ' + pollRes.image_filename, 'success');

                                // Pasang foto ke draf resep
                                const applyForm = new FormData();
                                applyForm.append('ajax_action', 'apply_ai_image_draft');
                                applyForm.append('csrf_token', CSRF_TOKEN);
                                applyForm.append('import_id', polishRes.import_id);
                                applyForm.append('image_filename', pollRes.image_filename);
                                await safeFetchJson('importer.php', { method: 'POST', body: applyForm });
                                break;
                            } else if (pollRes && pollRes.status === 'failed') {
                                logTerminal('✕ G-Labs gagal me-render: ' + (pollRes.error || 'Unknown error'), 'error');
                                break;
                            } else {
                                // Masih running / queued
                                if (statusText) statusText.textContent = 'G-Labs me-render foto resep #' + currentIndex + ' (' + seconds + 's)...';
                                if (seconds % 6 === 0) {
                                    logTerminal('  ⏳ G-Labs sedang me-render foto masakan (' + seconds + 's)...', 'info');
                                }
                            }
                        } catch (pollErr) {
                            console.warn('G-Labs poll retry:', pollErr);
                        }
                    }

                    if (!pollSuccess && pollCount >= 40) {
                        logTerminal('⚠️ G-Labs timeout (120s), menggunakan foto draf awal.', 'warning');
                    }
                } else {
                    logTerminal('⚠️ G-Labs tidak merespons: ' + (submitRes?.error || 'Koneksi gagal') + ', memakai foto asli.', 'warning');
                }

                polishRes.image_url = finalImageUrl;
                polishRes.image_engine = finalImageEngine;

                successCount++;
                batchResults.push(polishRes);
                logTerminal('✓ Resep #' + currentIndex + ' berhasil diorkestrasi lengkap ("' + polishRes.recipe_title + '")!', 'success');
                renderBatchTableRow(polishRes);
                updateBatchCounterBadge();

            } catch (err) {
                failCount++;
                logTerminal('✕ Resep #' + currentIndex + ' error: ' + err.message, 'error');
                renderBatchErrorRow(currentUrl, err.message);
            }

            setStepPill(4); // Selesai 1 item
            
            // Jeda antar-antrean (Human-like random delay untuk anti-bot / anti-scraping ban)
            if (i < validUrls.length - 1) {
                let delayMs = 600;
                if (batchDelayMode === 'random_2_5') {
                    delayMs = Math.floor(Math.random() * (5000 - 2000 + 1)) + 2000;
                } else if (batchDelayMode === 'random_5_10') {
                    delayMs = Math.floor(Math.random() * (10000 - 5000 + 1)) + 5000;
                } else if (batchDelayMode === 'fixed_1') {
                    delayMs = 1000;
                } else if (batchDelayMode === 'none') {
                    delayMs = 600;
                }
                const delaySec = (delayMs / 1000).toFixed(1);
                logTerminal('⏳ [Jeda Anti-Bot] Menunggu ' + delaySec + ' detik sebelum memproses resep berikutnya...', 'info');
                await new Promise(r => setTimeout(r, delayMs));
            } else {
                await new Promise(r => setTimeout(r, 600));
            }
        }

        // Selesai seluruh antrean
        if (progressBarFill) progressBarFill.style.width = '100%';
        if (percentText) percentText.textContent = '100%';
        if (statusText) statusText.textContent = 'Orkestrasi batch selesai! (' + successCount + ' berhasil, ' + failCount + ' gagal)';

        logTerminal('====================================================', 'info');
        logTerminal('[Selesai] ' + successCount + ' resep berhasil diorkestrasi dan siap untuk dipublikasikan.', 'success');

        isBatchRunning = false;
        btnStart.disabled = false;
        btnStart.innerHTML = '<i class="material-icons" style="font-size:18px;">bolt</i> <span>JALANKAN BATCH ORCHESTRATOR</span>';
        textarea.disabled = false;

        updateFloatingBatchBar();
    }

    function renderBatchTableRow(item) {
        const tbody = document.getElementById('batchReviewTableBody');
        if (!tbody) return;

        const row = document.createElement('tr');
        row.id = 'batchRow_' + item.import_id;

        const thumbSrc = item.image_url || 'assets/images/no-image.png';

        // Options kategori
        let catOptionsHtml = '<option value="0">-- Pilih Kategori --</option>';
        ALL_CATEGORIES.forEach(c => {
            const isSel = (parseInt(c.cid) === parseInt(item.cat_id)) ? 'selected' : '';
            catOptionsHtml += '<option value="' + c.cid + '" ' + isSel + '>' + escapeHtml(c.category_name) + '</option>';
        });

        const timeInfo = (item.cook_time ? item.cook_time : '-') + ' | ' + (item.servings ? item.servings + ' porsi' : '-');

        row.innerHTML = `
            <td style="text-align:center;">
                <input type="checkbox" name="batchRowSelect" id="checkBatch_${item.import_id}" value="${item.import_id}" checked onchange="updateFloatingBatchBar();">
                <label for="checkBatch_${item.import_id}" style="margin:0; padding:0; height:18px;"></label>
            </td>
            <td style="text-align:center;">
                <img src="${thumbSrc}" class="batch-thumb-img" alt="Recipe Thumb" onclick="window.open('${thumbSrc}', '_blank');" title="Klik untuk lihat foto resolusi penuh">
            </td>
            <td>
                <div style="font-weight:700; color:#1C1917; font-size:13px; line-height:1.4; margin-bottom:2px;" id="titleText_${item.import_id}">
                    ${escapeHtml(item.recipe_title)}
                </div>
                <div style="font-size:11px; color:#78716C;">
                    <a href="${escapeHtml(item.source_url)}" target="_blank" style="color:#78716C; text-decoration:none;">
                        <i class="material-icons" style="font-size:11px; vertical-align:middle;">open_in_new</i> Sumber Web Asal
                    </a>
                </div>
            </td>
            <td>
                <select class="inline-category-select" id="inlineCat_${item.import_id}" onchange="updateBatchCategoryInline(${item.import_id}, this);">
                    ${catOptionsHtml}
                </select>
            </td>
            <td style="font-size:11.5px; color:#44403C;">
                <i class="material-icons" style="font-size:13px; vertical-align:middle; color:#C2410C;">timer</i> ${escapeHtml(timeInfo)}
            </td>
            <td style="text-align:center;" id="statusTd_${item.import_id}">
                <span class="label" style="background:#FFF1EB; color:#C2410C; font-weight:700; border:1px solid #FED7AA; font-size:10px; border-radius:4px; padding:3px 6px;">
                    Siap Publish
                </span>
            </td>
            <td style="text-align:center;">
                <div style="display:flex; gap:4px; justify-content:center;">
                    <button type="button" class="btn btn-default btn-xs waves-effect" onclick="openDraftPreviewModal(${item.import_id});" title="Pratinjau / Edit Draf Lengkap" style="border-radius:4px;">
                        <i class="material-icons" style="font-size:14px; vertical-align:middle; color:#C2410C;">edit_note</i> Edit
                    </button>
                    <button type="button" class="btn btn-default btn-xs waves-effect" onclick="removeBatchRow(${item.import_id});" title="Hapus dari antrean" style="border-radius:4px;">
                        <i class="material-icons" style="font-size:14px; vertical-align:middle; color:#DC2626;">delete</i>
                    </button>
                </div>
            </td>
        `;

        tbody.appendChild(row);
    }

    function renderBatchErrorRow(url, errorMsg) {
        const tbody = document.getElementById('batchReviewTableBody');
        if (!tbody) return;

        const row = document.createElement('tr');
        row.style.background = '#FEF2F2';
        row.innerHTML = `
            <td style="text-align:center;"><i class="material-icons" style="color:#DC2626; font-size:16px;">error</i></td>
            <td style="text-align:center;">-</td>
            <td colspan="3">
                <div style="font-weight:700; color:#DC2626; font-size:12px;">Gagal Diproses</div>
                <div style="font-size:11px; color:#78716C;">${escapeHtml(url)}</div>
                <div style="font-size:11.5px; color:#991B1B; margin-top:2px;">${escapeHtml(errorMsg)}</div>
            </td>
            <td style="text-align:center;">
                <span class="label label-danger">Gagal</span>
            </td>
            <td style="text-align:center;">
                <button type="button" class="btn btn-default btn-xs" onclick="this.closest('tr').remove();" style="border-radius:4px;">
                    Hapus
                </button>
            </td>
        `;
        tbody.appendChild(row);
    }

    function updateBatchCategoryInline(importId, selectEl) {
        const catId = selectEl.value;
        const origBg = selectEl.style.backgroundColor;
        selectEl.style.backgroundColor = '#FEF3C7'; // Yellow loading

        const formData = new FormData();
        formData.append('ajax_action', 'update_draft_detail');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', importId);
        formData.append('cat_id', catId);

        // Cari item di batchResults untuk menjaga data lain tetap utuh
        const item = batchResults.find(b => parseInt(b.import_id) === parseInt(importId));
        if (item) {
            formData.append('recipe_title', item.recipe_title || '');
            formData.append('prep_time', item.prep_time || '');
            formData.append('cook_time', item.cook_time || '');
            formData.append('servings', item.servings || '');
            formData.append('summary', item.summary || '');
            formData.append('selected_image_url', item.selected_image_url || '');
            formData.append('ingredients', JSON.stringify(item.ingredients || []));
            formData.append('instructions', JSON.stringify(item.instructions || []));
        }

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            if (res && res.success) {
                selectEl.style.backgroundColor = '#DCFCE7'; // Green success
                if (item) item.cat_id = catId;
                setTimeout(() => { selectEl.style.backgroundColor = ''; }, 1200);
            } else {
                selectEl.style.backgroundColor = '#FEE2E2'; // Red error
                alert('Gagal memperbarui kategori: ' + (res.error || 'Terjadi kesalahan'));
            }
        })
        .catch(err => {
            selectEl.style.backgroundColor = '#FEE2E2';
            alert('Kesalahan jaringan: ' + err.message);
        });
    }

    function updateBatchCounterBadge() {
        const badge = document.getElementById('batchTableCountBadge');
        if (badge) {
            badge.textContent = batchResults.length + ' Resep Siap';
        }
        updateFloatingBatchBar();
    }

    function toggleSelectAllBatch(masterCheckbox) {
        const checkboxes = document.querySelectorAll('input[name="batchRowSelect"]');
        checkboxes.forEach(cb => { cb.checked = masterCheckbox.checked; });
        updateFloatingBatchBar();
    }

    function updateFloatingBatchBar() {
        const bar = document.getElementById('floatingBatchBar');
        const countText = document.getElementById('selectedBatchCountText');
        const btnPublish = document.getElementById('btnPublishSelected');
        if (!bar) return;

        const selected = document.querySelectorAll('input[name="batchRowSelect"]:checked');
        if (selected.length > 0) {
            bar.style.display = 'flex';
            if (countText) countText.textContent = selected.length + ' Resep Terpilih';
            if (btnPublish) btnPublish.disabled = false;
        } else {
            bar.style.display = 'none';
        }
    }

    function removeBatchRow(importId) {
        if (!confirm('Hapus draf resep ini dari antrean review?')) return;
        const row = document.getElementById('batchRow_' + importId);
        if (row) row.remove();
        batchResults = batchResults.filter(b => parseInt(b.import_id) !== parseInt(importId));
        updateBatchCounterBadge();
    }

    function openDraftPreviewModal(importId) {
        const item = batchResults.find(b => parseInt(b.import_id) === parseInt(importId));
        if (!item) {
            alert('Data draf tidak ditemukan.');
            return;
        }

        document.getElementById('modalImportId').value = item.import_id;
        document.getElementById('modalRecipeTitle').value = item.recipe_title || '';
        document.getElementById('modalCatId').value = item.cat_id || '0';
        document.getElementById('modalPrepTime').value = item.prep_time || '';
        document.getElementById('modalCookTime').value = item.cook_time || '';
        document.getElementById('modalServings').value = item.servings || '';
        document.getElementById('modalSummary').value = item.summary || '';
        document.getElementById('modalSelectedImageUrl').value = item.selected_image_url || '';

        const previewImg = document.getElementById('modalPreviewImg');
        if (previewImg) {
            previewImg.src = item.image_url || 'assets/images/no-image.png';
        }

        const promptBox = document.getElementById('modalPhotoPromptText');
        if (promptBox) {
            promptBox.textContent = item.image_prompt || 'Commercial food photography of ' + item.recipe_title;
        }

        // Ingredients
        let ingText = '';
        if (Array.isArray(item.ingredients)) {
            ingText = item.ingredients.map(i => typeof i === 'string' ? i : (i.name || JSON.stringify(i))).join('\n');
        } else if (typeof item.ingredients === 'string') {
            ingText = item.ingredients;
        }
        document.getElementById('modalIngredients').value = ingText;

        // Instructions
        let instText = '';
        if (Array.isArray(item.instructions)) {
            instText = item.instructions.map(i => typeof i === 'string' ? i : (i.text || JSON.stringify(i))).join('\n');
        } else if (typeof item.instructions === 'string') {
            instText = item.instructions;
        }
        document.getElementById('modalInstructions').value = instText;

        $('#recipePreviewModal').modal('show');
    }

    function saveDraftFromModal() {
        const importId = document.getElementById('modalImportId').value;
        const btnSave = document.getElementById('btnSaveModalDraft');
        if (!importId) return;

        const title = document.getElementById('modalRecipeTitle').value.trim();
        const catId = document.getElementById('modalCatId').value;
        if (!title) {
            alert('Judul resep tidak boleh kosong.');
            return;
        }

        btnSave.disabled = true;
        btnSave.innerHTML = '<i class="material-icons" style="font-size:15px; vertical-align:middle;">hourglass_empty</i> Menyimpan...';

        const ingredients = document.getElementById('modalIngredients').value.split('\n').map(s => s.trim()).filter(s => s.length > 0);
        const instructions = document.getElementById('modalInstructions').value.split('\n').map(s => s.trim()).filter(s => s.length > 0);

        const formData = new FormData();
        formData.append('ajax_action', 'update_draft_detail');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('import_id', importId);
        formData.append('recipe_title', title);
        formData.append('cat_id', catId);
        formData.append('prep_time', document.getElementById('modalPrepTime').value.trim());
        formData.append('cook_time', document.getElementById('modalCookTime').value.trim());
        formData.append('servings', document.getElementById('modalServings').value.trim());
        formData.append('summary', document.getElementById('modalSummary').value.trim());
        formData.append('selected_image_url', document.getElementById('modalSelectedImageUrl').value.trim());
        formData.append('ingredients', JSON.stringify(ingredients));
        formData.append('instructions', JSON.stringify(instructions));

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            btnSave.disabled = false;
            btnSave.innerHTML = '<i class="material-icons" style="font-size:15px; vertical-align:middle;">save</i> Simpan Perubahan Draf';

            if (res && res.success) {
                // Update batchResults
                const item = batchResults.find(b => parseInt(b.import_id) === parseInt(importId));
                if (item) {
                    item.recipe_title = title;
                    item.cat_id = catId;
                    item.summary = document.getElementById('modalSummary').value.trim();
                    item.prep_time = document.getElementById('modalPrepTime').value.trim();
                    item.cook_time = document.getElementById('modalCookTime').value.trim();
                    item.servings = document.getElementById('modalServings').value.trim();
                    item.ingredients = ingredients;
                    item.instructions = instructions;
                }

                // Update text di tabel review
                const titleEl = document.getElementById('titleText_' + importId);
                if (titleEl) titleEl.textContent = title;
                const inlineCat = document.getElementById('inlineCat_' + importId);
                if (inlineCat) inlineCat.value = catId;

                $('#recipePreviewModal').modal('hide');
                logTerminal('✓ Draf #' + importId + ' berhasil diperbarui dari Modal Preview.', 'success');
            } else {
                alert('Gagal menyimpan draf: ' + (res.error || 'Terjadi kesalahan'));
            }
        })
        .catch(err => {
            btnSave.disabled = false;
            btnSave.innerHTML = '<i class="material-icons" style="font-size:15px; vertical-align:middle;">save</i> Simpan Perubahan Draf';
            alert('Kesalahan jaringan: ' + err.message);
        });
    }

    function quickAddCategoryPrompt(targetSelectId) {
        const catName = prompt('Masukkan nama kategori masakan baru:');
        if (!catName || !catName.trim()) return;

        const formData = new FormData();
        formData.append('ajax_action', 'quick_add_category');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('category_name', catName.trim());
        formData.append('product_code', document.getElementById('batchProductCode')?.value || 'airfry');

        safeFetchJson('importer.php', { method: 'POST', body: formData })
        .then(res => {
            if (res && res.success) {
                const newCid = res.cid;
                const newName = res.category_name;

                // Tambah ke ALL_CATEGORIES
                if (!ALL_CATEGORIES.some(c => parseInt(c.cid) === parseInt(newCid))) {
                    ALL_CATEGORIES.push({ cid: newCid, category_name: newName });
                }

                // Tambahkan option ke semua select kategori
                const selects = document.querySelectorAll('select.inline-category-select, #batchDefaultCatId, #modalCatId');
                selects.forEach(sel => {
                    if (!sel.querySelector('option[value="' + newCid + '"]')) {
                        const opt = document.createElement('option');
                        opt.value = newCid;
                        opt.textContent = newName;
                        sel.appendChild(opt);
                    }
                });

                // Pilih di target select
                if (targetSelectId) {
                    const targetEl = document.getElementById(targetSelectId);
                    if (targetEl) targetEl.value = newCid;
                }

                logTerminal('✓ Kategori baru "' + newName + '" (ID #' + newCid + ') berhasil dibuat.', 'success');
            } else {
                alert('Gagal menambahkan kategori: ' + (res.error || 'Kesalahan'));
            }
        })
        .catch(err => alert('Kesalahan jaringan: ' + err.message));
    }

    async function publishAllSelectedBatch() {
        const selectedCheckboxes = document.querySelectorAll('input[name="batchRowSelect"]:checked');
        if (selectedCheckboxes.length === 0) {
            alert('Pilih minimal satu draf resep untuk diterbitkan.');
            return;
        }

        const draftIds = Array.from(selectedCheckboxes).map(cb => parseInt(cb.value));
        const autoPush = document.getElementById('batchAutoPushCheckbox')?.checked ? 1 : 0;

        const confirmMsg = 'Terbitkan ' + draftIds.length + ' resep terpilih ke database utama Recipe Studio?' +
            (autoPush ? '\n\n* Opsi Auto-Push AKTIF: Resep akan otomatis disinkronisasikan ke Live Hosting / Android App.' : '');

        if (!confirm(confirmMsg)) return;

        const btnPublish = document.getElementById('btnPublishSelected');
        btnPublish.disabled = true;
        btnPublish.innerHTML = '<i class="material-icons" style="font-size:18px;">autorenew</i> <span>MENERBITKAN RESEP...</span>';

        logTerminal('Menerbitkan ' + draftIds.length + ' resep terpilih ke database utama...', 'info');

        const formData = new FormData();
        formData.append('ajax_action', 'bulk_publish_drafts');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('draft_ids', JSON.stringify(draftIds));
        formData.append('auto_push', autoPush);

        try {
            const res = await safeFetchJson('importer.php', { method: 'POST', body: formData });
            btnPublish.disabled = false;
            btnPublish.innerHTML = '<i class="material-icons" style="font-size:18px;">publish</i> <span>TERBITKAN SEMUA YANG DIPILIH</span>';

            if (res && res.success) {
                logTerminal('✓ ' + res.message, 'success');
                if (res.published_list && Array.isArray(res.published_list)) {
                    res.published_list.forEach(p => {
                        const statusTd = document.getElementById('statusTd_' + p.import_id);
                        if (statusTd) {
                            statusTd.innerHTML = '<span class="label label-success" style="font-weight:700;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">done</i> Diterbitkan (#' + p.recipe_id + ')</span>';
                        }
                        const cb = document.getElementById('checkBatch_' + p.import_id);
                        if (cb) {
                            cb.checked = false;
                            cb.disabled = true;
                        }
                    });
                }
                updateFloatingBatchBar();
                alert('✓ Sukses! ' + res.published_count + ' resep berhasil diterbitkan ke database utama Recipe Studio!\n\nAnda dapat melihatnya di menu Manage Recipes.');
            } else {
                alert('Gagal menerbitkan resep: ' + (res.error || (res.errors ? res.errors.join('\n') : 'Terjadi kesalahan')));
                logTerminal('✕ Gagal menerbitkan: ' + (res.error || 'Terjadi kesalahan'), 'error');
            }
        } catch (err) {
            btnPublish.disabled = false;
            btnPublish.innerHTML = '<i class="material-icons" style="font-size:18px;">publish</i> <span>TERBITKAN SEMUA YANG DIPILIH</span>';
            alert('Kesalahan jaringan: ' + err.message);
            logTerminal('✕ Kesalahan jaringan saat menerbitkan: ' + err.message, 'error');
        }
    }
</script>

<?php include_once('includes/footer.php'); ?>
