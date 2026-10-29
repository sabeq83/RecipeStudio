# Panduan Operasional AI Agent — Recipe Studio V3

Dokumen ini adalah aturan baku operasional (SOP) untuk Antigravity dan AI Assistant yang bekerja di repositori ini.

---

## 1. Repositori & Cabang Git
* **Repository:** `https://github.com/sabeq83/RecipeStudio.git`
* **Default Branch:** `main`
* **Host Target Remote:** Server Mac Mini (`macmini` / `100.95.245.55`)
* **Path Remote Server:** `/Users/masbenu/recipe_studio/` (Active symlink: `current/`)
* **Port Service:**
  * Local Dev: `http://127.0.0.1:8090/`
  * Mac Mini Studio: `http://100.95.245.55:8090/` (PM2 service: `recipe-studio`)

---

## 2. Prosedur Wajib Rilis & Versioning (SOP Rilis)
Setiap kali selesai mengimplementasikan fitur baru, refaktorisasi, atau bugfix (setelah pengujian lokal berhasil), agen **WAJIB** menjalankan prosedur rilis otomatis:

### A. Rilis Non-Interaktif (Sangat Direkomendasikan)
Gunakan perintah rilis otomatis untuk mengupdate versi, mencatat changelog, melakukan commit, tag Git, dan push ke GitHub:
```bash
npm run release-non-interactive -- --type patch --title "Judul Rilis Singkat" --points "Poin perubahan 1|Poin perubahan 2"
```

* `--type`: `patch` (default untuk bugfix), `minor` (fitur baru), atau `major` (perubahan arsitektur besar)
* `--title`: Judul rilis singkat dan padat
* `--points`: Rincian poin perubahan yang dipisahkan dengan tanda pipa (`|`)

### B. Sumber Kebenaran Changelog (SOT)
* Riwayat rilis dikelola secara otomatis di `sot/global/changelog.md`.
* Arsitektur deployment mengacu pada `sot/global/GIT_BASED_ATOMIC_DEPLOYMENT.md`.
* Dilarang mengedit tag atau versi secara manual di luar alur `scripts/release.js` agar tag Git dan `package.json` selalu sinkron.

---

## 3. SOP Deployment ke Mac Mini (Git-Based Atomic Deployment)
> **PENTING:** Deployment ke server Mac Mini **HANYA** boleh dijalankan jika pengguna (User) telah menguji tampilan/fungsionalitas secara lokal dan memberikan instruksi eksplisit untuk melakukan deployment. Agen **DILARANG** melakukan deployment otomatis tanpa persetujuan pengguna.

* **Inisialisasi Awal Server (Jika belum pernah di-setup):**
```bash
npm run deploy:macmini:bootstrap
```

* **Deployment Atomik (Setelah persetujuan User):**
```bash
npm run deploy:macmini
```

* Perintah di atas akan secara otomatis mem-fetch commit SHA terbaru, membuat release worktree baru, menghubungkan shared storage `upload/`, memvalidasi sintaks PHP, mengalihkan symlink `current`, dan me-reload PM2 dengan zero-downtime serta auto-rollback.
* Untuk memeriksa status rilis di server:
```bash
npm run deploy:macmini:status
```
* Untuk rollback instan bila ditemukan issue:
```bash
npm run deploy:macmini:rollback
```

---

## 4. Kebijakan Pengujian Antarmuka (Browser Testing)
* **DILARANG** menjalankan testing browser otomatis (seperti `browser_subagent` atau subagent browser otomatis lainnya) untuk pengujian UI/UX dan mockup visual.
* Seluruh pengujian tampilan antarmuka, interaksi visual, dan review browser **WAJIB diserahkan sepenuhnya kepada User**.
* Agen bertugas memvalidasi kebenaran kode (sintaks PHP, validitas CSS/JS), menyediakan file & URL lokal (misal: `http://127.0.0.1:8088/...`), serta memberikan panduan poin verifikasi yang ringkas dan jelas kepada User.

