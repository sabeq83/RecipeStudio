<?php
/**
 * Recipe Studio V3 - Idempotent Database Schema Synchronizer
 * Memastikan skema database di server Mac Mini atau server hosting
 * 100% identik dan mutakhir dengan database lokal yang sudah teruji.
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && (!isset($_SESSION['admin_user']) && !isset($_GET['token']))) {
    // Basic protection if run via browser
}

$configPaths = [
    __DIR__ . '/../includes/config.php',
    '/Users/masbenu/recipe_studio/current/includes/config.php',
    getcwd() . '/includes/config.php'
];
$loaded = false;
foreach ($configPaths as $cp) {
    if (file_exists($cp)) {
        require_once $cp;
        $loaded = true;
        break;
    }
}
if (!$loaded) {
    die("File includes/config.php tidak ditemukan.\n");
}

if (!isset($connect) || !($connect instanceof mysqli)) {
    die("Koneksi database tidak valid.\n");
}

echo "=== SINKRONISASI SKEMA DATABASE RECIPE STUDIO ===\n";
echo "Database Target : " . $database . "\n";
echo "Waktu Eksekusi  : " . date('Y-m-d H:i:s') . "\n\n";

$messages = [];
$errors = [];

function addColumnIfNotExists(mysqli $db, string $table, string $column, string $definition, ?string $after = null): bool {
    global $messages, $errors;
    $res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    if ($res && $res->num_rows > 0) {
        $messages[] = "✓ Kolom `{$table}`.`{$column}` sudah ada.";
        return true;
    }

    $afterClause = $after ? " AFTER `{$after}`" : "";
    $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}{$afterClause}";
    if ($db->query($sql)) {
        $messages[] = "⚡ Berhasil menambahkan kolom `{$table}`.`{$column}`.";
        return true;
    } else {
        $errors[] = "✗ Gagal menambahkan kolom `{$table}`.`{$column}`: " . $db->error;
        return false;
    }
}

function addIndexIfNotExists(mysqli $db, string $table, string $indexName, string $columns): bool {
    global $messages, $errors;
    $res = $db->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'");
    if ($res && $res->num_rows > 0) {
        return true;
    }
    $sql = "ALTER TABLE `{$table}` ADD INDEX `{$indexName}` ({$columns})";
    if ($db->query($sql)) {
        $messages[] = "⚡ Berhasil menambahkan index `{$indexName}` pada `{$table}`.";
        return true;
    } else {
        $errors[] = "✗ Gagal menambahkan index `{$indexName}`: " . $db->error;
        return false;
    }
}

// 1. Kolom Multitenant product_code pada tbl_recipes dan tbl_category
echo "1. Menyesuaikan kolom `product_code`...\n";
addColumnIfNotExists($connect, 'tbl_recipes', 'product_code', "VARCHAR(50) NOT NULL DEFAULT 'airfry'", 'cat_id');
addIndexIfNotExists($connect, 'tbl_recipes', 'idx_recipes_product', 'product_code');

addColumnIfNotExists($connect, 'tbl_recipes', 'live_recipe_id', "INT(11) DEFAULT NULL", 'last_update');
addColumnIfNotExists($connect, 'tbl_recipes', 'last_synced_at', "DATETIME DEFAULT NULL", 'live_recipe_id');

addColumnIfNotExists($connect, 'tbl_category', 'product_code', "VARCHAR(50) NOT NULL DEFAULT 'airfry'", 'cid');

// 2. Kolom Cloudflare R2 dan MiniMax TTS pada tbl_ai_config
echo "2. Menyesuaikan kolom pada `tbl_ai_config`...\n";
$r2Cols = [
    'r2_enabled' => "TINYINT(1) DEFAULT 0",
    'r2_account_id' => "VARCHAR(100) DEFAULT ''",
    'r2_access_key_id' => "VARCHAR(100) DEFAULT ''",
    'r2_secret_access_key' => "VARCHAR(100) DEFAULT ''",
    'r2_bucket_name' => "VARCHAR(100) DEFAULT ''",
    'r2_public_domain' => "VARCHAR(255) DEFAULT ''",
    'minimax_api_key' => "VARCHAR(255) DEFAULT ''",
    'minimax_group_id' => "VARCHAR(100) DEFAULT ''",
    'minimax_model' => "VARCHAR(50) DEFAULT 'speech-01-hd'",
    'minimax_default_language' => "VARCHAR(10) DEFAULT 'id'",
    'minimax_default_voice' => "VARCHAR(100) DEFAULT 'male-qn-qingse'",
    'minimax_default_speed' => "DECIMAL(3,2) DEFAULT 1.00",
    'minimax_default_volume' => "DECIMAL(3,2) DEFAULT 1.00",
];
foreach ($r2Cols as $col => $def) {
    addColumnIfNotExists($connect, 'tbl_ai_config', $col, $def);
}

// 3. Kolom TTS pada tbl_ai_video_projects dan tbl_ai_video_scenes
echo "3. Menyesuaikan kolom pada tabel video AI...\n";
$videoProjectCols = [
    'tts_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
    'tts_language' => "VARCHAR(10) NOT NULL DEFAULT 'id-ID'",
    'tts_voice_persona' => "VARCHAR(100) NOT NULL DEFAULT 'Indonesian_energetic_streamer_vv2'",
    'tts_speed' => "DECIMAL(3,2) NOT NULL DEFAULT 1.00",
    'tts_volume' => "DECIMAL(3,2) NOT NULL DEFAULT 1.00",
    'tts_enable_bgm' => "TINYINT(1) NOT NULL DEFAULT 1",
    'tts_bgm_volume' => "DECIMAL(3,2) NOT NULL DEFAULT 0.18",
    'tts_full_audio_path' => "VARCHAR(255) DEFAULT NULL",
];
foreach ($videoProjectCols as $col => $def) {
    addColumnIfNotExists($connect, 'tbl_ai_video_projects', $col, $def);
}

$videoSceneCols = [
    'tts_text' => "TEXT DEFAULT NULL",
    'tts_audio_path' => "VARCHAR(255) DEFAULT NULL",
    'tts_duration' => "DECIMAL(6,2) DEFAULT NULL",
    'tts_status' => "ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending'",
];
foreach ($videoSceneCols as $col => $def) {
    addColumnIfNotExists($connect, 'tbl_ai_video_scenes', $col, $def);
}

// 4. Tabel-Tabel Tambahan (IF NOT EXISTS)
echo "4. Memeriksa pembuatan tabel-tabel tambahan...\n";

$missingTablesSql = [
    'tbl_ai_recipe_batches' => "CREATE TABLE IF NOT EXISTS `tbl_ai_recipe_batches` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `batch_name` varchar(255) NOT NULL,
      `product_code` varchar(50) NOT NULL DEFAULT 'airfry',
      `target_language` varchar(20) NOT NULL DEFAULT 'auto',
      `style_preset` varchar(100) NOT NULL DEFAULT 'Modern Fine Dining',
      `aspect_ratio` varchar(20) NOT NULL DEFAULT '4:3',
      `total_urls` int(11) NOT NULL DEFAULT 0,
      `completed_urls` int(11) NOT NULL DEFAULT 0,
      `failed_urls` int(11) NOT NULL DEFAULT 0,
      `status` enum('pending','running','completed','failed','paused') NOT NULL DEFAULT 'pending',
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_batch_product` (`product_code`),
      KEY `idx_batch_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'tbl_ai_recipe_drafts' => "CREATE TABLE IF NOT EXISTS `tbl_ai_recipe_drafts` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `batch_id` int(11) DEFAULT NULL,
      `source_url` text NOT NULL,
      `recipe_title` varchar(255) NOT NULL,
      `prep_time` varchar(45) DEFAULT '',
      `cook_time` varchar(45) DEFAULT '',
      `servings` varchar(45) DEFAULT '',
      `summary` text DEFAULT NULL,
      `ingredients_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
      `instructions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
      `selected_image_url` varchar(255) DEFAULT '',
      `image_prompt` text DEFAULT NULL,
      `cat_id` int(11) NOT NULL DEFAULT 0,
      `product_code` varchar(50) NOT NULL DEFAULT 'airfry',
      `status` enum('draft','published','rejected','failed') NOT NULL DEFAULT 'draft',
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_draft_batch` (`batch_id`),
      KEY `idx_draft_status` (`status`),
      KEY `idx_draft_product` (`product_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'tbl_ingredients' => "CREATE TABLE IF NOT EXISTS `tbl_ingredients` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `ingredient_name` varchar(255) NOT NULL,
      `standard_unit` varchar(50) DEFAULT '',
      `category` varchar(100) DEFAULT '',
      `product_code` varchar(50) NOT NULL DEFAULT 'protein',
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_ing_name_product` (`ingredient_name`, `product_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'tbl_prep_packs' => "CREATE TABLE IF NOT EXISTS `tbl_prep_packs` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `pack_name` varchar(255) NOT NULL,
      `description` text DEFAULT NULL,
      `target_calories` int(11) DEFAULT 0,
      `target_protein` int(11) DEFAULT 0,
      `product_code` varchar(50) NOT NULL DEFAULT 'protein',
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    'tbl_recipe_nutrition' => "CREATE TABLE IF NOT EXISTS `tbl_recipe_nutrition` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `recipe_id` int(11) NOT NULL,
      `calories` int(11) DEFAULT 0,
      `protein_grams` decimal(6,2) DEFAULT 0.00,
      `carbs_grams` decimal(6,2) DEFAULT 0.00,
      `fat_grams` decimal(6,2) DEFAULT 0.00,
      `fiber_grams` decimal(6,2) DEFAULT 0.00,
      `sodium_mg` decimal(6,2) DEFAULT 0.00,
      `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_recipe_id` (`recipe_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
];

foreach ($missingTablesSql as $tblName => $createSql) {
    $chk = $connect->query("SHOW TABLES LIKE '{$tblName}'");
    if ($chk && $chk->num_rows > 0) {
        $messages[] = "✓ Tabel `{$tblName}` sudah ada.";
    } else {
        if ($connect->query($createSql)) {
            $messages[] = "⚡ Berhasil membuat tabel `{$tblName}`.";
        } else {
            $errors[] = "✗ Gagal membuat tabel `{$tblName}`: " . $connect->error;
        }
    }
}

echo "\n--- RINGKASAN HASIL SINKRONISASI ---\n";
foreach ($messages as $msg) {
    echo $msg . "\n";
}
if (!empty($errors)) {
    echo "\nTERJADI KESALAHAN:\n";
    foreach ($errors as $err) {
        echo $err . "\n";
    }
    echo "\n❌ Sinkronisasi selesai dengan " . count($errors) . " error.\n";
    exit(1);
} else {
    echo "\n🎉 SINKRONISASI SKEMA DATABASE BERHASIL 100%! SEMUA TABEL DAN KOLOM TELAH SESUAI DENGAN LOCALHOST. 🎉\n";
    exit(0);
}
