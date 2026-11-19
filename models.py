import os
from sqlalchemy import create_engine, Column, Integer, String, Float, Text, Boolean, Date, DateTime, ForeignKey
from sqlalchemy.ext.declarative import declarative_base
from sqlalchemy.orm import sessionmaker, relationship
from datetime import datetime

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DATABASE_URL = os.environ.get("DATABASE_URL", f"sqlite:///{os.path.join(BASE_DIR, 'donasi_latee.db')}")

engine = create_engine(DATABASE_URL, connect_args={"check_same_thread": False})
SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)
Base = declarative_base()

class ProgramCategory(Base):
    __tablename__ = "program_categories"
    id = Column(Integer, primary_key=True, index=True)
    code = Column(String(20), unique=True, nullable=False) # IP, SPM, PML2, SK, PTWB, etc.
    name = Column(String(255), nullable=False) # Nama Lengkap Pos Akun
    qris_unit_digit = Column(String(10), nullable=True) # "1", "2", "3", etc.
    category_type = Column(String(50), default="proyek_pembangunan") # 'proyek_pembangunan' atau 'non_pembangunan'
    pic_name = Column(String(150), nullable=True) # Penanggung Jawab Pos
    description = Column(Text, nullable=True)
    is_active = Column(Boolean, default=True)

    users = relationship("User", back_populates="assigned_category")
    materials = relationship("MaterialItem", back_populates="category")

class User(Base):
    __tablename__ = "users"
    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(150), nullable=False)
    email = Column(String(150), unique=True, index=True, nullable=False)
    password = Column(String(255), nullable=False)
    role = Column(String(50), default="admin_keuangan") # admin_keuangan, pimpinan, penjab_pos, petugas_lapangan
    assigned_category_id = Column(Integer, ForeignKey("program_categories.id"), nullable=True)
    assigned_categories = Column(Text, nullable=True) # JSON or comma separated category IDs: "[1, 2, 12]"
    access_level = Column(String(50), default="full") # full, read_only, add_only, view_edit, etc.
    created_at = Column(DateTime, default=datetime.utcnow)

    assigned_category = relationship("ProgramCategory", back_populates="users")

class Account(Base):
    __tablename__ = "accounts"
    id = Column(Integer, primary_key=True, index=True)
    code = Column(String(50), unique=True, nullable=False) # kas_tunai, bank_bni, piutang, dll.
    name = Column(String(100), nullable=False) # Kas Tunai, Bank BNI 1946, Piutang
    initial_balance = Column(Float, default=0.0)
    current_balance = Column(Float, default=0.0)

class Donor(Base):
    __tablename__ = "donors"
    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(255), nullable=False, index=True)
    phone = Column(String(50), nullable=True)
    address = Column(Text, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)
    transactions = relationship("Transaction", back_populates="donor")

class Transaction(Base):
    __tablename__ = "transactions"
    id = Column(Integer, primary_key=True, index=True)
    transaction_date = Column(Date, nullable=False, index=True)
    type = Column(String(20), nullable=False) # 'income' (donasi) or 'expense' (pengeluaran)
    account_id = Column(Integer, ForeignKey("accounts.id"), nullable=False)
    category_id = Column(Integer, ForeignKey("program_categories.id"), nullable=False)
    donor_id = Column(Integer, ForeignKey("donors.id"), nullable=True)
    donor_name_raw = Column(String(255), nullable=True)
    amount = Column(Float, nullable=False)
    description = Column(Text, nullable=True)
    receipt_photo_path = Column(String(255), nullable=True)
    qris_reff_id = Column(String(100), unique=True, nullable=True)
    qris_bill_no = Column(String(100), nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)

    account = relationship("Account")
    category = relationship("ProgramCategory")
    donor = relationship("Donor", back_populates="transactions")

class DonationItem(Base):
    __tablename__ = "donation_items"
    id = Column(Integer, primary_key=True, index=True)
    transaction_date = Column(Date, nullable=False, index=True)
    donor_name = Column(String(255), nullable=False)
    item_category = Column(String(100), nullable=False) # Material Bangunan, Konsumsi Tukang, dll.
    item_name = Column(String(255), nullable=False)
    quantity = Column(Float, nullable=False)
    unit = Column(String(50), nullable=False) # Sak, Truk, Dus, Porsi, dll.
    estimated_value = Column(Float, nullable=True)
    description = Column(Text, nullable=True)
    category_id = Column(Integer, ForeignKey("program_categories.id"), nullable=True)
    photo_path = Column(String(255), nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)

    category = relationship("ProgramCategory")

# =========================================================
# MODUL BARU: TRACKING HUTANG & PIUTANG OTOMATIS
# =========================================================
class DebtTransaction(Base):
    __tablename__ = "debt_transactions"
    id = Column(Integer, primary_key=True, index=True)
    category_id = Column(Integer, ForeignKey("program_categories.id"), nullable=False)
    transaction_date = Column(Date, nullable=False, index=True)
    # jenis: 'hutang_pinjam', 'hutang_bayar', 'piutang_pinjam', 'piutang_bayar'
    type = Column(String(30), nullable=False)
    party_name = Column(String(255), nullable=False) # Kak Affan, P2AL, Toko Hidayah, Bangunan SPM, dll.
    amount = Column(Float, nullable=False)
    description = Column(Text, nullable=True)
    receipt_photo_path = Column(String(255), nullable=True)
    source_pos_tx_id = Column(Integer, ForeignKey("pos_transactions.id"), nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)

    category = relationship("ProgramCategory")
    source_pos_tx = relationship("PosTransaction")

# =========================================================
# MODUL BARU: KATALOG & PENGGUNAAN MATERIAL / BARANG LAPANGAN
# =========================================================
class MaterialItem(Base):
    __tablename__ = "material_items"
    id = Column(Integer, primary_key=True, index=True)
    category_id = Column(Integer, ForeignKey("program_categories.id"), nullable=False)
    code = Column(String(50), nullable=False) # BC, SM3, BS1, PS1, dll.
    name = Column(String(255), nullable=False) # Semen Merah Putih, Besi 10, Pasir, dll.
    unit = Column(String(50), nullable=False) # Sak, Pick up, Dump, Pcs, dll.
    unit_price = Column(Float, default=0.0) # Perkiraan harga per satuan
    initial_stock = Column(Float, default=0.0)
    current_stock = Column(Float, default=0.0)
    created_at = Column(DateTime, default=datetime.utcnow)

    category = relationship("ProgramCategory", back_populates="materials")
    transactions = relationship("MaterialTransaction", back_populates="material")

class MaterialTransaction(Base):
    __tablename__ = "material_transactions"
    id = Column(Integer, primary_key=True, index=True)
    material_id = Column(Integer, ForeignKey("material_items.id"), nullable=False)
    category_id = Column(Integer, ForeignKey("program_categories.id"), nullable=False)
    transaction_date = Column(Date, nullable=False, index=True)
    type = Column(String(20), nullable=False) # 'masuk' (Beli/Donasi Masuk) atau 'keluar' (Pemakaian Lapangan)
    quantity = Column(Float, nullable=False)
    unit_price = Column(Float, default=0.0)
    total_price = Column(Float, default=0.0)
    supplier_or_source = Column(String(255), nullable=True) # Beli di Toko / Donasi Pak Yunan / dll.
    usage_for = Column(Text, nullable=True) # Form pemakaian: 'Pengecoran dak lantai 2', 'Plester dinding', dll.
    photo_path = Column(String(255), nullable=True)
    source_pos_tx_id = Column(Integer, ForeignKey("pos_transactions.id"), nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)

    material = relationship("MaterialItem", back_populates="transactions")
    category = relationship("ProgramCategory")
    source_pos_tx = relationship("PosTransaction")

# =========================================================
# MODUL BARU: PENCATATAN TRANSAKSI KAS INTERNAL POS (TURUNAN DANA)
# =========================================================
class PosTransaction(Base):
    __tablename__ = "pos_transactions"
    id = Column(Integer, primary_key=True, index=True)
    category_id = Column(Integer, ForeignKey("program_categories.id"), nullable=False)
    transaction_date = Column(Date, nullable=False, index=True)
    # type: 'income' (alokasi dari donasi latee / penerimaan kas pos) atau 'expense' (belanja/operasional riil lapangan pos)
    type = Column(String(20), nullable=False) 
    # sumber: 'alokasi_pusat' (otomatis dari donasi), 'pendapatan_pos_langsung', 'belanja_material', 'upah_tukang', 'operasional_lapangan'
    category_tx_type = Column(String(50), default="operasional_lapangan")
    source_transaction_id = Column(Integer, ForeignKey("transactions.id"), nullable=True) # Terhubung ke donasi pusat jika ada
    amount = Column(Float, nullable=False)
    recipient_or_sender = Column(String(255), nullable=True) # Penerima dana / Toko / Sumber Alokasi
    description = Column(Text, nullable=True) # Keterangan belanja/alokasi
    receipt_photo_path = Column(String(255), nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)

    category = relationship("ProgramCategory")
    source_transaction = relationship("Transaction")

# =========================================================
# MODUL BARU: NERACA & POSISI AKTIVA/PASSIVA DINAMIS
# =========================================================
class CustomBalanceSheetItem(Base):
    __tablename__ = "custom_balance_sheet_items"
    id = Column(Integer, primary_key=True, index=True)
    category_id = Column(Integer, ForeignKey("program_categories.id"), nullable=False)
    period = Column(String(50), nullable=False) # misal '2026-05' atau 'Pekan-14-2026'
    side = Column(String(20), nullable=False) # 'aktiva' atau 'passiva'
    sub_category = Column(String(100), nullable=False) # 'harta_lancar', 'biaya_operasional', 'kewajiban', 'sumber_dana'
    code = Column(String(50), nullable=True) # K.B, K.K, M, H, H.U, dll.
    name = Column(String(255), nullable=False) # Nama baris pos
    amount_prev_month = Column(Float, default=0.0)
    amount_current_month = Column(Float, default=0.0)
    amount_total = Column(Float, default=0.0)
    is_auto = Column(Boolean, default=False)
    created_at = Column(DateTime, default=datetime.utcnow)

    category = relationship("ProgramCategory")

class ClosingPeriod(Base):
    __tablename__ = "closing_periods"
    id = Column(Integer, primary_key=True, index=True)
    year = Column(Integer, nullable=False)
    month = Column(Integer, nullable=False)
    auditor_name = Column(String(150), default="Ainul Fais")
    in_charge_name = Column(String(150), default="Penanggung Jawab Keuangan")
    is_locked = Column(Boolean, default=False)
    closed_at = Column(DateTime, default=datetime.utcnow)

class PeriodBalance(Base):
    __tablename__ = "period_balances"
    id = Column(Integer, primary_key=True, index=True)
    closing_period_id = Column(Integer, ForeignKey("closing_periods.id"), nullable=False)
    category_id = Column(Integer, ForeignKey("program_categories.id"), nullable=False)
    starting_balance = Column(Float, default=0.0)
    total_income = Column(Float, default=0.0)
    total_expense = Column(Float, default=0.0)
    ending_balance = Column(Float, default=0.0)

    category = relationship("ProgramCategory")

def init_db():
    Base.metadata.create_all(bind=engine)

if __name__ == "__main__":
    init_db()
    print("Database tables initialized successfully.")
