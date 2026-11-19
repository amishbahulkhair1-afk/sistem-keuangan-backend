from fastapi import FastAPI, HTTPException, Depends, UploadFile, File, Form
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from sqlalchemy.orm import Session
from sqlalchemy import func, extract
from pydantic import BaseModel
from typing import Optional, List
from datetime import date, datetime
import openpyxl
import os
import re
import json

from models import SessionLocal, User, Account, ProgramCategory, Donor, Transaction, DonationItem, ClosingPeriod, PeriodBalance, DebtTransaction, MaterialItem, MaterialTransaction, CustomBalanceSheetItem, PosTransaction

app = FastAPI(title="Sistem Donasi & Keuangan PP Annuqayah Latee")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

UPLOAD_DIR = os.environ.get("UPLOAD_DIR", os.path.join(os.path.dirname(os.path.abspath(__file__)), "uploads"))
os.makedirs(UPLOAD_DIR, exist_ok=True)
app.mount("/uploads", StaticFiles(directory=UPLOAD_DIR), name="uploads")

def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()

def sanitize_donor_name(name_raw: str) -> str:
    if not name_raw:
        return "Hamba Allah"
    cleaned = re.sub(r'[\*\_]', ' ', str(name_raw))
    cleaned = ' '.join(cleaned.split())
    return cleaned.title()

# --- AUTH API ---
class LoginRequest(BaseModel):
    email: str
    password: str

@app.post("/api/auth/login")
@app.post("/api/login")
def login(req: LoginRequest, db: Session = Depends(get_db)):
    user = db.query(User).filter(User.email == req.email).first()
    if not user or user.password != req.password:
        raise HTTPException(status_code=401, detail="Email atau password salah")
    
    assigned_cats_list = []
    if user.assigned_categories:
        try:
            assigned_cats_list = json.loads(user.assigned_categories)
        except:
            assigned_cats_list = [int(x.strip()) for x in user.assigned_categories.split(",") if x.strip().isdigit()]
    elif user.assigned_category_id:
        assigned_cats_list = [user.assigned_category_id]

    return {
        "token": f"token-{user.id}-{user.role}",
        "user": {
            "id": user.id,
            "name": user.name,
            "email": user.email,
            "role": user.role,
            "access_level": user.access_level or "full",
            "assigned_category_id": user.assigned_category_id,
            "assigned_categories": assigned_cats_list,
            "assigned_category_code": user.assigned_category.code if user.assigned_category else None,
            "assigned_category_name": user.assigned_category.name if user.assigned_category else None
        }
    }

# =========================================================
# DATA MASTER CRUD API (POS PROGRAM, REKENING, DONATUR, USER)
# =========================================================

# 1. Master Pos Program (Dinamis & Fleksibel)
class CategoryCreate(BaseModel):
    code: str
    name: str
    qris_unit_digit: Optional[str] = None
    category_type: Optional[str] = "proyek_pembangunan" # 'proyek_pembangunan' / 'non_pembangunan'
    pic_name: Optional[str] = None
    description: Optional[str] = None
    is_active: Optional[bool] = True

class CategoryUpdate(BaseModel):
    name: str
    qris_unit_digit: Optional[str] = None
    category_type: Optional[str] = "proyek_pembangunan"
    pic_name: Optional[str] = None
    description: Optional[str] = None
    is_active: Optional[bool] = True

@app.get("/api/categories")
def get_categories(db: Session = Depends(get_db)):
    categories = db.query(ProgramCategory).all()
    return [{
        "id": c.id,
        "code": c.code,
        "nama_kategori": c.name,
        "name": c.name,
        "qris_digit": c.qris_unit_digit,
        "category_type": c.category_type or "proyek_pembangunan",
        "pic_name": c.pic_name or "-",
        "description": c.description or "-",
        "is_active": c.is_active,
        "tipe": "program"
    } for c in categories]

@app.post("/api/categories")
def create_category(req: CategoryCreate, db: Session = Depends(get_db)):
    code_upper = req.code.strip().upper()
    existing = db.query(ProgramCategory).filter(ProgramCategory.code == code_upper).first()
    if existing:
        raise HTTPException(status_code=400, detail=f"Kode pos '{code_upper}' sudah ada")
    cat = ProgramCategory(
        code=code_upper,
        name=req.name.strip(),
        qris_unit_digit=req.qris_unit_digit.strip() if req.qris_unit_digit else None,
        category_type=req.category_type or "proyek_pembangunan",
        pic_name=req.pic_name.strip() if req.pic_name else None,
        description=req.description.strip() if req.description else None,
        is_active=req.is_active
    )
    db.add(cat)
    db.commit()
    db.refresh(cat)
    return {"id": cat.id, "message": f"Pos program '{cat.name}' berhasil ditambahkan"}

@app.put("/api/categories/{category_id}")
def update_category(category_id: int, req: CategoryUpdate, db: Session = Depends(get_db)):
    cat = db.query(ProgramCategory).filter(ProgramCategory.id == category_id).first()
    if not cat:
        raise HTTPException(status_code=404, detail="Pos program tidak ditemukan")
    cat.name = req.name.strip()
    cat.qris_unit_digit = req.qris_unit_digit.strip() if req.qris_unit_digit else None
    cat.category_type = req.category_type or "proyek_pembangunan"
    cat.pic_name = req.pic_name.strip() if req.pic_name else None
    cat.description = req.description.strip() if req.description else None
    cat.is_active = req.is_active
    db.commit()
    return {"message": f"Pos program '{cat.name}' berhasil diperbarui"}

@app.delete("/api/categories/{category_id}")
def delete_category(category_id: int, db: Session = Depends(get_db)):
    cat = db.query(ProgramCategory).filter(ProgramCategory.id == category_id).first()
    if not cat:
        raise HTTPException(status_code=404, detail="Pos program tidak ditemukan")
    # Soft delete / toggle active
    cat.is_active = False
    db.commit()
    return {"message": f"Pos program '{cat.name}' dinonaktifkan"}

# 2. Master Akun Rekening / Sumber Dana
class AccountCreate(BaseModel):
    code: str
    name: str
    initial_balance: Optional[float] = 0.0

class AccountUpdate(BaseModel):
    name: str
    initial_balance: Optional[float] = 0.0

@app.get("/api/accounts")
def get_accounts(db: Session = Depends(get_db)):
    accounts = db.query(Account).all()
    return [{
        "id": a.id,
        "code": a.code,
        "name": a.name,
        "initial_balance": a.initial_balance,
        "current_balance": a.current_balance
    } for a in accounts]

@app.post("/api/accounts")
def create_account(req: AccountCreate, db: Session = Depends(get_db)):
    code_lower = req.code.strip().lower()
    existing = db.query(Account).filter(Account.code == code_lower).first()
    if existing:
        raise HTTPException(status_code=400, detail=f"Kode rekening '{code_lower}' sudah ada")
    acc = Account(
        code=code_lower,
        name=req.name.strip(),
        initial_balance=req.initial_balance or 0.0,
        current_balance=req.initial_balance or 0.0
    )
    db.add(acc)
    db.commit()
    db.refresh(acc)
    return {"id": acc.id, "message": f"Rekening '{acc.name}' berhasil ditambahkan"}

@app.put("/api/accounts/{account_id}")
def update_account(account_id: int, req: AccountUpdate, db: Session = Depends(get_db)):
    acc = db.query(Account).filter(Account.id == account_id).first()
    if not acc:
        raise HTTPException(status_code=404, detail="Rekening tidak ditemukan")
    acc.name = req.name.strip()
    if req.initial_balance is not None:
        diff = req.initial_balance - acc.initial_balance
        acc.initial_balance = req.initial_balance
        acc.current_balance += diff
    db.commit()
    return {"message": f"Rekening '{acc.name}' berhasil diperbarui"}

@app.delete("/api/accounts/{account_id}")
def delete_account(account_id: int, db: Session = Depends(get_db)):
    acc = db.query(Account).filter(Account.id == account_id).first()
    if not acc:
        raise HTTPException(status_code=404, detail="Rekening tidak ditemukan")
    # Cek apakah ada transaksi menggunakan akun ini
    tx_count = db.query(Transaction).filter(Transaction.account_id == account_id).count()
    if tx_count > 0:
        raise HTTPException(status_code=400, detail=f"Tidak dapat menghapus rekening karena memiliki {tx_count} transaksi terhubung")
    db.delete(acc)
    db.commit()
    return {"message": f"Rekening '{acc.name}' berhasil dihapus"}

# 3. Master Donatur (CRM)
class DonorCreate(BaseModel):
    name: str
    phone: Optional[str] = None
    address: Optional[str] = None

class DonorUpdate(BaseModel):
    name: str
    phone: Optional[str] = None
    address: Optional[str] = None

@app.get("/api/donors")
def get_donors(
    search: Optional[str] = None,
    pos: Optional[str] = None,
    start_date: Optional[str] = None,
    end_date: Optional[str] = None,
    db: Session = Depends(get_db)
):
    query = db.query(Donor)
    if search:
        query = query.filter(Donor.name.ilike(f"%{search}%"))
    donors = query.order_by(Donor.name.asc()).limit(500).all()
    
    # Parse range date jika ada
    tgl_awal = None
    tgl_akhir = None
    if start_date:
        try:
            tgl_awal = datetime.strptime(start_date, "%Y-%m-%d").date()
        except:
            pass
    if end_date:
        try:
            tgl_akhir = datetime.strptime(end_date, "%Y-%m-%d").date()
        except:
            pass

    # Filter pos category
    selected_cat = None
    if pos and pos.strip():
        pos_clean = pos.strip()
        selected_cat = db.query(ProgramCategory).filter(
            (ProgramCategory.code == pos_clean.upper()) |
            (ProgramCategory.name == pos_clean) |
            (ProgramCategory.id == int(pos_clean) if pos_clean.isdigit() else False)
        ).first()

    result = []
    for d in donors:
        tx_query = db.query(Transaction).filter(Transaction.donor_id == d.id, Transaction.type == "income")
        if selected_cat:
            tx_query = tx_query.filter(Transaction.category_id == selected_cat.id)
        if tgl_awal and tgl_akhir:
            tx_query = tx_query.filter(Transaction.transaction_date >= tgl_awal, Transaction.transaction_date <= tgl_akhir)
        elif tgl_awal:
            tx_query = tx_query.filter(Transaction.transaction_date >= tgl_awal)
        elif tgl_akhir:
            tx_query = tx_query.filter(Transaction.transaction_date <= tgl_akhir)

        txs = tx_query.order_by(Transaction.transaction_date.desc()).all()
        
        # Jika filter pos aktif dan donatur ini tidak punya donasi di pos tersebut, lewati
        if selected_cat and len(txs) == 0:
            continue

        total_donasi = sum(t.amount for t in txs)
        frekuensi = len(txs)
        
        # Breakdown donasi per pos program
        pos_map = {}
        for t in txs:
            c_name = t.category.name if t.category else "Umum"
            c_code = t.category.code if t.category else "-"
            pos_key = f"[{c_code}] {c_name}"
            pos_map[pos_key] = pos_map.get(pos_key, 0) + t.amount
        
        pos_breakdown = ", ".join([f"{k}: Rp {v:,.0f}".replace(",", ".") for k, v in pos_map.items()]) if pos_map else "-"

        # Ambil ringkasan riwayat transaksi
        tx_details = []
        for t in txs[:10]: # 10 transaksi terakhir
            tx_details.append({
                "id": t.id,
                "tanggal": t.transaction_date.strftime("%Y-%m-%d") if t.transaction_date else "-",
                "nominal": t.amount,
                "pos_program": t.category.name if t.category else "-",
                "pos_code": t.category.code if t.category else "-",
                "metode": t.account.name if t.account else "-",
                "keterangan": t.description or "-"
            })

        result.append({
            "id": d.id,
            "name": d.name,
            "phone": d.phone or "-",
            "address": d.address or "-",
            "total_donasi": total_donasi,
            "frekuensi": frekuensi,
            "pos_breakdown": pos_breakdown,
            "tx_details": tx_details,
            "created_at": str(d.created_at.date()) if d.created_at else "-"
        })
    return result

@app.post("/api/donors")
def create_donor(req: DonorCreate, db: Session = Depends(get_db)):
    clean_name = sanitize_donor_name(req.name)
    existing = db.query(Donor).filter(Donor.name == clean_name).first()
    if existing:
        return {"id": existing.id, "message": f"Donatur '{clean_name}' sudah terdaftar"}
    donor = Donor(
        name=clean_name,
        phone=req.phone,
        address=req.address
    )
    db.add(donor)
    db.commit()
    db.refresh(donor)
    return {"id": donor.id, "message": f"Donatur '{donor.name}' berhasil didaftarkan"}

@app.put("/api/donors/{donor_id}")
def update_donor(donor_id: int, req: DonorUpdate, db: Session = Depends(get_db)):
    donor = db.query(Donor).filter(Donor.id == donor_id).first()
    if not donor:
        raise HTTPException(status_code=404, detail="Donatur tidak ditemukan")
    donor.name = sanitize_donor_name(req.name)
    donor.phone = req.phone
    donor.address = req.address
    db.commit()
    return {"message": f"Data donatur '{donor.name}' berhasil diperbarui"}

@app.delete("/api/donors/{donor_id}")
def delete_donor(donor_id: int, db: Session = Depends(get_db)):
    donor = db.query(Donor).filter(Donor.id == donor_id).first()
    if not donor:
        raise HTTPException(status_code=404, detail="Donatur tidak ditemukan")
    # Lepaskan relasi transaksi jika ada
    db.query(Transaction).filter(Transaction.donor_id == donor_id).update({Transaction.donor_id: None})
    db.delete(donor)
    db.commit()
    return {"message": f"Donatur '{donor.name}' berhasil dihapus"}

# 4. Master Pengguna / Petugas
class UserCreate(BaseModel):
    name: str
    email: str
    password: str
    role: Optional[str] = "admin_keuangan" # admin_keuangan, pimpinan, penjab_pos, petugas_lapangan
    assigned_category_id: Optional[int] = None
    assigned_categories: Optional[List[int]] = None # Multiple Assigned Pos IDs: [1, 2, 12]
    access_level: Optional[str] = "full" # full, read_only, add_only, view_edit

class UserUpdate(BaseModel):
    name: str
    email: str
    password: Optional[str] = None
    role: Optional[str] = "admin_keuangan"
    assigned_category_id: Optional[int] = None
    assigned_categories: Optional[List[int]] = None
    access_level: Optional[str] = "full"

@app.get("/api/users")
def get_users(db: Session = Depends(get_db)):
    users = db.query(User).all()
    all_categories = {c.id: c for c in db.query(ProgramCategory).all()}
    result = []
    for u in users:
        cats_ids = []
        if u.assigned_categories:
            try:
                cats_ids = json.loads(u.assigned_categories)
            except:
                cats_ids = [int(x.strip()) for x in u.assigned_categories.split(",") if x.strip().isdigit()]
        elif u.assigned_category_id:
            cats_ids = [u.assigned_category_id]

        pos_labels = []
        for cid in cats_ids:
            if cid in all_categories:
                pos_labels.append(f"[{all_categories[cid].code}] {all_categories[cid].name}")

        result.append({
            "id": u.id,
            "name": u.name,
            "email": u.email,
            "role": u.role,
            "access_level": u.access_level or "full",
            "assigned_category_id": u.assigned_category_id,
            "assigned_categories": cats_ids,
            "assigned_category_name": u.assigned_category.name if u.assigned_category else "-",
            "assigned_category_code": u.assigned_category.code if u.assigned_category else "-",
            "pos_labels": pos_labels,
            "created_at": str(u.created_at.date()) if u.created_at else "-"
        })
    return result

@app.post("/api/users")
def create_user(req: UserCreate, db: Session = Depends(get_db)):
    existing = db.query(User).filter(User.email == req.email.strip()).first()
    if existing:
        raise HTTPException(status_code=400, detail="Email sudah digunakan")
    
    cats_json = None
    primary_cat_id = req.assigned_category_id
    if req.assigned_categories and len(req.assigned_categories) > 0:
        cats_json = json.dumps(req.assigned_categories)
        if not primary_cat_id:
            primary_cat_id = req.assigned_categories[0]
    elif req.assigned_category_id:
        cats_json = json.dumps([req.assigned_category_id])

    user = User(
        name=req.name.strip(),
        email=req.email.strip(),
        password=req.password,
        role=req.role or "admin_keuangan",
        assigned_category_id=primary_cat_id,
        assigned_categories=cats_json,
        access_level=req.access_level or "full"
    )
    db.add(user)
    db.commit()
    db.refresh(user)
    return {"id": user.id, "message": f"Petugas '{user.name}' berhasil ditambahkan"}

@app.put("/api/users/{user_id}")
def update_user(user_id: int, req: UserUpdate, db: Session = Depends(get_db)):
    user = db.query(User).filter(User.id == user_id).first()
    if not user:
        raise HTTPException(status_code=404, detail="Petugas tidak ditemukan")
    existing = db.query(User).filter(User.email == req.email.strip(), User.id != user_id).first()
    if existing:
        raise HTTPException(status_code=400, detail="Email sudah digunakan petugas lain")
    
    cats_json = None
    primary_cat_id = req.assigned_category_id
    if req.assigned_categories is not None:
        cats_json = json.dumps(req.assigned_categories)
        if len(req.assigned_categories) > 0:
            if not primary_cat_id or primary_cat_id not in req.assigned_categories:
                primary_cat_id = req.assigned_categories[0]
        else:
            primary_cat_id = None
    elif req.assigned_category_id:
        cats_json = json.dumps([req.assigned_category_id])

    user.name = req.name.strip()
    user.email = req.email.strip()
    user.role = req.role or "admin_keuangan"
    user.assigned_category_id = primary_cat_id
    user.assigned_categories = cats_json
    user.access_level = req.access_level or "full"
    if req.password:
        user.password = req.password
    db.commit()
    return {"message": f"Data petugas '{user.name}' berhasil diperbarui"}

@app.delete("/api/users/{user_id}")
def delete_user(user_id: int, db: Session = Depends(get_db)):
    user = db.query(User).filter(User.id == user_id).first()
    if not user:
        raise HTTPException(status_code=404, detail="Petugas tidak ditemukan")
    db.delete(user)
    db.commit()
    return {"message": f"Petugas '{user.name}' berhasil dihapus"}

# =========================================================
# MODUL 5: TRACKING TRANSAKSI HUTANG & PIUTANG OTOMATIS
# =========================================================
class DebtCreate(BaseModel):
    category_id: int
    transaction_date: Optional[str] = None
    type: str # 'hutang_pinjam', 'hutang_bayar', 'piutang_pinjam', 'piutang_bayar'
    party_name: str
    amount: float
    description: Optional[str] = None

@app.get("/api/debts")
def get_debts(
    category_id: Optional[int] = None,
    type_group: Optional[str] = None, # 'hutang' atau 'piutang'
    party_name: Optional[str] = None,
    db: Session = Depends(get_db)
):
    query = db.query(DebtTransaction)
    if category_id:
        query = query.filter(DebtTransaction.category_id == category_id)
    if type_group == "hutang":
        query = query.filter(DebtTransaction.type.in_(["hutang_pinjam", "hutang_bayar"]))
    elif type_group == "piutang":
        query = query.filter(DebtTransaction.type.in_(["piutang_pinjam", "piutang_bayar"]))
    if party_name:
        query = query.filter(DebtTransaction.party_name.ilike(f"%{party_name}%"))
        
    debts = query.order_by(DebtTransaction.transaction_date.desc(), DebtTransaction.id.desc()).all()
    return [{
        "id": d.id,
        "category_id": d.category_id,
        "pos_code": d.category.code if d.category else "-",
        "pos_name": d.category.name if d.category else "-",
        "transaction_date": str(d.transaction_date),
        "type": d.type,
        "party_name": d.party_name,
        "amount": d.amount,
        "description": d.description,
        "bukti_foto": f"/uploads/{os.path.basename(d.receipt_photo_path)}" if d.receipt_photo_path else None
    } for d in debts]

@app.get("/api/debts/recap")
def get_debts_recap(category_id: Optional[int] = None, db: Session = Depends(get_db)):
    query = db.query(DebtTransaction)
    if category_id:
        query = query.filter(DebtTransaction.category_id == category_id)
    all_txs = query.all()
    
    hutang_recap = {}
    piutang_recap = {}
    
    for t in all_txs:
        p_name = t.party_name.strip()
        if t.type in ["hutang_pinjam", "hutang_bayar"]:
            if p_name not in hutang_recap:
                hutang_recap[p_name] = {"party_name": p_name, "total_pinjam": 0.0, "total_bayar": 0.0, "saldo": 0.0}
            if t.type == "hutang_pinjam":
                hutang_recap[p_name]["total_pinjam"] += t.amount
            else:
                hutang_recap[p_name]["total_bayar"] += t.amount
            hutang_recap[p_name]["saldo"] = hutang_recap[p_name]["total_pinjam"] - hutang_recap[p_name]["total_bayar"]
        elif t.type in ["piutang_pinjam", "piutang_bayar"]:
            if p_name not in piutang_recap:
                piutang_recap[p_name] = {"party_name": p_name, "total_piutang": 0.0, "total_bayar": 0.0, "saldo": 0.0}
            if t.type == "piutang_pinjam":
                piutang_recap[p_name]["total_piutang"] += t.amount
            else:
                piutang_recap[p_name]["total_bayar"] += t.amount
            piutang_recap[p_name]["saldo"] = piutang_recap[p_name]["total_piutang"] - piutang_recap[p_name]["total_bayar"]
            
    total_sisa_hutang = sum(h["saldo"] for h in hutang_recap.values())
    total_sisa_piutang = sum(p["saldo"] for p in piutang_recap.values())
    
    return {
        "hutang_list": list(hutang_recap.values()),
        "piutang_list": list(piutang_recap.values()),
        "total_sisa_hutang": total_sisa_hutang,
        "total_sisa_piutang": total_sisa_piutang
    }

@app.post("/api/debts")
def create_debt_transaction(req: DebtCreate, db: Session = Depends(get_db)):
    tx_d = datetime.strptime(req.transaction_date, "%Y-%m-%d").date() if req.transaction_date else date.today()
    dt = DebtTransaction(
        category_id=req.category_id,
        transaction_date=tx_d,
        type=req.type,
        party_name=req.party_name.strip(),
        amount=req.amount,
        description=req.description
    )
    db.add(dt)
    db.commit()
    db.refresh(dt)
    return {"id": dt.id, "message": "Pencatatan hutang/piutang berhasil disimpan"}

@app.delete("/api/debts/{debt_id}")
def delete_debt_transaction(debt_id: int, db: Session = Depends(get_db)):
    dt = db.query(DebtTransaction).filter(DebtTransaction.id == debt_id).first()
    if not dt:
        raise HTTPException(status_code=404, detail="Data hutang/piutang tidak ditemukan")
    db.delete(dt)
    db.commit()
    return {"message": "Data transaksi hutang/piutang berhasil dihapus"}

# =========================================================
# MODUL BARU: PENCATATAN KAS & TRANSAKSI RIIL POS PROGRAM (TURUNAN DANA)
# =========================================================
class PosTxCreate(BaseModel):
    category_id: int
    transaction_date: Optional[str] = None
    type: str # 'income' (penerimaan kas pos) atau 'expense' (belanja/operasional riil pos)
    category_tx_type: Optional[str] = "operasional_lapangan" # 'alokasi_pusat', 'pendapatan_pos_langsung', 'belanja_material', 'upah_tukang', 'operasional_lapangan'
    amount: Optional[float] = 0.0
    recipient_or_sender: Optional[str] = None
    description: Optional[str] = None
    material_id: Optional[int] = None
    material_name: Optional[str] = None
    material_brand: Optional[str] = None
    material_quantity: Optional[float] = None
    material_unit: Optional[str] = None
    material_price: Optional[float] = None

@app.get("/api/pos-transactions")
def get_pos_transactions(category_id: Optional[int] = None, tx_type: Optional[str] = None, db: Session = Depends(get_db)):
    query = db.query(PosTransaction)
    if category_id:
        query = query.filter(PosTransaction.category_id == category_id)
    if tx_type:
        query = query.filter(PosTransaction.type == tx_type)
        
    txs = query.order_by(PosTransaction.transaction_date.desc(), PosTransaction.id.desc()).all()
    
    # Hitung Saldo Kas Internal Pos
    total_in = sum(t.amount for t in txs if t.type == "income")
    total_out = sum(t.amount for t in txs if t.type == "expense")
    saldo_kas_pos = total_in - total_out
    
    return {
        "transactions": [{
            "id": t.id,
            "category_id": t.category_id,
            "pos_code": t.category.code if t.category else "-",
            "pos_name": t.category.name if t.category else "-",
            "transaction_date": str(t.transaction_date),
            "type": t.type,
            "category_tx_type": t.category_tx_type,
            "source_transaction_id": t.source_transaction_id,
            "amount": t.amount,
            "recipient_or_sender": t.recipient_or_sender or "-",
            "description": t.description or "-",
            "bukti_foto": f"/uploads/{os.path.basename(t.receipt_photo_path)}" if t.receipt_photo_path else None
        } for t in txs],
        "summary": {
            "total_income": total_in,
            "total_expense": total_out,
            "saldo_kas_pos": saldo_kas_pos
        }
    }

@app.post("/api/pos-transactions")
def create_pos_transaction(req: PosTxCreate, db: Session = Depends(get_db)):
    tx_d = datetime.strptime(req.transaction_date, "%Y-%m-%d").date() if req.transaction_date else date.today()
    cat_type = req.category_tx_type or "operasional_lapangan"
    
    # Otomatis tentukan tipe arus kas jika kategori adalah hutang/piutang
    tx_type = req.type
    if cat_type in ["hutang_pinjam", "piutang_bayar"]:
        tx_type = "income"
    elif cat_type in ["hutang_bayar", "piutang_pinjam", "belanja_material"]:
        tx_type = "expense"

    # Hitung nominal transaksi jika berasal dari material (qty * harga)
    final_amount = float(req.amount or 0.0)
    if final_amount <= 0 and req.material_quantity and req.material_price:
        final_amount = float(req.material_quantity) * float(req.material_price)

    # Otomatis susun deskripsi jika belum diisi
    final_desc = req.description.strip() if req.description else ""
    if not final_desc and (req.material_name or req.material_id):
        m_name = req.material_name or "Material"
        m_brand = f" {req.material_brand.strip()}" if req.material_brand else ""
        m_qty = f" {req.material_quantity}" if req.material_quantity else ""
        m_unit = f" {req.material_unit}" if req.material_unit else ""
        final_desc = f"Beli {m_name}{m_brand}{m_qty}{m_unit}".strip()
        
    pt = PosTransaction(
        category_id=req.category_id,
        transaction_date=tx_d,
        type=tx_type,
        category_tx_type=cat_type,
        amount=final_amount,
        recipient_or_sender=req.recipient_or_sender.strip() if req.recipient_or_sender else None,
        description=final_desc if final_desc else None
    )
    db.add(pt)
    db.commit()
    db.refresh(pt)

    # 1. Jika transaksi ini merupakan Hutang / Piutang, sinkronkan otomatis ke modul DebtTransaction
    if cat_type in ["hutang_pinjam", "hutang_bayar", "piutang_pinjam", "piutang_bayar"]:
        party = req.recipient_or_sender.strip() if req.recipient_or_sender else "Pihak Terkait"
        dt = DebtTransaction(
            category_id=req.category_id,
            transaction_date=tx_d,
            type=cat_type,
            party_name=party,
            amount=final_amount,
            description=final_desc if final_desc else None,
            source_pos_tx_id=pt.id
        )
        db.add(dt)
        db.commit()

    # 2. Material Otomatis: Jika ada material_id ATAU nama material diisi (input langsung / otomatis katalog)
    mat_to_record = None
    if req.material_id:
        mat_to_record = db.query(MaterialItem).filter(MaterialItem.id == req.material_id).first()
    elif req.material_name and req.material_name.strip():
        # Pembersihan data & standarisasi nama + merek
        raw_name = req.material_name.strip()
        raw_brand = req.material_brand.strip() if req.material_brand else ""
        
        full_display_name = f"{raw_name} {raw_brand}".strip() if raw_brand else raw_name
        full_display_name = " ".join(full_display_name.split()) # normalisasi spasi
        
        # Cari apakah sudah ada di katalog pos ini (case-insensitive & pembersihan nama)
        existing_mats = db.query(MaterialItem).filter(MaterialItem.category_id == req.category_id).all()
        matched = None
        for em in existing_mats:
            if em.name.strip().lower() == full_display_name.lower() or em.name.strip().lower() == raw_name.lower():
                matched = em
                break
        
        if matched:
            mat_to_record = matched
        else:
            # Generate kode material otomatis (misal: SMR, SM, dsb)
            words = full_display_name.split()
            base_code = ("".join(w[0] for w in words if w))[:4].upper() or "MAT"
            # Pastikan kode unik di pos ini
            existing_codes = {em.code.upper() for em in existing_mats}
            mat_code = base_code
            counter = 1
            while mat_code in existing_codes:
                mat_code = f"{base_code}{counter}"
                counter += 1
            
            unit_val = req.material_unit.strip() if req.material_unit else "Pcs"
            unit_p_val = float(req.material_price or 0.0)
            if unit_p_val <= 0 and req.material_quantity and float(req.material_quantity) > 0:
                unit_p_val = req.amount / float(req.material_quantity)
            
            new_mat = MaterialItem(
                category_id=req.category_id,
                code=mat_code,
                name=full_display_name,
                unit=unit_val,
                unit_price=unit_p_val,
                initial_stock=0.0,
                current_stock=0.0
            )
            db.add(new_mat)
            db.commit()
            db.refresh(new_mat)
            mat_to_record = new_mat

    if mat_to_record:
        qty = float(req.material_quantity or 1.0)
        unit_p = (req.amount / qty) if qty > 0 else (req.material_price or mat_to_record.unit_price)
        mt = MaterialTransaction(
            material_id=mat_to_record.id,
            category_id=req.category_id,
            transaction_date=tx_d,
            type="masuk",
            quantity=qty,
            unit_price=unit_p,
            total_price=req.amount if req.amount > 0 else (qty * unit_p),
            supplier_or_source=req.recipient_or_sender.strip() if req.recipient_or_sender else None,
            usage_for=f"Pembelian material via Kas Pos ({req.description or mat_to_record.name})",
            source_pos_tx_id=pt.id
        )
        db.add(mt)
        mat_to_record.current_stock += qty
        if unit_p > 0:
            mat_to_record.unit_price = unit_p
        db.commit()

    return {"id": pt.id, "message": "Pencatatan transaksi kas pos berhasil disimpan"}

@app.delete("/api/pos-transactions/{tx_id}")
def delete_pos_transaction(tx_id: int, db: Session = Depends(get_db)):
    pt = db.query(PosTransaction).filter(PosTransaction.id == tx_id).first()
    if not pt:
        raise HTTPException(status_code=404, detail="Transaksi pos tidak ditemukan")
    
    # Hapus entri hutang/piutang yang terkait jika ada
    db.query(DebtTransaction).filter(DebtTransaction.source_pos_tx_id == tx_id).delete()
    
    # Rollback dan hapus entri transaksi material jika ada
    mat_txs = db.query(MaterialTransaction).filter(MaterialTransaction.source_pos_tx_id == tx_id).all()
    for mt in mat_txs:
        m = db.query(MaterialItem).filter(MaterialItem.id == mt.material_id).first()
        if m:
            if mt.type == "masuk":
                m.current_stock -= mt.quantity
            else:
                m.current_stock += mt.quantity
        db.delete(mt)
    
    db.delete(pt)
    db.commit()
    return {"message": "Transaksi pos berhasil dihapus"}


# =========================================================
# MODUL 6: KATALOG & PEMAKAIAN MATERIAL / BARANG LAPANGAN
# =========================================================
class MaterialItemCreate(BaseModel):
    category_id: int
    code: str
    name: str
    unit: str
    unit_price: Optional[float] = 0.0
    initial_stock: Optional[float] = 0.0

class MaterialItemUpdate(BaseModel):
    name: str
    unit: str
    unit_price: Optional[float] = 0.0
    initial_stock: Optional[float] = 0.0

class MaterialTransactionCreate(BaseModel):
    material_id: int
    category_id: int
    transaction_date: Optional[str] = None
    type: str # 'masuk' atau 'keluar'
    quantity: float
    supplier_or_source: Optional[str] = None
    usage_for: Optional[str] = None # Formulir Keterangan Pemakaian Lapangan

@app.get("/api/materials")
def get_materials(category_id: Optional[int] = None, db: Session = Depends(get_db)):
    query = db.query(MaterialItem)
    if category_id:
        query = query.filter(MaterialItem.category_id == category_id)
    items = query.all()
    
    result = []
    total_stock_value = 0.0
    for m in items:
        tx_in = db.query(func.sum(MaterialTransaction.quantity)).filter(
            MaterialTransaction.material_id == m.id, MaterialTransaction.type == "masuk"
        ).scalar() or 0.0
        tx_out = db.query(func.sum(MaterialTransaction.quantity)).filter(
            MaterialTransaction.material_id == m.id, MaterialTransaction.type == "keluar"
        ).scalar() or 0.0
        
        calculated_stock = m.initial_stock + tx_in - tx_out
        total_val = calculated_stock * m.unit_price
        total_stock_value += total_val
        
        result.append({
            "id": m.id,
            "category_id": m.category_id,
            "pos_code": m.category.code if m.category else "-",
            "pos_name": m.category.name if m.category else "-",
            "code": m.code,
            "name": m.name,
            "unit": m.unit,
            "unit_price": m.unit_price,
            "initial_stock": m.initial_stock,
            "total_masuk": tx_in,
            "total_keluar": tx_out,
            "current_stock": calculated_stock,
            "total_value": total_val
        })
    return {"materials": result, "grand_total_value": total_stock_value}

@app.post("/api/materials")
def create_material_item(req: MaterialItemCreate, db: Session = Depends(get_db)):
    code_clean = req.code.strip().upper()
    existing = db.query(MaterialItem).filter(
        MaterialItem.category_id == req.category_id, MaterialItem.code == code_clean
    ).first()
    if existing:
        raise HTTPException(status_code=400, detail=f"Kode material '{code_clean}' sudah ada di pos ini")
    m = MaterialItem(
        category_id=req.category_id,
        code=code_clean,
        name=req.name.strip(),
        unit=req.unit.strip(),
        unit_price=req.unit_price or 0.0,
        initial_stock=req.initial_stock or 0.0,
        current_stock=req.initial_stock or 0.0
    )
    db.add(m)
    db.commit()
    db.refresh(m)
    return {"id": m.id, "message": f"Material '{m.name}' berhasil ditambahkan ke katalog pos"}

@app.put("/api/materials/{material_id}")
def update_material_item(material_id: int, req: MaterialItemUpdate, db: Session = Depends(get_db)):
    m = db.query(MaterialItem).filter(MaterialItem.id == material_id).first()
    if not m:
        raise HTTPException(status_code=404, detail="Material tidak ditemukan")
    m.name = req.name.strip()
    m.unit = req.unit.strip()
    m.unit_price = req.unit_price or 0.0
    m.initial_stock = req.initial_stock or 0.0
    db.commit()
    return {"message": "Data material berhasil diperbarui"}

@app.delete("/api/materials/{material_id}")
def delete_material_item(material_id: int, db: Session = Depends(get_db)):
    m = db.query(MaterialItem).filter(MaterialItem.id == material_id).first()
    if not m:
        raise HTTPException(status_code=404, detail="Material tidak ditemukan")
    db.query(MaterialTransaction).filter(MaterialTransaction.material_id == material_id).delete()
    db.delete(m)
    db.commit()
    return {"message": "Material berhasil dihapus"}

@app.get("/api/materials/transactions")
def get_material_transactions(category_id: Optional[int] = None, db: Session = Depends(get_db)):
    query = db.query(MaterialTransaction)
    if category_id:
        query = query.filter(MaterialTransaction.category_id == category_id)
    txs = query.order_by(MaterialTransaction.transaction_date.desc(), MaterialTransaction.id.desc()).all()
    return [{
        "id": t.id,
        "material_id": t.material_id,
        "material_code": t.material.code if t.material else "-",
        "material_name": t.material.name if t.material else "-",
        "unit": t.material.unit if t.material else "-",
        "category_id": t.category_id,
        "pos_code": t.category.code if t.category else "-",
        "pos_name": t.category.name if t.category else "-",
        "transaction_date": str(t.transaction_date),
        "type": t.type,
        "quantity": t.quantity,
        "unit_price": t.unit_price,
        "total_price": t.total_price,
        "supplier_or_source": t.supplier_or_source,
        "usage_for": t.usage_for,
        "bukti_foto": f"/uploads/{os.path.basename(t.photo_path)}" if t.photo_path else None
    } for t in txs]

@app.post("/api/materials/transactions")
def create_material_transaction(req: MaterialTransactionCreate, db: Session = Depends(get_db)):
    m = db.query(MaterialItem).filter(MaterialItem.id == req.material_id).first()
    if not m:
        raise HTTPException(status_code=404, detail="Material tidak ditemukan")
    
    tx_d = datetime.strptime(req.transaction_date, "%Y-%m-%d").date() if req.transaction_date else date.today()
    tot_price = req.quantity * m.unit_price
    
    mt = MaterialTransaction(
        material_id=req.material_id,
        category_id=req.category_id,
        transaction_date=tx_d,
        type=req.type,
        quantity=req.quantity,
        unit_price=m.unit_price,
        total_price=tot_price,
        supplier_or_source=req.supplier_or_source,
        usage_for=req.usage_for
    )
    db.add(mt)
    
    # Update current stock
    if req.type == "masuk":
        m.current_stock += req.quantity
    else:
        m.current_stock -= req.quantity
        
    db.commit()
    db.refresh(mt)
    msg = "Penerimaan material berhasil dicatat" if req.type == "masuk" else f"Pemakaian material ({req.quantity} {m.unit}) berhasil dicatat!"
    return {"id": mt.id, "message": msg}

@app.delete("/api/materials/transactions/{tx_id}")
def delete_material_transaction(tx_id: int, db: Session = Depends(get_db)):
    mt = db.query(MaterialTransaction).filter(MaterialTransaction.id == tx_id).first()
    if not mt:
        raise HTTPException(status_code=404, detail="Transaksi material tidak ditemukan")
    m = db.query(MaterialItem).filter(MaterialItem.id == mt.material_id).first()
    if m:
        if mt.type == "masuk":
            m.current_stock -= mt.quantity
        else:
            m.current_stock += mt.quantity
    db.delete(mt)
    db.commit()
    return {"message": "Transaksi material berhasil dibatalkan"}

# =========================================================
# MODUL 7: NERACA DINAMIS & FLEKSIBEL (CUSTOM BALANCE SHEET)
# =========================================================
class CustomBalanceItemCreate(BaseModel):
    category_id: int
    period: Optional[str] = None
    side: str # 'aktiva' atau 'passiva'
    sub_category: str # 'harta_lancar', 'biaya_operasional', 'kewajiban', 'sumber_dana'
    code: Optional[str] = None
    name: str
    amount_prev_month: Optional[float] = 0.0
    amount_current_month: Optional[float] = 0.0
    amount_total: Optional[float] = 0.0

@app.get("/api/reports/neraca-pos/{category_id}")
def get_neraca_pos(category_id: int, period: Optional[str] = None, db: Session = Depends(get_db)):
    cat = db.query(ProgramCategory).filter(ProgramCategory.id == category_id).first()
    if not cat:
        raise HTTPException(status_code=404, detail="Pos program tidak ditemukan")
        
    # Agregasi Transaksi Donasi Pusat + Transaksi Kas Internal Pos
    center_donasi = db.query(func.sum(Transaction.amount)).filter(
        Transaction.category_id == category_id, Transaction.type == "income"
    ).scalar() or 0.0
    
    # Donasi langsung / Alokasi Donasi yang dicatat di Kas Pos
    pos_donasi_langsung = db.query(func.sum(PosTransaction.amount)).filter(
        PosTransaction.category_id == category_id,
        PosTransaction.type == "income",
        PosTransaction.category_tx_type.in_(["alokasi_pusat", "pendapatan_pos_langsung"])
    ).scalar() or 0.0

    # Total Sumber Dana Donasi (Passiva Bagian B)
    total_donasi = center_donasi + pos_donasi_langsung

    # Kas Pos Masuk & Keluar Riil
    pos_income_total = db.query(func.sum(PosTransaction.amount)).filter(
        PosTransaction.category_id == category_id, PosTransaction.type == "income"
    ).scalar() or 0.0
    
    pos_expense_total = db.query(func.sum(PosTransaction.amount)).filter(
        PosTransaction.category_id == category_id, PosTransaction.type == "expense"
    ).scalar() or 0.0
    
    # Hutang & Piutang Pos
    debts = db.query(DebtTransaction).filter(DebtTransaction.category_id == category_id).all()
    tot_hutang_pinjam = sum(d.amount for d in debts if d.type == "hutang_pinjam")
    tot_hutang_bayar = sum(d.amount for d in debts if d.type == "hutang_bayar")
    sisa_hutang = tot_hutang_pinjam - tot_hutang_bayar
    
    tot_piutang_pinjam = sum(d.amount for d in debts if d.type == "piutang_pinjam")
    tot_piutang_bayar = sum(d.amount for d in debts if d.type == "piutang_bayar")
    sisa_piutang = tot_piutang_pinjam - tot_piutang_bayar
    
    # Nilai Stok Material Sisa
    materials = db.query(MaterialItem).filter(MaterialItem.category_id == category_id).all()
    nilai_stok_material = 0.0
    for m in materials:
        m_in = db.query(func.sum(MaterialTransaction.quantity)).filter(
            MaterialTransaction.material_id == m.id, MaterialTransaction.type == "masuk"
        ).scalar() or 0.0
        m_out = db.query(func.sum(MaterialTransaction.quantity)).filter(
            MaterialTransaction.material_id == m.id, MaterialTransaction.type == "keluar"
        ).scalar() or 0.0
        stok_riil = m.initial_stock + m_in - m_out
        nilai_stok_material += (stok_riil * m.unit_price)
        
    # Saldo Kas Pos = Total Kas Masuk (Donasi + Pinjaman Hutang + Terima Piutang) - Total Kas Keluar (Belanja + Bayar Hutang + Beri Piutang)
    # Jika belum ada pencatatan kas pos tapi ada donasi pusat, saldo kas pos = donasi pusat
    sisa_kas_pos = (pos_income_total - pos_expense_total) if (pos_income_total > 0 or pos_expense_total > 0) else center_donasi
    
    # Custom items yang diinput manual oleh Penjab
    custom_items = db.query(CustomBalanceSheetItem).filter(
        CustomBalanceSheetItem.category_id == category_id
    ).all()
    
    aktiva_harta_lancar = [
        {"code": "K.K", "name": f"Kas Operasional Pos ({cat.name})", "amount": sisa_kas_pos, "is_auto": True},
        {"code": "P.U", "name": "Piutang ke Pihak Lain", "amount": sisa_piutang, "is_auto": True},
    ]
    
    aktiva_biaya = []
    
    passiva_kewajiban = [
        {"code": "H.U", "name": "Kewajiban Hutang ke Pihak Lain", "amount": sisa_hutang, "is_auto": True},
    ]
    
    passiva_sumber_dana = [
        {"code": "DONASI", "name": "Akumulasi Penerimaan Donasi & Alokasi Lembaga", "amount": total_donasi, "is_auto": True},
    ]
    
    for ci in custom_items:
        # Cari apakah ada transaksi kas pos yang menggunakan kategori baris neraca ini
        matched_tx_amount = db.query(func.sum(PosTransaction.amount)).filter(
            PosTransaction.category_id == category_id,
            (PosTransaction.category_tx_type == ci.code) | (PosTransaction.category_tx_type == ci.name)
        ).scalar() or 0.0

        current_amount = (ci.amount_current_month or 0.0) + matched_tx_amount
        total_amount = (ci.amount_prev_month or 0.0) + current_amount

        item_data = {
            "id": ci.id,
            "code": ci.code or "-",
            "name": ci.name,
            "amount_prev": ci.amount_prev_month,
            "amount_current": current_amount,
            "amount": total_amount,
            "is_auto": False
        }
        if ci.side == "aktiva":
            if ci.sub_category == "harta_lancar":
                aktiva_harta_lancar.append(item_data)
            else:
                aktiva_biaya.append(item_data)
        else:
            if ci.sub_category == "kewajiban":
                passiva_kewajiban.append(item_data)
            else:
                passiva_sumber_dana.append(item_data)
                
    total_aktiva = sum(i["amount"] for i in aktiva_harta_lancar) + sum(i["amount"] for i in aktiva_biaya)
    total_passiva = sum(i["amount"] for i in passiva_kewajiban) + sum(i["amount"] for i in passiva_sumber_dana)
    selisih = round(total_aktiva - total_passiva, 2)
    
    return {
        "pos": {
            "id": cat.id,
            "code": cat.code,
            "name": cat.name,
            "category_type": cat.category_type,
            "pic_name": cat.pic_name or "Penanggung Jawab"
        },
        "aktiva": {
            "harta_lancar": aktiva_harta_lancar,
            "biaya_operasional": aktiva_biaya,
            "total_aktiva": total_aktiva
        },
        "passiva": {
            "kewajiban": passiva_kewajiban,
            "sumber_dana": passiva_sumber_dana,
            "total_passiva": total_passiva
        },
        "reconciliation": {
            "is_balanced": (selisih == 0.0),
            "selisih": selisih,
            "status": "SEIMBANG (KLOP)" if selisih == 0.0 else f"SELISIH Rp {selisih:,.2f}"
        }
    }

@app.post("/api/reports/neraca-pos/{category_id}/items")
def create_custom_balance_item(category_id: int, req: CustomBalanceItemCreate, db: Session = Depends(get_db)):
    ci = CustomBalanceSheetItem(
        category_id=category_id,
        period=req.period or "2026-05",
        side=req.side,
        sub_category=req.sub_category,
        code=req.code,
        name=req.name.strip(),
        amount_prev_month=req.amount_prev_month or 0.0,
        amount_current_month=req.amount_current_month or 0.0,
        amount_total=req.amount_total or (req.amount_prev_month or 0.0) + (req.amount_current_month or 0.0),
        is_auto=False
    )
    db.add(ci)
    db.commit()
    db.refresh(ci)
    return {"id": ci.id, "message": "Baris neraca manual berhasil ditambahkan"}

@app.put("/api/reports/neraca-pos/items/{item_id}")
def update_custom_balance_item(item_id: int, req: CustomBalanceItemCreate, db: Session = Depends(get_db)):
    ci = db.query(CustomBalanceSheetItem).filter(CustomBalanceSheetItem.id == item_id).first()
    if not ci:
        raise HTTPException(status_code=404, detail="Baris neraca tidak ditemukan")
    
    ci.side = req.side
    ci.sub_category = req.sub_category
    ci.code = req.code
    ci.name = req.name.strip()
    ci.amount_prev_month = req.amount_prev_month or 0.0
    ci.amount_current_month = req.amount_current_month or 0.0
    ci.amount_total = req.amount_total or (req.amount_prev_month or 0.0) + (req.amount_current_month or 0.0)
    
    db.commit()
    db.refresh(ci)
    return {"id": ci.id, "message": f"Baris neraca '{ci.name}' berhasil diperbarui"}

@app.delete("/api/reports/neraca-pos/items/{item_id}")
def delete_custom_balance_item(item_id: int, db: Session = Depends(get_db)):
    ci = db.query(CustomBalanceSheetItem).filter(CustomBalanceSheetItem.id == item_id).first()
    if not ci:
        raise HTTPException(status_code=404, detail="Baris neraca tidak ditemukan")
    db.delete(ci)
    db.commit()
    return {"message": "Baris neraca berhasil dihapus"}

# =========================================================
# DASHBOARD STATS & TRANSAKSI
# =========================================================

@app.get("/api/dashboard/stats")
def get_dashboard_stats(db: Session = Depends(get_db)):
    total_income = db.query(func.sum(Transaction.amount)).filter(Transaction.type == "income").scalar() or 0.0
    total_expense = db.query(func.sum(Transaction.amount)).filter(Transaction.type == "expense").scalar() or 0.0
    
    accounts = db.query(Account).all()
    total_assets = sum(a.current_balance for a in accounts)
    
    total_donors = db.query(func.count(Donor.id)).scalar() or 0
    total_items = db.query(func.count(DonationItem.id)).scalar() or 0
    
    categories = db.query(ProgramCategory).filter(ProgramCategory.is_active == True).all()
    category_summary = []
    for c in categories:
        inc = db.query(func.sum(Transaction.amount)).filter(Transaction.type == "income", Transaction.category_id == c.id).scalar() or 0.0
        exp = db.query(func.sum(Transaction.amount)).filter(Transaction.type == "expense", Transaction.category_id == c.id).scalar() or 0.0
        category_summary.append({
            "id": c.id,
            "name": c.name,
            "code": c.code,
            "income": inc,
            "expense": exp,
            "net_balance": inc - exp
        })

    # --- AGREGASI TREN BULANAN (12 Bulan Berjalan) ---
    month_names_id = ["", "Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Ags", "Sep", "Okt", "Nov", "Des"]
    curr_year = date.today().year
    monthly_trends = []
    
    prev_month_income = 0.0
    for m in range(1, 13):
        start_d = date(curr_year, m, 1)
        next_m = m + 1 if m < 12 else 1
        next_y = curr_year if m < 12 else curr_year + 1
        end_d = date(next_y, next_m, 1)

        m_inc = db.query(func.sum(Transaction.amount)).filter(
            Transaction.type == "income",
            Transaction.transaction_date >= start_d,
            Transaction.transaction_date < end_d
        ).scalar() or 0.0

        m_exp = db.query(func.sum(Transaction.amount)).filter(
            Transaction.type == "expense",
            Transaction.transaction_date >= start_d,
            Transaction.transaction_date < end_d
        ).scalar() or 0.0

        growth_pct = 0.0
        if prev_month_income > 0:
            growth_pct = round(((m_inc - prev_month_income) / prev_month_income) * 100, 1)
        elif m_inc > 0 and prev_month_income == 0 and m > 1:
            growth_pct = 100.0

        monthly_trends.append({
            "month": m,
            "month_name": month_names_id[m],
            "year": curr_year,
            "label": f"{month_names_id[m]} {curr_year}",
            "income": m_inc,
            "expense": m_exp,
            "net": m_inc - m_exp,
            "growth_pct": growth_pct
        })
        if m_inc > 0:
            prev_month_income = m_inc

    # --- AGREGASI TREN TAHUNAN (5 Tahun Terakhir) ---
    yearly_trends = []
    prev_year_income = 0.0
    for y in range(curr_year - 3, curr_year + 2):
        start_y = date(y, 1, 1)
        end_y = date(y + 1, 1, 1)

        y_inc = db.query(func.sum(Transaction.amount)).filter(
            Transaction.type == "income",
            Transaction.transaction_date >= start_y,
            Transaction.transaction_date < end_y
        ).scalar() or 0.0

        y_exp = db.query(func.sum(Transaction.amount)).filter(
            Transaction.type == "expense",
            Transaction.transaction_date >= start_y,
            Transaction.transaction_date < end_y
        ).scalar() or 0.0

        y_growth = 0.0
        if prev_year_income > 0:
            y_growth = round(((y_inc - prev_year_income) / prev_year_income) * 100, 1)
        elif y_inc > 0 and prev_year_income == 0:
            y_growth = 100.0

        yearly_trends.append({
            "year": y,
            "label": str(y),
            "income": y_inc,
            "expense": y_exp,
            "net": y_inc - y_exp,
            "growth_pct": y_growth
        })
        if y_inc > 0:
            prev_year_income = y_inc
        
    return {
        "total_income": total_income,
        "total_expense": total_expense,
        "net_balance": total_income - total_expense,
        "total_assets": total_assets,
        "total_donors": total_donors,
        "total_donation_items": total_items,
        "category_summary": category_summary,
        "accounts": [{"name": a.name, "balance": a.current_balance} for a in accounts],
        "monthly_trends": monthly_trends,
        "yearly_trends": yearly_trends
    }

@app.get("/api/transactions")
def list_transactions(limit: int = 500, category_id: Optional[int] = None, tx_type: Optional[str] = None, db: Session = Depends(get_db)):
    query = db.query(Transaction)
    if category_id:
        query = query.filter(Transaction.category_id == category_id)
    if tx_type:
        query = query.filter(Transaction.type == tx_type)
    txs = query.order_by(Transaction.transaction_date.desc(), Transaction.id.desc()).limit(limit).all()
    result = []
    for t in txs:
        result.append({
            "id": t.id,
            "tanggal": str(t.transaction_date),
            "jenis": t.type,
            "category_id": t.category_id,
            "pos_program": t.category.name if t.category else "-",
            "pos_code": t.category.code if t.category else "-",
            "metode": t.account.name if t.account else "-",
            "donatur": t.donor.name if t.donor else (t.donor_name_raw or "Hamba Allah"),
            "nominal": t.amount,
            "keterangan": t.description,
            "bukti_foto": f"/uploads/{os.path.basename(t.receipt_photo_path)}" if t.receipt_photo_path else None,
            "qris_reff_id": t.qris_reff_id
        })
    return result

@app.post("/api/transactions")
async def create_transaction(
    tanggal: str = Form(...),
    jenis: str = Form(...),
    metode: str = Form(...),
    category_id: int = Form(...),
    nominal: float = Form(...),
    keterangan: Optional[str] = Form(None),
    nama_donatur: Optional[str] = Form(None),
    bukti_foto: Optional[UploadFile] = File(None),
    db: Session = Depends(get_db)
):
    tx_type = "income" if jenis in ["income", "umum_masuk", "masuk"] else "expense"
    
    # Check if metode is account ID or code
    try:
        acc_id = int(metode)
        account = db.query(Account).filter(Account.id == acc_id).first()
    except:
        acc_code = "kas_tunai" if "kas" in metode.lower() and "non" not in metode.lower() else "bank_bni"
        account = db.query(Account).filter(Account.code == acc_code).first()
        
    if not account:
        account = db.query(Account).first()
        
    donor_id = None
    if nama_donatur and tx_type == "income":
        clean_name = sanitize_donor_name(nama_donatur)
        donor = db.query(Donor).filter(Donor.name == clean_name).first()
        if not donor:
            donor = Donor(name=clean_name)
            db.add(donor)
            db.flush()
        donor_id = donor.id

    photo_path = None
    if bukti_foto and bukti_foto.filename:
        filename = f"bukti_{datetime.now().strftime('%Y%m%d_%H%M%S')}_{bukti_foto.filename}"
        save_path = os.path.join(UPLOAD_DIR, filename)
        with open(save_path, "wb") as f:
            f.write(await bukti_foto.read())
        photo_path = save_path

    tx_date = datetime.strptime(tanggal, "%Y-%m-%d").date() if tanggal else date.today()

    tx = Transaction(
        transaction_date=tx_date,
        type=tx_type,
        account_id=account.id,
        category_id=category_id,
        donor_id=donor_id,
        donor_name_raw=nama_donatur,
        amount=nominal,
        description=keterangan,
        receipt_photo_path=photo_path
    )
    db.add(tx)
    
    if tx_type == "income":
        account.current_balance += nominal
    else:
        account.current_balance -= nominal
        # OTOMATIS: Pengeluaran dari Donasi Latee pusat yang dialokasikan ke pos program (misal SPM)
        # menjadi penambah saldo Kas Masuk/Aktiva bagi Pos Program tersebut.
        pos_income = PosTransaction(
            category_id=category_id,
            transaction_date=tx_date,
            type="income",
            category_tx_type="alokasi_pusat",
            source_transaction_id=tx.id,
            amount=nominal,
            recipient_or_sender="Donasi Latee Pusat",
            description=f"Alokasi Dana Pusat: {keterangan or 'Pencairan dana untuk operasional pos'}"
        )
        db.add(pos_income)
        
    db.commit()
    db.refresh(tx)
    return {"id": tx.id, "message": "Transaksi berhasil disimpan"}

@app.get("/api/transactions/{tx_id}")
def get_transaction_detail(tx_id: int, db: Session = Depends(get_db)):
    tx = db.query(Transaction).filter(Transaction.id == tx_id).first()
    if not tx:
        raise HTTPException(status_code=404, detail="Transaksi tidak ditemukan")
    return {
        "id": tx.id,
        "tanggal": str(tx.transaction_date),
        "jenis": tx.type,
        "account_id": tx.account_id,
        "category_id": tx.category_id,
        "pos_program": tx.category.name if tx.category else "-",
        "pos_code": tx.category.code if tx.category else "-",
        "metode": tx.account.name if tx.account else "-",
        "donatur": tx.donor.name if tx.donor else (tx.donor_name_raw or "Hamba Allah"),
        "nominal": tx.amount,
        "keterangan": tx.description,
        "bukti_foto": f"/uploads/{os.path.basename(tx.receipt_photo_path)}" if tx.receipt_photo_path else None,
        "qris_reff_id": tx.qris_reff_id
    }

@app.put("/api/transactions/{tx_id}")
async def update_transaction(
    tx_id: int,
    tanggal: str = Form(...),
    jenis: str = Form(...),
    metode: str = Form(...),
    category_id: int = Form(...),
    nominal: float = Form(...),
    keterangan: Optional[str] = Form(None),
    nama_donatur: Optional[str] = Form(None),
    bukti_foto: Optional[UploadFile] = File(None),
    db: Session = Depends(get_db)
):
    tx = db.query(Transaction).filter(Transaction.id == tx_id).first()
    if not tx:
        raise HTTPException(status_code=404, detail="Transaksi tidak ditemukan")

    old_account = db.query(Account).filter(Account.id == tx.account_id).first()
    if old_account:
        if tx.type == "income":
            old_account.current_balance -= tx.amount
        else:
            old_account.current_balance += tx.amount

    tx_type = "income" if jenis in ["income", "umum_masuk", "masuk"] else "expense"
    
    try:
        acc_id = int(metode)
        new_account = db.query(Account).filter(Account.id == acc_id).first()
    except:
        acc_code = "kas_tunai" if "kas" in metode.lower() and "non" not in metode.lower() else "bank_bni"
        new_account = db.query(Account).filter(Account.code == acc_code).first()

    if not new_account:
        new_account = old_account or db.query(Account).first()

    donor_id = tx.donor_id
    if nama_donatur and tx_type == "income":
        clean_name = sanitize_donor_name(nama_donatur)
        donor = db.query(Donor).filter(Donor.name == clean_name).first()
        if not donor:
            donor = Donor(name=clean_name)
            db.add(donor)
            db.flush()
        donor_id = donor.id
    elif tx_type != "income":
        donor_id = None

    photo_path = tx.receipt_photo_path
    if bukti_foto and bukti_foto.filename:
        filename = f"bukti_{datetime.now().strftime('%Y%m%d_%H%M%S')}_{bukti_foto.filename}"
        save_path = os.path.join(UPLOAD_DIR, filename)
        with open(save_path, "wb") as f:
            f.write(await bukti_foto.read())
        photo_path = save_path

    tx_date = datetime.strptime(tanggal, "%Y-%m-%d").date() if tanggal else date.today()

    tx.transaction_date = tx_date
    tx.type = tx_type
    tx.account_id = new_account.id
    tx.category_id = category_id
    tx.donor_id = donor_id
    tx.donor_name_raw = nama_donatur
    tx.amount = nominal
    tx.description = keterangan
    tx.receipt_photo_path = photo_path

    if tx_type == "income":
        new_account.current_balance += nominal
        db.query(PosTransaction).filter(PosTransaction.source_transaction_id == tx.id).delete()
    else:
        new_account.current_balance -= nominal
        pos_tx = db.query(PosTransaction).filter(PosTransaction.source_transaction_id == tx.id).first()
        if pos_tx:
            pos_tx.category_id = category_id
            pos_tx.transaction_date = tx_date
            pos_tx.amount = nominal
            pos_tx.description = f"Alokasi Dana Pusat: {keterangan or 'Pencairan dana untuk operasional pos'}"
        else:
            pos_income = PosTransaction(
                category_id=category_id,
                transaction_date=tx_date,
                type="income",
                category_tx_type="alokasi_pusat",
                source_transaction_id=tx.id,
                amount=nominal,
                recipient_or_sender="Donasi Latee Pusat",
                description=f"Alokasi Dana Pusat: {keterangan or 'Pencairan dana untuk operasional pos'}"
            )
            db.add(pos_income)

    db.commit()
    db.refresh(tx)
    return {"id": tx.id, "message": "Transaksi berhasil diperbarui"}

@app.delete("/api/transactions/{tx_id}")
def delete_transaction(tx_id: int, db: Session = Depends(get_db)):
    tx = db.query(Transaction).filter(Transaction.id == tx_id).first()
    if not tx:
        raise HTTPException(status_code=404, detail="Transaksi tidak ditemukan")

    account = db.query(Account).filter(Account.id == tx.account_id).first()
    if account:
        if tx.type == "income":
            account.current_balance -= tx.amount
        else:
            account.current_balance += tx.amount

    db.query(PosTransaction).filter(PosTransaction.source_transaction_id == tx.id).delete()

    if tx.receipt_photo_path and os.path.exists(tx.receipt_photo_path):
        try:
            os.remove(tx.receipt_photo_path)
        except:
            pass

    db.delete(tx)
    db.commit()
    return {"message": "Transaksi berhasil dihapus"}

@app.get("/api/donation-items")
def list_donation_items(category_id: Optional[int] = None, db: Session = Depends(get_db)):
    query = db.query(DonationItem)
    if category_id:
        query = query.filter(DonationItem.category_id == category_id)
    items = query.order_by(DonationItem.transaction_date.desc(), DonationItem.id.desc()).all()
    result = []
    for item in items:
        result.append({
            "id": item.id,
            "transaction_date": str(item.transaction_date),
            "donor_name": item.donor_name,
            "category_id": item.category_id,
            "pos_code": item.category.code if item.category else "-",
            "pos_name": item.category.name if item.category else "-",
            "item_category": item.item_category,
            "item_name": item.item_name,
            "quantity": item.quantity,
            "unit": item.unit,
            "estimated_value": item.estimated_value,
            "description": item.description,
            "bukti_foto": f"/uploads/{os.path.basename(item.photo_path)}" if item.photo_path else None
        })
    return result

@app.post("/api/donation-items")
async def create_donation_item(
    tanggal: str = Form(...),
    nama_donatur: str = Form(...),
    kategori_barang: str = Form(...),
    nama_barang: str = Form(...),
    jumlah: float = Form(...),
    satuan: str = Form(...),
    category_id: Optional[int] = Form(None),
    estimasi_nilai: Optional[float] = Form(None),
    keterangan: Optional[str] = Form(None),
    bukti_foto: Optional[UploadFile] = File(None),
    db: Session = Depends(get_db)
):
    photo_path = None
    if bukti_foto and bukti_foto.filename:
        filename = f"barang_{datetime.now().strftime('%Y%m%d_%H%M%S')}_{bukti_foto.filename}"
        save_path = os.path.join(UPLOAD_DIR, filename)
        with open(save_path, "wb") as f:
            f.write(await bukti_foto.read())
        photo_path = save_path

    tx_date = datetime.strptime(tanggal, "%Y-%m-%d").date() if tanggal else date.today()
    item = DonationItem(
        transaction_date=tx_date,
        donor_name=sanitize_donor_name(nama_donatur),
        category_id=category_id,
        item_category=kategori_barang,
        item_name=nama_barang,
        quantity=jumlah,
        unit=satuan,
        estimated_value=estimasi_nilai,
        description=keterangan,
        photo_path=photo_path
    )
    db.add(item)
    db.commit()
    db.refresh(item)
    return {"id": item.id, "message": "Donasi barang berhasil dicatat"}

@app.post("/api/transactions/import-qris")
async def import_qris(file: UploadFile = File(...), db: Session = Depends(get_db)):
    if not file.filename.endswith(('.xlsx', '.xls')):
        raise HTTPException(status_code=400, detail="Harap unggah file Excel (.xlsx)")
        
    save_path = os.path.join(UPLOAD_DIR, f"temp_{file.filename}")
    with open(save_path, "wb") as f:
        f.write(await file.read())
        
    wb = openpyxl.load_workbook(save_path, data_only=True)
    ws = wb.active
    
    account_bni = db.query(Account).filter(Account.code == "bank_bni").first()
    categories = {c.code: c for c in db.query(ProgramCategory).all()}
    cat_by_digit = {c.qris_unit_digit: c for c in db.query(ProgramCategory).all() if c.qris_unit_digit}
    default_cat = categories.get("STHL") or list(categories.values())[0]

    header_row = 2
    col_map = {
        "bill_no": 6,
        "reff_id": 7,
        "name": 14,
        "amount": 17,
        "date": 20
    }

    for r in range(1, min(6, ws.max_row + 1)):
        for c in range(1, ws.max_column + 1):
            val = str(ws.cell(r, c).value or '').strip().lower()
            if val == "customer name":
                col_map["name"] = c
                header_row = r
            elif val == "amount":
                col_map["amount"] = c
                header_row = r
            elif val == "reff id" or val == "ref id":
                col_map["reff_id"] = c
            elif val == "bill number" or val == "bill no":
                col_map["bill_no"] = c
            elif val == "trx date":
                col_map["date"] = c

    imported_count = 0
    skipped_count = 0

    for r in range(header_row + 1, ws.max_row + 1):
        raw_name = ws.cell(r, col_map["name"]).value
        raw_amount = ws.cell(r, col_map["amount"]).value
        raw_reff = ws.cell(r, col_map["reff_id"]).value
        raw_bill = ws.cell(r, col_map["bill_no"]).value
        raw_date = ws.cell(r, col_map["date"]).value

        if raw_amount is None:
            continue

        try:
            amount = float(raw_amount)
        except:
            continue

        if amount <= 0:
            continue

        tx_date = date.today()
        if isinstance(raw_date, datetime):
            tx_date = raw_date.date()
        elif isinstance(raw_date, date):
            tx_date = raw_date

        reff_id = str(raw_reff).strip() if raw_reff else f"QRIS-{r}-{int(amount)}"
        bill_no = str(raw_bill).strip() if raw_bill else ""

        if reff_id and db.query(Transaction).filter(Transaction.qris_reff_id == reff_id).first():
            skipped_count += 1
            continue

        last_digit_1 = str(int(amount))[-1]
        last_digit_2 = str(int(amount))[-2:] if len(str(int(amount))) >= 2 else ""
        target_category = cat_by_digit.get(last_digit_2) or cat_by_digit.get(last_digit_1) or default_cat

        clean_name = sanitize_donor_name(raw_name)
        donor = db.query(Donor).filter(Donor.name == clean_name).first()
        if not donor:
            donor = Donor(name=clean_name)
            db.add(donor)
            db.flush()

        tx = Transaction(
            transaction_date=tx_date,
            type="income",
            account_id=account_bni.id,
            category_id=target_category.id,
            donor_id=donor.id,
            donor_name_raw=clean_name,
            amount=amount,
            description=f"Donasi QRIS ({clean_name})",
            qris_reff_id=reff_id,
            qris_bill_no=bill_no
        )
        db.add(tx)
        account_bni.current_balance += amount
        imported_count += 1

    db.commit()
    if os.path.exists(save_path):
        os.remove(save_path)

    return {
        "status": "success",
        "imported_count": imported_count,
        "skipped_count": skipped_count,
        "message": f"Berhasil mengimpor {imported_count} transaksi QRIS ({skipped_count} duplikat diabaikan)."
    }

@app.get("/api/reports/buku-bantu")
def get_buku_bantu(year: int = date.today().year, month: int = date.today().month, db: Session = Depends(get_db)):
    categories = db.query(ProgramCategory).filter(ProgramCategory.is_active == True).all()
    rows = []
    total_saldo_lalu = 0.0
    total_mutasi_masuk = 0.0
    total_mutasi_keluar = 0.0
    total_saldo_akhir = 0.0

    for c in categories:
        inc_lalu = db.query(func.sum(Transaction.amount)).filter(
            Transaction.category_id == c.id,
            Transaction.type == "income",
            Transaction.transaction_date < date(year, month, 1)
        ).scalar() or 0.0
        exp_lalu = db.query(func.sum(Transaction.amount)).filter(
            Transaction.category_id == c.id,
            Transaction.type == "expense",
            Transaction.transaction_date < date(year, month, 1)
        ).scalar() or 0.0
        saldo_lalu = inc_lalu - exp_lalu

        next_month = month + 1 if month < 12 else 1
        next_year = year if month < 12 else year + 1
        start_date = date(year, month, 1)
        end_date = date(next_year, next_month, 1)

        inc_ini = db.query(func.sum(Transaction.amount)).filter(
            Transaction.category_id == c.id,
            Transaction.type == "income",
            Transaction.transaction_date >= start_date,
            Transaction.transaction_date < end_date
        ).scalar() or 0.0
        exp_ini = db.query(func.sum(Transaction.amount)).filter(
            Transaction.category_id == c.id,
            Transaction.type == "expense",
            Transaction.transaction_date >= start_date,
            Transaction.transaction_date < end_date
        ).scalar() or 0.0
        saldo_ini = inc_ini - exp_ini
        saldo_akhir = saldo_lalu + saldo_ini

        total_saldo_lalu += saldo_lalu
        total_mutasi_masuk += inc_ini
        total_mutasi_keluar += exp_ini
        total_saldo_akhir += saldo_akhir

        rows.append({
            "pos_id": c.id,
            "nama_pos": c.name,
            "kode_pos": c.code,
            "saldo_bulan_lalu": saldo_lalu,
            "mutasi_masuk_bulan_ini": inc_ini,
            "mutasi_keluar_bulan_ini": exp_ini,
            "mutasi_bersih_bulan_ini": saldo_ini,
            "saldo_akhir": saldo_akhir
        })

    return {
        "periode": f"{year}-{month:02d}",
        "rows": rows,
        "totals": {
            "total_saldo_lalu": total_saldo_lalu,
            "total_mutasi_masuk": total_mutasi_masuk,
            "total_mutasi_keluar": total_mutasi_keluar,
            "total_saldo_akhir": total_saldo_akhir
        }
    }

@app.get("/api/reports/neraca")
def get_neraca(year: int = date.today().year, month: int = date.today().month, db: Session = Depends(get_db)):
    buku_bantu = get_buku_bantu(year, month, db)
    accounts = db.query(Account).all()
    
    total_aktiva = sum(a.current_balance for a in accounts)
    total_passiva = buku_bantu["totals"]["total_saldo_akhir"]
    selisih = round(total_aktiva - total_passiva, 2)
    
    return {
        "periode": f"Bulan {month} Tahun {year}",
        "aktiva": {
            "harta_lancar": [{"nama": a.name, "saldo": a.current_balance} for a in accounts],
            "total_aktiva": total_aktiva
        },
        "passiva": {
            "dana_program": buku_bantu["rows"],
            "total_passiva": total_passiva
        },
        "reconciliation": {
            "is_balanced": (selisih == 0.0),
            "selisih": selisih,
            "status": "SEIMBANG (KLOP)" if selisih == 0.0 else f"SELISIH Rp {selisih:,.2f}"
        },
        "pengesahan": {
            "tanggal_laporan": f"{date.today().strftime('%d %B %Y')}",
            "pemeriksa": "Ainul Fais",
            "penanggung_jawab": "Penanggung Jawab Keuangan"
        }
    }

# =========================================================
# GENERATOR & VIEWER PDF NATIVE RESMI PESANTREN ANNUQAYAH
# =========================================================
from fastapi.responses import Response
import io
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4, landscape
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, HRFlowable, Image as RLImage
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import cm, mm

def format_rupiah_pdf(val):
    if val is None:
        val = 0
    return f"Rp {int(val):,}".replace(",", ".")

@app.get("/api/reports/pdf/{report_type}")
def get_report_pdf(
    report_type: str,
    year: int = date.today().year,
    month: int = date.today().month,
    pos: Optional[str] = None,
    jenis: Optional[str] = None,
    start_date: Optional[str] = None,
    end_date: Optional[str] = None,
    db: Session = Depends(get_db)
):
    buffer = io.BytesIO()
    
    # Orientasi Dokumen (Landscape untuk jurnal transaksi, rekap donatur, pemetaan, dan logistik/barang)
    pagesize = landscape(A4) if report_type in ["transaksi", "buku_bantu", "donatur", "pemetaan_program", "barang"] else A4
    doc = SimpleDocTemplate(
        buffer,
        pagesize=pagesize,
        rightMargin=15*mm,
        leftMargin=15*mm,
        topMargin=15*mm,
        bottomMargin=15*mm
    )

    styles = getSampleStyleSheet()
    title_style = ParagraphStyle(
        'KopTitle',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=13,
        leading=16,
        alignment=1, # Center
        textColor=colors.HexColor("#064e3b")
    )
    sub_title_style = ParagraphStyle(
        'KopSub',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=10,
        leading=13,
        alignment=1,
        textColor=colors.HexColor("#0f172a")
    )
    address_style = ParagraphStyle(
        'KopAddress',
        parent=styles['Normal'],
        fontName='Helvetica',
        fontSize=8,
        leading=10,
        alignment=1,
        textColor=colors.HexColor("#475569")
    )
    report_heading_style = ParagraphStyle(
        'ReportHeading',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=12,
        leading=15,
        alignment=1,
        textColor=colors.HexColor("#0f172a")
    )
    report_subheading_style = ParagraphStyle(
        'ReportSubHeading',
        parent=styles['Normal'],
        fontName='Helvetica-Oblique',
        fontSize=9,
        leading=12,
        alignment=1,
        textColor=colors.HexColor("#334155")
    )
    cell_style = ParagraphStyle(
        'CellText',
        parent=styles['Normal'],
        fontName='Helvetica',
        fontSize=8.5,
        leading=11
    )
    cell_bold = ParagraphStyle(
        'CellBold',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=8.5,
        leading=11
    )

    elements = []

    # 1. KOP SURAT RESMI LENGKAP DENGAN LOGO
    logo_path = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "sistem-keuangan-web", "src", "assets", "logo.jpg")
    
    kop_text_cells = [
        Paragraph("PONDOK PESANTREN ANNUQAYAH LATEE", title_style),
        Paragraph("PUSAT PELAPORAN KEUANGAN PONDOK PESANTREN ANNUQAYAH LATEE", sub_title_style),
        Paragraph("Guluk-Guluk, Sumenep, Jawa Timur 69463 • Telp/WA: (0328) • Email: latee@annuqayah.test", address_style)
    ]

    if os.path.exists(logo_path):
        logo_img = RLImage(logo_path, width=48, height=48)
        # Width total A4 portrait ~510pt, landscape ~760pt
        total_w = 760 if report_type in ["transaksi", "buku_bantu", "donatur", "pemetaan_program", "barang"] else 510
        kop_table_data = [[logo_img, kop_text_cells]]
        kop_table = Table(kop_table_data, colWidths=[60, total_w - 60])
        kop_table.setStyle(TableStyle([
            ('VALIGN', (0,0), (-1,-1), 'MIDDLE'),
            ('ALIGN', (0,0), (0,0), 'CENTER'),
            ('LEFTPADDING', (0,0), (-1,-1), 0),
            ('RIGHTPADDING', (0,0), (-1,-1), 0),
            ('TOPPADDING', (0,0), (-1,-1), 0),
            ('BOTTOMPADDING', (0,0), (-1,-1), 0),
        ]))
        elements.append(kop_table)
    else:
        for p in kop_text_cells:
            elements.append(p)

    elements.append(Spacer(1, 2*mm))
    elements.append(HRFlowable(width="100%", thickness=2, color=colors.HexColor("#0f172a"), spaceAfter=1, spaceBefore=0))
    elements.append(HRFlowable(width="100%", thickness=0.8, color=colors.HexColor("#0f172a"), spaceAfter=4*mm, spaceBefore=0))

    months_id = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"]
    nama_bulan = months_id[month] if 1 <= month <= 12 else ""

    # 2. ISI LAPORAN SESUAI TIPE
    if report_type == "neraca":
        neraca = get_neraca(year, month, db)
        elements.append(Paragraph("LAPORAN NERACA KEUANGAN RESMI", report_heading_style))
        elements.append(Paragraph(f"Periode: Bulan {nama_bulan} Tahun {year}", report_subheading_style))
        elements.append(Spacer(1, 4*mm))

        # Status Rekonsiliasi
        status_text = f"STATUS NERACA: <b>{neraca['reconciliation']['status']}</b> (Aktiva: {format_rupiah_pdf(neraca['aktiva']['total_aktiva'])} | Passiva: {format_rupiah_pdf(neraca['passiva']['total_passiva'])})"
        elements.append(Paragraph(status_text, ParagraphStyle('Recon', parent=styles['Normal'], fontSize=9, alignment=1, textColor=colors.HexColor("#065f46"))))
        elements.append(Spacer(1, 4*mm))

        # Tabel 2 Kolom (Aktiva & Passiva)
        aktiva_data = [["I. AKTIVA (HARTA LANCAR)", "SALDO"]]
        for h in neraca["aktiva"]["harta_lancar"]:
            aktiva_data.append([h["nama"], format_rupiah_pdf(h["saldo"])])
        aktiva_data.append(["JUMLAH AKTIVA", format_rupiah_pdf(neraca["aktiva"]["total_aktiva"])])

        passiva_data = [["II. PASSIVA (DANA PROGRAM)", "SALDO AKHIR"]]
        for dp in neraca["passiva"]["dana_program"]:
            passiva_data.append([dp["nama_pos"], format_rupiah_pdf(dp["saldo_akhir"])])
        passiva_data.append(["JUMLAH PASSIVA", format_rupiah_pdf(neraca["passiva"]["total_passiva"])])

        # Render Table Aktiva & Passiva
        t_aktiva = Table(aktiva_data, colWidths=[150, 100])
        t_aktiva.setStyle(TableStyle([
            ('BACKGROUND', (0,0), (-1,0), colors.HexColor("#f1f5f9")),
            ('FONTNAME', (0,0), (-1,0), 'Helvetica-Bold'),
            ('FONTSIZE', (0,0), (-1,-1), 8.5),
            ('ALIGN', (1,0), (1,-1), 'RIGHT'),
            ('GRID', (0,0), (-1,-1), 0.5, colors.HexColor("#cbd5e1")),
            ('FONTNAME', (0,-1), (-1,-1), 'Helvetica-Bold'),
            ('BACKGROUND', (0,-1), (-1,-1), colors.HexColor("#f8fafc")),
        ]))

        t_passiva = Table(passiva_data, colWidths=[150, 100])
        t_passiva.setStyle(TableStyle([
            ('BACKGROUND', (0,0), (-1,0), colors.HexColor("#f1f5f9")),
            ('FONTNAME', (0,0), (-1,0), 'Helvetica-Bold'),
            ('FONTSIZE', (0,0), (-1,-1), 8.5),
            ('ALIGN', (1,0), (1,-1), 'RIGHT'),
            ('GRID', (0,0), (-1,-1), 0.5, colors.HexColor("#cbd5e1")),
            ('FONTNAME', (0,-1), (-1,-1), 'Helvetica-Bold'),
            ('BACKGROUND', (0,-1), (-1,-1), colors.HexColor("#f8fafc")),
        ]))

        combined = [[t_aktiva, t_passiva]]
        t_comb = Table(combined, colWidths=[260, 260])
        t_comb.setStyle(TableStyle([('VALIGN', (0,0), (-1,-1), 'TOP')]))
        elements.append(t_comb)

    elif report_type == "buku_bantu":
        bb = get_buku_bantu(year, month, db)
        elements.append(Paragraph("BUKU PEMBANTU RINCIAN SALDO PROGRAM", report_heading_style))
        elements.append(Paragraph(f"Periode: Bulan {nama_bulan} Tahun {year}", report_subheading_style))
        elements.append(Spacer(1, 4*mm))

        table_data = [["No", "Nama Pos Program", "Saldo Lalu", "Mutasi Masuk", "Mutasi Keluar", "Saldo Akhir"]]
        for idx, r in enumerate(bb["rows"]):
            table_data.append([
                str(idx + 1),
                r["nama_pos"],
                format_rupiah_pdf(r["saldo_bulan_lalu"]),
                format_rupiah_pdf(r["mutasi_masuk_bulan_ini"]),
                format_rupiah_pdf(r["mutasi_keluar_bulan_ini"]),
                format_rupiah_pdf(r["saldo_akhir"])
            ])
        table_data.append([
            "", "TOTAL KESELURUHAN",
            format_rupiah_pdf(bb["totals"]["total_saldo_lalu"]),
            format_rupiah_pdf(bb["totals"]["total_mutasi_masuk"]),
            format_rupiah_pdf(bb["totals"]["total_mutasi_keluar"]),
            format_rupiah_pdf(bb["totals"]["total_saldo_akhir"])
        ])

        t_bb = Table(table_data, colWidths=[25, 230, 110, 110, 110, 120])
        t_bb.setStyle(TableStyle([
            ('BACKGROUND', (0,0), (-1,0), colors.HexColor("#f1f5f9")),
            ('FONTNAME', (0,0), (-1,0), 'Helvetica-Bold'),
            ('FONTSIZE', (0,0), (-1,-1), 8.5),
            ('ALIGN', (2,0), (-1,-1), 'RIGHT'),
            ('GRID', (0,0), (-1,-1), 0.5, colors.HexColor("#cbd5e1")),
            ('FONTNAME', (0,-1), (-1,-1), 'Helvetica-Bold'),
            ('BACKGROUND', (0,-1), (-1,-1), colors.HexColor("#f8fafc")),
        ]))
        elements.append(t_bb)

    elif report_type == "transaksi":
        txs = db.query(Transaction).all()
        tx_data = []
        for t in txs:
            pos_name = t.category.name if t.category else "-"
            pos_code = t.category.code if t.category else "-"
            metode = t.account.name if t.account else "-"
            if pos and pos.strip():
                pos_clean = pos.strip().upper()
                if pos_clean != pos_code.upper() and pos_clean != pos_name.upper() and (pos_clean != str(t.category_id)):
                    continue
            if jenis and t.type != jenis:
                continue
            tx_data.append({
                "tanggal": t.transaction_date.strftime("%Y-%m-%d") if t.transaction_date else "-",
                "donatur": sanitize_donor_name(t.donor.name if t.donor else (t.donor_name_raw or t.description or "-")),
                "pos": pos_name,
                "metode": metode,
                "ket": t.description or "-",
                "in": t.amount if t.type == "income" else 0,
                "out": t.amount if t.type == "expense" else 0,
            })

        elements.append(Paragraph("JURNAL MUTASI KAS & BANK LENGKAP", report_heading_style))
        elements.append(Paragraph(f"Filter Pos Program: <b>{pos or 'Semua Pos'}</b> • Jenis: <b>{jenis or 'Semua'}</b>", report_subheading_style))
        elements.append(Spacer(1, 4*mm))

        table_data = [["No", "Tanggal", "Donatur / Penerima", "Pos Program", "Metode", "Keterangan", "Penerimaan (+)", "Pengeluaran (-)"]]
        tot_in = 0
        tot_out = 0
        for idx, t in enumerate(tx_data):
            tot_in += t["in"]
            tot_out += t["out"]
            table_data.append([
                str(idx + 1),
                t["tanggal"],
                t["donatur"][:25],
                t["pos"][:20],
                t["metode"],
                t["ket"][:30],
                format_rupiah_pdf(t["in"]) if t["in"] > 0 else "-",
                format_rupiah_pdf(t["out"]) if t["out"] > 0 else "-"
            ])
        table_data.append([
            "", "TOTAL MUTASI", "", "", "", "",
            format_rupiah_pdf(tot_in),
            format_rupiah_pdf(tot_out)
        ])

        t_tx = Table(table_data, colWidths=[20, 60, 120, 110, 70, 140, 95, 95])
        t_tx.setStyle(TableStyle([
            ('BACKGROUND', (0,0), (-1,0), colors.HexColor("#f1f5f9")),
            ('FONTNAME', (0,0), (-1,0), 'Helvetica-Bold'),
            ('FONTSIZE', (0,0), (-1,-1), 8),
            ('ALIGN', (6,0), (-1,-1), 'RIGHT'),
            ('GRID', (0,0), (-1,-1), 0.5, colors.HexColor("#cbd5e1")),
            ('FONTNAME', (0,-1), (-1,-1), 'Helvetica-Bold'),
            ('BACKGROUND', (0,-1), (-1,-1), colors.HexColor("#f8fafc")),
        ]))
        elements.append(t_tx)

    elif report_type == "donatur":
        # Parse range date jika ada
        tgl_awal = None
        tgl_akhir = None
        if start_date:
            try:
                tgl_awal = datetime.strptime(start_date, "%Y-%m-%d").date()
            except:
                pass
        if end_date:
            try:
                tgl_akhir = datetime.strptime(end_date, "%Y-%m-%d").date()
            except:
                pass

        # Pos category filter
        selected_cat = None
        if pos and pos.strip():
            pos_clean = pos.strip()
            selected_cat = db.query(ProgramCategory).filter(
                (ProgramCategory.code == pos_clean.upper()) |
                (ProgramCategory.name == pos_clean) |
                (ProgramCategory.id == int(pos_clean) if pos_clean.isdigit() else False)
            ).first()

        donors = db.query(Donor).order_by(Donor.name.asc()).all()
        
        if selected_cat:
            elements.append(Paragraph(f"REKAPITULASI DONATUR PROGRAM: {selected_cat.name.upper()} ({selected_cat.code})", report_heading_style))
        else:
            elements.append(Paragraph("REKAPITULASI HISTORI & PEMETAAN DONASI DONATUR", report_heading_style))

        if tgl_awal and tgl_akhir:
            periode_str = f"Rentang Waktu: <b>{tgl_awal.strftime('%d %b %Y')}</b> s/d <b>{tgl_akhir.strftime('%d %b %Y')}</b>"
        elif tgl_awal:
            periode_str = f"Rentang Waktu: Mulai <b>{tgl_awal.strftime('%d %b %Y')}</b>"
        elif tgl_akhir:
            periode_str = f"Rentang Waktu: Sampai <b>{tgl_akhir.strftime('%d %b %Y')}</b>"
        else:
            periode_str = f"Periode: Bulan {nama_bulan} Tahun {year} (Akumulasi Lengkap)"
            
        if selected_cat:
            periode_str += f" • Pos Filter: <b>{selected_cat.name} ({selected_cat.code})</b>"
            
        elements.append(Paragraph(periode_str, report_subheading_style))
        elements.append(Spacer(1, 4*mm))

        table_data = [["No", "Nama Donatur", "Kontak / No. WA", "Alamat / Asal", "Alokasi Pos Program", "Frekuensi", "Total Donasi", "Status Donatur"]]
        tot_don = 0
        total_frekuensi = 0
        active_count = 0

        for idx, d in enumerate(donors):
            tx_q = db.query(Transaction).filter(Transaction.donor_id == d.id, Transaction.type == "income")
            if tgl_awal:
                tx_q = tx_q.filter(Transaction.transaction_date >= tgl_awal)
            if tgl_akhir:
                tx_q = tx_q.filter(Transaction.transaction_date <= tgl_akhir)
                
            txs = tx_q.all()
            
            if selected_cat:
                txs = [t for t in txs if t.category_id == selected_cat.id]
                
            freq = len(txs)
            if freq == 0:
                continue
                
            sum_don = sum(t.amount for t in txs)
            
            # Kumpulkan pos-pos yang didonasikan
            pos_names_set = set()
            for t in txs:
                if t.category:
                    pos_names_set.add(t.category.code)
            pos_label = ", ".join(sorted(pos_names_set)) if pos_names_set else "-"

            tot_don += sum_don
            total_frekuensi += freq
            active_count += 1
            
            status_donatur = "Donatur Aktif" if freq >= 3 else ("Donatur Rutin" if freq >= 1 else "Terdaftar")

            table_data.append([
                str(active_count),
                sanitize_donor_name(d.name),
                d.phone or "-",
                d.address or "-",
                pos_label,
                f"{freq}x",
                format_rupiah_pdf(sum_don),
                status_donatur
            ])

        table_data.append([
            "", "TOTAL REKAPITULASI", "", "",
            f"{active_count} Donatur",
            f"{total_frekuensi}x transaksi",
            format_rupiah_pdf(tot_don),
            "-"
        ])

        t_don = Table(table_data, colWidths=[25, 150, 90, 115, 120, 60, 105, 75])
        t_don.setStyle(TableStyle([
            ('BACKGROUND', (0,0), (-1,0), colors.HexColor("#f1f5f9")),
            ('FONTNAME', (0,0), (-1,0), 'Helvetica-Bold'),
            ('FONTSIZE', (0,0), (-1,-1), 8.5),
            ('ALIGN', (4,0), (4,-1), 'CENTER'),
            ('ALIGN', (5,0), (5,-1), 'CENTER'),
            ('ALIGN', (6,0), (6,-1), 'RIGHT'),
            ('ALIGN', (7,0), (7,-1), 'CENTER'),
            ('GRID', (0,0), (-1,-1), 0.5, colors.HexColor("#cbd5e1")),
            ('FONTNAME', (0,-1), (-1,-1), 'Helvetica-Bold'),
            ('BACKGROUND', (0,-1), (-1,-1), colors.HexColor("#ecfdf5")),
            ('TEXTCOLOR', (0,-1), (-1,-1), colors.HexColor("#065f46")),
        ]))
        elements.append(t_don)

    elif report_type == "pemetaan_program":
        # LAPORAN PEMETAAN & DISTRIBUSI LENGKAP PER POS PROGRAM (SPM, IP, PML2, DLL)
        categories = db.query(ProgramCategory).filter(ProgramCategory.is_active == True).all()
        
        elements.append(Paragraph("LAPORAN PEMETAAN & DISTRIBUSI DONASI PER POS PROGRAM", report_heading_style))
        elements.append(Paragraph("Pemetaan Alokasi Donasi Donatur ke Setiap Pos Program Pesantren (SPM, IP, PML2, SK, dll.)", report_subheading_style))
        elements.append(Spacer(1, 4*mm))

        # Tabel Ringkasan Pemetaan per Pos Program
        summary_data = [["No", "Kode Pos", "Nama Pos Program", "Jumlah Donatur", "Total Transaksi", "Total Donasi Terkumpul"]]
        grand_total_donasi = 0
        grand_total_tx = 0
        
        for idx, cat in enumerate(categories):
            cat_txs = db.query(Transaction).filter(Transaction.category_id == cat.id, Transaction.type == "income").all()
            total_nominal = sum(t.amount for t in cat_txs)
            tx_count = len(cat_txs)
            donor_ids = set(t.donor_id for t in cat_txs if t.donor_id)
            donor_count = len(donor_ids)
            
            grand_total_donasi += total_nominal
            grand_total_tx += tx_count
            
            summary_data.append([
                str(idx + 1),
                cat.code,
                cat.name,
                f"{donor_count} Donatur",
                f"{tx_count} transaksi",
                format_rupiah_pdf(total_nominal)
            ])

        summary_data.append([
            "", "", "TOTAL KESELURUHAN POS",
            "-",
            f"{grand_total_tx} transaksi",
            format_rupiah_pdf(grand_total_donasi)
        ])

        t_summary = Table(summary_data, colWidths=[25, 60, 240, 110, 110, 130])
        t_summary.setStyle(TableStyle([
            ('BACKGROUND', (0,0), (-1,0), colors.HexColor("#064e3b")),
            ('TEXTCOLOR', (0,0), (-1,0), colors.white),
            ('FONTNAME', (0,0), (-1,0), 'Helvetica-Bold'),
            ('FONTSIZE', (0,0), (-1,-1), 8.5),
            ('ALIGN', (3,0), (4,-1), 'CENTER'),
            ('ALIGN', (5,0), (5,-1), 'RIGHT'),
            ('GRID', (0,0), (-1,-1), 0.5, colors.HexColor("#cbd5e1")),
            ('FONTNAME', (0,-1), (-1,-1), 'Helvetica-Bold'),
            ('BACKGROUND', (0,-1), (-1,-1), colors.HexColor("#ecfdf5")),
            ('TEXTCOLOR', (0,-1), (-1,-1), colors.HexColor("#065f46")),
        ]))
        elements.append(t_summary)
        elements.append(Spacer(1, 6*mm))

        # Rincian Top Donatur per Pos Utama (SPM, IP, PML2, dll.)
        elements.append(Paragraph("<b>RINCIAN DONATUR PER POS PROGRAM:</b>", ParagraphStyle('SubSub', parent=styles['Normal'], fontSize=10, textColor=colors.HexColor("#0f172a"))))
        elements.append(Spacer(1, 2*mm))

        for cat in categories:
            cat_txs = db.query(Transaction).filter(Transaction.category_id == cat.id, Transaction.type == "income").all()
            if not cat_txs:
                continue
            
            # Agregasi donasi per donatur untuk program ini
            donor_agg = {}
            for t in cat_txs:
                d_name = sanitize_donor_name(t.donor.name if t.donor else (t.donor_name_raw or "Hamba Allah"))
                if d_name not in donor_agg:
                    donor_agg[d_name] = {"count": 0, "amount": 0}
                donor_agg[d_name]["count"] += 1
                donor_agg[d_name]["amount"] += t.amount

            # Buat mini table per pos
            cat_heading = f"Pos Program: <b>{cat.name} ({cat.code})</b> • Total: <b>{format_rupiah_pdf(sum(t.amount for t in cat_txs))}</b>"
            elements.append(Paragraph(cat_heading, ParagraphStyle('PosHead', parent=styles['Normal'], fontSize=9, textColor=colors.HexColor("#047857"))))
            elements.append(Spacer(1, 1*mm))

            cat_table_data = [["No", "Nama Donatur", "Frekuensi Donasi", "Total Donasi"]]
            for d_idx, (d_name, d_val) in enumerate(sorted(donor_agg.items(), key=lambda x: x[1]["amount"], reverse=True)):
                cat_table_data.append([
                    str(d_idx + 1),
                    d_name,
                    f"{d_val['count']} kali",
                    format_rupiah_pdf(d_val['amount'])
                ])

            t_cat = Table(cat_table_data, colWidths=[25, 300, 150, 150])
            t_cat.setStyle(TableStyle([
                ('BACKGROUND', (0,0), (-1,0), colors.HexColor("#f1f5f9")),
                ('FONTNAME', (0,0), (-1,0), 'Helvetica-Bold'),
                ('FONTSIZE', (0,0), (-1,-1), 8),
                ('ALIGN', (2,0), (2,-1), 'CENTER'),
                ('ALIGN', (3,0), (3,-1), 'RIGHT'),
                ('GRID', (0,0), (-1,-1), 0.5, colors.HexColor("#cbd5e1")),
            ]))
            elements.append(t_cat)
            elements.append(Spacer(1, 3*mm))

    elif report_type == "barang":
        # Parse range date jika ada
        tgl_awal = None
        tgl_akhir = None
        if start_date:
            try:
                tgl_awal = datetime.strptime(start_date, "%Y-%m-%d").date()
            except:
                pass
        if end_date:
            try:
                tgl_akhir = datetime.strptime(end_date, "%Y-%m-%d").date()
            except:
                pass

        # Pos category filter
        selected_cat = None
        if pos and pos.strip():
            pos_clean = pos.strip()
            selected_cat = db.query(ProgramCategory).filter(
                (ProgramCategory.code == pos_clean.upper()) |
                (ProgramCategory.name == pos_clean) |
                (ProgramCategory.id == int(pos_clean) if pos_clean.isdigit() else False)
            ).first()

        # Query: Donasi Barang Fisik dari Donatur (DonationItem)
        q_items = db.query(DonationItem)
        if selected_cat:
            q_items = q_items.filter(DonationItem.category_id == selected_cat.id)
        if tgl_awal and tgl_akhir:
            q_items = q_items.filter(DonationItem.transaction_date >= tgl_awal, DonationItem.transaction_date <= tgl_akhir)
        elif tgl_awal:
            q_items = q_items.filter(DonationItem.transaction_date >= tgl_awal)
        elif tgl_akhir:
            q_items = q_items.filter(DonationItem.transaction_date <= tgl_akhir)
        elif year and month:
            q_items = q_items.filter(
                extract('year', DonationItem.transaction_date) == year,
                extract('month', DonationItem.transaction_date) == month
            )
        
        items_list = q_items.order_by(DonationItem.transaction_date.desc(), DonationItem.id.desc()).all()

        # Judul & Subjudul Laporan
        if selected_cat:
            elements.append(Paragraph(f"LAPORAN PENERIMAAN DONASI BARANG: {selected_cat.name.upper()} ({selected_cat.code})", report_heading_style))
        else:
            elements.append(Paragraph("DAFTAR PENERIMAAN DONASI BARANG & LOGISTIK", report_heading_style))

        if tgl_awal and tgl_akhir:
            periode_str = f"Rentang Waktu: <b>{tgl_awal.strftime('%d %b %Y')}</b> s/d <b>{tgl_akhir.strftime('%d %b %Y')}</b>"
        elif tgl_awal:
            periode_str = f"Rentang Waktu: Mulai <b>{tgl_awal.strftime('%d %b %Y')}</b>"
        elif tgl_akhir:
            periode_str = f"Rentang Waktu: Sampai <b>{tgl_akhir.strftime('%d %b %Y')}</b>"
        else:
            periode_str = f"Periode: Bulan <b>{nama_bulan} {year}</b>"

        if selected_cat:
            periode_str += f" • Pos Program: <b>{selected_cat.name} ({selected_cat.code})</b>"
        else:
            periode_str += " • Lingkup: <b>Semua Pos Program (Konsolidasi)</b>"

        elements.append(Paragraph(periode_str, report_subheading_style))
        elements.append(Spacer(1, 4*mm))

        # Tabel Data Penerimaan Donasi Barang Fisik
        table_data = [["No", "Tanggal", "Nama Penyumbang / Donatur", "Pos Program Tujuan", "Kategori Logistik", "Nama Barang", "Volume / Qty", "Keterangan"]]
        row_idx = 1

        for it in items_list:
            pos_label = it.category.code if it.category else (selected_cat.code if selected_cat else "-")
            table_data.append([
                str(row_idx),
                it.transaction_date.strftime("%Y-%m-%d") if it.transaction_date else "-",
                sanitize_donor_name(it.donor_name),
                pos_label,
                it.item_category or "Material Bangunan",
                it.item_name or "-",
                f"{it.quantity} {it.unit or ''}",
                it.description or "-"
            ])
            row_idx += 1

        if len(table_data) == 1:
            table_data.append(["-", "-", "Belum ada penerimaan donasi barang pada periode / pos ini", "-", "-", "-", "-", "-"])

        t_item = Table(table_data, colWidths=[25, 70, 150, 85, 110, 140, 75, 90])
        t_item.setStyle(TableStyle([
            ('BACKGROUND', (0,0), (-1,0), colors.HexColor("#f1f5f9")),
            ('FONTNAME', (0,0), (-1,0), 'Helvetica-Bold'),
            ('FONTSIZE', (0,0), (-1,-1), 8),
            ('ALIGN', (0,0), (0,-1), 'CENTER'),
            ('ALIGN', (3,0), (3,-1), 'CENTER'),
            ('ALIGN', (6,0), (6,-1), 'CENTER'),
            ('GRID', (0,0), (-1,-1), 0.5, colors.HexColor("#cbd5e1")),
        ]))
        elements.append(t_item)

    # 3. LEMBAR PENGESAHAN & TANDA TANGAN
    elements.append(Spacer(1, 8*mm))
    sign_w = 370 if report_type in ["transaksi", "buku_bantu", "donatur", "pemetaan_program", "barang"] else 250
    sign_data = [
        [
            Paragraph("Mengetahui / Memeriksa,<br/><br/><br/><b><u>Ainul Fais</u></b><br/>Pemeriksa Keuangan Pesantren", ParagraphStyle('Sign1', parent=styles['Normal'], fontSize=8.5, alignment=1)),
            Paragraph(f"Guluk-Guluk, {date.today().strftime('%d %B %Y')}<br/>Penanggung Jawab Keuangan,<br/><br/><br/><b><u>Bendahara PPA Latee</u></b><br/>Pengembangan & Pembangunan", ParagraphStyle('Sign2', parent=styles['Normal'], fontSize=8.5, alignment=1))
        ]
    ]
    sign_table = Table(sign_data, colWidths=[sign_w, sign_w])
    sign_table.setStyle(TableStyle([('ALIGN', (0,0), (-1,-1), 'CENTER')]))
    elements.append(sign_table)

    # Build Document
    doc.build(elements)
    pdf_value = buffer.getvalue()
    buffer.close()

    return Response(
        content=pdf_value,
        media_type="application/pdf",
        headers={
            "Content-Disposition": f"inline; filename=Laporan_{report_type}_{year}_{month}.pdf"
        }
    )

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)
