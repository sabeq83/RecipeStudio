<?php

namespace Importer\Parsers;

use Exception;

/**
 * JsonLdRecipeParser
 * Parser Universal berbasis Schema.org (JSON-LD) yang mendukung portal resep
 * Indonesia (Cookpad, Masak Apa Hari Ini, Endeus, Sajian Sedap, dll)
 * dan portal resep internasional (AllRecipes, Tasty, Serious Eats, dll).
 */
class JsonLdRecipeParser implements ParserInterface
{
    /**
     * Menentukan apakah parser ini mendukung URL yang diberikan.
     * Parser ini bertindak sebagai universal parser untuk website resep yang memiliki Schema.org.
     */
    public function supports(string $url): bool
    {
        // Menerima semua URL HTTP/HTTPS kecuali BBC (yang sudah memiliki BbcFoodParser khusus)
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        return !empty($host) && !str_contains($host, 'bbc.co.uk') && !str_contains($host, 'bbc.com');
    }

    /**
     * Parse HTML dan ekstrak data resep Schema.org JSON-LD.
     */
    public function parse(string $html, string $url): array
    {
        if (empty(trim($html))) {
            throw new Exception("Halaman HTML dari sumber resep kosong.");
        }

        // 1. Ekstrak seluruh blok application/ld+json
        $recipeData = $this->extractRecipeJsonLd($html);

        if (!$recipeData) {
            // Fallback: coba ekstrak data dasar via Microdata / OpenGraph jika JSON-LD tidak lengkap
            $recipeData = $this->fallbackOpenGraphParse($html, $url);
        }

        if (!$recipeData) {
            throw new Exception("Format resep standar Schema.org (JSON-LD) tidak ditemukan pada URL ini. Pastikan halaman adalah artikel resep lengkap.");
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

        return $this->normalizeRecipeSchema($rawRecipe);
    }

    /**
     * Rekursif mencari objek Recipe di dalam JSON-LD (baik single object, array, maupun @graph)
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

        foreach ($data as $k => $v) {
            if (is_array($v) && $k !== '@graph') {
                $found = $this->findRecipeInGraph($v);
                if ($found) return $found;
            }
        }

        return null;
    }

    /**
     * Standarisasi format schema Recipe ke array yang dibutuhkan Recipe Studio
     */
    private function normalizeRecipeSchema(array $raw): array
    {
        // 1. Title
        $title = html_entity_decode(trim($raw['name'] ?? $raw['headline'] ?? 'Resep Tanpa Judul'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Summary
        $summary = html_entity_decode(trim(strip_tags($raw['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 3. Waktu (ISO 8601 durasi -> string bersahabat)
        $prepTime = $this->formatIsoDuration($raw['prepTime'] ?? '');
        $cookTime = $this->formatIsoDuration($raw['cookTime'] ?? '');
        $totalTime = $this->formatIsoDuration($raw['totalTime'] ?? '');

        // 4. Servings
        $servings = '';
        if (!empty($raw['recipeYield'])) {
            if (is_array($raw['recipeYield'])) {
                $servings = (string)reset($raw['recipeYield']);
            } else {
                $servings = (string)$raw['recipeYield'];
            }
            $servings = trim(preg_replace('/[^0-9\-–]/', ' ', $servings));
            $servings = preg_replace('/\s+/', ' ', $servings);
            if (!empty($servings)) {
                $servings .= ' porsi';
            }
        }

        // 5. Ingredients
        $ingredients = [];
        $rawIng = $raw['recipeIngredient'] ?? $raw['ingredients'] ?? [];
        if (is_array($rawIng)) {
            foreach ($rawIng as $ing) {
                if (is_string($ing) && trim($ing) !== '') {
                    $clean = html_entity_decode(trim(strip_tags($ing)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($clean !== '') {
                        $ingredients[] = $clean;
                    }
                }
            }
        }

        // 6. Instructions
        $instructions = [];
        $rawInst = $raw['recipeInstructions'] ?? [];
        if (is_string($rawInst)) {
            $parts = preg_split('/[\r\n]+/', $rawInst);
            foreach ($parts as $p) {
                $clean = html_entity_decode(trim(strip_tags($p)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($clean !== '') $instructions[] = $clean;
            }
        } elseif (is_array($rawInst)) {
            $this->extractInstructionsRecursive($rawInst, $instructions);
        }

        // 7. Image Candidates
        $imageCandidates = [];
        $rawImg = $raw['image'] ?? [];
        if (is_string($rawImg) && !empty($rawImg)) {
            $imageCandidates[] = $rawImg;
        } elseif (is_array($rawImg)) {
            if (isset($rawImg['url']) && is_string($rawImg['url'])) {
                $imageCandidates[] = $rawImg['url'];
            } else {
                foreach ($rawImg as $im) {
                    if (is_string($im) && !empty($im)) {
                        $imageCandidates[] = $im;
                    } elseif (is_array($im) && isset($im['url'])) {
                        $imageCandidates[] = $im['url'];
                    }
                }
            }
        }

        return [
            'recipe_title' => $title,
            'prep_time' => $prepTime,
            'cook_time' => $cookTime,
            'total_time' => $totalTime,
            'servings' => $servings,
            'summary' => $summary,
            'ingredients' => $ingredients,
            'instructions' => $instructions,
            'image_candidates' => array_values(array_unique($imageCandidates)),
            'raw_payload' => $raw
        ];
    }

    /**
     * Ekstraksi instruksi dari struktur bersarang HowToStep / HowToSection
     */
    private function extractInstructionsRecursive(array $items, array &$instructions): void
    {
        foreach ($items as $item) {
            if (is_string($item)) {
                $clean = html_entity_decode(trim(strip_tags($item)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($clean !== '') $instructions[] = $clean;
            } elseif (is_array($item)) {
                if (isset($item['@type']) && strcasecmp($item['@type'], 'HowToSection') === 0 && isset($item['itemListElement'])) {
                    $this->extractInstructionsRecursive((array)$item['itemListElement'], $instructions);
                } elseif (isset($item['text'])) {
                    $clean = html_entity_decode(trim(strip_tags((string)$item['text'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($clean !== '') $instructions[] = $clean;
                } elseif (isset($item['itemListElement']) && is_array($item['itemListElement'])) {
                    $this->extractInstructionsRecursive($item['itemListElement'], $instructions);
                }
            }
        }
    }

    /**
     * Konversi durasi ISO 8601 (contoh: PT20M, PT1H15M) ke teks bahasa manusia
     */
    private function formatIsoDuration($iso): string
    {
        if (empty($iso) || !is_string($iso)) return '';
        $iso = strtoupper(trim($iso));
        if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $iso, $m)) {
            return $iso; // Jika format biasa (misal "20 mins"), biarkan apa adanya
        }

        $parts = [];
        if (!empty($m[1])) $parts[] = (int)$m[1] . ' hari';
        if (!empty($m[2])) $parts[] = (int)$m[2] . ' jam';
        if (!empty($m[3])) $parts[] = (int)$m[3] . ' menit';

        return !empty($parts) ? implode(' ', $parts) : '';
    }

    /**
     * Fallback parser menggunakan OpenGraph & metadata dasar
     */
    private function fallbackOpenGraphParse(string $html, string $url): ?array
    {
        if (!preg_match('#<title[^>]*>(.*?)</title>#is', $html, $tm)) {
            return null;
        }

        $title = trim(html_entity_decode($tm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = preg_replace('/(\s*[-|–]\s*(Cookpad|Masak Apa Hari Ini|Endeus|Sajian Sedap).*)$/i', '', $title);

        $image = '';
        if (preg_match('#<meta[^>]+property=[\'"]og:image[\'"][^>]+content=[\'"]([^\'"]+)[\'"]#i', $html, $im)) {
            $image = $im[1];
        }

        $desc = '';
        if (preg_match('#<meta[^>]+(?:property=[\'"]og:description[\'"]|name=[\'"]description[\'"])[^>]+content=[\'"]([^\'"]+)[\'"]#i', $html, $dm)) {
            $desc = trim(html_entity_decode($dm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return [
            'recipe_title' => $title,
            'prep_time' => '',
            'cook_time' => '',
            'total_time' => '',
            'servings' => '',
            'summary' => $desc,
            'ingredients' => [],
            'instructions' => [],
            'image_candidates' => $image ? [$image] : [],
            'raw_payload' => ['source' => 'opengraph_fallback', 'url' => $url]
        ];
    }
}
