#!/usr/bin/env python3
"""Компактный исходник src/<id>.txt → data/<id>.json.

  @key value            — метаданные раздела (id, chapter, chapterName, title, titleRu, pages, exercises, solutions, bookRows)
  # s1 Название          — новый набор;  ## b1 Название — бонус-набор
  de | ru | ex | note | g
  ! ловушка | объяснение
"""
import json, sys, os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

def convert(sid):
    src = os.path.join(ROOT, "src", sid + ".txt")
    d = {"sets": [], "traps": []}
    for n, raw in enumerate(open(src, encoding="utf-8"), 1):
        line = raw.strip()
        if not line or line.startswith("//"):
            continue
        if line.startswith("@"):
            k, _, v = line[1:].partition(" ")
            d[k] = int(v) if k in ("chapter", "bookRows") else v.strip()
        elif line.startswith("## ") or line.startswith("# "):
            bonus = line.startswith("## ")
            _, gid, name = line.split(" ", 2)
            s = {"id": gid, "name": name, "items": []}
            if bonus:
                s["bonus"] = True
            d["sets"].append(s)
        elif line.startswith("!"):
            k, v = [x.strip() for x in line[1:].split("|", 1)]
            d["traps"].append({"k": k, "v": v})
        else:
            p = [x.strip() for x in line.split("|")]
            if len(p) != 5:
                sys.exit(f"{src}:{n}: ожидалось 5 полей, получено {len(p)}: {line}")
            d["sets"][-1]["items"].append(dict(zip(("de", "ru", "ex", "note", "g"), p)))
    k = 0
    for s in d["sets"]:
        if not s.get("bonus"):
            k += 1
            s["id"] = "s%d" % k
    order = ["id", "chapter", "chapterName", "title", "titleRu", "pages", "exercises", "solutions", "bookRows", "sets", "traps"]
    out = {k: d[k] for k in order if k in d}
    out.update({k: v for k, v in d.items() if k not in out})
    json.dump(out, open(os.path.join(ROOT, "data", sid + ".json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)

for a in sys.argv[1:]:
    convert(a)
