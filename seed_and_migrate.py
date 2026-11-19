import os
import openpyxl
from datetime import datetime
from models import SessionLocal, init_db, User, Account, ProgramCategory, Donor, Transaction, DonationItem

init_db()
db = SessionLocal()

# 1. Seed Users
if not db.query(User).filter_by(email="admin@annuqayah.test").first():
    admin = User(
        name="Pengurus Keuangan",
        email="admin@annuqayah.test",
        password="admin", # In production use hashed password
        role="admin_keuangan"
    )
    pimpinan = User(
        name="Pengasuh PP Annuqayah Latee",
        email="pimpinan@annuqayah.test",
        password="admin",
        role="pimpinan"
    )
    db.add_all([admin, pimpinan])
    db.commit()
    print("Default users seeded.")

# 2. Seed Accounts
accounts_data = [
    {"code": "kas_tunai", "name": "Kas Tunai", "initial_balance": 1363000.0},
    {"code": "bank_bni", "name": "Bank BNI 1946", "initial_balance": 11327838.85},
    {"code": "piutang", "name": "Piutang", "initial_balance": 28371500.0}
]
for acc in accounts_data:
    if not db.query(Account).filter_by(code=acc["code"]).first():
        db.add(Account(**acc, current_balance=acc["initial_balance"]))
db.commit()
print("Accounts seeded.")

# 3. Seed Master Program Categories (Nama Lengkap)
categories_data = [
    {"code": "IP", "name": "Infaq Produktif", "qris_unit_digit": "1"},
    {"code": "SPM", "name": "Pembangunan SPM", "qris_unit_digit": "2"},
    {"code": "PP", "name": "Pengembangan Pembangunan Baru", "qris_unit_digit": "3"},
    {"code": "PML2", "name": "Pembangunan Mushalla Latee II", "qris_unit_digit": "4"},
    {"code": "SK", "name": "Sanitasi dan Kebersihan", "qris_unit_digit": "5"},
    {"code": "PT", "name": "Pembangunan Tahfidh", "qris_unit_digit": "6"},
    {"code": "PK", "name": "Infaq Peduli Khusus", "qris_unit_digit": "7"},
    {"code": "DL", "name": "Dana Dalfis", "qris_unit_digit": "8"},
    {"code": "PTT", "name": "Pembelian Tanah Tahap VI", "qris_unit_digit": "9"},
    {"code": "TPT", "name": "Tabungan Pembelian Tanah Tahap VI", "qris_unit_digit": "10"},
    {"code": "PPAB", "name": "Pembangunan Pagar Asrama Baru", "qris_unit_digit": "11"},
    {"code": "PTWB", "name": "Pembangunan Tempat Wudu dan Bersuci", "qris_unit_digit": "12"},
    {"code": "UM", "name": "Pengembangan Umum", "qris_unit_digit": "13"},
    {"code": "KT", "name": "Konsumsi Untuk Tukang", "qris_unit_digit": "14"},
    {"code": "STHL", "name": "Sumbangan Tanpa Digit Terakhir", "qris_unit_digit": "0"}
]
for cat in categories_data:
    existing = db.query(ProgramCategory).filter_by(code=cat["code"]).first()
    if not existing:
        db.add(ProgramCategory(**cat))
db.commit()
print("Program categories seeded.")

db.close()
