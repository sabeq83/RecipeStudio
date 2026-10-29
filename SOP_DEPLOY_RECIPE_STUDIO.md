# SOP SINKRONISASI & DEPLOYMENT ATOMIK RECIPE STUDIO V3 (SERVER MAC MINI)

Dokumen standar operasional prosedur (SOP) untuk rilis dan deployment **Recipe Studio V3** ke Server Mac Mini (`100.95.245.55`) menggunakan arsitektur **Git-Based Atomic Deployment** yang terisolasi.

---

## 1. Spesifikasi Environment
* **Host Target:** `macmini` (`masbenu@100.95.245.55`)
* **Path Root Server:** `/Users/masbenu/recipe_studio/`
* **Path Aktif (Symlink):** `/Users/masbenu/recipe_studio/current/`
* **Storage Media Persisten:** `/Users/masbenu/recipe_studio/shared/upload/`
* **Service PM2:** `recipe-studio` (Port `8090`)
* **FFmpeg Server:** `/opt/homebrew/bin/ffmpeg`
* **Repositori GitHub:** `https://github.com/sabeq83/RecipeStudio.git`

> [!NOTE]
> Recipe Studio berjalan di **Port 8090**, terpisah penuh dari `airfry-studio-v2` (Port 8085) sehingga kedua sistem tidak saling mengganggu.

---

## 2. Inisialisasi Pertama Kali di Server Mac Mini (Bootstrap)

Hanya dijalankan sekali saat menyiapkan Recipe Studio pertama kali di Server Mac Mini:

```bash
npm run deploy:macmini:bootstrap
```

Perintah ini akan secara otomatis:
1. Membuat direktori `/Users/masbenu/recipe_studio/` (`releases`, `shared/upload`, `source`).
2. Melakukan clone mirror dari `https://github.com/sabeq83/RecipeStudio.git`.
3. Mengonfigurasi dan menyalakan PM2 service `recipe-studio` di port `8090`.
4. Melakukan verifikasi HTTP health check.

---

## 3. Alur Rilis & Deployment Standar (One-Command Deployment)

Setiap kali Anda selesai melakukan perubahan fitur atau pembaruan konten:

### Langkah A: Rilis Versi Baru (Git Tag & Changelog)
```bash
# Mode Interaktif (Pilih Patch, Minor, atau Major):
npm run release

# Atau Mode Cepat Non-Interaktif:
npm run release-non-interactive -- --type patch --title "Pembaruan Fitur"
```

### Langkah B: Deploy Atomik ke Mac Mini
```bash
npm run deploy:macmini
```

### Apa yang Dilakukan Otomatis oleh Skrip Deploy?
1. Mengambil Git commit SHA terbaru dari remote GitHub `RecipeStudio`.
2. Server Mac Mini membuat release worktree baru di `releases/<timestamp>-<sha>/`.
3. Menyambungkan folder `shared/upload/` secara instan.
4. Menjalankan syntax check PHP (`php -l`) pada file-file utama.
5. Memindahkan symlink atomik `current` ke rilis baru (zero-downtime).
6. Me-restart PM2 service `recipe-studio` (Port 8090).
7. Menjalankan HTTP health check otomatis.
8. Melakukan rollback otomatis jika terjadi kegagalan.
9. Membersihkan rilis usang di luar 5 rilis terakhir.

---

## 4. Perintah Operasional Tambahan

### A. Memeriksa Status Rilis di Mac Mini
```bash
npm run deploy:macmini:status
```

### B. Rollback Instan (Zero-Downtime Rollback)
Jika rilis baru memiliki masalah dan ingin kembali ke rilis sebelumnya dalam hitungan detik:
```bash
npm run deploy:macmini:rollback
```

### C. Simulasi Deployment (Dry-Run)
Untuk memeriksa alur deployment tanpa melakukan perubahan nyata di server:
```bash
npm run deploy:macmini:dry-run
```

---

## 5. Keamanan Data & Multi-App Sync
* **Media & Upload (`upload/`)**: Disimpan persisten di `/Users/masbenu/recipe_studio/shared/upload/` dan terisolasi dari folder rilis kode.
* **Multi-App Distribution**: Konten dari Recipe Studio dapat didistribusikan ke masing-masing live hosting aplikasi melalui tombol sinkronisasi:
  - AirFryer App (EN)
  - Nusa Saji App (ID)
  - ProteinPrep App (MealPrep)
