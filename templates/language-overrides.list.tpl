{foreach $fileErrors as $fileError}
    <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill"></i> {$fileError}</div>
{/foreach}

<div class="card admidio-field-group mb-3">
    <div class="card-header">{$l10n->get('PLG_LANGUAGE_OVERRIDES_FIND_TEXT')}</div>
    <div class="card-body">
        <form id="lo_search_form" method="get" action="{$searchUrl}" class="row g-2 align-items-center" autocomplete="off">
            <input type="hidden" name="mode" value="list">
            <div class="col-12 col-md">
                <input type="text" name="query" value="{$query|escape}" class="form-control" autofocus
                       placeholder="{$l10n->get('PLG_LANGUAGE_OVERRIDES_SEARCH_PLACEHOLDER')}">
            </div>
            <div class="col-12 col-md-auto">
                <select name="lang" class="form-select" aria-label="{$l10n->get('SYS_LANGUAGE')}">
                    {foreach $languages as $code => $name}
                        <option value="{$code}"{if $code eq $language} selected{/if}>{$name|escape}</option>
                    {/foreach}
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-secondary">
                    <i class="bi bi-search"></i> {$l10n->get('PLG_LANGUAGE_OVERRIDES_SEARCH')}
                </button>
            </div>
            <div class="col-12">
                <div class="form-check form-check-inline">
                    <input type="checkbox" class="form-check-input" id="lo_en" name="en" value="1"{if $withEnglish} checked{/if}>
                    <label class="form-check-label" for="lo_en">{$l10n->get('PLG_LANGUAGE_OVERRIDES_ALSO_ENGLISH')}</label>
                </div>
                <div class="form-check form-check-inline">
                    <input type="checkbox" class="form-check-input" id="lo_re" name="re" value="1"{if $regex} checked{/if}>
                    <label class="form-check-label" for="lo_re">{$l10n->get('PLG_LANGUAGE_OVERRIDES_REGEX')}</label>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="lo_results" class="mb-4" aria-live="polite"></div>

<h2 class="h5">{$l10n->get('PLG_LANGUAGE_OVERRIDES_MY_OVERRIDES')}</h2>
{if $overrideRows|@count eq 0}
    <div class="alert alert-info" role="alert"><i class="bi bi-info-circle-fill"></i> {$l10n->get('PLG_LANGUAGE_OVERRIDES_NO_OVERRIDES')}</div>
{else}
    <div class="table-responsive">
        <table class="table table-condensed table-hover" style="max-width: 100%;">
            <thead>
                <tr>
                    <th>{$l10n->get('PLG_LANGUAGE_OVERRIDES_KEY')}</th>
                    <th>{$l10n->get('SYS_LANGUAGE')}</th>
                    <th>{$l10n->get('PLG_LANGUAGE_OVERRIDES_ORIGINAL')}</th>
                    <th>{$l10n->get('PLG_LANGUAGE_OVERRIDES_OVERRIDE')}</th>
                    <th>{$l10n->get('PLG_LANGUAGE_OVERRIDES_STATUS')}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                {foreach $overrideRows as $row}
                    <tr id="{$row.id}">
                        <td style="word-break: break-word;"><code>{$row.key nofilter}</code></td>
                        <td>{$row.language nofilter}</td>
                        <td style="word-break: break-word;">{$row.original nofilter}</td>
                        <td style="word-break: break-word;">{$row.override nofilter}</td>
                        <td>{$row.status nofilter}</td>
                        <td class="text-end">{include 'sys-template-parts/list.functions.tpl' data=$row}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
{/if}
