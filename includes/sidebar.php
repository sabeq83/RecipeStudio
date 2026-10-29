    <section>
        <!-- Left Sidebar (Mockup Style 1:1) -->
        <aside id="leftsidebar" class="sidebar">
            
            <!-- Brand -->
            <div class="sidebar-brand">
                <img src="assets/images/logo.png" alt="Recipe Studio" style="width:38px; height:38px; border-radius:10px; object-fit:cover; box-shadow:0 3px 10px rgba(194, 65, 12, 0.35);">
                <div>
                    <div class="sidebar-brand-text">Recipe Studio</div>
                    <div class="sidebar-brand-sub">Unified Multi-App Hub</div>
                </div>
            </div>

            <!-- User Info -->
            <div class="sidebar-user">
                <div class="sidebar-avatar"><?php echo strtoupper(substr($data['username'] ?? 'AS', 0, 2)); ?></div>
                <div style="flex:1; min-width:0;">
                    <div class="sidebar-user-name"><?php echo htmlspecialchars($data['username'] ?? 'Studio Admin'); ?></div>
                    <div class="sidebar-user-role">Super Administrator</div>
                </div>
                <a href="logout.php" onclick="return confirm('Are you sure want to logout?')" title="Logout" style="color:#94A3B8; text-decoration:none; display:flex; align-items:center;">
                    <i class="material-icons" style="font-size:18px;">power_settings_new</i>
                </a>
            </div>

            <!-- Menu -->
            <div class="sidebar-menu">
                <?php 
                    $page = $_SERVER['REQUEST_URI'] ?? ''; 
                    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
                ?>

                <div class="menu-header">MENU UTAMA</div>
                
                <a href="dashboard.php" class="menu-item <?php if ($script == 'dashboard.php' || $script == 'index.php') echo 'active'; ?>">
                    <i class="material-icons">dashboard</i>
                    <span>Dashboard</span>
                </a>

                <a href="category.php" class="menu-item <?php if (strpos($script, 'category') !== false) echo 'active'; ?>">
                    <i class="material-icons">view_list</i>
                    <span>Manage Category</span>
                </a>

                <a href="featured.php" class="menu-item <?php if (strpos($script, 'featured') !== false) echo 'active'; ?>">
                    <i class="material-icons">star</i>
                    <span>Manage Featured</span>
                </a>

                <a href="recipes.php" class="menu-item <?php if (strpos($script, 'recipe') !== false && $script != 'api_sync_recipe.php') echo 'active'; ?>">
                    <i class="material-icons">restaurant</i>
                    <span>Manage Recipes</span>
                </a>

                <?php if (isset($activeProduct) && $activeProduct === 'proteinprep'): ?>
                <a href="prep-packs.php" class="menu-item <?php if (strpos($script, 'prep-pack') !== false) echo 'active'; ?>" style="background:rgba(99,102,241,0.1); border-left:3px solid #818CF8;">
                    <i class="material-icons" style="color:#818CF8;">inventory_2</i>
                    <span style="color:#C7D2FE; font-weight:700;">Prep Packs</span>
                    <span class="menu-badge" style="background:#4F46E5;">Active</span>
                </a>
                <?php endif; ?>


                <?php if (defined('ENABLE_STUDIO_FEATURES') && ENABLE_STUDIO_FEATURES): ?>
                <div class="menu-header" style="margin-top:10px;">STUDIO &amp; OTOMASI</div>

                <a href="ai-generator.php" class="menu-item <?php if ($script == 'ai-generator.php') echo 'active'; ?>">
                    <i class="material-icons" style="color:var(--af-flame);">auto_awesome</i>
                    <span>AI Studio Hub</span>
                    <span class="menu-badge">3 Tools</span>
                </a>

                <a href="recipe-generator.php" class="menu-item <?php if ($script == 'recipe-generator.php') echo 'active'; ?>">
                    <i class="material-icons" style="color:#38BDF8;">auto_stories</i>
                    <span>Batch Recipe Generator</span>
                    <span class="menu-badge" style="background:#0284C7;">AI</span>
                </a>

                <a href="ai-config.php" class="menu-item <?php if ($script == 'ai-config.php') echo 'active'; ?>">
                    <i class="material-icons" style="color:var(--af-indigo);">tune</i>
                    <span>Konfigurasi AI Pool</span>
                </a>

                <a href="image-generator.php" class="menu-item <?php if ($script == 'image-generator.php') echo 'active'; ?>">
                    <i class="material-icons" style="color:#6366f1;">photo_camera</i>
                    <span>Image Studio</span>
                </a>

                <a href="video-generator.php" class="menu-item <?php if ($script == 'video-generator.php') echo 'active'; ?>">
                    <i class="material-icons" style="color:#ec4899;">videocam</i>
                    <span>Video Studio</span>
                </a>

                <a href="importer.php" class="menu-item <?php if ($script == 'importer.php' && ($_GET['tab'] ?? '') !== 'batch') echo 'active'; ?>">
                    <i class="material-icons" style="color:#10b981;">cloud_download</i>
                    <span>Impor Resep</span>
                </a>

                <a href="importer.php?tab=batch" class="menu-item <?php if ($script == 'importer.php' && ($_GET['tab'] ?? '') === 'batch') echo 'active'; ?>">
                    <i class="material-icons" style="color:#C2410C;">auto_mode</i>
                    <span>Batch Orchestrator</span>
                </a>
                <?php endif; ?>

                <div class="menu-header" style="margin-top:10px;">MONETISASI &amp; SISTEM</div>

                <a href="ads.php" class="menu-item <?php if ($script == 'ads.php') echo 'active'; ?>">
                    <i class="material-icons">monetization_on</i>
                    <span>Manage Ads</span>
                </a>

                <a href="notification.php" class="menu-item <?php if (strpos($script, 'notification') !== false) echo 'active'; ?>">
                    <i class="material-icons">notifications</i>
                    <span>Notification</span>
                </a>

                <a href="admin.php" class="menu-item <?php if (strpos($script, 'admin') !== false) echo 'active'; ?>">
                    <i class="material-icons">people</i>
                    <span>Administrators</span>
                </a>

                <a href="settings.php" class="menu-item <?php if (strpos($script, 'settings') !== false || strpos($script, 'api-key') !== false) echo 'active'; ?>">
                    <i class="material-icons">settings</i>
                    <span>Setting</span>
                </a>

                <a href="sync-destinations.php" class="menu-item <?php if ($script == 'sync-destinations.php') echo 'active'; ?>">
                    <i class="material-icons" style="color:#00BCD4;">cloud_sync</i>
                    <span>Live Sync Profil</span>
                </a>

                <a href="apps.php" class="menu-item <?php if (strpos($script, 'apps') !== false) echo 'active'; ?>">
                    <i class="material-icons">adb</i>
                    <span>Apps</span>
                </a>

                <div class="menu-header" style="margin-top:10px;">SESSION</div>

                <a href="logout.php" onclick="return confirm('Are you sure want to logout?')" class="menu-item">
                    <i class="material-icons" style="color:#EF4444;">power_settings_new</i>
                    <span style="color:#EF4444; font-weight:700;">Logout</span>
                </a>
            </div>

            <!-- Footer -->
            <div style="padding:14px 18px; border-top:1px solid #E7E5E4; font-size:11px; color:#A8A29E; margin-top:auto;">
                <div><?php echo $copyright; ?></div>
                <div style="margin-top:2px;"><b>Version: </b> <?php echo $app_version; ?></div>
            </div>
        </aside>
    </section>