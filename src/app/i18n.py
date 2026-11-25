"""Polyglot: gettext-style babel.cfg + loader."""
import json
import os
from functools import lru_cache
from pathlib import Path

_DIR = Path(__file__).parent / "translations"
_SUPPORTED = ("id", "en", "zh")
_DEFAULT_LANG = os.environ.get("CMT_LANG", "id")


@lru_cache(maxsize=None)
def get(lang: str) -> dict:
    if lang not in _SUPPORTED:
        lang = _DEFAULT_LANG
    f = _DIR / f"{lang}.json"
    try:
        return json.loads(f.read_text(encoding="utf-8"))
    except FileNotFoundError:
        return {}


def t(lang: str, key: str) -> str:
    return get(lang).get(key, key)


def lang_from_request(request) -> str:
    """Pick lang from cookie, else Accept-Language, else default."""
    from fastapi import Request
    lang = request.cookies.get("cmt_lang")
    if lang not in _SUPPORTED:
        lang = _DEFAULT_LANG
    return lang