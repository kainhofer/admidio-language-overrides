<?php
namespace AdmidioPlugin\LanguageOverrides\Tests\Unit;

require_once dirname(__DIR__, 2) . '/src/Service/OverrideException.php';
require_once dirname(__DIR__, 2) . '/src/Service/OverrideFileService.php';

use Admidio\Hooks\Hooks;
use Admidio\Infrastructure\Language;
use Admidio\Tests\Support\AdmidioTestCase;
use AdmidioPlugin\LanguageOverrides\Service\OverrideFileService;
use Psr\Log\NullLogger;

/**
 * The files the plugin writes are read by the language class of Admidio itself.
 */
final class LanguageLookupTest extends AdmidioTestCase
{
    private OverrideFileService $files;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $admidioRoot = realpath(__DIR__ . '/../../../..');
        if (!defined('ADMIDIO_PATH')) {
            define('ADMIDIO_PATH', $admidioRoot);
        }
        if (!defined('FOLDER_DATA')) {
            require_once $admidioRoot . '/tests/env.php';
            admidioTestLoadEnvironment($admidioRoot . '/.env.test');
            define('FOLDER_DATA', admidioTestDataFolder($admidioRoot));
        }
        if (!defined('FOLDER_LANGUAGES')) {
            define('FOLDER_LANGUAGES', '/languages');
        }
        if (!defined('FOLDER_PLUGINS')) {
            define('FOLDER_PLUGINS', '/plugins');
        }
        require_once ADMIDIO_PATH . '/system/bootstrap/function.php';
        $GLOBALS['gLogger'] = $GLOBALS['gLogger'] ?? new NullLogger();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Hooks::reset();
        $this->files = new OverrideFileService(ADMIDIO_PATH . FOLDER_DATA . FOLDER_LANGUAGES, null);
        $this->assertSame(array(), $this->files->getLanguages(), 'the test data folder has no overrides to begin with');
    }

    protected function tearDown(): void
    {
        foreach ($this->files->getLanguages() as $language) {
            $this->files->remove($language, array_keys($this->files->read($language)));
        }
        @unlink($this->files->getDirectory() . '/.lock');
        @rmdir($this->files->getDirectory());
        parent::tearDown();
    }

    public function testAnOverrideReplacesTheTextOfTheCoreAndRemovingItRestoresTheText(): void
    {
        $this->assertSame('Speichern', (new Language('de'))->get('SYS_SAVE'));

        $this->files->set('de', array('SYS_SAVE' => array('text' => 'Sichern', 'original' => 'Speichern')));
        $this->assertSame('Sichern', (new Language('de'))->get('SYS_SAVE'));

        $this->files->remove('de', array('SYS_SAVE'));
        $this->assertSame('Speichern', (new Language('de'))->get('SYS_SAVE'));
    }

    public function testAnOverrideOfEnglishDoesNotReachAGermanVisitor(): void
    {
        $this->files->set('en', array('SYS_SAVE' => array('text' => 'Store', 'original' => 'Save')));

        $this->assertSame('Store', (new Language('en'))->get('SYS_SAVE'));
        $this->assertSame('Speichern', (new Language('de'))->get('SYS_SAVE'));
    }

    public function testTheFormsOfAddressOfGermanAreSeparateLanguages(): void
    {
        $this->files->set('de', array('SYS_SAVE' => array('text' => 'Sichern', 'original' => 'Speichern')));

        $this->assertSame('Sichern', (new Language('de'))->get('SYS_SAVE'));
        $this->assertSame('Speichern', (new Language('de-DE'))->get('SYS_SAVE'));
    }

    public function testTheEntryWithTheOriginalAttributeStillHasItsPlaceholdersFilledIn(): void
    {
        $this->files->set('de', array('SYS_CREATED_BY_AND_AT' => array(
            'text' => 'Von #VAR1_BOLD# am #VAR2#',
            'original' => 'x'
        )));

        $this->assertSame('Von <strong>Anna</strong> am 1.1.', (new Language('de'))->get('SYS_CREATED_BY_AND_AT', array('Anna', '1.1.')));
    }
}
