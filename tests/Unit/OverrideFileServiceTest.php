<?php
namespace AdmidioPlugin\LanguageOverrides\Tests\Unit;

require_once dirname(__DIR__, 2) . '/src/Service/OverrideException.php';
require_once dirname(__DIR__, 2) . '/src/Service/OverrideFileService.php';

use Admidio\Tests\Support\AdmidioTestCase;
use AdmidioPlugin\LanguageOverrides\Service\OverrideException;
use AdmidioPlugin\LanguageOverrides\Service\OverrideFileService;

final class OverrideFileServiceTest extends AdmidioTestCase
{
    private string $base;
    private string $directory;
    private string $backups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/lo-test-' . bin2hex(random_bytes(4));
        $this->directory = $this->base . '/languages';
        $this->backups = $this->base . '/backups';
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->directory . '/{,.}*', GLOB_BRACE) ?: array(), glob($this->backups . '/*') ?: array()) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->directory);
        @rmdir($this->backups);
        @rmdir($this->base);
        parent::tearDown();
    }

    private function service(int $backupCount = 20): OverrideFileService
    {
        return new OverrideFileService($this->directory, $this->backups, $backupCount);
    }

    private static function entry(string $text, ?string $original = null): array
    {
        return array('text' => $text, 'original' => $original);
    }

    public function testAFileIsCreatedWithItsFolderAndReadBack(): void
    {
        $this->service()->set('de', array('SYS_CONTACTS' => self::entry('Mitgliederliste', 'Kontakte')));

        $entries = $this->service()->read('de');
        $this->assertSame('Mitgliederliste', $entries['SYS_CONTACTS']['text']);
        $this->assertSame('Kontakte', $entries['SYS_CONTACTS']['original']);
        $this->assertSame(array('de'), $this->service()->getLanguages());
    }

    public function testTheAttributeThatAdmidioIgnoresDoesNotHideTheEntryFromItsLookup(): void
    {
        $this->service()->set('de', array('SYS_CONTACTS' => self::entry('Mitgliederliste', 'Kontakte')));

        $xml = simplexml_load_file($this->directory . '/de.xml');
        $nodes = $xml->xpath('/resources/string[@name="SYS_CONTACTS"]');
        $this->assertSame('Mitgliederliste', (string) $nodes[0]);
    }

    public function testSpecialCharactersSurviveTheRoundTrip(): void
    {
        $text = 'Tom & Jerry <b>"quoted"</b> it\'s #VAR1# \\n second line';
        $this->service()->set('en', array('SYS_X' => self::entry($text, 'A & B')));

        $entry = $this->service()->read('en')['SYS_X'];
        $this->assertSame($text, $entry['text']);
        $this->assertSame('A & B', $entry['original']);
    }

    public function testEntriesStaySortedByName(): void
    {
        $service = $this->service();
        $service->set('en', array('SYS_B' => self::entry('b')));
        $service->set('en', array('SYS_C' => self::entry('c'), 'SYS_A' => self::entry('a')));

        $this->assertSame(array('SYS_A', 'SYS_B', 'SYS_C'), array_keys($service->read('en')));
        $names = array();
        preg_match_all('/name="([A-Z_]+)"/', (string) file_get_contents($this->directory . '/en.xml'), $names);
        $this->assertSame(array('SYS_A', 'SYS_B', 'SYS_C'), $names[1]);
    }

    public function testChangingAnEntryKeepsItsPlaceAndTheCommentsOfTheFile(): void
    {
        mkdir($this->directory, 0775, true);
        file_put_contents($this->directory . '/de.xml', "<?xml version='1.0' encoding='UTF-8'?>\n<resources>\n  <!-- our wording -->\n  <string name=\"SYS_A\">a</string>\n  <string name=\"SYS_C\">c</string>\n</resources>\n");

        $this->service()->set('de', array('SYS_C' => self::entry('new'), 'SYS_B' => self::entry('b')));

        $content = (string) file_get_contents($this->directory . '/de.xml');
        $this->assertStringContainsString('<!-- our wording -->', $content);
        $this->assertSame(array('SYS_A' => 'a', 'SYS_B' => 'b', 'SYS_C' => 'new'), array_map(
            static fn(array $entry): string => $entry['text'],
            $this->service()->read('de')
        ));
    }

    public function testTheLastEntryRemovedDeletesTheFile(): void
    {
        $service = $this->service();
        $service->set('de', array('SYS_A' => self::entry('a'), 'SYS_B' => self::entry('b')));

        $service->remove('de', array('SYS_A'));
        $this->assertSame(array('SYS_B'), array_keys($service->read('de')));

        $service->remove('de', array('SYS_B'));
        $this->assertFileDoesNotExist($this->directory . '/de.xml');
        $this->assertSame(array(), $service->getLanguages());
    }

    public function testAChangedFileIsNotOverwrittenBehindTheBackOfAForm(): void
    {
        $service = $this->service();
        $service->set('de', array('SYS_A' => self::entry('a')));
        $hash = $service->getHash('de');

        $service->set('de', array('SYS_A' => self::entry('somebody else')));

        $this->expectException(OverrideException::class);
        $service->set('de', array('SYS_A' => self::entry('mine')), $hash);
    }

    public function testTheReplacedFileIsCopiedAndOnlyTheLastCopiesAreKept(): void
    {
        $service = $this->service(2);
        $service->set('de', array('SYS_A' => self::entry('1')));   // creates, nothing to copy
        $this->assertCount(0, glob($this->backups . '/*.xml') ?: array());

        foreach (array('2', '3', '4') as $text) {
            $service->set('de', array('SYS_A' => self::entry($text)));
            usleep(2000);
        }

        $copies = glob($this->backups . '/de-*.xml') ?: array();
        $this->assertCount(2, $copies);
        rsort($copies);
        $this->assertStringContainsString('>3<', (string) file_get_contents($copies[0]));
    }

    public function testAFileThatIsNotALanguageFileIsRefusedAndLeftAlone(): void
    {
        mkdir($this->directory, 0775, true);
        file_put_contents($this->directory . '/de.xml', '<resources><string name="A">oops</resources>');
        $service = $this->service();

        try {
            $service->set('de', array('SYS_A' => self::entry('a')));
            $this->fail('expected a refusal');
        } catch (OverrideException $exception) {
            $this->assertSame('PLG_LANGUAGE_OVERRIDES_FILE_UNREADABLE', $exception->getTextId());
        }
        $this->assertStringContainsString('oops', (string) file_get_contents($this->directory . '/de.xml'));
    }

    public function testALanguageCodeCannotLeaveTheFolder(): void
    {
        $this->expectException(OverrideException::class);
        $this->service()->set('../evil', array('SYS_A' => self::entry('a')));
    }

    public function testRegionalCodesAreAccepted(): void
    {
        $this->assertTrue(OverrideFileService::isValidLanguageCode('de-DE'));
        $this->assertTrue(OverrideFileService::isValidLanguageCode('pt-BR'));
        $this->assertFalse(OverrideFileService::isValidLanguageCode('de/../x'));
        $this->assertFalse(OverrideFileService::isValidLanguageCode(''));
    }
}
