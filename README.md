# Backend - Sistem Donasi & Keuangan PP Annuqayah Latee

Layanan backend REST API menggunakan FastAPI dan SQLite untuk Sistem Donasi dan Keuangan PP Annuqayah Latee.

## 🚀 Panduan Instalasi & Menjalankan

### 1. Buat dan Aktifkan Virtual Environment
```bash
python3 -m venv venv
source venv/bin/activate
```

### 2. Install Dependensi
```bash
pip install -r requirements.txt
```

### 3. Inisialisasi Database (Jika pertama kali)
```bash
python3 seed_and_migrate.py
```

### 4. Jalankan Server API
```bash
python3 -m uvicorn main:app --host 0.0.0.0 --port 8000 --reload
```

Server API akan berjalan pada `http://localhost:8000`.  
Dokumentasi interaktif Swagger UI tersedia di `http://localhost:8000/docs`.
