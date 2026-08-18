<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

if (! defined('_PS_VERSION_')) {
    exit;
}

use Lyranetwork\LyraEmbedded\Sdk\Rest\Api as LyraRest;
use Lyranetwork\LyraEmbedded\Utility\Tools as LyraTools;
use Lyranetwork\LyraEmbedded\Utility\RestHelper as LyraRestHelper;

/**
 * Admin controller handling all AJAX requests.
 *
 * Routes incoming POST parameters to the appropriate handler:
 *   - `saveModels` – persist configuration models sent by the epsilon widget.
 *   - `getModels`  – return saved form models as JSON.
 *   - `getWidgetToken`           – call the REST API to obtain a form token.
 */
class LyraEmbeddedAdminController extends ModuleAdminController
{
    /**
     * Dispatch the incoming POST request to the correct handler.
     *
     * Reads well-known POST parameters and delegates to the matching private
     * method or outputs JSON inline via `die()`.  When none of the expected
     * parameters are present the method returns silently.
     *
     * @return void
     */
    public function postProcess()
    {
        $action = Tools::getValue('action');
        if (empty($action)) {
            $this->ajaxDie(
                [
                    'error' => true,
                    'message' => 'Empty action received.'
                ]
            );
        }

        switch ($action) {
            case 'getWidgetToken':
                $this->getWidgetToken(json_decode(Tools::getValue('data'), true));

                break;

            case 'saveModels':
                $this->saveModels(json_decode(Tools::getValue('data'), true));

                break;

            case 'getModels':
                $this->getModels();

                break;

            default:
                $this->ajaxDie(
                    [
                        'error' => true,
                        'message' => 'Unsupported action ' . $action . '.'
                    ]
                );

                break;
        }
    }

    /**
     * Obtain a widget form token from the Lyra REST API.
     *
     * @param  array $formConfiguration  Decoded widget shop configuration object
     *                                   containing `shopId`, `privateKey`, and `wsData`.
     *
     * @return void
     */
    private function getWidgetToken($model)
    {
        $params = [
            "amount" => $model['wsData']['amount'],
            "currency" => $model['wsData']['currency'],
            "orderId" => "myOrderId-999999",
            "formAction" => $model['wsData']['formAction'],
            "customer" => [
                'reference' => $model['wsData']['customer']['reference'],
                "email" => "sample@example.com"
            ],
            "contrib" => LyraTools::getContrib()
        ];

        $whiteLabel = $model['payzenWhiteLabel'] ?? null;

        try {
            $client = new LyraRest(
                LyraTools::getRestUrl($whiteLabel),
                $model['shopId'],
                $model['privateKey']
            );

            $result = $client->post('V4/Charge/CreatePayment', json_encode($params));

            LyraTools::getLogger()->logInfo("Widget form token created successfully with data: " . json_encode($params) . ".");

            $this->ajaxDie(
                [
                    'token' => json_encode($result),
                    'message' => 'Widget form token created successfully.'
                ]
            );
        } catch(Exception $e) {
            LyraTools::getLogger()->logError('An error occurred while creating widget form token: ' . $e->getMessage());

            $this->ajaxDie(
                [
                    'error' => true,
                    'token' => '',
                    'message' => $e->getMessage()
                ]
            );
        }
    }

    /**
     * Saves payment form configuration models to database.
     *
     * @param  array $models  Decoded JSON payload containing models.
     *
     * @return void
     */
    private function saveModels($models)
    {
        if (! Configuration::updateValue('LYRA_EMBEDDED_FORM_CONFIG_MODELS', json_encode($models)) ) {
            LyraTools::getLogger()->logError("An error occurred while saving models.");

            $this->ajaxDie(
                [
                    'error' => true,
                    'message' => 'An error occurred while saving models.'
                ]
            );
        }

        $activeModel = LyraRestHelper::getActiveFormConfig();
        if (! Configuration::updateValue('LYRA_EMBEDDED_SHOP_MODE', $activeModel['shopMode'] ?? 'test') ) {
            LyraTools::getLogger()->logError("An error occurred while updating shop mode.");
        }

        LyraTools::getLogger()->logInfo("Models saved successfully.");

        $this->ajaxDie(
            [
                'success' => true,
                'message' => 'Models saved successfully.'
            ]
        );
    }

    private function getModels()
    {
        $models = Configuration::get('LYRA_EMBEDDED_FORM_CONFIG_MODELS');
        if ($models) {
            LyraTools::getLogger()->logInfo("Models retrieved successfully.");

            $this->ajaxDie(
                [
                    'models' => $models,
                    'message' => 'Models retrieved successfully.'
                ]
            );
        }

        LyraTools::getLogger()->logInfo("No models were found.");

        $this->ajaxDie(
            [
                'models' => '',
                'message' => 'No models found.'
            ]
        );
    }

    protected function ajaxDie($value = null, $controller = null, $method = null)
    {
        echo json_encode($value);
        exit;
    }
}