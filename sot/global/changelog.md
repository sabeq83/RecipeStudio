# Changelog

## V3.2.1 — Fix Batch Orchestrator G-Labs Async, Scene Setting & Draft Publish (30/10/2026)
- Migrasi Batch Orchestrator ke alur murni G-Labs asinkron dengan polling live terminal dan jeda acak anti-bot
- Perbaikan parsing bahan dan instruksi draf Cookpad dari kolom ingredients_json dan instructions_json
- Penyempurnaan deskripsi setting lokasi background dan plating eksplisit di prompt builder Gemini untuk setiap preset gaya foto
- Koreksi undefined key array bahan dan instruksi pada batch publish
- Perbaikan binding parameter query riwayat generasi AI

## V3.2.0 — Multi-App Live Sync & AI Config Exclusive Accordion (30/10/2026)
- Single-Column dan Exclusive Single-Open Accordion di ai-config.php dengan default tertutup
- Multi-App Live Web Hosting Sync Manager dengan Bearer Token Generator per baris
- Dropdown Aplikasi Target dinamis di Batch Orchestrator importer.php
- Optimasi prompt resep multi-kategori dan perbaikan cache styling

## V3.1.0 — Redesign Tema Warna Nordic Warm (29/10/2026)
- Redesign tema warna Recipe Studio ke palet Nordic Warm (Terracotta & Sand Warm)
- Pembaruan tokens CSS airfry-suite.css dan airfry-theme.css
- Harmonisasi topbar espresso, sidebar oat, kartu konten, dan stepper wizard
- Pembaruan SOP pada AGENTS.md untuk kebijakan pengujian browser diserahkan ke pengguna

## [3.0.0] - 2026-10-29
### Rilis Perdana Recipe Studio V3 (Unified Multi-Product Hub)
- **Unified Multi-Product Hub**: Menggabungkan ekosistem aplikasi AirFryer (EN), Nusa Saji (ID), dan ProteinPrep (MealPrep) dalam 1 webadmin terpadu.
- **Product Context Switcher**: Pergantian konteks aplikasi (AirFryer / Nusa Saji / ProteinPrep) secara instan via Top Bar Navbar.
- **Universal Recipe Importer (Schema.org JSON-LD)**: Dukungan impor resep dari portal Indonesia (Cookpad, Masak Apa Hari Ini, Endeus, Sajian Sedap, dll.) serta portal global (BBC Food, Allrecipes, Tasty, dll.).
- **Modul ProteinPrep Terpadu**: Integrasi modul Prep Packs (`prep-packs.php`, `prep-pack-editor.php`) dan skema master ingredients & nutrition calculation.
- **MiniMax TTS speech-2.8-turbo**: Engine text-to-speech canggih dengan persona suara Indonesia & English serta BGM audio mixer.
- **Multi-Destination Live Hosting Sync**: Dukungan distribusi resep terisolasi ke masing-masing API server live hosting.
- **Atomic Deployment Mac Mini**: Skrip deployment terisolasi pada port 8090 (`recipe-studio`).

