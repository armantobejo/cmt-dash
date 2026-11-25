<?php

namespace App\Support;

/**
 * Port of Python i18n.py — cookie/Accept-Language language selection with
 * JSON translation files (lang/cmt/{id,en,zh}.json, copied verbatim from
 * src/app/translations). Lookup falls back to the KEY itself when missing
 * (the templates rely on that for 'Opr Cost', 'Invoice', 'DNCN').
 */
class CmtI18n
{
    public const SUPPORTED = ['id', 'en', 'zh'];

    /** Cache of loaded dictionaries (per process; scoped tests rebind an instance). */
    protected array $loaded = [];

    public function __construct(
        protected string $defaultLang,
        protected string $langDir,
    ) {
    }

    /** Python i18n.t(lang, key): dict lookup with identity fallback. */
    public function t(string $lang, string $key): string
    {
        return $this->get($lang)[$key] ?? $key;
    }

    /** Python i18n.get(lang): load lang/cmt/{lang}.json; unsupported -> default. */
    public function get(string $lang): array
    {
        if (! in_array($lang, self::SUPPORTED, true)) {
            $lang = $this->defaultLang;
        }
        if (! isset($this->loaded[$lang])) {
            $path = $this->langDir.DIRECTORY_SEPARATOR."{$lang}.json";
            $this->loaded[$lang] = is_file($path)
                ? (json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) ?: [])
                : [];
        }

        return $this->loaded[$lang];
    }

    /** Python i18n.lang_from_request: cmt_lang cookie, else default. */
    public function langFromCookie(?string $cookieValue): string
    {
        if ($cookieValue !== null && in_array($cookieValue, self::SUPPORTED, true)) {
            return $cookieValue;
        }

        return $this->defaultLang;
    }
}
