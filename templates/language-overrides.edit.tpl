<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    <div class="admidio-form-required-notice"><span>{$l10n->get('SYS_REQUIRED_INPUT')}</span></div>

    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    {foreach $blocks as $block}
        <div class="card admidio-field-group">
            <div class="card-body">
                {include 'sys-template-parts/form.custom-content.tpl' data=$elements[$block.original]}
                {include 'sys-template-parts/form.multiline.tpl' data=$elements[$block.text]}
                {include 'sys-template-parts/form.input.tpl' data=$elements[$block.hash]}
            </div>
        </div>
    {/foreach}
    {if $hasOtherGerman}
        <div class="card admidio-field-group">
            <div class="card-body">
                {include 'sys-template-parts/form.checkbox.tpl' data=$elements['lo_apply_other_german']}
            </div>
        </div>
    {/if}
    <div class="form-alert" style="display: none;">&nbsp;</div>
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save']}
</form>
