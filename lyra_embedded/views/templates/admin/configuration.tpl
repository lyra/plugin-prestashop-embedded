{**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 *}

<script type="module" src="{$LYRA_EPSILON_URL|escape:'html':'UTF-8'}epsilon.js"></script>
<link rel="stylesheet" href="{$LYRA_EPSILON_URL|escape:'html':'UTF-8'}epsilon.css">

<script type="module">
    window.epsilon.onModelsUpdate(async (modelsConfig) => {
        await saveModels(modelsConfig);
    });

    window.epsilon.onFormTokenRequest(async (config) => {
        return await getFormToken(config);
    });

    window.epsilon.showPanelElement('cmsPaymentMethodLabel');
</script>

{if $IS_WHITE_LABEL_ALL }
    <script type="module">
        window.epsilon.showPanelElement('payzenWhiteLabel');
    </script>
{/if}

<div class="panel">
    <div class="form-wrapper">
        <div class="form-group lyra-div">
            <div class="panel-heading">{l s='Payment form and means' mod='lyra_embedded'}</div>
            <div class="lyra-widget-div">
                <p class="lyra-widget-desc">{$LYRA_WIDGET_DESC}</p>
                <button id="LYRA_WIDGET" onclick="event.preventDefault(); window.epsilon.open();" >
                    <img class="lyra-button-img" width="24" height="24" src="{$LYRA_BRUSH_IMG}">

                    {l s='Configure the payment form' mod='lyra_embedded'}
                </button>
            </div>
            <div>
                <span>
                    <img src="{$LYRA_EPSILON_URL|escape:'html':'UTF-8'}widget-preview.webp" class="lyra-widget-img">
                </span>
            </div>
        </div>

        <div class="form-group lyra-div">
            <div class="panel-heading">{l s='Server settings' mod='lyra_embedded'}</div>
            <div>
                <p><b>{l s='REST API Notification URL' mod='lyra_embedded'}</b></p>
            </div>
            <div>
                <input type="text" name="LYRA_REST_NOTIFY_URL" value="{$LYRA_REST_NOTIFY_URL}" readonly="readonly">
            </div>
            <br />
            <div class="alert alert-info">
                <p>{l s='URL to copy into your bank Back Office > Settings > Notification rules. In multistore mode, notification URL is the same for all the stores.' mod='lyra_embedded'}</p>
            </div>
        </div>

        <div class="form-group lyra-div">
            <div class="panel-heading">{l s='Plugin credits' mod='lyra_embedded'}</div>
            <div class="lyra-div">{l s='Developed by' mod='lyra_embedded'} <b><a href="https://www.lyra.com/" target="_blank">Lyra Network</a></b></div>
            <div class="lyra-div">{l s='Contact ' mod='lyra_embedded'} <span style="display: inline-table;"><b>{$LYRA_SUPPORT_MAIL|unescape:'html':'UTF-8'}</b></span></div>
            <div class="lyra-div">{l s='Module version' mod='lyra_embedded'} <b>{if $smarty.const._PS_HOST_MODE_}Cloud{/if}{$LYRA_PLUGIN_VERSION|escape:'html':'UTF-8'}</b></div>

            {if !empty($LYRA_DOC_FILES)}
                <br />
                <div class="alert alert-info">
                    <span>{l s='Access to the module configuration documentation:' mod='lyra_embedded'}</span>

                    {foreach from=$LYRA_DOC_FILES key="lang" item="url"}
                        <a class="lyra-doc-link" href="{$url|escape:'html':'UTF-8'}" target="_blank">{$lang|escape:'html':'UTF-8'}</a>
                    {/foreach}
                </div>
            {/if}
        </div>
    </div>
</div>