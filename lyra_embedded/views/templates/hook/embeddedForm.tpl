{**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 *}

<!-- This meta tag is mandatory to avoid encoding problems caused by \PrestaShop\PrestaShop\Core\Payment\PaymentOptionFormDecorator -->
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />

{if $LYRA_FORM_TOKEN}
    <section id="lyra-rest-wrapper" style="margin-bottom: 3rem;">
        <div class="kr-smart-form" kr-form-token="{$LYRA_FORM_TOKEN|escape:'html':'UTF-8'}"></div>
    </section>
{else}
    <div id="lyra-no-token-warning" class="alert alert-danger" role="danger" data-alert="danger">{$LYRA_ERROR_MSG|escape:'html':'UTF-8'}</div>
{/if}

<script type="text/javascript">
    $(document).ready(function() {
        let decodedConfig = "{$LYRA_FORM_CONFIG|escape:'javascript':'UTF-8' nofilter}";
        KR.setFormConfig(JSON.parse(decodedConfig));
    });
</script>