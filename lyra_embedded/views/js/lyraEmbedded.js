/**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

/**
 * REST API JS tools.
 */

var LYRA_LAST_CART = false;
var LYRA_CREATE_ORDER = true;

$(function() {
    setTimeout(function() {
        $('.lyra .kr-payment-button').click(function(e) {
            $('.lyra .kr-form-error').html('');
        });

        lyraInitRestEvents();
    }, 0);

    $('input[name="payment-option"]').on('change', async function(e) {
        let moduleName = this.getAttribute("data-module-name");
        if (moduleName !== 'lyra_embedded' && (LYRA_LAST_CART !== false)) {
            await lyraRestoreCart();
        }
    });
});

var lyraRestoreCart = async function() {
    $.ajax({
        type: 'POST',
        url: decodeURIComponent(lyraEmbedded.checkoutUrl),
        async: false,
        cache: false,
        data: {
            'action': 'restoreCart',
            'data': LYRA_LAST_CART
        },
        success: function(json) {
            var response = JSON.parse(json);
            if (response.message) {
                console.log(response.message);
            }

            LYRA_LAST_CART = false;
        }
    });
};

var lyraRefreshToken = async function() {
    return new Promise((resolve, reject) => {
        $.ajax({
            type: 'POST',
            url: decodeURIComponent(lyraEmbedded.checkoutUrl),
            async: false,
            cache: false,
            data: {
                'action': 'refreshToken',
            },
            success: function(json) {
                var response = JSON.parse(json);

                if (response.token) {
                    LYRA_CREATE_ORDER = false;

                    if (response.lastCart) {
                        LYRA_LAST_CART = response.lastCart;
                    }

                    KR.setFormConfig({ formToken: response.token }).then(
                        function(v) {
                            KR = v.KR;
                            resolve(response);
                        }
                    );
                }
            },
            error: function(error) {
                console.log(JSON.stringify(error));

                reject(error);
            }
       });
   });
};

var lyraInitRestEvents = function() {
    KR.onError(async function(e) {
        $('.lyra .kr-form-error').html('<span style="color: red;"><span>' + e.errorMessage + '</span></span>');
    });

    KR.onFocus(function(e) {
        $('.lyra .kr-form-error').html('');
    });

    KR.smartForm.onClick(function({paymentMethod, action, formId}) {
        const isWalletPage = document.querySelector('[id$="lyra_embedded-wallet"]');
        if (isWalletPage) {
            return;
        }

        return lyraCanProceed(paymentMethod, action);
    });
};

var lyraCanProceed = async function(paymentMethod, action) {
    if (! lyraCheckTermsAndConditions()) {
        KR.throwCustomError(lyraTranslate("CLIENT_312"), paymentMethod);

        return false;
    }

    if (LYRA_CREATE_ORDER === false) {
        return true;
    }

    if (action === 'beforePaymentStart') {
        if (paymentMethod === 'CARDS') {
            $('.kr-payment-button').attr('disabled', 'disabled');
        } else {
            $('button.kr-method-label.kr-' + paymentMethod.toLowerCase()).attr('disabled', 'disabled');
        }
    }

    if (paymentMethod === 'CARDS') {
        // Expanded card form is selected, let's check form validity.
        try {
            await KR.validateForm();
            await lyraRefreshToken();

            return true;
        } catch(v) {
            console.log(v);

            return v.result.doOnError();
        }
    } else {
        await lyraRefreshToken();

        return true;
    }
};

var lyraCheckTermsAndConditions = function() {
    var approvedConditions = ($('input[name*="conditions_to_approve\"]:checked').length == $('input[name*="conditions_to_approve\"]').length);
    if (! approvedConditions || $("#payment-confirmation button.btn").prop('disabled')) {
       return false;
    }

    return true;
};

// Translate error message.
var lyraTranslate = function(code) {
    var lang = LYRA_LANGUAGE; // Global variable that contains current language.
    var messages = LYRA_ERROR_MESSAGES.hasOwnProperty(lang) ? LYRA_ERROR_MESSAGES[lang] : LYRA_ERROR_MESSAGES['en'];

    if (! messages.hasOwnProperty(code)) {
        var index = code.lastIndexOf('_');
        code = code.substring(0, index + 1) + '999';
    }

    return messages[code];
};

var LYRA_ERROR_MESSAGES = {
    fr: {
        CLIENT_312: 'Veuillez vous assurer que vous avez accepté les conditions générales de vente.'
    },

    en: {
        CLIENT_312: 'Please make sure you have accepted the terms and conditions.'
    },

    de: {
        CLIENT_312: 'Bitte akzeptieren Sie unsere Allgemeinen Geschäftsbedingungen.'
    },

    es: {
        CLIENT_312: 'Es necesario aceptar los términos y condiciones al final de la página para poder proceder con el pago.'
    },

    br: {
        CLIENT_312: 'Certifique-se que aceitou os termos e as condições.'
    },

    pt: {
        CLIENT_312: 'Certifique-se que aceitou os termos e as condições.'
    }
};