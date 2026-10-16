#!/usr/bin/env python3
"""Проверка контента личной платформы: data/de/*.json и data/frei/*.json.
Ищет повторяющиеся id, неверные индексы ответов, пустые поля, неизвестные типы заданий и голоса.
Запуск: python3 tools/check_lernen.py"""
import json, os, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
VOICES = {"alloy", "ash", "ballad", "coral", "echo", "fable", "nova", "onyx", "sage", "shimmer", "verse"}
LEVELS = {"B1", "B1+", "B2", "B2+", "C1", "C1+", "B2–C1"}
errors = 0


def err(where, msg):
    global errors
    errors += 1
    print("  ОШИБКА %s: %s" % (where, msg))


def check_task(where, t, paras=0):
    k = t.get("t")
    if not t.get("q"):
        err(where, "пустой вопрос")
    if k == "tf":
        if t.get("a") not in ("r", "f", "n"):
            err(where, "tf: ответ должен быть r/f/n")
        if t.get("two") and t.get("a") == "n":
            err(where, "tf без «nicht im Text», но ответ n")
    elif k == "mc":
        o = t.get("o") or []
        if len(o) < 2 or not isinstance(t.get("a"), int) or not 0 <= t["a"] < len(o):
            err(where, "mc: неверный индекс ответа")
    elif k == "open":
        if not t.get("a"):
            err(where, "open: нет образца ответа")
    elif k == "head":
        heads, a, ps = t.get("heads") or [], t.get("a") or [], t.get("paras") or []
        if len(a) != len(ps) or any(not isinstance(x, int) or not 0 <= x < len(heads) for x in a):
            err(where, "head: ответы не совпадают с абзацами/заголовками")
        if paras and any(p < 1 or p > paras for p in ps):
            err(where, "head: номер абзаца вне текста")
    elif k == "gap":
        if not t.get("a"):
            err(where, "gap: нет ответа")
    else:
        err(where, "неизвестный тип задания %r" % k)


def check_gram(area, d):
    ids = set()
    for tp in d.get("topics", []):
        w = "%s/grammatik/%s" % (area, tp.get("id"))
        if tp["id"] in ids:
            err(w, "повтор id")
        ids.add(tp["id"])
        if not tp.get("theory"):
            err(w, "нет теории")
        for i, q in enumerate(tp.get("items", [])):
            wi = "%s #%d" % (w, i + 1)
            k = q.get("t")
            if k == "mc":
                if not 0 <= q.get("a", -1) < len(q.get("o", [])):
                    err(wi, "mc: неверный индекс")
            elif k in ("gap", "tr"):
                if not q.get("a") or not all(isinstance(x, str) and x.strip() for x in q["a"]):
                    err(wi, "нет эталонных ответов")
                if k == "gap" and "___" not in q.get("q", ""):
                    err(wi, "gap без ___")
            else:
                err(wi, "неизвестный тип %r" % k)
            if q.get("lv") not in (1, 2, 3):
                err(wi, "lv должен быть 1–3")


def main():
    for area in ("de", "frei"):
        base = os.path.join(ROOT, "data", area)
        for name in sorted(os.listdir(base)):
            if not name.endswith(".json"):
                continue
            d = json.load(open(os.path.join(base, name), encoding="utf-8"))
            kind = name[:-5]
            print("— %s/%s" % (area, kind))
            if kind == "grammatik":
                check_gram(area, d)
            elif kind == "lesen":
                ids = set()
                for t in d.get("texts", []):
                    w = "%s/lesen/%s" % (area, t.get("id"))
                    if t["id"] in ids: err(w, "повтор id")
                    ids.add(t["id"])
                    if t.get("level") not in LEVELS: err(w, "уровень %r" % t.get("level"))
                    if not t.get("paras"): err(w, "нет текста")
                    for i, q in enumerate(t.get("tasks", [])): check_task("%s #%d" % (w, i + 1), q, len(t.get("paras", [])))
            elif kind == "hoeren":
                ids = set()
                for it in d.get("items", []):
                    w = "%s/hoeren/%s" % (area, it.get("id"))
                    if it["id"] in ids: err(w, "повтор id")
                    ids.add(it["id"])
                    voices = it.get("voices") or {}
                    for spk, v in voices.items():
                        if v.get("voice") not in VOICES: err(w, "голос %r у %s" % (v.get("voice"), spk))
                    for spk, text in it.get("lines", []):
                        if spk not in voices: err(w, "нет голоса для %r" % spk)
                        if len(text) > 4000: err(w, "реплика длиннее 4000 знаков (лимит TTS)")
                    for i, q in enumerate(it.get("tasks", [])): check_task("%s #%d" % (w, i + 1), q)
            elif kind == "sprechen":
                ids = set()
                for t in d.get("tasks", []):
                    w = "%s/sprechen/%s" % (area, t.get("id"))
                    if t["id"] in ids: err(w, "повтор id")
                    ids.add(t["id"])
                    if not t.get("title"): err(w, "нет заголовка")
                    if t.get("voice") and t["voice"] not in VOICES: err(w, "голос %r" % t["voice"])
            elif kind == "wendungen":
                for dk in d.get("decks", []):
                    for i, c in enumerate(dk.get("cards", [])):
                        if not c.get("de") or not c.get("ru"): err("%s/%s #%d" % (area, dk["id"], i + 1), "пустая карточка")
    print("\nОшибок: %d" % errors)
    sys.exit(1 if errors else 0)


main()
