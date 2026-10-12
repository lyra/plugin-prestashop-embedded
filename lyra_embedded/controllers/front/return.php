<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

use Lyranetwork\LyraEmbedded\Utility\Tools as LyraTools;
use Lyranetwork\LyraEmbedded\Utility\RestHelper as LyraRestHelper;
use Lyranetwork\LyraEmbedded\Sdk\Form\Api as LyraApi;

class Lyra_EmbeddedReturnModuleFrontController extends ModuleFrontController
{
    /**
     * Handle the payment gateway return (browser redirection after payment).
     * Validates the response signature, resolves the order state and redirects
     * the customer to the appropriate confirmation or history page.
     *
     * @see FrontController::postProcess()
     * @return void
     */
    public function postProcess()
    {
        LyraTools::getLogger()->logInfo("User return to shop process starts.");

        if (! LyraRestHelper::checkRestResponseValidity()) {
            LyraTools::getLogger()->logError('Invalid return request received, redirect to home page. Content: ' . print_r($_POST, true));

            Tools::redirect('index.php');
        }

        // Use $_POST content to avoid stipslashes from JSON data.
        $data = $_POST;

        // Check the authenticity of the request.
        if (! LyraRestHelper::checkHash($data, false)) {
            $ip = Tools::getRemoteAddr();
            LyraTools::getLogger()->logError("{$ip} tries to access module/lyra_embedded/return page without valid signature with parameters: " . print_r($data, true));

            Tools::redirect('index.php');
        }

        $answer = json_decode($data['kr-answer'], true);
        if (! is_array($answer)) {
            LyraTools::getLogger()->logError('Invalid return request received, redirect to home page. Content of kr-answer: ' . $data['kr-answer']);

            Tools::redirect('index.php');
        }

        $transactions = $answer['transactions'] ?? [];
        if (! is_array($transactions) || empty ($transactions)) {
            LyraTools::getLogger()->logError('No transactions found in payment response.');

            Tools::redirect('index.php');
        }

        $metadata = $transactions[0]['metadata'] ?? null;
        if ($metadata && isset($metadata['fromAccount'])) {
            if (isset($transactions[0]['paymentMethodToken'])) {
                $this->context->cookie->lyraPaymentMethodTokenCreationSuccess = $this->module->l('Payment means successfully added.', 'wallet');
            } else {
                $this->context->cookie->lyraPaymentMethodTokenCreationError = $this->module->l('Unable to add payment means to your account.', 'wallet');
            }

            Tools::redirect(Context::getContext()->link->getModuleLink($this->module->name, 'wallet', [], true));
        }

        $orderId = $answer['orderDetails']['orderId'] ?? null;
        if (! $orderId) {
            LyraTools::getLogger()->logError('No order ID was received in response.');

            Tools::redirect('index.php');
        }

        $order = new Order($orderId);
        if (! $order || ! $order->id) {
            LyraTools::getLogger()->logError("Order #$orderId not found.");

            Tools::redirect('index.php');
        }

        // Remove cart from session
        unset($this->context->cookie->id_cart);

        $paymentResult = LyraRestHelper::paymentResult($answer);
        $transUuid = $paymentResult['uuid'];
        $transStatus = $paymentResult['status'];

        LyraTools::getLogger()->logInfo("Payment status for transaction with UUID #$transUuid is {$transStatus}.");

        $currentStateData = $order->getCurrentStateFull(1);
        $currentState = is_array($currentStateData) ? $currentStateData['id_order_state'] : $order->getCurrentState();
        $currentStateName = is_array($currentStateData) ? $currentStateData['name'] : '';

        LyraTools::getLogger()->logInfo("The current state for order #$orderId is {$currentStateName} ({$currentState}).");

        $isProcessed = $currentState != Configuration::get('LYRA_EMBEDDED_OS_WAITING_PAYMENT');
        if (LyraApi::isAcceptedPayment($transStatus)) {
            $msg = (LyraApi::isPendingPayment($transStatus)) ? 'Pending payment' : 'Accepted payment';
            LyraTools::getLogger()->logInfo("{$msg} for order #$orderId");
            if (! $isProcessed) {
                LyraTools::getLogger()->logInfo("Order #$orderId not processed yet, let's update order state.");
                $this->module->setOrderState($paymentResult, $order);
                $this->module->savePayment($order, $paymentResult);
                LyraTools::getLogger()->logInfo("Order #$orderId state updated successfully.");
            }

            // Redirect to order confirmation page..
            $this->redirectSuccess($order);
        } else {
            LyraTools::getLogger()->logInfo("Payment failed for order #$orderId.");
            if (! $isProcessed) {
                LyraTools::getLogger()->logInfo("Order #$orderId not processed yet, let's update order state.");
                $this->module->setOrderState($paymentResult, $order);
                LyraTools::getLogger()->logInfo("Order #$orderId state updated successfully.");
            }

            $customer = $this->context->customer ?? null;
            if ($customer && $customer->isLogged()) {
                LyraTools::getLogger()->logInfo("The customer is logged in, let's redirct them to order history page.");

                Tools::redirect('index.php?controller=history');
            } else {
                LyraTools::getLogger()->logInfo("The customer is not logged in, let's redirect them to cart page.");

                $cart = new Cart($order->id_cart);
                $duplicate = $cart->duplicate();
                $this->context->cookie->id_cart = $duplicate['cart']->id;

                $this->context->controller->warning[] = $this->getTranslator()->trans('Your payment was not accepted. Please, try to re-order.', [],
                    'Modules.Lyra_Embedded.Cart'
                );

                $this->context->controller->redirectWithNotifications('index.php?controller=cart');
            }
        }
    }

    /**
     * Redirect the customer to the order confirmation page.
     *
     * @param \Order $order The successfully placed order.
     * @return void
     */
    private function redirectSuccess($order)
    {
        $link = 'index.php?controller=order-confirmation&id_cart=' . $order->id_cart . '&id_module=' . $this->module->id .
            '&id_order=' . $order->id . '&key=' . $order->secure_key;

        Tools::redirect($link);
    }
}