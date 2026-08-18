{**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 *}

{extends file='customer/page.tpl'}

{block name='page_title'}
    {l s='My payment means' mod='lyra_embedded'}
{/block}

{block name='page_content'}
    <div class="container">
        <section class="page_content">
            <div id="lyra-no-tokens-warning" {if $LYRA_SHOW_WALLET == true} style="display: none;" {/if} class="alert alert-info" role="alert" data-alert="info">{l s='You have no stored payment means.' mod='lyra_embedded'}</div>

            {if $LYRA_SHOW_WALLET == true}
                <div class="col-md-4">
                    {include file="module:lyra_embedded/views/templates/hook/embeddedForm.tpl"}
                </div>
            {/if}
        </section>
    </div>
{/block}