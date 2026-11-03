<?php
namespace AdmidioPlugin\LanguageOverrides\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\LanguageOverrides\LanguageOverridesPlugin;
use AdmidioPlugin\LanguageOverrides\Service\OverrideException;
use AdmidioPlugin\LanguageOverrides\Service\OverrideText;
use AdmidioPlugin\LanguageOverrides\Service\PlaceholderValidator;
use AdmidioPlugin\LanguageOverrides\Service\StringCatalogService;

/**
 * The page of the language overrides plugin: search the texts, list the overrides, edit one text.
 */
class OverridesPresenter extends PagePresenter
{
    /** The two forms of address of the German translation, which are separate languages. */
    private const GERMAN_VARIANTS = array('de' => 'de-DE', 'de-DE' => 'de');

    private const SEARCH_LIMIT = 100;

    protected Plugin $plugin;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->plugin = PluginRegistry::requireEnabled(LanguageOverridesPlugin::ID);

        parent::__construct();

        $templates = $this->plugin->getDirectory(Plugin::DIR_TEMPLATES);
        if ($templates !== null) {
            $this->addTemplateFolder($templates);
        }

        if (!LanguageOverridesPlugin::isUsableByCurrentUser()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    }

    /**
     * The language to work in, from the request or else the language of the current user.
     * @param array<string,string> $languages the available languages by code
     */
    public static function resolveLanguage(string $requested, array $languages): string
    {
        global $gL10n;

        return isset($languages[$requested]) ? $requested : $gL10n->getLanguage();
    }

    protected function pageUrl(array $parameters = array()): string
    {
        return SecurityUtils::encodeUrl($this->plugin->getUrl('index.php'), $parameters);
    }

    /**
     * The search, and the overrides that exist.
     * @throws Exception|\Smarty\Exception
     */
    public function createList(string $query, string $language, bool $withEnglish, bool $regex = false): void
    {
        global $gL10n;

        $this->setHtmlID('adm_language_overrides');
        $this->setHeadline($gL10n->get('PLG_LANGUAGE_OVERRIDES_NAME'));

        $languages = $gL10n->getAvailableLanguages();
        $language = self::resolveLanguage($language, $languages);

        $files = LanguageOverridesPlugin::overrides();
        $catalog = LanguageOverridesPlugin::catalog();

        $overrides = array();
        $fileErrors = array();
        foreach ($files->getLanguages() as $code) {
            try {
                $overrides[$code] = $files->read($code);
            } catch (OverrideException $exception) {
                $fileErrors[] = $gL10n->get($exception->getTextId(), $exception->getParams());
            }
        }

        $this->assignSmartyVariable('searchUrl', $this->plugin->getUrl('index.php'));
        $this->assignSmartyVariable('query', $query);
        $this->assignSmartyVariable('languages', $languages);
        $this->assignSmartyVariable('language', $language);
        $this->assignSmartyVariable('withEnglish', $withEnglish);
        $this->assignSmartyVariable('regex', $regex);
        $this->assignSmartyVariable('overrideRows', $this->buildOverrideRows($overrides, $catalog, $languages));
        $this->assignSmartyVariable('fileErrors', $fileErrors);
        $this->addHtmlByTemplate('language-overrides.list.tpl');
        $this->addJavascript($this->searchScript(), true);
    }

    /**
     * The hits of a search, ready to be sent to the page as JSON. The texts are sent as they are
     * and split around the hit, so that the page puts them in as text and never as HTML.
     * @return array{results: array<int,array<string,mixed>>, total: int}
     * @throws Exception|OverrideException
     */
    public function searchData(string $query, string $language, bool $withEnglish, bool $regex = false): array
    {
        global $gL10n;

        $languages = $gL10n->getAvailableLanguages();
        $language = self::resolveLanguage($language, $languages);
        $reference = LanguageOverridesPlugin::REFERENCE_LANGUAGE;
        $searchLanguages = $language === $reference || !$withEnglish ? array($language) : array($language, $reference);

        $files = LanguageOverridesPlugin::overrides();
        $overrides = array();
        foreach ($searchLanguages as $code) {
            try {
                $overrides[$code] = $files->read($code);
            } catch (OverrideException) {
                $overrides[$code] = array();
            }
        }

        $search = LanguageOverridesPlugin::catalog()->search(
            $query,
            $searchLanguages,
            (bool) LanguageOverridesPlugin::setting(LanguageOverridesPlugin::SETTING_SHOW_INSTALLER_STRINGS),
            self::SEARCH_LIMIT,
            $regex
        );

        $results = array();
        foreach ($search['results'] as $hit) {
            $texts = array();
            $overridden = array();
            foreach ($hit['texts'] as $code => $text) {
                $texts[] = array('language' => $code) + self::splitAtHit($text, $query, $regex);
                if (isset($overrides[$code][$hit['id']])) {
                    $overridden[] = $code;
                }
            }

            $results[] = array(
                'id' => $hit['id'],
                'texts' => $texts,
                'overridden' => implode(', ', $overridden),
                'url' => html_entity_decode($this->pageUrl(array(
                    'mode' => 'edit', 'key' => $hit['id'], 'lang' => $language, 'en' => $withEnglish ? 1 : 0
                )))
            );
        }

        return array('results' => $results, 'total' => $search['total']);
    }

    /**
     * The script of the search field: it asks the page for the hits while the administrator types,
     * waits a moment after the last key, and ignores an answer that a newer search has overtaken.
     */
    private function searchScript(): string
    {
        global $gL10n;

        $config = json_encode(array(
            'url' => $this->plugin->getUrl('index.php'),
            'limit' => self::SEARCH_LIMIT,
            'key' => $gL10n->get('PLG_LANGUAGE_OVERRIDES_KEY'),
            'text' => $gL10n->get('SYS_TEXT'),
            'overriddenIn' => $gL10n->get('PLG_LANGUAGE_OVERRIDES_OVERRIDDEN_IN'),
            'customize' => $gL10n->get('PLG_LANGUAGE_OVERRIDES_CUSTOMIZE'),
            'noResults' => $gL10n->get('PLG_LANGUAGE_OVERRIDES_NO_RESULTS'),
            'tooMany' => $gL10n->get('PLG_LANGUAGE_OVERRIDES_TOO_MANY_RESULTS', array('{shown}', '{total}'))
        ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        return 'var loConfig = ' . $config . ";\n" . <<<'JS'
(function () {
    var form = document.getElementById('lo_search_form');
    var out = document.getElementById('lo_results');
    var query = form.elements['query'];
    var timer = null;
    var latest = 0;
    var request = null;

    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function message(kind, text) {
        var box = element('div', 'alert alert-' + kind);
        box.setAttribute('role', 'alert');
        box.textContent = text;
        return box;
    }

    function cell(row, child) {
        var td = element('td');
        td.style.wordBreak = 'break-word';
        td.appendChild(child);
        row.appendChild(td);
        return td;
    }

    function render(data) {
        out.replaceChildren();
        if (data.status === 'error') {
            out.appendChild(message('danger', data.message));
            return;
        }
        if (data.results.length === 0) {
            out.appendChild(message('info', loConfig.noResults));
            return;
        }
        if (data.total > data.results.length) {
            out.appendChild(message('warning', loConfig.tooMany
                .replace('{shown}', data.results.length).replace('{total}', data.total)));
        }

        var table = element('table', 'table table-condensed table-hover');
        var head = element('tr');
        [loConfig.key, loConfig.text, loConfig.overriddenIn, ''].forEach(function (title) {
            head.appendChild(element('th', '', title));
        });
        table.appendChild(element('thead')).appendChild(head);

        var body = element('tbody');
        data.results.forEach(function (hit) {
            var row = element('tr');
            cell(row, element('code', '', hit.id));

            var texts = element('div');
            hit.texts.forEach(function (text) {
                var line = element('div');
                line.appendChild(element('span', 'badge text-bg-light me-1', text.language));
                line.appendChild(document.createTextNode(text.before));
                if (text.match !== '') { line.appendChild(element('mark', '', text.match)); }
                line.appendChild(document.createTextNode(text.after));
                texts.appendChild(line);
            });
            cell(row, texts);
            cell(row, document.createTextNode(hit.overridden));

            var link = element('a', 'admidio-icon-link');
            link.href = hit.url;
            link.title = loConfig.customize;
            link.appendChild(element('i', 'bi bi-pencil-square'));
            cell(row, link).className = 'text-end';
            body.appendChild(row);
        });
        table.appendChild(body);

        var wrapper = element('div', 'table-responsive mb-4');
        wrapper.appendChild(table);
        out.appendChild(wrapper);
    }

    function search() {
        var term = query.value.trim();
        latest++;
        if (request) { request.abort(); }
        if (term === '') { out.replaceChildren(); return; }

        var mine = latest;
        request = new AbortController();
        var parameters = new URLSearchParams({
            mode: 'search',
            query: term,
            lang: form.elements['lang'].value,
            en: form.elements['en'].checked ? 1 : 0,
            re: form.elements['re'].checked ? 1 : 0
        });
        fetch(loConfig.url + '?' + parameters.toString(), {signal: request.signal, credentials: 'same-origin'})
            .then(function (response) { return response.json(); })
            .then(function (data) { if (mine === latest) { render(data); } })
            .catch(function (error) {
                if (error.name !== 'AbortError' && mine === latest) {
                    out.replaceChildren(message('danger', String(error)));
                }
            });
    }

    form.addEventListener('submit', function (event) { event.preventDefault(); clearTimeout(timer); search(); });
    query.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 250); });
    form.elements['lang'].addEventListener('change', search);
    form.elements['en'].addEventListener('change', search);
    form.elements['re'].addEventListener('change', search);
    if (query.value.trim() !== '') { search(); }
})();
JS;
    }

    /**
     * A text cut around the first occurrence of the query. Nothing is marked if the text has the
     * words of the query only in another spelling (other case is found, other accents are not).
     * @return array{before: string, match: string, after: string}
     */
    private static function splitAtHit(string $text, string $query, bool $regex = false): array
    {
        if ($regex) {
            // the offsets of a match in a UTF-8 pattern are bytes
            if (@preg_match(StringCatalogService::delimit($query), $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                return array('before' => $text, 'match' => '', 'after' => '');
            }
            return array(
                'before' => substr($text, 0, $match[0][1]),
                'match' => $match[0][0],
                'after' => substr($text, $match[0][1] + strlen($match[0][0]))
            );
        }

        $query = trim($query);
        $position = $query === '' ? false : mb_stripos($text, $query);
        if ($position === false) {
            return array('before' => $text, 'match' => '', 'after' => '');
        }

        $length = mb_strlen($query);
        return array(
            'before' => mb_substr($text, 0, $position),
            'match' => mb_substr($text, $position, $length),
            'after' => mb_substr($text, $position + $length)
        );
    }

    /**
     * @param array<string,array<string,array{text: string, original: ?string}>> $overrides
     * @param array<string,string> $languages
     * @return array<int,array<string,mixed>>
     */
    private function buildOverrideRows(array $overrides, $catalog, array $languages): array
    {
        global $gL10n, $gCurrentSession;

        $rows = array();
        foreach ($overrides as $code => $entries) {
            foreach ($entries as $id => $entry) {
                $original = $catalog->getOriginal($id, $code);
                $badges = array();
                if ($original === null) {
                    $badges[] = '<span class="badge text-bg-danger">' . $gL10n->get('PLG_LANGUAGE_OVERRIDES_STATUS_ORPHANED') . '</span>';
                } else {
                    if ($entry['original'] !== null && $entry['original'] !== $original) {
                        $badges[] = '<span class="badge text-bg-warning" title="'
                            . SecurityUtils::encodeHTML($gL10n->get('PLG_LANGUAGE_OVERRIDES_STATUS_OUTDATED_DESC')) . '">'
                            . $gL10n->get('PLG_LANGUAGE_OVERRIDES_STATUS_OUTDATED') . '</span>';
                    }
                    if (PlaceholderValidator::compare($original, $entry['text'])['missing'] !== array()) {
                        $badges[] = '<span class="badge text-bg-warning" title="'
                            . SecurityUtils::encodeHTML($gL10n->get('PLG_LANGUAGE_OVERRIDES_STATUS_PLACEHOLDERS_DESC')) . '">'
                            . $gL10n->get('PLG_LANGUAGE_OVERRIDES_STATUS_PLACEHOLDERS') . '</span>';
                    }
                }

                $rowId = 'adm_lo_' . preg_replace('/[^A-Za-z0-9_]/', '_', $code . '_' . $id);
                $deleteUrl = $this->pageUrl(array('mode' => 'delete', 'key' => $id, 'lang' => $code));
                $rows[] = array(
                    'id' => $rowId,
                    'uuid' => $rowId,
                    'key' => SecurityUtils::encodeHTML($id),
                    'language' => SecurityUtils::encodeHTML($languages[$code] ?? $code),
                    'original' => $original === null ? '' : SecurityUtils::encodeHTML($original),
                    'override' => SecurityUtils::encodeHTML($entry['text']),
                    'status' => implode(' ', $badges),
                    'actions' => array(
                        array(
                            'url' => $this->pageUrl(array('mode' => 'edit', 'key' => $id, 'lang' => $code, 'en' => 0)),
                            'icon' => 'bi bi-pencil-square',
                            'tooltip' => $gL10n->get('SYS_EDIT')
                        ),
                        array(
                            'dataHref' => 'callUrlHideElement(\'' . $rowId . '\', \'' . $deleteUrl . '\', \''
                                . $gCurrentSession->getCsrfToken() . '\')',
                            'dataMessage' => $gL10n->get('PLG_LANGUAGE_OVERRIDES_RESET_CONFIRM', array($id)),
                            'icon' => 'bi bi-trash',
                            'tooltip' => $gL10n->get('PLG_LANGUAGE_OVERRIDES_RESET')
                        )
                    )
                );
            }
        }

        return $rows;
    }

    /**
     * The id of a form field for a language: the code has a minus in it, which an id should not.
     */
    public static function fieldId(string $prefix, string $language): string
    {
        return $prefix . str_replace('-', '_', $language);
    }

    /**
     * The languages the edit form shows, and in the same way the languages its POST is read for.
     * @param array<string,string> $languages the available languages by code
     * @return array<int,string>
     */
    public static function editLanguages(string $language, bool $withEnglish, array $languages): array
    {
        $shown = array($language);
        if ($withEnglish && $language !== LanguageOverridesPlugin::REFERENCE_LANGUAGE) {
            $shown[] = LanguageOverridesPlugin::REFERENCE_LANGUAGE;
        }
        return array_values(array_filter($shown, static fn(string $code): bool => isset($languages[$code])));
    }

    /**
     * The other form of address of the German translation, if the language is one of them and the
     * other one is installed.
     * @param array<string,string> $languages
     */
    public static function otherGermanVariant(string $language, array $languages): ?string
    {
        $other = self::GERMAN_VARIANTS[$language] ?? null;
        return $other !== null && isset($languages[$other]) ? $other : null;
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createEditForm(string $textId, string $language, bool $withEnglish): void
    {
        global $gL10n, $gCurrentSession;

        $languages = $gL10n->getAvailableLanguages();
        $language = self::resolveLanguage($language, $languages);
        $files = LanguageOverridesPlugin::overrides();
        $catalog = LanguageOverridesPlugin::catalog();

        if ($catalog->getOriginal($textId, $language) === null) {
            throw new Exception('PLG_LANGUAGE_OVERRIDES_UNKNOWN_TEXT', array($textId));
        }

        $this->setHtmlID('adm_language_overrides_edit');
        $this->setHeadline($gL10n->get('PLG_LANGUAGE_OVERRIDES_EDIT_TEXT', array($textId)));

        $form = new FormPresenter(
            'adm_language_overrides_edit_form',
            'language-overrides.edit.tpl',
            $this->pageUrl(array('mode' => 'save', 'key' => $textId, 'lang' => $language, 'en' => $withEnglish ? 1 : 0)),
            $this
        );

        $blocks = array();
        foreach (self::editLanguages($language, $withEnglish, $languages) as $code) {
            $original = $catalog->getOriginal($textId, $code) ?? '';
            $current = $files->read($code)[$textId]['text'] ?? $original;
            $placeholders = PlaceholderValidator::numbers($original);

            $form->addCustomContent(
                self::fieldId('lo_original_', $code),
                $gL10n->get('PLG_LANGUAGE_OVERRIDES_ORIGINAL_IN', array($languages[$code])),
                '<code style="white-space: pre-wrap;">' . SecurityUtils::encodeHTML(OverrideText::toEditor($original)) . '</code>'
            );
            $form->addMultilineTextInput(
                self::fieldId('lo_text_', $code),
                $gL10n->get('PLG_LANGUAGE_OVERRIDES_TEXT_IN', array($languages[$code])),
                OverrideText::toEditor($current),
                4,
                array(
                    'maxLength' => OverrideText::MAX_LENGTH,
                    'property' => FormPresenter::FIELD_REQUIRED,
                    'helpTextId' => $placeholders === array() ? '' : array(
                        'PLG_LANGUAGE_OVERRIDES_PLACEHOLDERS_HELP',
                        implode(', ', array_map(static fn(int $number): string => '#VAR' . $number . '#', $placeholders))
                    )
                )
            );
            $form->addInput(
                self::fieldId('lo_hash_', $code),
                '',
                $files->getHash($code),
                array('property' => FormPresenter::FIELD_HIDDEN)
            );
            $blocks[] = $code;
        }

        $otherGerman = self::otherGermanVariant($language, $languages);
        if ($otherGerman !== null) {
            $form->addCheckbox(
                'lo_apply_other_german',
                $gL10n->get('PLG_LANGUAGE_OVERRIDES_APPLY_TO_OTHER_GERMAN', array($languages[$otherGerman])),
                true,
                array('helpTextId' => 'PLG_LANGUAGE_OVERRIDES_APPLY_TO_OTHER_GERMAN_DESC')
            );
        }

        $form->addSubmitButton('adm_button_save', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));

        $this->assignSmartyVariable('blocks', array_map(static fn(string $code): array => array(
            'original' => self::fieldId('lo_original_', $code),
            'text' => self::fieldId('lo_text_', $code),
            'hash' => self::fieldId('lo_hash_', $code)
        ), $blocks));
        $this->assignSmartyVariable('hasOtherGerman', $otherGerman !== null);
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }
}
