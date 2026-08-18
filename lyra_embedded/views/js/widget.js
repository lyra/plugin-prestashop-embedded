/**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

$(function() {
    $.ajax({
        type: 'POST',
        url: decodeURIComponent(LYRA_ADMIN_CONTROLLER_URL),
        async: false,
        cache: false,
        data: {
            'action': 'getModels'
        },
        success: function(response) {
            response = JSON.parse(response);
            if (response.models) {
                window.epsilon.setModels(JSON.parse(response.models));
            }

            console.log(response.message);
        }
    }).fail(function (jqXHR, textStatus, error) {
        console.log(error);
    });
});

var getFormToken = async function getFormToken(config) {
    var token = '';

    $.ajax({
        type: 'POST',
        url: decodeURIComponent(LYRA_ADMIN_CONTROLLER_URL),
        async: false,
        cache: false,
        data: {
            'action': 'getWidgetToken',
            'data': JSON.stringify(config)
        },
        success: function(response) {
            response = JSON.parse(response);
            token = JSON.parse(response.token);

            console.log(response.message);
        }
    }).fail(function (jqXHR, textStatus, error) {
        console.log(error);
    });

    return token;
};

var saveModels = async function(modelsToSave) {
    $.ajax({
        type: 'POST',
        url: decodeURIComponent(LYRA_ADMIN_CONTROLLER_URL),
        async: false,
        cache: false,
        data: {
            'action': 'saveModels',
            'data': JSON.stringify(modelsToSave)
        },
        success: function (response) {
            response = JSON.parse(response);
            console.log(response.message);
        }
    }).fail(function (jqXHR, textStatus, error) {
        console.log(error);
    });
}