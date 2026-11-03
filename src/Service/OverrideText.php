<?php
namespace AdmidioPlugin\LanguageOverrides\Service;

/**
 * The two forms of a text. In the XML a line break is the two characters \n, which Admidio turns
 * into <br /> when it reads the text; in a textarea it is a real line break.
 */
final class OverrideText
{
    public const MAX_LENGTH = 4000;

    public static function toEditor(string $stored): string
    {
        return str_replace('\\n', "\n", $stored);
    }

    public static function toStorage(string $edited): string
    {
        return str_replace(array("\r\n", "\r", "\n"), '\\n', trim($edited));
    }
}
