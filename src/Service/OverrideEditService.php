<?php
namespace AdmidioPlugin\LanguageOverrides\Service;

/**
 * Saves the texts of one text id in one or more languages.
 *
 * A language whose text equals the original has no override, and one whose text does not change
 * is left alone; so a form can submit every language it showed and only what was edited is written.
 * All texts are checked before the first file is touched.
 */
final class OverrideEditService
{
    public function __construct(
        private readonly OverrideFileService $files,
        private readonly StringCatalogService $catalog
    ) {
    }

    /**
     * @param array<string,string> $texts The texts as edited, by language.
     * @param array<string,string> $hashes The hash of the file of each language when the form was built.
     * @return array<int,string> The languages whose file was changed.
     * @throws OverrideException
     */
    public function apply(string $textId, array $texts, array $hashes = array()): array
    {
        $changes = array();

        foreach ($texts as $language => $edited) {
            $original = $this->catalog->getOriginal($textId, $language);
            if ($original === null) {
                throw new OverrideException('PLG_LANGUAGE_OVERRIDES_UNKNOWN_TEXT', array($textId));
            }

            $current = $this->files->read($language)[$textId]['text'] ?? null;
            $stored = OverrideText::toStorage($edited);

            if ($stored === ($current ?? $original)) {
                continue;
            }
            if ($stored === '') {
                throw new OverrideException('PLG_LANGUAGE_OVERRIDES_EMPTY_TEXT', array($language));
            }
            if (mb_strlen($stored) > OverrideText::MAX_LENGTH) {
                throw new OverrideException('PLG_LANGUAGE_OVERRIDES_TEXT_TOO_LONG', array((string) OverrideText::MAX_LENGTH));
            }

            if ($stored === $original) {
                $changes[$language] = null;
                continue;
            }

            $extra = PlaceholderValidator::compare($original, $stored)['extra'];
            if ($extra !== array()) {
                throw new OverrideException('PLG_LANGUAGE_OVERRIDES_EXTRA_PLACEHOLDER', array(
                    $language,
                    implode(', ', array_map(static fn(int $number): string => '#VAR' . $number . '#', $extra))
                ));
            }

            $changes[$language] = array('text' => $stored, 'original' => $original);
        }

        foreach ($changes as $language => $entry) {
            if ($entry === null) {
                $this->files->remove($language, array($textId), $hashes[$language] ?? null);
            } else {
                $this->files->set($language, array($textId => $entry), $hashes[$language] ?? null);
            }
        }

        return array_keys($changes);
    }

    /**
     * Remove the override of a text.
     * @throws OverrideException
     */
    public function reset(string $textId, string $language): void
    {
        $this->files->remove($language, array($textId));
    }
}
