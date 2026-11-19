import sqlite3
import pandas as pd
import sys

DB_PATH = "/home/mmansur/pusatlayanan/backend/donasi_latee.db"

def inspect_database():
    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()

    # Ambil semua nama tabel
    cursor.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%';")
    tables = [row[0] for row in cursor.fetchall()]

    print("=" * 60)
    print(f"DATABASE: {DB_PATH}")
    print(f"TABEL YANG TERSEDIA: {', '.join(tables)}")
    print("=" * 60)

    for table in tables:
        cursor.execute(f"SELECT COUNT(*) FROM {table}")
        count = cursor.fetchone()[0]
        print(f"\n📂 TABEL: {table} ({count} baris data)")
        
        # Tampilkan beberapa baris pertama
        if count > 0:
            df = pd.read_sql_query(f"SELECT * FROM {table} LIMIT 5", conn)
            print(df.to_string(index=False))
        else:
            print("   (Belum ada data / tabel kosong)")
        print("-" * 60)

    conn.close()

if __name__ == "__main__":
    inspect_database()
