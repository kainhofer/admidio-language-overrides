<?php
namespace AdmidioPlugin\LanguageOverrides;

use Admidio\Infrastructure\Plugins\PluginRegistry;
use AdmidioPlugin\LanguageOverrides\Service\OverrideFileService;
use AdmidioPlugin\LanguageOverrides\Service\StringCatalogService;

final class LanguageOverridesPlugin
{
    public const ID = 'language-overrides';

    public const SETTING_BACKUP_COUNT = 'language_overrides_backup_count';
    public const SETTING_SHOW_INSTALLER_STRINGS = 'language_overrides_show_installer_strings';

    /** The text of the core of this language is the fallback of every other one. */
    public const REFERENCE_LANGUAGE = 'en';

    /**
     * Whether the acting user may use the plugin: any administrator of Admidio. The override files
     * belong to the installation and not to an organization, so no organization is asked.
     */
    public static function isUsableByCurrentUser(): bool
    {
        global $gCurrentUser, $gValidLogin;

        return !empty($gValidLogin) && isset($gCurrentUser) && $gCurrentUser->isAdministrator();
    }

    /**
     * Keeps the menu entry from everybody who may not use the page. It sees every component, and
     * answers for its own only.
     */
    public static function filterVisibility(bool $visible, string $componentName): bool
    {
        if ($componentName !== self::ID) {
            return $visible;
        }

        return $visible && self::isUsableByCurrentUser();
    }

    public static function setting(string $name): mixed
    {
        $plugin = PluginRegistry::get(self::ID);
        if ($plugin === null) {
            return null;
        }

        return $plugin->getSettingValues()[$name] ?? null;
    }

    /**
     * @throws \Admidio\Infrastructure\Exception
     */
    public static function overrides(): OverrideFileService
    {
        return OverrideFileService::forInstallation((int) (self::setting(self::SETTING_BACKUP_COUNT) ?? 20));
    }

    public static function catalog(): StringCatalogService
    {
        return StringCatalogService::forInstallation();
    }

    /**
     * Running sessions keep the texts they have read and the list of language folders, so a change
     * reaches them only when they are rebuilt.
     */
    public static function reloadSessions(): void
    {
        global $gCurrentSession;

        $gCurrentSession->reloadAllSessions();
    }

    private function __construct()
    {
    }
}
