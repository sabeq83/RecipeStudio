<?php

namespace Ai;

use mysqli;
use Throwable;

class RecipeStudioDatabaseMigration
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function run(): array
    {
        $logs = [];

        // 1. Tambah product_code pada tbl_recipes
        try {
            $colRes = $this->db->query("SHOW COLUMNS FROM `tbl_recipes` LIKE 'product_code'");
            if ($colRes && $colRes->num_rows === 0) {
                $this->db->query("ALTER TABLE `tbl_recipes` ADD COLUMN `product_code` VARCHAR(50) NOT NULL DEFAULT 'airfry' AFTER `cat_id`");
                $this->db->query("ALTER TABLE `tbl_recipes` ADD INDEX `idx_recipes_product` (`product_code`)");
                $logs[] = "Menambahkan kolom product_code ke tbl_recipes.";
            }
        } catch (Throwable $e) {
            $logs[] = "Warning product_code tbl_recipes: " . $e->getMessage();
        }

        // 2. Tambah product_code pada tbl_category
        try {
            $colRes = $this->db->query("SHOW COLUMNS FROM `tbl_category` LIKE 'product_code'");
            if ($colRes && $colRes->num_rows === 0) {
                $this->db->query("ALTER TABLE `tbl_category` ADD COLUMN `product_code` VARCHAR(50) NOT NULL DEFAULT 'airfry' AFTER `cid`");
                $logs[] = "Menambahkan kolom product_code ke tbl_category.";
            }
        } catch (Throwable $e) {
            $logs[] = "Warning product_code tbl_category: " . $e->getMessage();
        }

        // 3. Tambah Tabel Prep Packs & Nutrisi ProteinPrep
        $queries = [
            "CREATE TABLE IF NOT EXISTS `tbl_ingredients` (
                `ingredient_id` INT(11) NOT NULL AUTO_INCREMENT,
                `ingredient_name` VARCHAR(255) NOT NULL,
                `category` VARCHAR(100) NOT NULL DEFAULT 'Produce',
                `state_condition` VARCHAR(50) NOT NULL DEFAULT 'raw',
                `default_unit` VARCHAR(50) NOT NULL DEFAULT 'g',
                `density_g_per_ml` DECIMAL(8,4) NULL,
                `reference_source` VARCHAR(255) NOT NULL DEFAULT 'USDA FoodData Central',
                `reference_id` VARCHAR(100) NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`ingredient_id`),
                UNIQUE KEY `uniq_ingredient_condition` (`ingredient_name`, `state_condition`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `tbl_recipe_nutrition` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `recipe_id` INT(11) NOT NULL,
                `calories` INT(11) NOT NULL DEFAULT 0,
                `protein_g` DECIMAL(8,1) NOT NULL DEFAULT 0.0,
                `carbs_g` DECIMAL(8,1) NOT NULL DEFAULT 0.0,
                `fat_g` DECIMAL(8,1) NOT NULL DEFAULT 0.0,
                `serving_size_info` VARCHAR(100) NOT NULL DEFAULT '1 portion',
                `verified_by_human` TINYINT(1) NOT NULL DEFAULT 0,
                `verification_date` DATETIME NULL,
                `calculation_notes` TEXT NULL,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_recipe_nutrition` (`recipe_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `tbl_prep_packs` (
                `pack_id` INT(11) NOT NULL AUTO_INCREMENT,
                `product_code` VARCHAR(50) NOT NULL DEFAULT 'proteinprep',
                `pack_name` VARCHAR(255) NOT NULL,
                `pack_description` TEXT NOT NULL,
                `pack_image` VARCHAR(255) NOT NULL,
                `total_portions_summary` VARCHAR(100) NOT NULL DEFAULT '4 portions · 2 recipes',
                `active_time_minutes` INT(11) NOT NULL DEFAULT 35,
                `total_time_minutes` INT(11) NOT NULL DEFAULT 45,
                `tools_summary` VARCHAR(255) NOT NULL DEFAULT '1 pan + pot',
                `info_callout` TEXT NULL,
                `labels` VARCHAR(500) NOT NULL DEFAULT 'High Protein',
                `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
                `status` ENUM('draft', 'published', 'withdrawn') NOT NULL DEFAULT 'published',
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`pack_id`),
                KEY `idx_pack_status` (`product_code`, `status`, `is_featured`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                        "CREATE TABLE IF NOT EXISTS `tbl_ai_recipe_batches` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `batch_code` VARCHAR(64) NOT NULL,
                `theme` VARCHAR(150) NOT NULL,
                `primary_protein` VARCHAR(50) NOT NULL,
                `recipe_count` INT(11) NOT NULL DEFAULT 3,
                `target_portions` INT(11) NOT NULL DEFAULT 4,
                `language` VARCHAR(10) NOT NULL DEFAULT 'en',
                `max_prep_minutes` INT(11) NOT NULL DEFAULT 45,
                `dietary_preferences` VARCHAR(255) NULL,
                `excluded_ingredients` TEXT NULL,
                `admin_notes` TEXT NULL,
                `model_used` VARCHAR(100) NOT NULL DEFAULT 'gemini-2.0-flash',
                `prompt_version` VARCHAR(20) NOT NULL DEFAULT 'v1.0',
                `prep_pack_id` INT(11) NULL DEFAULT NULL,
                `status` ENUM('generating', 'completed', 'partial', 'failed') NOT NULL DEFAULT 'completed',
                `raw_response` LONGTEXT NULL,
                `error_message` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_batch_code` (`batch_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `tbl_ai_recipe_drafts` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `batch_id` INT(11) NOT NULL,
                `recipe_id` INT(11) NULL DEFAULT NULL,
                `draft_title` VARCHAR(255) NOT NULL,
                `draft_data_json` LONGTEXT NOT NULL,
                `validation_status` ENUM('valid', 'warning', 'invalid') NOT NULL DEFAULT 'valid',
                `validation_notes` TEXT NULL,
                `similarity_warning` TEXT NULL,
                `is_published` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_batch` (`batch_id`),
                KEY `idx_recipe` (`recipe_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `tbl_sync_destinations` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];

        foreach ($queries as $q) {
            try {
                $this->db->query($q);
            } catch (Throwable $e) {
                $logs[] = "Warning table creation: " . $e->getMessage();
            }
        }

        // 4. Default Seed untuk Sync Destinations
        try {
            $destCheck = $this->db->query("SELECT COUNT(*) as cnt FROM `tbl_sync_destinations`");
            $row = $destCheck ? $destCheck->fetch_assoc() : null;
            if (!$row || (int)$row['cnt'] === 0) {
                $this->db->query("INSERT INTO `tbl_sync_destinations` (`product_code`, `label`, `target_api_url`, `sync_token`, `is_active`) VALUES
                    ('airfry', 'Live AirFryer App (English)', 'https://airfryeats.com/api_sync_recipe.php', 'airfry_sync_sec_99a8b7c6d5e4f3a2b1', 1),
                    ('nusasaji', 'Live Nusa Saji App (Indonesia)', 'https://nusasaji.com/api_sync_recipe.php', 'nusasaji_sync_sec_88b7c6d5e4f3a2b1c2', 1),
                    ('proteinprep', 'Live ProteinPrep App (MealPrep)', 'https://proteinprep.app/api_sync_recipe.php', 'proteinprep_sync_sec_77c6d5e4f3a2b1c2d3', 1)
                ");
                $logs[] = "Inisialisasi 3 profil live sync destination (AirFryer, Nusa Saji, ProteinPrep).";
            }
        } catch (Throwable $e) {
            $logs[] = "Warning seed sync destinations: " . $e->getMessage();
        }

        return $logs;
    }
}
