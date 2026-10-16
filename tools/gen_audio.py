#!/usr/bin/env python3
"""Заранее озвучить все аудио Hören (data/de/hoeren.json и data/frei/hoeren.json) голосами OpenAI.

Каждая реплика озвучивается один раз и кэшируется в db/audio/, поэтому повторный запуск бесплатный:
он догенерирует только новые или изменённые реплики. Сайт берёт эти же файлы — в Hören звук появится сразу.

Нужен ключ OpenAI: db/openai.key (Настройки → Ключи) или OPENAI_API_KEY=… .
Запуск:  python3 tools/gen_audio.py            — всё
         python3 tools/gen_audio.py de          — только подготовка к EPD
         python3 tools/gen_audio.py frei umfrage — одно аудио по id
         python3 tools/gen_audio.py --dry       — только посчитать, сколько это будет стоить
"""
import os, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, ROOT)
import lernen_api as L  # noqa: E402


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    dry = "--dry" in sys.argv
    areas = [args[0]] if args and args[0] in ("de", "frei") else ["de", "frei"]
    only = args[1] if len(args) > 1 else None
    if not dry and not L.openai_key():
        sys.exit("Нет ключа OpenAI: положи его в db/openai.key или задай OPENAI_API_KEY")
    os.makedirs(L.AUDIO_DIR, exist_ok=True)
    total_new = 0
    for area in areas:
        data = L.load_data(area, "hoeren") or {}
        for it in data.get("items", []):
            if only and it["id"] != only:
                continue
            lines = it.get("lines") or []
            missing = [i for i in range(len(lines)) if not os.path.isfile(os.path.join(L.AUDIO_DIR, L.tts_name(it, i)))]
            chars = sum(len(lines[i][1]) for i in missing)
            total_new += chars
            status = "готово" if not missing else "нужно озвучить %d реплик (%d знаков)" % (len(missing), chars)
            print("%-5s %-20s %s" % (area, it["id"], status))
            if dry or not missing:
                continue
            name, _ = L.script_audio(it)
            print("      → /audio/%s" % name)
    print("\nНовых знаков: %d, примерно $%.2f" % (total_new, total_new * L.TTS_MICRO_PER_CHAR / 1e6))


if __name__ == "__main__":
    main()
