"""
Filiallarni avtomatik yaratib, NVRlarni IP bo'yicha biriktiradi.
Faqat bir marta ishga tushiriladi:  python seed.py
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from database import engine, SessionLocal
import models
import poller as p

models.Base.metadata.create_all(bind=engine)

# ── FILIAL → IP (ikkinchi oktet diapazoni) ─────────────────────────────
# Format: (filial nomi, [ikkinchi oktetlar ro'yxati])
# Masalan: 10.15.120.x → ikkinchi oktet = 15 → "O'rta chirchiq"
BRANCH_MAP = [
    ("O'rta chirchiq", list(range(11, 16))),    # 10.11–15.x.x
    ("Chirchiq",        list(range(21, 27))),    # 10.21–26.x.x
    ("Qodiriya",        list(range(31, 36))),    # 10.31–35.x.x
    ("Toshkent",        list(range(41, 45))),    # 10.41–44.x.x
    ("Quyi bo'zsuv",    list(range(51, 57))),    # 10.51–56.x.x
    ("Farxod",          list(range(61, 70))),    # 10.61–69.x.x
    ("Samarqand",       list(range(71, 79))),    # 10.71–78.x.x
    ("Xisorak",         [81]),                   # 10.81.x.x
    ("To'palang",       list(range(91, 100))),   # 10.91–99.x.x
    ("Andijon",         list(range(111, 115))),  # 10.111–114.x.x
    ("Shaxrixon",       list(range(121, 125))),  # 10.121–124.x.x
    ("Norin",           list(range(131, 136))),  # 10.131–135.x.x
    ("Qamchiq",         [141]),                  # 10.141.x.x
    ("Oxangaron",       [151]),                  # 10.151.x.x
]

def get_second_octet(ip: str) -> int | None:
    try:
        parts = ip.strip().split(".")
        return int(parts[1]) if len(parts) >= 2 else None
    except Exception:
        return None

def find_branch(ip: str, branch_db_map: dict) -> models.Branch | None:
    octet = get_second_octet(ip)
    if octet is None:
        return None
    for name, octets in BRANCH_MAP:
        if octet in octets:
            return branch_db_map.get(name)
    return None

def main():
    db = SessionLocal()
    try:
        # 1. Filiallarni yaratish
        print("=" * 50)
        print("1. Filiallar yaratilmoqda...")
        branch_db_map = {}
        for name, _ in BRANCH_MAP:
            existing = db.query(models.Branch).filter(models.Branch.name == name).first()
            if existing:
                branch_db_map[name] = existing
                print(f"   · {name} — allaqachon mavjud")
            else:
                b = models.Branch(name=name)
                db.add(b)
                db.flush()
                branch_db_map[name] = b
                print(f"   ✓ {name} — yaratildi")
        db.commit()
        print(f"   Jami: {len(branch_db_map)} ta filial")

        # 2. HikCentral dan NVR sinxronlash
        print("\n2. HikCentral dan NVRlar yuklanmoqda...")
        p.sync_nvrs_to_db(db)
        nvrs = db.query(models.NVR).all()
        print(f"   {len(nvrs)} ta NVR topildi")

        # 3. NVRlarni filiallarga biriktirish
        print("\n3. NVRlar filiallarga biriktirilmoqda...")
        matched = 0
        unmatched = []
        for nvr in nvrs:
            branch = find_branch(nvr.ip, branch_db_map)
            if branch:
                nvr.branch_id = branch.id
                matched += 1
                print(f"   ✓ {nvr.name or nvr.hik_code}  ({nvr.ip})  →  {branch.name}")
            else:
                nvr.branch_id = None
                unmatched.append(f"{nvr.name or nvr.hik_code} ({nvr.ip})")
        db.commit()

        print(f"\n   Biriktirildi: {matched} ta NVR")
        if unmatched:
            print(f"   Topilmadi ({len(unmatched)} ta):")
            for u in unmatched:
                print(f"     - {u}")

        # 4. Natija xulosasi
        print("\n" + "=" * 50)
        print("NATIJA:")
        for b in db.query(models.Branch).all():
            count = db.query(models.NVR).filter(models.NVR.branch_id == b.id).count()
            print(f"  {b.name}: {count} ta NVR")

        print("\nMuvaffaqiyatli yakunlandi!")
        print("Endi 'python run.py' ni ishga tushiring.")

    finally:
        db.close()

if __name__ == "__main__":
    main()
