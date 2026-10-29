<?php

namespace Importer\Parsers;

use Exception;

/**
 * CookpadParser
 * Dedicated parser for Cookpad recipes (cookpad.com across all locales: /id/, /us/, /es/, etc.)
 * Extracts Schema.org JSON-LD Recipe data dynamically, cleans titles, standardizes portions,
 * and collects high-resolution hero and step images.
 */
class CookpadParser implements ParserInterface
{
    /**
     * Checks if URL belongs to Cookpad.
     */
    public function supports(string $url): bool
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        return ($host === 'cookpad.com' || str_ends_with($host, '.cookpad.com'));
    }

    /**
     * Parses HTML from a Cookpad recipe page.
     *
     * @param string $html Raw HTML content
     * @param string $url Source URL
     * @return array Standardized recipe array
     * @throws Exception
     */
    public function parse(string $html, string $url): array
    {
        if (empty(trim($html))) {
            throw new Exception("Halaman HTML dari Cookpad kosong.");
        }

        // 1. Ekstrak data JSON-LD Schema.org Recipe
        $recipeData = $this->extractRecipeJsonLd($html);

        if (!$recipeData) {
            // Fallback: coba ekstrak data via OpenGraph meta tags
            $recipeData = $this->fallbackMetaParse($html, $url);
        }

        if (!$recipeData) {
            throw new Exception("Format data resep tidak ditemukan di halaman Cookpad ini. Pastikan URL merupakan halaman resep aktif.");
        }

        return $recipeData;
    }

    /**
     * Cari dan ekstrak objek @type = Recipe dari tag <script type="application/ld+json">
     */
    private function extractRecipeJsonLd(string $html): ?array
    {
        if (!preg_match_all('#<script[^>]+type=[\'"]application/ld\+json[\'"][^>]*>(.*?)</script>#is', $html, $matches)) {
            return null;
        }

        $rawRecipe = null;

        foreach ($matches[1] as $jsonText) {
            $jsonText = trim($jsonText);
            if (empty($jsonText)) continue;

            $decoded = json_decode($jsonText, true);
            if (!is_array($decoded)) continue;

            $found = $this->findRecipeInGraph($decoded);
            if ($found) {
                $rawRecipe = $found;
                break;
            }
        }

        if (!$rawRecipe) {
            return null;
        }

        return $this->normalizeCookpadSchema($rawRecipe, $html);
    }

    /**
     * Rekursif mencari objek Recipe di dalam JSON-LD
     */
    private function findRecipeInGraph(array $data): ?array
    {
        if (isset($data['@type'])) {
            $types = (array)$data['@type'];
            foreach ($types as $t) {
                if (strcasecmp($t, 'Recipe') === 0) {
                    return $data;
                }
            }
        }

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $node) {
                if (is_array($node)) {
                    $found = $this->findRecipeInGraph($node);
                    if ($found) return $found;
                }
            }
        }

        return null;
    }

    /**
     * Normalisasi data Schema.org Cookpad ke skema Recipe Studio
     */
    private function normalizeCookpadSchema(array $raw, string $html): array
    {
        // 1. Judul Resep & Pembersihan Suffix
        $rawTitle = trim($raw['name'] ?? $raw['headline'] ?? '');
        $title = $this->cleanCookpadTitle($rawTitle);

        // 2. Ringkasan
        $summary = trim(strip_tags($raw['description'] ?? ''));

        // 3. Porsi (Yield)
        $servings = '';
        if (!empty($raw['recipeYield'])) {
            $rawYield = is_array($raw['recipeYield']) ? implode(' ', $raw['recipeYield']) : (string)$raw['recipeYield'];
            $servings = $this->normalizeYield($rawYield);
        }

        // 4. Waktu Memasak
        $prepTime = $this->formatDuration($raw['prepTime'] ?? '');
        $cookTime = $this->formatDuration($raw['cookTime'] ?? '');
        $totalTime = $this->formatDuration($raw['totalTime'] ?? '');

        // Jika waktu kosong di JSON-LD, lakukan estimasi cerdas dari teks langkah
        if (empty($cookTime) && empty($prepTime)) {
            $estimated = $this->estimateTimeFromText($html);
            $prepTime = $estimated['prep_time'];
            $cookTime = $estimated['cook_time'];
            $totalTime = $estimated['total_time'];
        }

        // 5. Bahan-Bahan (Dinamis berapapun jumlahnya)
        $ingredients = [];
        $rawIngredients = $raw['recipeIngredient'] ?? $raw['ingredients'] ?? [];
        if (is_array($rawIngredients)) {
            foreach ($rawIngredients as $item) {
                if (is_string($item)) {
                    $clean = trim(html_entity_decode(strip_tags($item), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    // Hapus hanya bullet point atau dash di awal (pertahankan angka takaran)
                    $clean = preg_replace('/^[\-\*\•\–\—]\s*/u', '', $clean);
                    if ($clean !== '') {
                        $ingredients[] = $clean;
                    }
                }
            }
        }

        // 6. Langkah-Langkah Instruksi (Dinamis berapapun jumlahnya)
        $instructions = [];
        $rawInstructions = $raw['recipeInstructions'] ?? [];
        $stepImages = [];

        if (is_array($rawInstructions)) {
            foreach ($rawInstructions as $step) {
                if (is_string($step)) {
                    $clean = trim(html_entity_decode(strip_tags($step), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    // Hapus penomoran awal karena tampilan webadmin/aplikasi sudah memiliki nomor otomatis
                    $clean = preg_replace('/^\d+[\.\)\-]\s*/', '', $clean);
                    if ($clean !== '') {
                        $instructions[] = $clean;
                    }
                } elseif (is_array($step)) {
                    // HowToSection / HowToStep
                    if (isset($step['itemListElement']) && is_array($step['itemListElement'])) {
                        foreach ($step['itemListElement'] as $subStep) {
                            $text = trim(html_entity_decode(strip_tags($subStep['text'] ?? $subStep['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                            $text = preg_replace('/^\d+[\.\)\-]\s*/', '', $text);
                            if ($text !== '') $instructions[] = $text;
                            if (!empty($subStep['image'])) {
                                $stepImages[] = is_array($subStep['image']) ? ($subStep['image']['url'] ?? '') : (string)$subStep['image'];
                            }
                        }
                    } else {
                        $text = trim(html_entity_decode(strip_tags($step['text'] ?? $step['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        $text = preg_replace('/^\d+[\.\)\-]\s*/', '', $text);
                        if ($text !== '') $instructions[] = $text;
                        if (!empty($step['image'])) {
                            $stepImages[] = is_array($step['image']) ? ($step['image']['url'] ?? '') : (string)$step['image'];
                        }
                    }
                }
            }
        }

        // 7. Kandidat Gambar (Pilih resolusi master tertinggi 1200px)
        $images = [];
        if (!empty($raw['image'])) {
            if (is_string($raw['image'])) {
                $images[] = $raw['image'];
            } elseif (is_array($raw['image'])) {
                if (isset($raw['image']['url'])) {
                    $images[] = $raw['image']['url'];
                } else {
                    foreach ($raw['image'] as $img) {
                        if (is_string($img)) $images[] = $img;
                        elseif (is_array($img) && !empty($img['url'])) $images[] = $img['url'];
                    }
                }
            }
        }

        // Masukkan step images ke kandidat
        foreach ($stepImages as $sImg) {
            if (!empty($sImg) && !in_array($sImg, $images, true)) {
                $images[] = $sImg;
            }
        }

        // Bersihkan dan tingkatkan kualitas resolusi CDN Cookpad
        $cleanedImages = [];
        foreach ($images as $imgUrl) {
            $hiRes = $this->upgradeCookpadImageResolution($imgUrl);
            if (!empty($hiRes) && !in_array($hiRes, $cleanedImages, true)) {
                $cleanedImages[] = $hiRes;
            }
        }

        return [
            'recipe_title' => $title ?: 'Resep Cookpad',
            'prep_time' => $prepTime,
            'cook_time' => $cookTime,
            'total_time' => $totalTime,
            'servings' => $servings,
            'summary' => $summary,
            'ingredients' => $ingredients,
            'instructions' => $instructions,
            'image_candidates' => $cleanedImages,
        ];
    }

    /**
     * Membersihkan judul resep Cookpad dari suffix nama pembuat, hashtag, dan emoji
     */
    private function cleanCookpadTitle(string $title): string
    {
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Hapus pola "oleh [Nama]" atau "by [Nama]" di akhir judul jika terbawa
        $title = preg_replace('/\s+(?:oleh|by)\s+[^,;|\(\)]+$/iu', '', $title);

        // Hapus hashtag
        $title = preg_replace('/#\w+/u', '', $title);

        // Bersihkan spasi ganda
        $title = preg_replace('/\s+/', ' ', $title);

        return trim($title);
    }

    /**
     * Normalisasi format porsi
     */
    private function normalizeYield(string $yield): string
    {
        $yield = trim($yield);
        if (preg_match('/(\d+(?:\s*-\s*\d+)?)\s*(?:orang|porsi|servings|people|portions)?/i', $yield, $m)) {
            return $m[1] . ' Porsi';
        }
        return $yield ?: '4 Porsi';
    }

    /**
     * Ubah durasi ISO 8601 (PT30M) atau teks menjadi format "X Mins"
     */
    private function formatDuration(string $duration): string
    {
        $duration = trim($duration);
        if (empty($duration)) return '';

        if (preg_match('/^P(?:(\d+)D)?T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $duration, $m)) {
            $hours = (int)($m[2] ?? 0);
            $mins = (int)($m[3] ?? 0);
            $totalMins = ($hours * 60) + $mins;
            if ($totalMins > 0) {
                return $totalMins . ' Mins';
            }
        }

        if (preg_match('/(\d+)\s*(?:menit|mins|minutes)/i', $duration, $m)) {
            return $m[1] . ' Mins';
        }

        return $duration;
    }

    /**
     * Estimasi waktu memasak dari teks HTML Cookpad jika meta waktu kosong
     */
    private function estimateTimeFromText(string $html): array
    {
        $prep = '10 Mins';
        $cook = '20 Mins';

        if (preg_match_all('/(\d+)\s*(?:menit|mnt|mins)/i', $html, $matches)) {
            $times = array_map('intval', $matches[1]);
            $validTimes = array_filter($times, fn($t) => $t >= 5 && $t <= 180);
            if (!empty($validTimes)) {
                $cook = max($validTimes) . ' Mins';
            }
        }

        return [
            'prep_time' => $prep,
            'cook_time' => $cook,
            'total_time' => $cook
        ];
    }

    /**
     * Tingkatkan resolusi gambar CDN Cookpad ke kualitas 1200px jika memungkinkan
     */
    private function upgradeCookpadImageResolution(string $imgUrl): string
    {
        $imgUrl = trim($imgUrl);
        if (empty($imgUrl)) return '';

        // Jika URL Cookpad memuat thumbnail kecil (misal: /240x340cq50/ atau /300x426cq80/)
        // Ganti ke master resolusi tinggi /1200x630cq80/ atau /600x852cq80/
        if (str_contains($imgUrl, 'cpcdn.com/recipes/')) {
            $imgUrl = preg_replace('#/(?:240x340cq50|300x426cq80|480x680cq50)/#', '/1200x630cq80/', $imgUrl);
        }

        return $imgUrl;
    }

    /**
     * Fallback parsing via OpenGraph meta tags
     */
    private function fallbackMetaParse(string $html, string $url): ?array
    {
        $title = '';
        $desc = '';
        $image = '';

        if (preg_match('/<meta[^>]+property=[\'"]og:title[\'"][^>]+content=[\'"](.*?)[\'"]/is', $html, $m)) {
            $title = $this->cleanCookpadTitle(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('/<meta[^>]+property=[\'"]og:description[\'"][^>]+content=[\'"](.*?)[\'"]/is', $html, $m)) {
            $desc = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('/<meta[^>]+property=[\'"]og:image[\'"][^>]+content=[\'"](.*?)[\'"]/is', $html, $m)) {
            $image = trim($m[1]);
        }

        if (empty($title)) return null;

        return [
            'recipe_title' => $title,
            'prep_time' => '10 Mins',
            'cook_time' => '20 Mins',
            'total_time' => '30 Mins',
            'servings' => '4 Porsi',
            'summary' => $desc,
            'ingredients' => [],
            'instructions' => [],
            'image_candidates' => !empty($image) ? [$this->upgradeCookpadImageResolution($image)] : [],
        ];
    }
}
