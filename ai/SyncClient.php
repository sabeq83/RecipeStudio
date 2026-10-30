<?php

namespace Ai;

use mysqli;
use CURLFile;
use Exception;

class SyncClient
{
    private mysqli $db;
    public const DEFAULT_TARGET_URL = 'https://airfry.web.id/app/api_sync_recipe.php';
    public const DEFAULT_SECRET_TOKEN = 'airfry_sync_sec_99a8b7c6d5e4f3a2b1';

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->ensureConfigColumns();
        $this->ensureDestinationsTable();
    }

    private function ensureConfigColumns(): void
    {
        $cols = [
            'sync_target_url' => "VARCHAR(255) NOT NULL DEFAULT '" . self::DEFAULT_TARGET_URL . "'",
            'sync_secret_token' => "VARCHAR(255) NOT NULL DEFAULT '" . self::DEFAULT_SECRET_TOKEN . "'"
        ];
        foreach ($cols as $col => $def) {
            $check = $this->db->query("SHOW COLUMNS FROM `tbl_ai_config` LIKE '{$col}'");
            if ($check && $check->num_rows === 0) {
                $this->db->query("ALTER TABLE `tbl_ai_config` ADD COLUMN `{$col}` {$def}");
            }
        }
    }

    private function ensureDestinationsTable(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `tbl_sync_destinations` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `product_code` VARCHAR(50) NOT NULL,
            `label` VARCHAR(100) NOT NULL,
            `target_api_url` VARCHAR(255) NOT NULL,
            `sync_token` VARCHAR(255) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `last_sync_at` DATETIME NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_sync_product` (`product_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $cntRes = $this->db->query("SELECT COUNT(*) as cnt FROM `tbl_sync_destinations`");
        $row = $cntRes ? $cntRes->fetch_assoc() : null;
        if (!$row || (int)$row['cnt'] === 0) {
            $this->db->query("INSERT INTO `tbl_sync_destinations` (`product_code`, `label`, `target_api_url`, `sync_token`, `is_active`) VALUES
                ('airfry', 'Air Fryer Recipes', 'https://airfryeats.com/app/api_sync_recipe.php', 'airfry_sync_sec_99a8b7c6d5e4f3a2b1', 1),
                ('nusasaji', 'Nusa Saji (Resep Tradisional)', 'https://nusasaji.com/api_sync_recipe.php', 'nusasaji_sync_sec_88b7c6d5e4f3a2b1c2', 1),
                ('protein', 'Protein Prep (Fitness Food)', 'https://proteinprep.app/api_sync_recipe.php', 'proteinprep_sync_sec_77c6d5e4f3a2b1c2d3', 1)
            ");
        }
    }

    public function getTargetUrl(): string
    {
        $res = $this->db->query("SELECT sync_target_url FROM tbl_ai_config WHERE id = 1 LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            $url = trim($row['sync_target_url'] ?? '');
            if (!empty($url)) {
                return $url;
            }
        }
        return self::DEFAULT_TARGET_URL;
    }

    public function getSecretToken(): string
    {
        $res = $this->db->query("SELECT sync_secret_token FROM tbl_ai_config WHERE id = 1 LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            $tok = trim($row['sync_secret_token'] ?? '');
            if (!empty($tok)) {
                return $tok;
            }
        }
        return self::DEFAULT_SECRET_TOKEN;
    }

    /**
     * Mengambil target URL dan Token berdasarkan product_code
     */
    public function getDestinationForProduct(string $productCode): array
    {
        $safe = $this->db->real_escape_string(trim($productCode));
        $res = $this->db->query("SELECT * FROM tbl_sync_destinations WHERE product_code = '$safe' AND is_active = 1 LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            return [
                'id' => (int)$row['id'],
                'label' => $row['label'],
                'product_code' => $row['product_code'],
                'target_url' => $row['target_api_url'],
                'token' => $row['sync_token']
            ];
        }

        // Fallback default
        return [
            'id' => 0,
            'label' => 'Live Default Hosting',
            'product_code' => $productCode ?: 'airfry',
            'target_url' => $this->getTargetUrl(),
            'token' => $this->getSecretToken()
        ];
    }

    /**
     * Ambil semua destinasi sync
     */
    public function getAllDestinations(): array
    {
        $list = [];
        $res = $this->db->query("SELECT * FROM tbl_sync_destinations ORDER BY id ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $list[] = $row;
            }
        }
        return $list;
    }

    /**
     * Ambil semua destinasi sync aktif (untuk dropdown Aplikasi Target)
     */
    public function getActiveDestinations(): array
    {
        $list = [];
        $res = $this->db->query("SELECT * FROM tbl_sync_destinations WHERE is_active = 1 ORDER BY id ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $list[] = $row;
            }
        }
        return $list;
    }

    public function getDestinationById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM tbl_sync_destinations WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            return $row;
        }
        return null;
    }

    public function addDestination(string $productCode, string $label, string $url, string $token, bool $isActive = true): array
    {
        $productCode = strtolower(trim($productCode));
        $productCode = preg_replace('/[^a-z0-9_-]/', '', $productCode);
        $label = trim($label);
        $url = trim($url);
        $token = trim($token);
        $activeInt = $isActive ? 1 : 0;

        if (empty($productCode) || empty($label) || empty($url) || empty($token)) {
            return ['success' => false, 'error' => 'Semua kolom formulir wajib diisi.'];
        }

        // Check duplicate product_code
        $check = $this->db->prepare("SELECT id FROM tbl_sync_destinations WHERE product_code = ? LIMIT 1");
        if ($check) {
            $check->bind_param('s', $productCode);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $check->close();
                return ['success' => false, 'error' => "Kode produk '{$productCode}' sudah terdaftar. Gunakan slug unik lain."];
            }
            $check->close();
        }

        $stmt = $this->db->prepare("INSERT INTO tbl_sync_destinations (product_code, label, target_api_url, sync_token, is_active) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('ssssi', $productCode, $label, $url, $token, $activeInt);
            $ok = $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();
            if ($ok) {
                return ['success' => true, 'id' => $newId, 'message' => "Aplikasi '{$label}' berhasil ditambahkan ke daftar sinkronisasi."];
            }
        }
        return ['success' => false, 'error' => 'Gagal menyimpan aplikasi: ' . $this->db->error];
    }

    public function updateDestination(int $id, string $label, string $url, string $token): array
    {
        $label = trim($label);
        $url = trim($url);
        $token = trim($token);

        if ($id <= 0 || empty($label) || empty($url) || empty($token)) {
            return ['success' => false, 'error' => 'Data input tidak valid atau ada kolom kosong.'];
        }

        $stmt = $this->db->prepare("UPDATE tbl_sync_destinations SET label = ?, target_api_url = ?, sync_token = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('sssi', $label, $url, $token, $id);
            $ok = $stmt->execute();
            $stmt->close();
            return ['success' => $ok, 'message' => $ok ? 'Pengaturan aplikasi berhasil diperbarui.' : 'Gagal memperbarui aplikasi.'];
        }
        return ['success' => false, 'error' => 'Database error: ' . $this->db->error];
    }

    public function deleteDestination(int $id): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'ID aplikasi tidak valid.'];
        }
        $stmt = $this->db->prepare("DELETE FROM tbl_sync_destinations WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute();
            $stmt->close();
            return ['success' => $ok, 'message' => 'Aplikasi berhasil dihapus dari daftar target.'];
        }
        return ['success' => false, 'error' => $this->db->error];
    }

    public function toggleDestination(int $id, bool $isActive): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'ID aplikasi tidak valid.'];
        }
        $val = $isActive ? 1 : 0;
        $stmt = $this->db->prepare("UPDATE tbl_sync_destinations SET is_active = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $val, $id);
            $ok = $stmt->execute();
            $stmt->close();
            return ['success' => $ok, 'is_active' => $val, 'message' => $val ? 'Aplikasi diaktifkan.' : 'Aplikasi dinonaktifkan.'];
        }
        return ['success' => false, 'error' => $this->db->error];
    }

    public function regenerateToken(int $id): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'ID aplikasi tidak valid.'];
        }
        $dest = $this->getDestinationById($id);
        if (!$dest) {
            return ['success' => false, 'error' => 'Aplikasi tidak ditemukan.'];
        }

        $prefix = preg_replace('/[^a-z0-9]/', '', strtolower($dest['product_code'])) ?: 'sync';
        $newToken = $prefix . '_sec_' . bin2hex(random_bytes(10));

        $stmt = $this->db->prepare("UPDATE tbl_sync_destinations SET sync_token = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('si', $newToken, $id);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                return [
                    'success' => true,
                    'new_token' => $newToken,
                    'message' => "Bearer token baru berhasil dibuat untuk {$dest['label']}."
                ];
            }
        }
        return ['success' => false, 'error' => 'Gagal generate token baru: ' . $this->db->error];
    }

    public function pingDestination(int $id): array
    {
        $dest = $this->getDestinationById($id);
        if (!$dest) {
            return ['success' => false, 'error' => 'Aplikasi tidak ditemukan.'];
        }
        return $this->pingCustom($dest['target_api_url'], $dest['sync_token'], $dest['label']);
    }

    public function pingCustom(string $url, string $token, string $label = 'Target Server'): array
    {
        $url = trim($url);
        $cleanToken = preg_replace('/^Bearer\s+/i', '', trim($token));

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => "URL endpoint tidak valid: '{$url}'"];
        }

        $startTime = microtime(true);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'action' => 'ping',
                'sync_token' => $cleanToken
            ],
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$cleanToken}",
                "X-Sync-Token: {$cleanToken}"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $latencyMs = round((microtime(true) - $startTime) * 1000);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        @curl_close($ch);

        if ($err) {
            return [
                'success' => false,
                'latency_ms' => $latencyMs,
                'http_code' => $httpCode,
                'error' => "Gagal terhubung ke {$label} ({$url}): {$err}"
            ];
        }

        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($data['success'])) {
            return [
                'success' => true,
                'latency_ms' => $latencyMs,
                'http_code' => $httpCode,
                'message' => $data['message'] ?? "Terhubung ke {$label} (HTTP {$httpCode} • {$latencyMs}ms)"
            ];
        }

        $msg = $data['error'] ?? ("HTTP {$httpCode}");
        return [
            'success' => false,
            'latency_ms' => $latencyMs,
            'http_code' => $httpCode,
            'error' => "Hosting menolak koneksi: {$msg}"
        ];
    }

    public function setConfig(string $targetUrl, string $secretToken): bool
    {
        $targetUrl = trim($targetUrl);
        $secretToken = trim($secretToken);
        $stmt = $this->db->prepare("UPDATE tbl_ai_config SET sync_target_url = ?, sync_secret_token = ? WHERE id = 1");
        if ($stmt) {
            $stmt->bind_param('ss', $targetUrl, $secretToken);
            $res = $stmt->execute();
            $stmt->close();
            return $res;
        }
        return false;
    }

    /**
     * Test ping to live receiver endpoint default.
     */
    public function pingLive(): array
    {
        return $this->pingCustom($this->getTargetUrl(), $this->getSecretToken(), 'Default Hosting');
    }

    /**
     * Alias untuk pushRecipeToLive
     */
    public function pushRecipe(int $recipeId, ?string $targetProduct = null): array
    {
        return $this->pushRecipeToLive($recipeId, $targetProduct);
    }

    /**
     * Push a local recipe + image to live shared hosting.
     */
    public function pushRecipeToLive(int $recipeId, ?string $targetProduct = null): array
    {
        if ($recipeId <= 0) {
            return ['success' => false, 'error' => 'ID resep tidak valid.'];
        }

        // Fetch local recipe details
        $stmt = $this->db->prepare("SELECT r.*, c.category_name 
            FROM `tbl_recipes` r 
            LEFT JOIN `tbl_category` c ON r.cat_id = c.cid 
            WHERE r.recipe_id = ? LIMIT 1");
        if (!$stmt) {
            return ['success' => false, 'error' => 'Prepare failed: ' . $this->db->error];
        }

        $stmt->bind_param('i', $recipeId);
        $stmt->execute();
        $res = $stmt->get_result();
        $recipe = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$recipe) {
            return ['success' => false, 'error' => "Resep dengan ID #{$recipeId} tidak ditemukan di database lokal."];
        }

        // Resolusi target URL dan Token berdasarkan prioritas:
        // 1. Parameter eksplisit $targetProduct
        // 2. Kolom $recipe['product_code'] dari database lokal
        // 3. Sesi aktif $_SESSION['active_product']
        // 4. Default 'airfry'
        $effectiveProduct = !empty($targetProduct)
            ? trim($targetProduct)
            : (!empty($recipe['product_code']) ? trim($recipe['product_code']) : ($_SESSION['active_product'] ?? 'airfry'));

        $dest = $this->getDestinationForProduct($effectiveProduct);
        $url = $dest['target_url'] ?? '';
        $token = $dest['token'] ?? '';

        if (empty($url) || empty($token)) {
            $url = $this->getTargetUrl();
            $token = $this->getSecretToken();
        }

        $cleanToken = preg_replace('/^Bearer\s+/i', '', trim($token));

        $postFields = [
            'action' => 'push_recipe',
            'sync_token' => $cleanToken,
            'local_recipe_id' => $recipeId,
            'recipe_title' => $recipe['recipe_title'] ?? '',
            'recipe_time' => $recipe['recipe_time'] ?? '',
            'recipe_description' => $recipe['recipe_description'] ?? '',
            'category_name' => $recipe['category_name'] ?? 'Air Fryer',
            'content_type' => $recipe['content_type'] ?? 'Post',
            'video_url' => $recipe['video_url'] ?? '',
            'video_id' => $recipe['video_id'] ?? '',
            'tags' => $recipe['tags'] ?? '',
            'size' => $recipe['size'] ?? ''
        ];

        // Attach image if exists locally
        $imgName = trim($recipe['recipe_image'] ?? '');
        $imagePath = dirname(__DIR__) . '/upload/' . $imgName;
        if (!empty($imgName) && file_exists($imagePath) && is_file($imagePath)) {
            $mime = 'image/jpeg';
            if (function_exists('mime_content_type')) {
                $mime = mime_content_type($imagePath) ?: 'image/jpeg';
            }
            $postFields['image_file'] = new CURLFile($imagePath, $mime, basename($imagePath));
        }

        // Attach video if exists locally
        $videoField = trim($recipe['video_url'] ?? '');
        $videoFilename = basename($videoField);
        $videoPath = dirname(__DIR__) . '/upload/video/' . $videoFilename;
        $hasVideoFile = false;

        if (!empty($videoFilename) && !filter_var($videoField, FILTER_VALIDATE_URL) && file_exists($videoPath) && is_file($videoPath)) {
            $vMime = 'video/mp4';
            if (function_exists('mime_content_type')) {
                $vMime = mime_content_type($videoPath) ?: 'video/mp4';
            }
            $postFields['video_file'] = new CURLFile($videoPath, $vMime, $videoFilename);
            $postFields['video_url'] = $videoFilename;
            $hasVideoFile = true;

            // Compute size if not already set
            if (empty($postFields['size'])) {
                $bytes = @filesize($videoPath) ?: 0;
                if ($bytes >= 1073741824) {
                    $postFields['size'] = number_format($bytes / 1073741824, 2) . ' GB';
                } else if ($bytes >= 1048576) {
                    $postFields['size'] = number_format($bytes / 1048576, 2) . ' MB';
                } else if ($bytes >= 1024) {
                    $postFields['size'] = number_format($bytes / 1024, 2) . ' KB';
                }
            }
        }

        // Dynamically adjust timeout based on whether video file is attached
        $curlTimeout = $hasVideoFile ? 300 : 60;
        @set_time_limit($curlTimeout + 60);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$cleanToken}",
                "X-Sync-Token: {$cleanToken}"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $curlTimeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        @curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => "Gagal mengirim data ke live hosting: {$err}"];
        }

        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($data['success'])) {
            $liveId = (int)($data['live_recipe_id'] ?? 0);

            // Update local tracking
            $now = date('Y-m-d H:i:s');
            $upStmt = $this->db->prepare("UPDATE `tbl_recipes` SET `live_recipe_id` = ?, `last_synced_at` = ? WHERE `recipe_id` = ?");
            if ($upStmt) {
                $upStmt->bind_param('isi', $liveId, $now, $recipeId);
                $upStmt->execute();
                $upStmt->close();
            }

            // Update last_sync_at pada tbl_sync_destinations
            if (!empty($effectiveProduct)) {
                $safeP = $this->db->real_escape_string($effectiveProduct);
                $this->db->query("UPDATE `tbl_sync_destinations` SET `last_sync_at` = '{$now}' WHERE `product_code` = '{$safeP}'");
            }

            return [
                'success' => true,
                'action' => $data['action'] ?? 'synced',
                'live_recipe_id' => $liveId,
                'recipe_title' => $recipe['recipe_title'],
                'target_product' => $effectiveProduct,
                'video_uploaded' => !empty($data['video_uploaded']) || $hasVideoFile,
                'video_filename' => $data['video_filename'] ?? ($hasVideoFile ? $videoFilename : ''),
                'message' => $data['message'] ?? "Resep berhasil diterbitkan ke live hosting ({$effectiveProduct}: ID #{$liveId})!"
            ];
        }

        $msg = $data['error'] ?? ("HTTP {$httpCode}");
        return ['success' => false, 'error' => "Live hosting menolak: {$msg}"];
    }
}
