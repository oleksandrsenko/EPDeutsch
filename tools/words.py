#!/usr/bin/env python3
"""Выгрузка всех немецких слов сайта из data/*.json: WORDS.md (по темам, с переводом) и words.txt (по одному слову в строке)."""
import json, glob, os, re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
key = lambda p: [int(x) if x.isdigit() else 99 for x in os.path.basename(p)[:-5].split(".")]
files = [f for f in sorted(glob.glob(os.path.join(ROOT, "data", "*.json")), key=key)
         if os.path.basename(f) not in ("exams.json", "hoeren.json")]

md, flat, seen, total = [], [], set(), 0
for path in files:
    d = json.load(open(path, encoding="utf-8"))
    md.append(f"\n## {d['id']} {d['title']} — {d.get('titleRu', '')}\n")
    for s in d["sets"]:
        md.append(f"\n### {s['name']}\n")
        for c in s["items"]:
            de = c["de"].replace("*", "").strip()
            md.append(f"- **{de}** — {c['ru']}")
            total += 1
            k = de.lower()
            if k not in seen:
                seen.add(k)
                flat.append(de)

head = f"# Все немецкие слова EPDeutsch Wörter\n\nКарточек: {total}, разных слов и выражений: {len(flat)}. Источник: `data/*.json`, пересобрать: `python3 tools/words.py`.\n"
open(os.path.join(ROOT, "WORDS.md"), "w", encoding="utf-8").write(head + "\n".join(md) + "\n")
open(os.path.join(ROOT, "words.txt"), "w", encoding="utf-8").write("\n".join(flat) + "\n")
print(total, "карточек,", len(flat), "уникальных")
