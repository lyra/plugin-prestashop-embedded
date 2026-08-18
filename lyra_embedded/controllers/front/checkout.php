<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

use Lyranetwork\LyraEmbedded\Sdk\Rest\Api as LyraRest;
use Lyranetwork\LyraEmbedded\Payment\PaymentHandler as PaymentHandler;
use Lyranetwork\LyraEmbedded\Utility\Tools as LyraTools;

class Lyra_EmbeddedCheckoutModuleFrontController extends ModuleFrontController
{
    /**
     * Handle POST actions dispatched from the front-end payment form.
     * Supported actions: refreshToken, restoreCart.
     *
     * @see FrontController::postProcess()
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
            case 'refreshToken':
                if (! $this->context->cookie->id_cart) {
                    $this->ajaxDie(
                        [
                            'error' => true,
                            'message' => 'No cart found.'
                        ]
                    );
                }

                $this->getToken();

                break;

            case 'restoreCart':
                if (! empty($this->context->cookie->id_cart)) {
                    $this->ajaxDie(
                        [
                            'success' => true,
                            'message' => 'Context already contains a cart.'
                        ]
                    );
                }

                $cartId = Tools::getValue('data');
                $cart = new Cart($cartId);
                $duplicate = $cart->duplicate();
                $this->context->cookie->id_cart = $duplicate['cart']->id;

                $this->ajaxDie(
                    [
                        'success' => true,
                        'message' => 'Cart restored successfully.'
                    ]
                );

            default:
                $this->ajaxDie(
                    [
                        'error' => true,
                        'message' => 'Invalid action.'
                    ]
                );

                break;
        }
    }

    /**
     * Create a PrestaShop order from the current cart and retrieve a new payment form token.
     * Terminates the request with a JSON response.
     *
     * @return void
     */
    private function getToken()
    {
        $cart = $this->context->cart;

        LyraTools::getLogger()->logInfo("Payment has been submitted, let's create order for cart #$cart->id.");

        $payment = new PaymentHandler();
        $order = $payment->createOrder($cart);

        $token = $order ? $payment->getOrderToken($order) : null;
        if (! empty($token)) {
            $json = [
                'token' => $token,
                'lastCart' => $cart->id
            ];
        } else {
            $json = [
                'status' => 'error',
                'message' => 'Error when creating form token.'
            ];
        }

        $this->ajaxDie($json);
    }

    /**
     * Encode the given value as JSON, output it and terminate the script.
     *
     * @param mixed       $value      The data to JSON-encode and output.
     * @param string|null $controller Unused — kept for signature compatibility.
     * @param string|null $method     Unused — kept for signature compatibility.
     * @return void
     */
    protected function ajaxDie($value = null, $controller = null, $method = null)
    {
        echo $this->jsonEncode($value);
        exit;
    }

    /**
     * JSON-encode a value using the PrestaShop helper when available, falling back to native json_encode.
     *
     * @param mixed $parameter The value to encode.
     * @return string
     */
    private function jsonEncode($parameter)
    {
        if (method_exists('Tools', 'jsonEncode')) {
            return Tools::jsonEncode($parameter);
        }

        return json_encode($parameter);
    }
}