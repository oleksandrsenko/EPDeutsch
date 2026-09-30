#!/usr/bin/env python3
"""Собирает data/*.json + src/translations.txt + src/grammar.txt в index.html."""
import json, glob, os, re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

def key(p):
    sid = os.path.basename(p)[:-5]
    return [int(x) if x.isdigit() else 99 for x in sid.split(".")]

book = []
for p in sorted(glob.glob(os.path.join(ROOT, "data", "*.json")), key=key):
    d = json.load(open(p, encoding="utf-8"))
    if "sets" not in d:
        continue
    sets = []
    for s in d["sets"]:
        items = []
        for c in s["items"]:
            row = [c["de"], c["ru"], c["ex"], c["note"], c["g"]]
            if d.get("verbs"):
                row += [c["pres"], c["prat"], c["perf"]]
            items.append(row)
        st = {"id": s["id"], "name": s["name"], "items": items}
        if s.get("bonus"):
            st["bonus"] = True
        sets.append(st)
    sec = {k: d.get(k, "") for k in ("id", "chapter", "title", "titleRu", "pages", "exercises", "solutions")}
    sec["sets"] = sets
    sec["traps"] = d.get("traps", [])
    if d.get("verbs"):
        sec["verbs"] = True
    book.append(sec)

tr = []
for n, line in enumerate(open(os.path.join(ROOT, "src", "translations.txt"), encoding="utf-8")):
    line = line.strip()
    if not line or line.startswith("//"):
        continue
    s, ru, de = [x.strip() for x in line.split("|")]
    tr.append({"id": "t%d" % n, "s": s, "ru": ru, "de": de})

gr, cn = [], {}
for n, line in enumerate(open(os.path.join(ROOT, "src", "grammar.txt"), encoding="utf-8")):
    line = line.strip()
    if not line or line.startswith("//"):
        continue
    if line.startswith("@"):
        k, _, name = line[1:].partition(" ")
        cn[k] = name
        continue
    c, q, st, a, r = [x.strip() for x in line.split("|")]
    gr.append({"id": "g%d" % n, "c": c, "cn": cn[c], "q": q, "st": st, "a": a, "r": r})

dump = lambda o: json.dumps(o, ensure_ascii=False, separators=(",", ":")).replace("</", "<\\/")
html = open(os.path.join(ROOT, "tools", "template.html"), encoding="utf-8").read()
html = html.replace("/*BOOK*/[]", dump(book)).replace("/*TR*/[]", dump(tr)).replace("/*GR*/[]", dump(gr))
open(os.path.join(ROOT, "index.html"), "w", encoding="utf-8").write(html)
print("index.html:", round(len(html.encode()) / 1024), "КБ ·", sum(len(s["items"]) for b in book for s in b["sets"]), "карточек ·", len(tr), "переводов ·", len(gr), "Umformungen")
