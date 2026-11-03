<?php
/**
 ***********************************************************************************************
 * Language overrides plugin - search the texts of Admidio, list the overrides and edit them.
 *
 * Parameters:
 * mode  : list (default) | search | edit | save | delete
 * query : text to search for (list, search); search answers as JSON
 * key   : text id (edit, save, delete)
 * lang  : language code; the language to search and to edit in (default: the language of the user)
 * en    : 1 to search and edit the English text as well (default 1)
 * re    : 1 if the query is a regular expression (search, default 0)
 *
 * @copyright Reinhold Kainhofer
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Exception;
use AdmidioPlugin\LanguageOverrides\LanguageOverridesPlugin;
use AdmidioPlugin\LanguageOverrides\Presenter\OverridesPresenter;
use AdmidioPlugin\LanguageOverrides\Service\OverrideEditService;
use AdmidioPlugin\LanguageOverrides\Service\OverrideException;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'list',
        'validValues' => array('list', 'search', 'edit', 'save', 'delete')
    ));
    $getQuery = admFuncVariableIsValid($_GET, 'query', 'string');
    $getKey = admFuncVariableIsValid($_GET, 'key', 'string');
    $getLanguage = admFuncVariableIsValid($_GET, 'lang', 'string');
    $getEnglish = admFuncVariableIsValid($_GET, 'en', 'bool', array('defaultValue' => true));
    $getRegex = admFuncVariableIsValid($_GET, 're', 'bool', array('defaultValue' => false));

    if (!LanguageOverridesPlugin::isUsableByCurrentUser()) {
        throw new Exception('SYS_NO_RIGHTS');
    }
    if (in_array($getMode, array('edit', 'save', 'delete'), true) && preg_match('/^[A-Z0-9_]+$/', $getKey) !== 1) {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }

    switch ($getMode) {
        case 'list':
            $page = new OverridesPresenter();
            $page->createList($getQuery, $getLanguage, $getEnglish, $getRegex);
            $gNavigation->addStartUrl(CURRENT_URL, $page->getHeadline(), 'bi-translate');
            $page->show();
            break;

        case 'search':
            // read-only and answered as JSON for the search field of the list
            $page = new OverridesPresenter();
            header('Content-Type: application/json; charset=utf-8');
            try {
                $data = $page->searchData($getQuery, $getLanguage, $getEnglish, $getRegex);
            } catch (OverrideException $exception) {
                throw new Exception($exception->getTextId(), $exception->getParams());
            }
            echo json_encode($data);
            break;

        case 'edit':
            $page = new OverridesPresenter();
            $page->createEditForm($getKey, $getLanguage, $getEnglish);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'save':
            $form = $gCurrentSession->getFormObject($_POST['adm_csrf_token']);
            $formValues = $form->validate($_POST);

            $languages = $gL10n->getAvailableLanguages();
            $getLanguage = OverridesPresenter::resolveLanguage($getLanguage, $languages);

            $texts = array();
            $hashes = array();
            foreach (OverridesPresenter::editLanguages($getLanguage, $getEnglish, $languages) as $code) {
                $texts[$code] = (string) ($formValues[OverridesPresenter::fieldId('lo_text_', $code)] ?? '');
                $hashes[$code] = (string) ($formValues[OverridesPresenter::fieldId('lo_hash_', $code)] ?? '');
            }

            $otherGerman = OverridesPresenter::otherGermanVariant($getLanguage, $languages);
            if ($otherGerman !== null && !empty($formValues['lo_apply_other_german'])) {
                // the same wording for the other form of address; its file may not have been seen
                // by the form, so it is not checked for a concurrent change
                $texts[$otherGerman] = $texts[$getLanguage];
            }

            try {
                (new OverrideEditService(LanguageOverridesPlugin::overrides(), LanguageOverridesPlugin::catalog()))
                    ->apply($getKey, $texts, $hashes);
            } catch (OverrideException $exception) {
                throw new Exception($exception->getTextId(), $exception->getParams());
            }
            LanguageOverridesPlugin::reloadSessions();

            $gNavigation->deleteLastUrl();
            echo json_encode(array('status' => 'success', 'url' => $gNavigation->getUrl()));
            break;

        case 'delete':
            \Admidio\Infrastructure\Utils\SecurityUtils::validateCsrfToken($_POST['adm_csrf_token']);
            $languages = $gL10n->getAvailableLanguages();
            if (!isset($languages[$getLanguage])) {
                throw new Exception('SYS_INVALID_PAGE_VIEW');
            }

            try {
                (new OverrideEditService(LanguageOverridesPlugin::overrides(), LanguageOverridesPlugin::catalog()))
                    ->reset($getKey, $getLanguage);
            } catch (OverrideException $exception) {
                throw new Exception($exception->getTextId(), $exception->getParams());
            }
            LanguageOverridesPlugin::reloadSessions();

            echo json_encode(array('status' => 'success'));
            break;
    }
} catch (Throwable $e) {
    handleException($e, in_array($getMode ?? '', array('search', 'save', 'delete')));
}
