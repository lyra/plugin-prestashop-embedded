{**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 *}

{include file="module:lyra_embedded/views/templates/hook/embeddedForm.tpl"}

<script type="text/javascript">
    $(document).ready(function() {
        $('input[type="radio"][name="payment-option"]').on('click', function(e) {
            lyraManageConfirmationButtonDisplay();
        });

        $('input[type="checkbox"][name="conditions_to_approve*"]').on('change', function(e) {
            if (this.checked) {
                lyraManageConfirmationButtonDisplay();
            } else {
                $("#payment-confirmation").removeClass('lyra-hide-element');
            }
        });

        var paymentOptions = $('.payment-option');
        if (paymentOptions && paymentOptions.length === 1) {
            $("#payment-option-1").prop("checked", true);

            // Check if it's Hummingbird theme for PrestaShop 9.x
            var paymentOptionFormCheck = $('.payment-option__form-check');
            if (paymentOptionFormCheck && paymentOptionFormCheck.length === 1) {
                $('.payment-option__form-check').addClass('lyra-hide-element');
            } else {
                $('#payment-option-1-container').addClass('lyra-hide-element');
            }

            let element = $('#payment-option-1-additional-information');
            if (element !== null) {
                element.addClass('lyra-show-element');
            }
        } else {
            $('.payment-option').removeClass('lyra-hide-element');

            let element = $('#payment-option-1-additional-information');
            if (element !== null) {
                element.removeClass('lyra-show-element');
            }

            var methodTitle = "{$LYRA_TITLE|escape:'html':'UTF-8'}";
            var spans = document.querySelectorAll("span");
            var found = null;
            spans.forEach(function(span) {
                if (span.textContent.trim() === methodTitle) {
                    found = span;
                }
            });

            if (found) {
                var parentDiv = found.closest('div[id*="payment-option-"]');
                var id = parentDiv.getAttribute('id');
                var match = id && id.match(/payment-option-(\d+)/);
                if (match && match.length > 1) {
                    var paymentOptionId = match[1];
                    $('#payment-option-' + paymentOptionId).prop("checked", true);
                }
            }
        }

        KR.onFormReady(() => {
            lyraManageConfirmationButtonDisplay();
        })
    });

    var lyraManageConfirmationButtonDisplay = async function() {
        let noToken = false;
        {if ! $LYRA_FORM_TOKEN}
            noToken = true;
        {/if}

        var currentOptionId = $("input[type='radio'][name='payment-option']:checked").attr('id');
        if (($("#" + currentOptionId + "-additional-information").find("#lyra-rest-wrapper").length > 0) && (noToken == false)) {
            $("#payment-confirmation").addClass('lyra-hide-element');
        } else {
            $("#payment-confirmation").removeClass('lyra-hide-element');
        }
    };
</script>