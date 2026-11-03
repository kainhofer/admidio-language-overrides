<?php
namespace AdmidioPlugin\LanguageOverrides\Tests\Unit;

require_once dirname(__DIR__, 2) . '/src/Service/OverrideException.php';
require_once dirname(__DIR__, 2) . '/src/Service/OverrideFileService.php';
require_once dirname(__DIR__, 2) . '/src/Service/OverrideText.php';
require_once dirname(__DIR__, 2) . '/src/Service/PlaceholderValidator.php';
require_once dirname(__DIR__, 2) . '/src/Service/StringCatalogService.php';
require_once dirname(__DIR__, 2) . '/src/Service/OverrideEditService.php';

use Admidio\Tests\Support\AdmidioTestCase;
use AdmidioPlugin\LanguageOverrides\Service\OverrideEditService;
use AdmidioPlugin\LanguageOverrides\Service\OverrideException;
use AdmidioPlugin\LanguageOverrides\Service\OverrideFileService;
use AdmidioPlugin\LanguageOverrides\Service\OverrideText;
use AdmidioPlugin\LanguageOverrides\Service\PlaceholderValidator;
use AdmidioPlugin\LanguageOverrides\Service\StringCatalogService;

final class OverrideEditServiceTest extends AdmidioTestCase
{
    private string $base;
    private OverrideFileService $files;
    private OverrideEditService $editor;
    private StringCatalogService $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/lo-edit-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/core', 0775, true);
        mkdir($this->base . '/plugin', 0775, true);
        $this->write('core/en.xml', array('SYS_CONTACTS' => 'Contacts', 'SYS_GREETING' => 'Hello #VAR1_BOLD#, you have #VAR2# messages', 'INS_SETUP' => 'Set up'));
        $this->write('core/de.xml', array('SYS_CONTACTS' => 'Kontakte', 'SYS_GREETING' => 'Hallo #VAR1_BOLD#, du hast #VAR2# Nachrichten'));
        $this->write('core/de-DE.xml', array('SYS_CONTACTS' => 'Kontakte'));
        $this->write('plugin/en.xml', array('PLG_X_NAME' => 'Contact book'));

        $this->files = new OverrideFileService($this->base . '/languages', null);
        $this->catalog = new StringCatalogService(array($this->base . '/core', $this->base . '/plugin'));
        $this->editor = new OverrideEditService($this->files, $this->catalog);
    }

    protected function tearDown(): void
    {
        foreach (array('core', 'plugin', 'languages') as $folder) {
            foreach (glob($this->base . '/' . $folder . '/{,.}*', GLOB_BRACE) ?: array() as $file) {
                is_file($file) && unlink($file);
            }
            @rmdir($this->base . '/' . $folder);
        }
        @rmdir($this->base);
        parent::tearDown();
    }

    /** @param array<string,string> $texts */
    private function write(string $file, array $texts): void
    {
        $xml = "<?xml version='1.0' encoding='UTF-8'?>\n<resources>\n";
        foreach ($texts as $id => $text) {
            $xml .= '  <string name="' . $id . '">' . htmlspecialchars($text, ENT_XML1) . "</string>\n";
        }
        file_put_contents($this->base . '/' . $file, $xml . "</resources>\n");
    }

    public function testAnEditedTextBecomesAnOverrideWithItsOriginal(): void
    {
        $changed = $this->editor->apply('SYS_CONTACTS', array('de' => 'Mitgliederliste'));

        $this->assertSame(array('de'), $changed);
        $entry = $this->files->read('de')['SYS_CONTACTS'];
        $this->assertSame('Mitgliederliste', $entry['text']);
        $this->assertSame('Kontakte', $entry['original']);
    }

    public function testALanguageThatWasNotEditedIsLeftAlone(): void
    {
        $changed = $this->editor->apply('SYS_CONTACTS', array('de' => 'Mitgliederliste', 'en' => 'Contacts'));

        $this->assertSame(array('de'), $changed);
        $this->assertSame(array(), $this->files->read('en'));
    }

    public function testTheOriginalTextRemovesTheOverride(): void
    {
        $this->editor->apply('SYS_CONTACTS', array('de' => 'Mitgliederliste'));
        $this->editor->apply('SYS_CONTACTS', array('de' => 'Kontakte'));

        $this->assertSame(array(), $this->files->read('de'));
    }

    public function testATextThatDoesNotExistInTheLanguageIsOverriddenAgainstTheReferenceLanguage(): void
    {
        // de-DE has no SYS_GREETING, so a visitor sees the English one
        $this->editor->apply('SYS_GREETING', array('de-DE' => 'Hallo #VAR1_BOLD#, Sie haben #VAR2# Nachrichten'));

        $this->assertSame('Hello #VAR1_BOLD#, you have #VAR2# messages', $this->files->read('de-DE')['SYS_GREETING']['original']);
    }

    public function testAnEditedTextCannotUseAValueTheOriginalDoesNotHave(): void
    {
        $this->expectException(OverrideException::class);
        $this->editor->apply('SYS_CONTACTS', array('de' => 'Liste von #VAR1#'));
    }

    public function testALeftOutValueIsAllowed(): void
    {
        $this->editor->apply('SYS_GREETING', array('de' => 'Hallo #VAR1_BOLD#'));
        $this->assertSame('Hallo #VAR1_BOLD#', $this->files->read('de')['SYS_GREETING']['text']);
    }

    public function testNothingIsWrittenIfOneOfTheLanguagesIsRefused(): void
    {
        try {
            $this->editor->apply('SYS_CONTACTS', array('de' => 'Mitgliederliste', 'en' => ''));
            $this->fail('expected a refusal');
        } catch (OverrideException $exception) {
            $this->assertSame('PLG_LANGUAGE_OVERRIDES_EMPTY_TEXT', $exception->getTextId());
        }
        $this->assertSame(array(), $this->files->getLanguages());
    }

    public function testAnUnknownTextIdIsRefused(): void
    {
        $this->expectException(OverrideException::class);
        $this->editor->apply('SYS_DOES_NOT_EXIST', array('de' => 'x'));
    }

    public function testLineBreaksAreStoredAsTheTwoCharactersAdmidioReads(): void
    {
        $this->editor->apply('SYS_CONTACTS', array('de' => "Zeile eins\r\nZeile zwei"));

        $this->assertSame('Zeile eins\\nZeile zwei', $this->files->read('de')['SYS_CONTACTS']['text']);
        $this->assertSame("Zeile eins\nZeile zwei", OverrideText::toEditor('Zeile eins\\nZeile zwei'));
    }

    public function testResetRemovesTheOverride(): void
    {
        $this->editor->apply('SYS_CONTACTS', array('de' => 'Mitgliederliste'));
        $this->editor->reset('SYS_CONTACTS', 'de');

        $this->assertSame(array(), $this->files->getLanguages());
    }

    public function testThePlaceholderComparisonLooksAtTheNumbersOnly(): void
    {
        $result = PlaceholderValidator::compare('A #VAR1_BOLD# B #VAR2#', 'x #VAR1# y #VAR3_ITALIC#');

        $this->assertSame(array(2), $result['missing']);
        $this->assertSame(array(3), $result['extra']);
    }

    public function testASearchFindsATextByWhatItSaysInAnySearchedLanguage(): void
    {
        $byGerman = $this->catalog->search('kontakt', array('de'), false);
        $byEnglish = $this->catalog->search('contacts', array('de', 'en'), false);

        $this->assertSame('SYS_CONTACTS', $byGerman['results'][0]['id']);
        $this->assertSame('SYS_CONTACTS', $byEnglish['results'][0]['id']);
        $this->assertSame(array('de' => 'Kontakte', 'en' => 'Contacts'), $byEnglish['results'][0]['texts']);
    }

    public function testASearchOfOneLanguageDoesNotSeeTheOthers(): void
    {
        $this->assertSame(array(), $this->catalog->search('messages', array('de'), false)['results']);
    }

    public function testASearchFindsAByKeyAndTheTextsOfPlugins(): void
    {
        $this->assertSame('SYS_CONTACTS', $this->catalog->search('SYS_CONTACTS', array('en'), false)['results'][0]['id']);
        $this->assertSame('PLG_X_NAME', $this->catalog->search('contact book', array('en'), false)['results'][0]['id']);
    }

    public function testInstallerTextsAreOnlySearchedOnRequest(): void
    {
        $this->assertSame(array(), $this->catalog->search('set up', array('en'), false)['results']);
        $this->assertSame('INS_SETUP', $this->catalog->search('set up', array('en'), true)['results'][0]['id']);
    }

    public function testASearchIsLimited(): void
    {
        $result = $this->catalog->search('s', array('en', 'de'), true, 1);

        $this->assertCount(1, $result['results']);
        $this->assertTrue($result['truncated']);
    }

    public function testTheTextThatIsTheQueryComesBeforeTextsThatOnlyContainIt(): void
    {
        $this->write('core/en.xml', array(
            'SYS_A_LONG' => 'Show all preferences of the organization',
            'SYS_B_WORD' => 'Your preferences',
            'SYS_C_EXACT' => 'Preferences',
            'SYS_D_START' => 'Preferences of the module',
            'SYS_E_INSIDE' => 'Superpreferences'
        ));
        $catalog = new StringCatalogService(array($this->base . '/core'));

        $result = $catalog->search('Preferences', array('en'), false);

        $this->assertSame(
            array('SYS_C_EXACT', 'SYS_D_START', 'SYS_B_WORD', 'SYS_A_LONG', 'SYS_E_INSIDE'),
            array_column($result['results'], 'id')
        );
        $this->assertSame(5, $result['total']);
    }

    public function testTheTotalCountsTheHitsThatAreCutOff(): void
    {
        $result = $this->catalog->search('s', array('en', 'de'), true, 1);

        $this->assertCount(1, $result['results']);
        $this->assertGreaterThan(1, $result['total']);
    }

    public function testARegularExpressionIsMatchedAgainstTheTextsAndTheIds(): void
    {
        $byText = $this->catalog->search('^Kont.*e$', array('de'), false, 100, true);
        $byId = $this->catalog->search('^SYS_GREET', array('de'), false, 100, true);

        $this->assertSame(array('SYS_CONTACTS'), array_column($byText['results'], 'id'));
        $this->assertSame(array('SYS_GREETING'), array_column($byId['results'], 'id'));
    }

    public function testARegularExpressionIsCaseInsensitiveAndTheWholeTextComesFirst(): void
    {
        $this->write('core/en.xml', array('SYS_A' => 'Show preferences', 'SYS_B' => 'Preferences', 'SYS_C' => 'Preferences of all'));
        $catalog = new StringCatalogService(array($this->base . '/core'));

        $result = $catalog->search('preferences', array('en'), false, 100, true);

        $this->assertSame(array('SYS_B', 'SYS_C', 'SYS_A'), array_column($result['results'], 'id'));
    }

    public function testATildeInARegularExpressionNeedsNoEscaping(): void
    {
        $this->write('core/en.xml', array('SYS_A' => 'About ~5 minutes', 'SYS_B' => 'Other'));
        $catalog = new StringCatalogService(array($this->base . '/core'));

        $this->assertSame(array('SYS_A'), array_column($catalog->search('~5', array('en'), false, 100, true)['results'], 'id'));
    }

    public function testAnInvalidRegularExpressionIsRefused(): void
    {
        try {
            $this->catalog->search('(unclosed', array('en'), false, 100, true);
            $this->fail('expected a refusal');
        } catch (OverrideException $exception) {
            $this->assertSame('PLG_LANGUAGE_OVERRIDES_INVALID_REGEX', $exception->getTextId());
        }
    }

    public function testAccentsAndMarkupDoNotMatter(): void
    {
        $this->assertSame('uber uns', StringCatalogService::normalize('<b>Über</b> uns'));
    }
}
