<?php
/**
 ***********************************************************************************************
 * Language overrides - plugin entry file.
 *
 * Runtime registration only. The plugin works on the files in adm_my_files/languages, which
 * Admidio reads before its own language files, so nothing is needed here to make an override
 * take effect.
 *
 * @copyright Reinhold Kainhofer
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Hooks\Hooks;
use AdmidioPlugin\LanguageOverrides\LanguageOverridesPlugin;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit('This page may not be called directly!');
}

// The plugin declares no access preference, so Admidio would show its menu entry to everybody.
Hooks::addFilter(
    'component_visible',
    array(LanguageOverridesPlugin::class, 'filterVisibility'),
    10,
    2,
    LanguageOverridesPlugin::ID
);
