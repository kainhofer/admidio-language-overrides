<?php
namespace AdmidioPlugin\LanguageOverrides\Service;

use Admidio\Infrastructure\Backups;

/**
 * The only class that touches the override files in adm_my_files/languages.
 *
 * Admidio reads <code>.xml from that folder before its own language file, so an entry here wins over
 * the text of the core. An entry may carry the attribute **original**, the text of the core at the
 * time it was written: the language class looks at the name only, so the attribute costs nothing and
 * lets the plugin tell later that the core text has changed since.
 *
 * A file is changed under an exclusive lock, by editing the document that is there and not by
 * writing a new one, so that comments an administrator put into it survive. The result replaces the
 * file with a rename, and the file it replaces is copied to the backup folder first.
 */
final class OverrideFileService
{
    public const BACKUP_SUBFOLDER = 'language-overrides';

    /**
     * @param string $directory The folder of the override files.
     * @param string|null $backupDirectory Where the replaced files are copied to; null for no backups.
     * @param int $backupCount How many copies of a language are kept.
     */
    public function __construct(
        private readonly string $directory,
        private readonly ?string $backupDirectory = null,
        private readonly int $backupCount = 20
    ) {
    }

    /**
     * The service for this installation: the folder Admidio reads, and the subfolder of the backup
     * directory of the installation.
     * @throws \Admidio\Infrastructure\Exception
     */
    public static function forInstallation(int $backupCount): self
    {
        $backupDirectory = $backupCount > 0 ? Backups::getDirectory() . '/' . self::BACKUP_SUBFOLDER : null;

        return new self(ADMIDIO_PATH . FOLDER_DATA . FOLDER_LANGUAGES, $backupDirectory, $backupCount);
    }

    public static function isValidLanguageCode(string $language): bool
    {
        return preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $language) === 1;
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function getFile(string $language): string
    {
        $this->assertLanguage($language);
        return $this->directory . '/' . $language . '.xml';
    }

    /**
     * The languages that have an override file.
     * @return array<int,string>
     */
    public function getLanguages(): array
    {
        $languages = array();
        foreach (glob($this->directory . '/*.xml') ?: array() as $file) {
            $language = basename($file, '.xml');
            if (self::isValidLanguageCode($language)) {
                $languages[] = $language;
            }
        }
        sort($languages);
        return $languages;
    }

    /**
     * Identifies the state of a file, to notice that somebody else changed it while a form was open.
     * It is the empty string for a language without a file.
     */
    public function getHash(string $language): string
    {
        $file = $this->getFile($language);
        return is_file($file) ? (string) md5_file($file) : '';
    }

    /**
     * The entries of a language.
     * @return array<string,array{text: string, original: ?string}> by text id
     * @throws OverrideException The file cannot be parsed.
     */
    public function read(string $language): array
    {
        $file = $this->getFile($language);
        if (!is_file($file)) {
            return array();
        }

        $entries = array();
        foreach ($this->load($file)->getElementsByTagName('string') as $element) {
            $name = $element->getAttribute('name');
            if ($name === '') {
                continue;
            }
            $entries[$name] = array(
                'text' => $element->textContent,
                'original' => $element->hasAttribute('original') ? $element->getAttribute('original') : null
            );
        }
        ksort($entries);
        return $entries;
    }

    /**
     * @return array<string,array<string,array{text: string, original: ?string}>> by language
     * @throws OverrideException
     */
    public function readAll(): array
    {
        $all = array();
        foreach ($this->getLanguages() as $language) {
            $all[$language] = $this->read($language);
        }
        return $all;
    }

    /**
     * Write entries into the file of a language.
     * @param array<string,array{text: string, original: ?string}> $entries by text id
     * @param string|null $expectedHash The hash the file had when the caller read it; if the file has
     *                                  changed since, nothing is written.
     * @throws OverrideException
     */
    public function set(string $language, array $entries, ?string $expectedHash = null): void
    {
        $this->modify($language, $expectedHash, function (\DOMDocument $document) use ($entries): void {
            foreach ($entries as $name => $entry) {
                $this->setEntry($document, (string) $name, $entry['text'], $entry['original'] ?? null);
            }
        });
    }

    /**
     * Remove entries; a file that has no entry left is deleted.
     * @param array<int,string> $names
     * @throws OverrideException
     */
    public function remove(string $language, array $names, ?string $expectedHash = null): void
    {
        $this->modify($language, $expectedHash, function (\DOMDocument $document) use ($names): void {
            foreach ($names as $name) {
                $element = $this->findEntry($document, $name);
                if ($element !== null) {
                    $previous = $element->previousSibling;
                    $element->parentNode->removeChild($element);
                    if ($previous instanceof \DOMText && trim($previous->data) === '') {
                        $previous->parentNode->removeChild($previous);
                    }
                }
            }
        });
    }

    /**
     * @param callable(\DOMDocument):void $change
     * @throws OverrideException
     */
    private function modify(string $language, ?string $expectedHash, callable $change): void
    {
        $file = $this->getFile($language);

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new OverrideException('PLG_LANGUAGE_OVERRIDES_NOT_WRITABLE', array($this->directory));
        }
        if (!is_writable($this->directory)) {
            throw new OverrideException('PLG_LANGUAGE_OVERRIDES_NOT_WRITABLE', array($this->directory));
        }

        $lock = fopen($this->directory . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new OverrideException('PLG_LANGUAGE_OVERRIDES_NOT_WRITABLE', array($this->directory));
        }

        try {
            $exists = is_file($file);
            if ($expectedHash !== null && $expectedHash !== ($exists ? (string) md5_file($file) : '')) {
                throw new OverrideException('PLG_LANGUAGE_OVERRIDES_CONFLICT', array($language));
            }

            if ($exists) {
                $document = $this->load($file);
            } else {
                $document = new \DOMDocument('1.0', 'UTF-8');
                $document->preserveWhiteSpace = true;
                $document->appendChild($document->createElement('resources'));
            }

            $change($document);

            $hasEntries = $document->getElementsByTagName('string')->length > 0;
            if ($exists) {
                $this->backup($language, $file);
            }

            if (!$hasEntries) {
                if ($exists) {
                    unlink($file);
                }
                return;
            }

            $temporary = $file . '.tmp';
            if (file_put_contents($temporary, $document->saveXML()) === false || !rename($temporary, $file)) {
                @unlink($temporary);
                throw new OverrideException('PLG_LANGUAGE_OVERRIDES_NOT_WRITABLE', array($file));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @throws OverrideException
     */
    private function load(string $file): \DOMDocument
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->load($file);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $document->documentElement === null || $document->documentElement->nodeName !== 'resources') {
            throw new OverrideException('PLG_LANGUAGE_OVERRIDES_FILE_UNREADABLE', array(basename($file)));
        }
        return $document;
    }

    private function findEntry(\DOMDocument $document, string $name): ?\DOMElement
    {
        foreach ($document->getElementsByTagName('string') as $element) {
            if ($element->getAttribute('name') === $name) {
                return $element;
            }
        }
        return null;
    }

    /**
     * Change an entry or insert it at its place in the order of the names, with the indentation of
     * the file.
     */
    private function setEntry(\DOMDocument $document, string $name, string $text, ?string $original): void
    {
        $element = $this->findEntry($document, $name);

        if ($element === null) {
            $element = $document->createElement('string');
            $element->setAttribute('name', $name);
            $root = $document->documentElement;

            $following = null;
            foreach ($document->getElementsByTagName('string') as $candidate) {
                if (strcmp($candidate->getAttribute('name'), $name) > 0) {
                    $following = $candidate;
                    break;
                }
            }

            if ($following !== null) {
                $root->insertBefore($element, $following);
                $root->insertBefore($document->createTextNode("\n  "), $following);
            } else {
                $last = $root->lastChild;
                if (!($last instanceof \DOMText) || trim($last->data) !== '') {
                    $last = $root->appendChild($document->createTextNode("\n"));
                }
                $root->insertBefore($document->createTextNode("\n  "), $last);
                $root->insertBefore($element, $last);
            }
        }

        while ($element->firstChild !== null) {
            $element->removeChild($element->firstChild);
        }
        $element->appendChild($document->createTextNode($text));

        if ($original !== null) {
            $element->setAttribute('original', $original);
        }
    }

    /**
     * @throws OverrideException
     */
    private function backup(string $language, string $file): void
    {
        if ($this->backupDirectory === null || $this->backupCount < 1) {
            return;
        }
        if (!is_dir($this->backupDirectory) && !@mkdir($this->backupDirectory, 0775, true) && !is_dir($this->backupDirectory)) {
            throw new OverrideException('PLG_LANGUAGE_OVERRIDES_BACKUP_FAILED', array($this->backupDirectory));
        }

        $target = sprintf(
            '%s/%s-%s-%03d.xml',
            $this->backupDirectory,
            $language,
            date('Ymd-His'),
            (int) (fmod(microtime(true), 1) * 1000)
        );
        if (!@copy($file, $target)) {
            throw new OverrideException('PLG_LANGUAGE_OVERRIDES_BACKUP_FAILED', array($this->backupDirectory));
        }

        // the name starts with the language and a fixed-width time, so the names sort by age
        $copies = glob($this->backupDirectory . '/' . $language . '-????????-??????-???.xml') ?: array();
        rsort($copies);
        foreach (array_slice($copies, $this->backupCount) as $old) {
            @unlink($old);
        }
    }

    private function assertLanguage(string $language): void
    {
        if (!self::isValidLanguageCode($language)) {
            throw new OverrideException('SYS_INVALID_PAGE_VIEW');
        }
    }
}
