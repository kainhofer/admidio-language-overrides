<?php
namespace AdmidioPlugin\LanguageOverrides\Service;

/**
 * Compares the placeholders of an override with those of the text it replaces.
 *
 * The caller of a text supplies the values by position, so #VAR3# in a text whose original has no
 * third value would appear literally on the page. A value that an override leaves out is legitimate,
 * a sentence may be worded without it, and is only reported.
 */
final class PlaceholderValidator
{
    /**
     * The numbers of the values a text uses, as #VAR1#, #VAR1_BOLD# or #VAR1_ITALIC#.
     * @return array<int,int> sorted, without duplicates
     */
    public static function numbers(string $text): array
    {
        preg_match_all('/#VAR(\d+)(?:_BOLD|_ITALIC)?#/', $text, $matches);
        $numbers = array_values(array_unique(array_map('intval', $matches[1])));
        sort($numbers);
        return $numbers;
    }

    /**
     * @return array{missing: array<int,int>, extra: array<int,int>}
     */
    public static function compare(string $original, string $override): array
    {
        $originalNumbers = self::numbers($original);
        $overrideNumbers = self::numbers($override);

        return array(
            'missing' => array_values(array_diff($originalNumbers, $overrideNumbers)),
            'extra' => array_values(array_diff($overrideNumbers, $originalNumbers))
        );
    }
}
