<?php
namespace AdmidioPlugin\LanguageOverrides\Service;

/**
 * What texts exist: the language files of the core and of the plugins, without the overrides, so
 * that the original of a text is always the one an override replaces.
 *
 * The files are read when they are asked for and kept for the rest of the request. A search reads
 * the files of the languages it was asked to search and nothing else.
 */
final class StringCatalogService
{
    /** @var array<string,array<string,string>> */
    private array $texts = array();

    /**
     * @param array<int,string> $directories The language folders, in the order Admidio searches them:
     *                                       the core first, then the plugins. The first folder that
     *                                       has a text wins, as in the language class.
     */
    public function __construct(private readonly array $directories)
    {
    }

    public static function forInstallation(): self
    {
        $directories = array(ADMIDIO_PATH . FOLDER_LANGUAGES);
        $pluginFolders = glob(ADMIDIO_PATH . FOLDER_PLUGINS . '/*/languages', GLOB_ONLYDIR) ?: array();
        sort($pluginFolders);

        return new self(array_merge($directories, $pluginFolders));
    }

    /**
     * All texts of a language, as written in the XML file.
     * @return array<string,string> by text id
     */
    public function getTexts(string $language): array
    {
        if (!OverrideFileService::isValidLanguageCode($language)) {
            return array();
        }
        if (isset($this->texts[$language])) {
            return $this->texts[$language];
        }

        $texts = array();
        foreach ($this->directories as $directory) {
            $file = $directory . '/' . $language . '.xml';
            if (!is_file($file)) {
                continue;
            }
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_file($file);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($xml === false) {
                continue;
            }
            foreach ($xml->string as $string) {
                $name = (string) $string['name'];
                if ($name !== '' && !isset($texts[$name])) {
                    $texts[$name] = (string) $string;
                }
            }
        }

        return $this->texts[$language] = $texts;
    }

    /**
     * The text a visitor sees without an override: the one of the language, or if that has none, the
     * one of the reference language.
     */
    public function getOriginal(string $textId, string $language, string $referenceLanguage = 'en'): ?string
    {
        return $this->getTexts($language)[$textId] ?? $this->getTexts($referenceLanguage)[$textId] ?? null;
    }

    /**
     * Find texts by their id or by what they say in any of the languages. The best matches come
     * first: a text that is the query, then one that starts with it, then one that has it as a word,
     * then any text that contains it, and last a text id that contains it.
     * @param array<int,string> $languages
     * @param bool $regex Whether the query is a regular expression (case-insensitive, matched against
     *                    the texts as written in the file and against the text ids) and not a phrase.
     * @return array{results: array<int,array{id: string, texts: array<string,string>}>, truncated: bool, total: int}
     *         The hits by text id, each with its text in every searched language that has one. Of
     *         **total** hits the first **limit** are returned.
     * @throws OverrideException The query is not a valid regular expression.
     */
    public function search(string $query, array $languages, bool $includeInstaller, int $limit = 100, bool $regex = false): array
    {
        $needle = $regex ? $query : self::normalize($query);
        $pattern = $regex && $needle !== '' ? self::delimit($needle) : null;
        if ($needle === '') {
            return array('results' => array(), 'truncated' => false, 'total' => 0);
        }

        $byLanguage = array();
        $ids = array();
        foreach ($languages as $language) {
            $byLanguage[$language] = $this->getTexts($language);
            foreach (array_keys($byLanguage[$language]) as $id) {
                $ids[$id] = true;
            }
        }
        ksort($ids);

        $word = $pattern !== null ? '' : '/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u';
        $hits = array();
        foreach (array_keys($ids) as $id) {
            if (!$includeInstaller && str_starts_with($id, 'INS_')) {
                continue;
            }

            $rank = self::matchesId((string) $id, $needle, $pattern) ? 4 : null;
            $length = PHP_INT_MAX;
            $texts = array();
            foreach ($byLanguage as $language => $languageTexts) {
                if (!isset($languageTexts[$id])) {
                    continue;
                }
                $texts[$language] = $languageTexts[$id];

                $normalized = $pattern === null ? self::normalize($languageTexts[$id]) : $languageTexts[$id];
                $textRank = $pattern === null
                    ? match (true) {
                        $normalized === $needle => 0,
                        str_starts_with($normalized, $needle) => 1,
                        preg_match($word, $normalized) === 1 => 2,
                        str_contains($normalized, $needle) => 3,
                        default => null
                    }
                    : self::regexRank($pattern, $normalized);
                if ($textRank !== null && ($rank === null || $textRank < $rank)) {
                    $rank = $textRank;
                }
                if ($textRank !== null) {
                    $length = min($length, mb_strlen($normalized));
                }
            }

            if ($rank !== null) {
                $hits[] = array('rank' => $rank, 'length' => $length, 'id' => (string) $id, 'texts' => $texts);
            }
        }

        usort($hits, static fn(array $a, array $b): int
            => array($a['rank'], $a['length'], $a['id']) <=> array($b['rank'], $b['length'], $b['id']));

        $results = array();
        foreach (array_slice($hits, 0, $limit) as $hit) {
            $results[] = array('id' => $hit['id'], 'texts' => $hit['texts']);
        }

        return array('results' => $results, 'truncated' => count($hits) > $limit, 'total' => count($hits));
    }

    /**
     * The regular expression a user typed as a pattern PHP can run: delimited, case-insensitive and
     * for UTF-8 text.
     * @throws OverrideException
     */
    public static function delimit(string $expression): string
    {
        $pattern = '~' . str_replace('~', '\\~', $expression) . '~iu';
        if (@preg_match($pattern, '') === false) {
            throw new OverrideException('PLG_LANGUAGE_OVERRIDES_INVALID_REGEX');
        }
        return $pattern;
    }

    private static function matchesId(string $id, string $needle, ?string $pattern): bool
    {
        return $pattern === null ? str_contains(self::normalize($id), $needle) : preg_match($pattern, $id) === 1;
    }

    /**
     * 0 if the expression matches the whole text, 1 if it matches from the beginning, 3 if it
     * matches somewhere, null if it does not match.
     */
    private static function regexRank(string $pattern, string $text): ?int
    {
        if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        if ($match[0][1] === 0) {
            return $match[0][0] === $text ? 0 : 1;
        }
        return 3;
    }

    /**
     * What a text is compared as: without markup, lower case and without accents.
     */
    public static function normalize(string $text): string
    {
        $text = preg_replace('/<[^>]*>|\[\/?[a-z]+\]|\\\\n/i', ' ', $text) ?? $text;
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 'UTF-8');

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if ($decomposed !== false) {
                $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
            }
        }

        return $text;
    }
}
