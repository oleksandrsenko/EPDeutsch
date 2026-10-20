#!/usr/bin/env python3
"""Имитация Anthropic / OpenAI / LanguageTool для локальной проверки: разумные ответы по типу запроса (план, абзац, задания …)."""
import json, os, random, re, sys, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SENT = [
 "Die Stadt Wien hat in den vergangenen Jahren zahlreiche Maßnahmen ergriffen, um den Zugang zu leistbarem Wohnraum zu erleichtern.",
 "Nach Angaben der Wiener Wohnbauforschung stieg die durchschnittliche Miete bei Neuvermietungen seit dem Jahr zweitausendzwanzig um mehr als dreißig Prozent.",
 "Besonders junge Menschen sehen sich dadurch gezwungen, ihre Pläne für eine eigene Wohnung zunächst zurückzustellen.",
 "„Wir haben monatelang gesucht und waren am Ende froh, überhaupt eine Besichtigung zu bekommen“, erzählt die Studentin Lena Hofer.",
 "Experten warnen allerdings davor, die Lage allein den Preisen zuzuschreiben, denn auch das Angebot an geförderten Wohnungen sei begrenzt.",
 "Der Sozialforscher Markus Reiter betont, dass sich die Situation in den Bundesländern deutlich unterscheide und kaum zu verallgemeinern sei.",
 "Wer in einem Gemeindebau lebt, zahlt im Durchschnitt weniger als die Hälfte dessen, was am freien Markt verlangt wird.",
 "Dennoch bleibt die Warteliste lang, und die Wartezeit beträgt in manchen Bezirken bis zu zwei Jahre.",
 "Die Bundesregierung prüft derzeit, ob eine Obergrenze für Mietsteigerungen rechtlich durchsetzbar wäre.",
 "Kritiker befürchten jedoch, dass private Investoren dann weniger bereit wären, in neue Projekte zu investieren.",
 "Eine aktuelle Studie der Universität Graz kommt zu dem Ergebnis, dass die Wohnkosten inzwischen fast ein Drittel des Haushaltseinkommens ausmachen.",
 "Gleichzeitig wächst die Zahl derjenigen, die auf gemeinschaftliche Wohnformen ausweichen, um die Kosten zu teilen.",
 "Ob diese Entwicklung auf Dauer tragfähig ist, lässt sich nach Ansicht der Fachleute derzeit nicht abschließend beurteilen.",
 "Zudem hängt die Zufriedenheit der Bewohner nicht nur vom Preis, sondern auch von der Lage und der Nachbarschaft ab.",
]
WORDS = ["Wohnbauforschung", "Neuvermietungen", "Studentin", "Besichtigung", "Gemeindebau", "Wartezeit", "Obergrenze", "Investoren", "Haushaltseinkommen", "Nachbarschaft", "Entwicklung", "Maßnahmen"]
STATS = {"calls": 0}

def words(s): return re.findall(r"[\wÄÖÜäöüß]+", s)

def para_text(i, n=125):
    out, c = [], 0
    k = i * 3
    while c < n:
        s = SENT[k % len(SENT)]; k += 1
        out.append(s); c += len(words(s))
    return " ".join(out)

def split_sentences(text):
    return [x.strip() for x in re.split(r"(?<=[.!?“])\s+", text) if len(x.strip()) > 40]

def first4(s): return " ".join(s.split()[:4])

def ltasks(kind, content):
    paras = re.findall(r"^Absatz (\d+): (.*)$", content, re.M)
    ps = [(int(n), t) for n, t in paras]
    sents = []
    for n, t in ps:
        for s in split_sentences(t): sents.append((n, s))
    random.seed(len(content))
    def pick(k): return random.sample(sents, min(k, len(sents)))
    items = []
    if kind in ("tf", "rfb"):
        for j, (n, s) in enumerate(pick(8)):
            a = ["r", "f", "n"][j % 3] if kind == "tf" else ["r", "f"][j % 2]
            items.append({"q": s[:160] + ("" if a == "r" else " (nicht ganz)"), "o": [], "ai": -1, "at": [a], "ev": "" if a == "n" else first4(s), "w": ""})
    elif kind == "mc3":
        for n, s in pick(8):
            items.append({"q": "Was sagt der Text über: " + " ".join(s.split()[:5]) + " …?", "o": [s[:60], "Etwas ganz anderes.", "Das bleibt offen."], "ai": 0, "at": [], "ev": first4(s), "w": ""})
    elif kind == "qs":
        for n, s in pick(8):
            ws = s.split()
            items.append({"q": "Nennen Sie: " + " ".join(ws[:4]) + " … ?", "o": [], "ai": -1, "at": [" ".join(ws[-3:]).strip(".")], "ev": first4(s), "w": ""})
    elif kind in ("head", "zu2"):
        nums = [n for n, _ in ps][:7] if kind == "head" else [n for n, _ in ps][:8]
        for n in nums:
            items.append({"q": ("Überschrift zu Absatz %d" % n) if kind == "head" else ("Aussage über Absatz %d: " % n) + first4(dict(ps)[n]), "o": [], "ai": n, "at": [], "ev": "", "w": ""})
        if kind == "head":
            items += [{"q": "Falsche Überschrift eins", "o": [], "ai": 0, "at": [], "ev": "", "w": ""}, {"q": "Falsche Überschrift zwei", "o": [], "ai": 0, "at": [], "ev": "", "w": ""}]
    elif kind in ("box", "sum", "cl4"):
        alltext = " ".join(t for _, t in ps)
        ws = [w for w in words(alltext) if len(w) > 7]
        seen = []
        for w in ws:
            if w not in seen: seen.append(w)
        take = seen[:8]
        text = "Zusammenfassung: " + " ".join("Der Absatz handelt von [[%d]] und weiteren Fragen." % (k + 1) for k in range(8))
        if kind == "box":
            return {"text": text, "words": take + seen[8:11], "items": [{"q": "", "o": [], "ai": -1, "at": [w], "ev": "", "w": ""} for w in take], "sec": 0}
        if kind == "sum":
            return {"text": text, "words": [], "items": [{"q": "", "o": [], "ai": -1, "at": [w], "ev": "", "w": ""} for w in take], "sec": 0}
        return {"text": text, "words": [], "items": [{"q": "", "o": [w, "Haus", "Auto", "Stein"], "ai": 0, "at": [], "ev": "", "w": ""} for w in take], "sec": 0}
    elif kind == "sdis":
        items = [{"q": "Dieser zusätzliche Satz passt thematisch, steht aber an keiner der entfernten Stellen im Text, sagt man.", "o": [], "ai": -1, "at": [], "ev": "", "w": ""} for _ in range(3)]
    else:
        for n, s in pick(6):
            ws = [w for w in words(s) if len(w) > 6]
            items.append({"q": "Bezug [[sie]] in: " + s[:50], "o": [], "ai": -1, "at": [ws[0] if ws else "Wien"], "ev": "", "w": ""})
    return {"text": "", "words": [], "items": items, "sec": 0}

def build(system, content, schema):
    s = system
    if "СПЛАНИРОВАТЬ длинную газетную" in s:
        topic = re.search(r"Тема: (.*)", content).group(1)
        return {"title": "Zwischen Traum und Realität: " + topic[:40], "kicker": "Gesellschaft", "lead": "Immer mehr Menschen müssen umdenken. Ein Dossier über Zahlen, Stimmen und offene Fragen.",
                "paras": ["Absatz %d: Die Studentin Lena Hofer (22) sucht seit Monaten, Miete 840 Euro, Wien; der Sozialforscher Markus Reiter ordnet ein und nennt Zahlen." % (i + 1) for i in range(8)]}
    if "Напиши ТОЛЬКО абзац номер" in s:
        i = int(re.search(r"номер (\d+) из", content).group(1))
        return {"text": para_text(i, 125)}
    if "СПЛАНИРОВАТЬ запись длиной" in s:
        return {"kind": "Radiointerview", "title": "Wohnen in Wien", "intro": "Sie hören ein Interview über Wohnen in Wien.",
                "speakers": [{"id": "A", "name": "Moderatorin", "female": True}, {"id": "B", "name": "Dr. Huber", "female": False}],
                "segments": ["Block %d: Moderatorin Anna Gruber fragt, Dr. Huber nennt Zahlen und Gründe, Wien, Graz." % (i + 1) for i in range(8)]}
    if "Напиши реплики для блока номер" in s or "Напиши реплики ТОЛЬКО для блока" in s:
        i = int(re.search(r"блока номер (\d+) из", content).group(1))
        lines = []
        k = i * 4
        for j in range(4):
            lines.append({"s": "A" if j % 2 == 0 else "B", "text": SENT[(k + j * 2) % len(SENT)] + " " + SENT[(k + j * 2 + 1) % len(SENT)] + " " + SENT[(k + j * 2 + 2) % len(SENT)]})
        return {"lines": lines}
    if "Aufgabe 1: ровно 7 вопросов" in s or "ровно 7 вопросов по порядку записи" in s:
        sents = [x for x in split_sentences(content) if len(x) > 60]
        items = []
        for j in range(7):
            t = sents[(j * 5) % len(sents)]
            items.append({"q": "Was wird über das Thema in Abschnitt %d gesagt?" % (j + 1), "o": [t[:70], "Das Gegenteil wurde behauptet.", "Darüber wurde nicht gesprochen.", "Es wurde nur beiläufig erwähnt."], "ai": 0, "ex": "Im Abschnitt %d" % (j + 1)})
        return {"items": items}
    if "конспект (Notizen) к записи" in s:
        tr = content
        cand = []
        for w in words(tr):
            if len(w) > 8 and w[0].isupper() and w not in cand: cand.append(w)
        take = cand[:8]
        notes = " · ".join("Punkt %d: [[%d]] spielt eine Rolle" % (k + 1, k + 1) for k in range(8))
        return {"notes": notes, "gaps": [{"at": [w]} for w in take]}
    if "Составь короткую теорию" in s:
        return {"title": "Konjunktiv II", "ru": "Сослагательное наклонение", "theory": "<p>" + "Это конструкция, которая выражает нереальные условия и вежливые просьбы в немецком языке. " * 4 + "</p><h3>Форма</h3><ul><li>würde + Infinitiv</li><li>hätte / wäre + Partizip II</li></ul><h3>Примеры</h3><div class=\"ex\">Wenn ich Zeit <b class=\"k\">hätte</b>, würde ich reisen.</div><div class=\"tip\">Не путай с Präteritum.</div>"}
    if "kind = \"" in content and "Strukturen und Wortschatz" in s:
        kind = re.search(r'kind = "(\w+)"', content).group(1)
        if kind == "ct":
            return {"items": [{"q": "Die Forschung zeigt, dass [[1]] in Städten stark zunimmt. Viele Menschen [[2]] deshalb auf das Auto. Die Politik [[3]] neue Konzepte und [[4]] sie schrittweise um. Das [[5]] bleibt umstritten.", "a": ["Verkehr", "verzichten", "entwickelt", "setzt", "Ergebnis"], "hint": "", "ex": "Achte auf Verbformen.", "dir": ""}]}
        if kind == "lt":
            return {"items": [{"q": "Sie fragte, ___ ich mitkommen wolle %d." % k, "a": ["ob"], "hint": "Konnektor", "ex": "Ja/Nein-Frage", "dir": ""} for k in range(5)]}
        if kind == "uf":
            return {"items": [{"q": "[[Wegen der anhaltenden Trockenheit im Sommer]] mussten viele Landwirte ihre Ernte früher einbringen %d." % k, "a": ["Weil es im Sommer anhaltend trocken war", "Weil der Sommer anhaltend trocken war"], "hint": "", "ex": "wegen → weil", "dir": "n2v"} for k in range(5)]}
        return {"items": [{"q": "Sie interessiert sich ___ moderne Kunst %d." % k, "a": ["für"], "hint": "(sich interessieren)", "ex": "sich interessieren für + A.", "dir": ""} for k in range(5)]}
    if "kind: " in content and "Leseverstehen" in s:
        kind = re.search(r"kind: (\w+)", content).group(1)
        return ltasks(kind, content)
    if "Оцени каждый ответ как на экзамене" in s or "Оцени каждый ответ по смыслу" in s:
        n = len(re.findall(r"^\d+\. Frage:", content, re.M))
        return {"results": [{"score": [1, 0.5, 0][k % 3], "why": "Проверка (mock)."} for k in range(n)]}
    return None

def fill(schema):
    t = schema.get("type")
    if t == "object": return {k: fill(v) for k, v in (schema.get("properties") or {}).items()}
    if t == "array": return [fill(schema["items"])] if schema.get("items") else []
    if t == "string": return (schema.get("enum") or ["Beispiel"])[0]
    if t == "integer": return (schema.get("enum") or [2])[-1]
    if t == "number": return 1
    if t == "boolean": return True
    return None

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def reply(self, obj, ctype="application/json", code=200):
        raw = obj if isinstance(obj, bytes) else json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(code); self.send_header("Content-Type", ctype); self.send_header("Content-Length", str(len(raw))); self.end_headers(); self.wfile.write(raw)
    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0); body = self.rfile.read(n)
        if self.path.startswith("/v1/messages"):
            STATS["calls"] += 1
            req = json.loads(body)
            system = req.get("system", ""); content = req["messages"][0]["content"]
            sc = req["output_config"]["format"]["schema"]
            delay = float(os.environ.get("MOCK_DELAY", "0.4"))
            if "SLOWTEST" in content: time.sleep(40)
            time.sleep(delay)
            out = build(system, content, sc)
            if out is None: out = fill(sc)
            sys.stderr.write("[mock] %s -> %s\n" % (system[:60].replace("\n", " "), json.dumps(out, ensure_ascii=False)[:120]))
            return self.reply({"stop_reason": "end_turn", "content": [{"type": "text", "text": json.dumps(out, ensure_ascii=False)}], "usage": {"input_tokens": 800, "output_tokens": 400}})
        if self.path.startswith("/v1/audio/speech"):
            time.sleep(0.6)
            return self.reply(b"ID3" + os.urandom(4000), "audio/mpeg")
        if self.path.startswith("/v2/check"):
            return self.reply({"matches": []})
        self.send_response(404); self.end_headers()

if __name__ == "__main__":
    ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1]) if len(sys.argv) > 1 else 8830), H).serve_forever()
