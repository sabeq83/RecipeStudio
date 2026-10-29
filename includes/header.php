<?php include_once ('session.php'); ?>
<?php
if (isset($_GET['switch_product'])) {
    $sw = trim($_GET['switch_product']);
    if (in_array($sw, ['airfry', 'nusasaji', 'proteinprep'])) {
        $_SESSION['active_product'] = $sw;
    }
    $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: $cleanUrl");
    exit;
}
$activeProduct = $_SESSION['active_product'] ?? 'airfry';

$productLabels = [
    'airfry' => ['label' => 'AirFryer', 'lang' => 'EN', 'icon' => 'local_fire_department', 'color' => '#F59E0B'],
    'nusasaji' => ['label' => 'Nusa Saji', 'lang' => 'ID', 'icon' => 'ramen_dining', 'color' => '#10B981'],
    'proteinprep' => ['label' => 'ProteinPrep', 'lang' => 'MealPrep', 'icon' => 'fitness_center', 'color' => '#6366F1'],
];
?>

<?php include_once ('config.php'); ?>
<?php include_once ('constant.php'); ?>
<?php include_once ('strings.php'); ?>
<?php include_once ('functions.php'); ?>



<?php

    $sqlSettings = "SELECT * FROM tbl_settings WHERE id = '1'";
    $resultSettings = $connect->query($sqlSettings);
    $settings_row = $resultSettings->fetch_assoc();

    $username = $_SESSION['user'];
    $sqlUser = "SELECT id, username, email FROM tbl_admin WHERE username = '$username'";
    $userResult = $connect->query($sqlUser);
    $data = $userResult->fetch_assoc();
            
?>

<!DOCTYPE html>
<html>
 
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=Edge">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
    <title><?php echo $app_name; ?></title>
    <!-- FAVICON -->
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/images/apple-touch-icon.png">

    <?php include_once ('assets/css.min.php'); ?>

</head>

<body class="theme-teal">

    <?php
        $current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    ?>
    <!-- TOP MOCKUP NAVIGATOR BAR -->
    <div class="mockup-switcher-bar">
        <div class="mockup-switcher-title">
            <i class="material-icons" style="color:#EA580C; font-size:20px;">restaurant_menu</i>
            <span style="font-weight:800; letter-spacing:0.3px;">Recipe Studio</span>
            <span class="badge-pill-top">Unified Hub</span>
        </div>

        <!-- PRODUCT CONTEXT SWITCHER -->
        <div style="display:flex; align-items:center; gap:6px; margin:0 12px; background:rgba(41,37,36,0.7); padding:4px 8px; border-radius:20px; border:1px solid #44403C;">
            <span style="font-size:10px; color:#A8A29E; font-weight:700; text-transform:uppercase; margin-right:4px;">App:</span>
            <?php foreach ($productLabels as $pCode => $pMeta): 
                $isActive = ($activeProduct === $pCode);
            ?>
                <a href="?switch_product=<?php echo $pCode; ?>" 
                   style="font-size:11px; font-weight:700; padding:4px 10px; border-radius:14px; text-decoration:none; display:flex; align-items:center; gap:5px; transition:all 0.2s; <?php echo $isActive ? 'background:'.$pMeta['color'].'; color:#FFFFFF; box-shadow:0 2px 6px rgba(0,0,0,0.3);' : 'color:#94A3B8; background:transparent;'; ?>"
                   title="Kelola Konten <?php echo $pMeta['label']; ?> (<?php echo $pMeta['lang']; ?>)">
                    <i class="material-icons" style="font-size:14px;"><?php echo $pMeta['icon']; ?></i>
                    <span><?php echo $pMeta['label']; ?></span>
                    <span style="font-size:9px; opacity:0.85; padding:1px 4px; background:rgba(0,0,0,0.25); border-radius:6px;"><?php echo $pMeta['lang']; ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="mockup-tabs">
            <a href="dashboard.php" class="mockup-tab-btn <?= in_array($current_script, ['dashboard.php', 'index.php']) ? 'active' : '' ?>">
                <i class="material-icons">dashboard</i> Dashboard
            </a>
            <a href="category.php" class="mockup-tab-btn <?= strpos($current_script, 'category') !== false ? 'active' : '' ?>">
                <i class="material-icons">view_list</i> Category
            </a>
            <a href="featured.php" class="mockup-tab-btn <?= strpos($current_script, 'featured') !== false ? 'active' : '' ?>">
                <i class="material-icons">star</i> Featured
            </a>
            <a href="recipes.php" class="mockup-tab-btn <?= (strpos($current_script, 'recipe') !== false && $current_script != 'api_sync_recipe.php') ? 'active' : '' ?>">
                <i class="material-icons">restaurant</i> Recipes
            </a>
            <?php if ($activeProduct === 'proteinprep'): ?>
            <a href="prep-packs.php" class="mockup-tab-btn <?= strpos($current_script, 'prep-pack') !== false ? 'active' : '' ?>" style="color:#A78BFA;">
                <i class="material-icons" style="color:#A78BFA;">inventory_2</i> Prep Packs
            </a>
            <?php endif; ?>

            <div class="mockup-tab-divider"></div>

            <a href="ai-generator.php" class="mockup-tab-btn <?= in_array($current_script, ['ai-generator.php', 'importer.php']) ? 'active' : '' ?>">
                <i class="material-icons" style="color:#F59E0B;">auto_awesome</i> AI Studio Hub
            </a>
            <a href="recipe-generator.php" class="mockup-tab-btn <?= $current_script == 'recipe-generator.php' ? 'active' : '' ?>">
                <i class="material-icons" style="color:#38BDF8;">auto_stories</i> Recipe Generator
            </a>
            <a href="ai-config.php" class="mockup-tab-btn <?= $current_script == 'ai-config.php' ? 'active' : '' ?>">
                <i class="material-icons" style="color:#818CF8;">tune</i> Konfigurasi AI Pool
            </a>
            <a href="image-generator.php" class="mockup-tab-btn <?= $current_script == 'image-generator.php' ? 'active' : '' ?>">
                <i class="material-icons" style="color:#60A5FA;">photo_camera</i> Image Studio
            </a>
            <a href="video-generator.php" class="mockup-tab-btn <?= $current_script == 'video-generator.php' ? 'active' : '' ?>">
                <i class="material-icons" style="color:#F472B6;">videocam</i> Video Studio
            </a>

            <div class="mockup-tab-divider"></div>

            <a href="ads.php" class="mockup-tab-btn <?= $current_script == 'ads.php' ? 'active' : '' ?>">
                <i class="material-icons">monetization_on</i> Ads
            </a>
            <a href="notification.php" class="mockup-tab-btn <?= strpos($current_script, 'notification') !== false ? 'active' : '' ?>">
                <i class="material-icons">notifications</i> Notification
            </a>
            <a href="admin.php" class="mockup-tab-btn <?= strpos($current_script, 'admin') !== false ? 'active' : '' ?>">
                <i class="material-icons">people</i> Admin
            </a>
            <a href="settings.php" class="mockup-tab-btn <?= strpos($current_script, 'settings') !== false ? 'active' : '' ?>">
                <i class="material-icons">settings</i> Settings
            </a>
            <a href="apps.php" class="mockup-tab-btn <?= strpos($current_script, 'apps') !== false ? 'active' : '' ?>">
                <i class="material-icons">adb</i> Apps
            </a>
        </div>
    </div>

<?php include_once ('sidebar.php'); ?>

    