<?php
/**
 * ProteinPrep Publication Validator
 * Enforces AC-09 and PRD Section 12 requirements before publishing recipes and packs.
 */

class PublicationValidator {
    private $mysqli;

    public function __construct(mysqli $mysqli) {
        $this->mysqli = $mysqli;
    }

    /**
     * Validates a recipe before publication
     * Rejects if:
     * - No ingredients or negative/zero quantities
     * - Nutrition not verified by human / missing
     * - Storage guidance or reheat guidance empty
     * - Servings <= 0
     */
    public function validateRecipe(int $recipe_id): array {
        $errors = array();

        // 1. Check recipe basic details
        $stmt = $this->mysqli->prepare("SELECT recipe_title, serving_size, status FROM tbl_recipes WHERE recipe_id = ?");
        $stmt->bind_param("i", $recipe_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $recipe = $res->fetch_assoc();
        $stmt->close();

        if (!$recipe) {
            return array('is_valid' => false, 'errors' => array("Recipe #$recipe_id not found"));
        }

        if ((int)$recipe['serving_size'] <= 0) {
            $errors[] = "Base serving size must be greater than 0 (found: {$recipe['serving_size']}).";
        }

        // 2. Check structured ingredients
        $stmt = $this->mysqli->prepare("SELECT COUNT(*) as cnt, MIN(quantity) as min_qty FROM tbl_recipe_ingredients WHERE recipe_id = ?");
        $stmt->bind_param("i", $recipe_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $ing = $res->fetch_assoc();
        $stmt->close();

        if ((int)$ing['cnt'] === 0) {
            $errors[] = "Recipe must have at least one structured ingredient.";
        }
        if ($ing['min_qty'] !== null && (float)$ing['min_qty'] <= 0) {
            $errors[] = "All ingredients must have positive quantities.";
        }

        // 3. Check nutrition data (Valid for AI-estimated or human-verified)
        $stmt = $this->mysqli->prepare("SELECT calories, protein_g, verified_by_human FROM tbl_recipe_nutrition WHERE recipe_id = ?");
        $stmt->bind_param("i", $recipe_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $nut = $res->fetch_assoc();
        $stmt->close();

        if (!$nut) {
            $errors[] = "Nutrition data is missing.";
        } else {
            if ((float)$nut['protein_g'] <= 0) {
                $errors[] = "Protein per portion must be greater than 0g.";
            }
            if ((int)$nut['calories'] <= 0) {
                $errors[] = "Calories per portion must be greater than 0 kcal.";
            }
        }

        // 4. Check mandatory post-cook guidance
        $stmt = $this->mysqli->prepare("SELECT store_guidance, reheat_guidance FROM tbl_recipe_guidance WHERE recipe_id = ?");
        $stmt->bind_param("i", $recipe_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $guidance = $res->fetch_assoc();
        $stmt->close();

        if (!$guidance || empty(trim($guidance['store_guidance']))) {
            $errors[] = "Mandatory storage guidance is missing.";
        }
        if (!$guidance || empty(trim($guidance['reheat_guidance']))) {
            $errors[] = "Mandatory reheating guidance is missing.";
        }

        return array(
            'is_valid' => count($errors) === 0,
            'errors' => $errors
        );
    }

    /**
     * Validates a prep pack before publication
     * Rejects if:
     * - Fewer than 2 constituent recipes
     * - Any constituent recipe fails validateRecipe()
     * - Missing portion options
     * - Missing sequential steps
     * - Missing active or total time
     */
    public function validatePack(int $pack_id): array {
        $errors = array();

        $stmt = $this->mysqli->prepare("SELECT pack_name, active_time_minutes, total_time_minutes FROM tbl_prep_packs WHERE pack_id = ?");
        $stmt->bind_param("i", $pack_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $pack = $res->fetch_assoc();
        $stmt->close();

        if (!$pack) {
            return array('is_valid' => false, 'errors' => array("Prep pack #$pack_id not found"));
        }

        if ((int)$pack['total_time_minutes'] <= 0) {
            $errors[] = "Total time minutes must be greater than 0.";
        }

        // Check constituent recipes
        $stmt = $this->mysqli->prepare("SELECT recipe_id FROM tbl_prep_pack_recipes WHERE pack_id = ? ORDER BY sort_order ASC");
        $stmt->bind_param("i", $pack_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $recipes = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($recipes) < 2) {
            $errors[] = "Prep pack must contain at least 2 constituent recipes (found: " . count($recipes) . ").";
        }

        foreach ($recipes as $r) {
            $r_check = $this->validateRecipe((int)$r['recipe_id']);
            if (!$r_check['is_valid']) {
                foreach ($r_check['errors'] as $err) {
                    $errors[] = "Recipe #{$r['recipe_id']}: $err";
                }
            }
        }

        // Check portion options
        $stmt = $this->mysqli->prepare("SELECT COUNT(*) as opt_cnt FROM tbl_prep_pack_options WHERE pack_id = ?");
        $stmt->bind_param("i", $pack_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $opt = $res->fetch_assoc();
        $stmt->close();

        if ((int)$opt['opt_cnt'] === 0) {
            $errors[] = "Prep pack must have at least one defined portion option.";
        }

        // Check sequential prep steps
        $stmt = $this->mysqli->prepare("SELECT COUNT(*) as step_cnt FROM tbl_prep_pack_steps WHERE pack_id = ?");
        $stmt->bind_param("i", $pack_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $step = $res->fetch_assoc();
        $stmt->close();

        if ((int)$step['step_cnt'] === 0) {
            $errors[] = "Prep pack must have sequential cooking steps.";
        }

        return array(
            'is_valid' => count($errors) === 0,
            'errors' => $errors
        );
    }
}
