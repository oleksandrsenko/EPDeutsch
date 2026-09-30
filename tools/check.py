#!/usr/bin/env python3
"""Проверка data/*.json: [[ ]] в примерах, дубликаты внутри раздела, поля, сверка с книгой."""
import json, re, sys, glob, os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
G = {"m", "f", "n", "pl", "v"}
errors = 0

def err(msg):
    global errors
    errors += 1
    print("  ОШИБКА:", msg)

def key(sec_id):
    return [int(x) if x.isdigit() else 99 for x in re.split(r"[.]", sec_id)]

files = sorted(glob.glob(os.path.join(ROOT, "data", "*.json")), key=lambda p: key(os.path.basename(p)[:-5]))
if len(sys.argv) > 1:
    files = [f for f in files if os.path.basename(f)[:-5] in sys.argv[1:]]

rows = []
for path in files:
    d = json.load(open(path, encoding="utf-8"))
    sid = d["id"]
    print(f"— {sid} {d['title']}")
    seen = {}
    main = bonus = 0
    for s in d["sets"]:
        n = len(s["items"])
        if not s.get("bonus") and not (6 <= n <= 13):
            print(f"  предупреждение: набор «{s['name']}» — {n} карточек")
        for i, c in enumerate(s["items"]):
            where = f"{s['id']}[{i}] {c.get('de','?')}"
            for f in ("de", "ru", "ex", "note", "g"):
                if f not in c:
                    err(f"{where}: нет поля {f}")
            if c.get("g") not in G:
                err(f"{where}: g={c.get('g')!r}")
            ex = c.get("ex", "")
            if ex and not re.search(r"\[\[.+?\]\]", ex):
                err(f"{where}: в примере нет [[ ]]")
            if ex.count("[[") != ex.count("]]"):
                err(f"{where}: непарные скобки [[ ]]")
            k = re.sub(r"\s+", " ", c.get("de", "")).strip().lower()
            if k in seen:
                err(f"{where}: дубликат {seen[k]}")
            seen[k] = where
            if s.get("bonus"):
                bonus += 1
            else:
                main += 1
    rows.append((sid, d["title"], d.get("bookRows", "?"), main, bonus))

print()
print(f"{'Раздел':<7}{'Тема':<34}{'строк в книге':>14}{'карточек':>10}{'бонус':>7}")
for r in rows:
    print(f"{r[0]:<7}{r[1][:33]:<34}{r[2]:>14}{r[3]:>10}{r[4]:>7}")
tb = sum(r[2] for r in rows if isinstance(r[2], int))
print(f"{'Итого':<41}{tb:>14}{sum(r[3] for r in rows):>10}{sum(r[4] for r in rows):>7}")
print("\nОшибок:", errors)
sys.exit(1 if errors else 0)
