#!/usr/bin/env python3
"""Собирает public/data/de/hoeren.json из tools/hoeren_src/<id>.txt: запись ≥7 минут и ровно два задания (A–D и конспект), 15 баллов.
Формат файла: строки «@X текст» — реплики спикера X; «#MC» — 7 строк «вопрос | a | b | c | d | буква верного | пояснение»;
«#NOTES» — конспект с [[1]]…[[8]]; «#GAPS» — 8 строк «n | ответ | другой допустимый ответ». Запуск: python3 tools/build_hoeren.py"""
import json, os, re, sys
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'tools/hoeren_src')
JSONP = os.path.join(ROOT, 'public/data/de/hoeren.json')
WPM = 145
norm = lambda s: ' '.join(re.sub(r'[^\wäöüß ]+', ' ', s.lower()).split())

def parse(path, voices):
    lines, mc, notes, gaps, mode, intro = [], [], '', {}, 'L', ''
    for raw in open(path, encoding='utf-8'):
        raw = raw.rstrip('\n')
        if not raw.strip(): continue
        if raw.startswith('#INTRO'): mode = 'I'; continue
        if raw.startswith('#MC'): mode = 'MC'; continue
        if raw.startswith('#NOTES'): mode = 'N'; continue
        if raw.startswith('#GAPS'): mode = 'G'; continue
        if raw.startswith('@'): mode = 'L'
        if mode == 'L':
            m = re.match(r'@(\w+)\s+(.*)', raw)
            if not m: raise SystemExit(f'{path}: неверная строка: {raw[:60]}')
            if m.group(1) not in voices: raise SystemExit(f'{path}: нет голоса {m.group(1)}')
            lines.append([m.group(1), m.group(2).strip()])
        elif mode == 'MC':
            p = [x.strip() for x in raw.split('|')]
            if len(p) != 7: raise SystemExit(f'{path}: MC должен иметь 7 полей: {raw[:60]}')
            o = p[1:5]; a = 'abcd'.index(p[5].lower())
            if len(set(o)) != 4: raise SystemExit(f'{path}: одинаковые варианты: {raw[:60]}')
            # верный ответ ставим на заранее выбранное место (разные буквы A–D), порядок остальных не меняется
            tgt = [2, 0, 3, 1, 2, 3, 0][len(mc) % 7]
            o[a], o[tgt] = o[tgt], o[a]
            mc.append({'q': p[0], 'o': o, 'a': tgt, 'ex': p[6]})
        elif mode == 'I': intro = raw.strip()
        elif mode == 'N': notes += (' ' if notes else '') + raw
        elif mode == 'G':
            p = [x.strip() for x in raw.split('|')]
            gaps[int(p[0])] = [x for x in p[1:] if x]
    return lines, mc, notes.strip(), gaps, intro

def main():
    data = json.load(open(JSONP, encoding='utf-8'))
    ok = bad = 0
    for it in data['items']:
        f = os.path.join(SRC, it['id'] + '.txt')
        if not os.path.exists(f): continue
        lines, mc, notes, gaps, intro = parse(f, it['voices'])
        text = norm(' '.join(l[1] for l in lines))
        words = sum(len(l[1].split()) for l in lines)
        errs = []
        if words < 1000: errs.append(f'слов {words} < 1000')
        if len(mc) != 7: errs.append(f'MC {len(mc)} ≠ 7')
        n = sorted(int(x) for x in re.findall(r'\[\[(\d+)\]\]', notes))
        if n != list(range(1, 9)): errs.append(f'пропуски конспекта {n}')
        if sorted(gaps) != list(range(1, 9)): errs.append(f'GAPS {sorted(gaps)}')
        for k, forms in gaps.items():
            for w in forms:
                if ' ' + norm(w) + ' ' not in ' ' + text + ' ': errs.append(f'ответ «{w}» (пропуск {k}) не звучит в записи')
        pos = [m['a'] for m in mc]
        if max(pos.count(i) for i in range(4)) > 3 or len(set(pos)) < 3: errs.append(f'ответы MC неравномерны {pos}')
        for k in range(1, 9):
            for w in gaps.get(k, [])[:1]:
                if re.search(r'\b' + re.escape(w) + r'\b', notes): errs.append(f'ответ «{w}» стоит в конспекте открытым текстом')
        if errs:
            bad += 1; print('✗', it['id'], '; '.join(errs)); continue
        it['lines'] = lines
        it['words'] = words
        it['minutes'] = round(words / WPM)
        it['ver'] = 2
        it['tasks'] = [
            {'t': 'mcg', 'q': 'Aufgabe 1: Wählen Sie die richtige Antwort (A–D).', 'items': mc},
            {'t': 'sum', 'q': 'Aufgabe 2: Hören Sie den Beitrag noch einmal und ergänzen Sie die Notizen. Schreiben Sie das Wort oder die Wörter, die Sie hören (höchstens zwei).',
             'text': notes, 'a': [gaps[k] for k in range(1, 9)]}]
        if intro: it['intro'] = intro
        ok += 1; print('✓', it['id'], words, 'слов,', it['minutes'], 'мин')
    old = [it['id'] for it in data['items'] if it.get('ver') != 2]
    json.dump(data, open(JSONP, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(f'готово: {ok}, с ошибками: {bad}, ещё не переделаны: {len(old)}')
    if old: print('  ', ', '.join(old))
main()
