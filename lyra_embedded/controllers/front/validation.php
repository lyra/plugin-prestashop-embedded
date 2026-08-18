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

class Lyra_EmbeddedValidationModuleFrontController extends ModuleFrontController
{
    /**
     * Handle the Instant Payment Notification (IPN) sent by the payment gateway server-to-server.
     * Validates the request authenticity, resolves the order state based on the payment result
     * and updates the order accordingly. Terminates with an OK/KO plain-text response expected
     * by the gateway to acknowledge the notification.
     *
     * @see FrontController::postProcess()
     * @return void
     */
    public function postProcess()
    {
        if (! LyraRestHelper::checkRestResponseValidity()) {
            LyraTools::getLogger()->logError('Invalid IPN request received. Content: ' . print_r($_POST, true));
            die('KO-Invalid IPN request received.');
        }

        // Use $_POST content to avoid stipslashes from JSON data.
        $data = $_POST;

        // Ignore IPN response on webservice operations.
        if (isset($data['kr-src']) && ($data['kr-src'] === 'WEBSERVICES')) {
            LyraTools::getLogger()->logInfo("Notification from webservice operation, IPN will be ignored.");
            die('OK-Notification from webservice operation, IPN ignored.');
        }

        $ipnSrc = "[IPN call" . (isset($data['kr-src']) && $data['kr-src'] ? " | src=" . $data['kr-src'] : "") . "][IP: {" . Tools::getRemoteAddr() . "}]";

        $answer = json_decode($data['kr-answer'], true);
        if (! is_array($answer)) {
            LyraTools::getLogger()->logError("{$ipnSrc} Invalid IPN response received. Content of kr-answer: " . $data['kr-answer']);
            die('KO-Invalid IPN response received.');
        }

        $orderCycle = $answer['orderCycle'] ?? '';

        // Ignore IPN response with abandoned/expired status if order cycle is not closed.
        if (LyraRestHelper::isAbandonedPayment($answer['orderStatus']) && ($orderCycle !== 'CLOSED')) {
            LyraTools::getLogger()->logWarning("{$ipnSrc} Abandoned or Expired payment, IPN will be ignored.");
            die('KO-Payment abandoned or expired.');
        }

        $paymentResult = LyraRestHelper::paymentResult($answer);

        $orderId = $answer['orderDetails']['orderId'] ?? null;
        if (! $orderId) {
            if (isset($paymentResult['operationType']) && ($paymentResult['operationType'] === 'VERIFICATION')) {
                LyraTools::getLogger()->logInfo("{$ipnSrc} Verification transaction, IPN will be ignored.");
                die('OK-Verification transaction, IPN ignored.');
            } else {
                LyraTools::getLogger()->logError("{$ipnSrc} No order ID.");
                die('KO-No order ID was received in response.');
            }
        }

        $order = new Order($orderId);
        if (! $order || ! $order->id) {
            LyraTools::getLogger()->logError("{$ipnSrc} Order #$orderId not found.");
            die('KO-Order not found.');
        }

        // Rebuild context.
        try {
            LyraTools::rebuildShopContext($order);
        } catch (Exception $e) {
            LyraTools::getLogger()->logError($e->getMessage() . ' order #' . $orderId);

            die('KO-' . $e->getMessage());
        }

        // Check the authenticity of the request.
        if (! LyraRestHelper::checkHash($data, true)) {
            LyraTools::getLogger()->logError("{$ipnSrc} Attempt access module/lyra_embedded/validation page without valid signature with parameters: " . print_r($data, true));

            die('An error occurred while computing the signature.');
        }

        $transUuid = $paymentResult['uuid'] ?? 'N/A';
        $transStatus = $paymentResult['status'] ?? $paymentResult['orderStatus'] ?? 'N/A';

        LyraTools::getLogger()->logInfo("{$ipnSrc} Process starts for order #$orderId. Payment status for transaction with UUID #$transUuid is {$transStatus}.");

        $currentStateData = $order->getCurrentStateFull(1);
        $currentState = is_array($currentStateData) ? $currentStateData['id_order_state'] : $order->getCurrentState();
        $currentStateName = is_array($currentStateData) ? $currentStateData['name'] : '';

        LyraTools::getLogger()->logInfo("{$ipnSrc} The current state for order #$orderId is {$currentStateName} ({$currentState}).");

        if ($currentState == Configuration::get('LYRA_EMBEDDED_OS_WAITING_PAYMENT')) { // Order not processed yet.
            if (LyraApi::isAcceptedPayment($transStatus)) {
                $msg = (LyraApi::isPendingPayment($transStatus)) ? 'Pending payment' : 'Accepted payment';
                LyraTools::getLogger()->logInfo("{$ipnSrc} {$msg} for order #$orderId, let's update order state.");
                $this->module->setOrderState($paymentResult, $order);
                $this->module->savePayment($order, $paymentResult);

                die('OK-' . $msg . ' , order has been updated successfully.');
            } elseif ($orderCycle !== 'CLOSED') { // Ignore IPN on failed payment attempts if buyer can try to re-order.
                LyraTools::getLogger()->logWarning("{$ipnSrc} Payment is not accepted but buyer can try to re-order. Do not process order #{$orderId} at this time.");

                die('KO-Payment failure but the buyer can retry.');
            } else {
                LyraTools::getLogger()->logInfo("{$ipnSrc} Payment abandoned or failed for order #$orderId, let's update order state.");
                $this->module->setOrderState($paymentResult, $order);
                if (LyraRestHelper::isAbandonedPayment($answer['orderStatus'])) {
                    die('KO-Payment abandoned, order has been cancelled.');
                } else {
                    die('KO-Payment failure, order has been updated.');
                }
            }
        } else { // Order already processed.
            if (LyraApi::isAcceptedPayment($transStatus)) {
                $msg = (LyraApi::isPendingPayment($transStatus)) ? 'Pending payment' : 'Accepted payment';
                LyraTools::getLogger()->logInfo("{$ipnSrc} {$msg} for already saved order #$orderId.");

                if (LyraTools::isSuccessState($order)) {
                    $this->module->setOrderState($paymentResult, $order);
                    $this->module->savePayment($order, $paymentResult);

                    die('OK-' . $msg . ' , order has been updated successfully.');
                }  elseif (in_array($currentState, [Configuration::get('PS_OS_ERROR'), Configuration::get('PS_OS_CANCELLED')])) {
                    // Error case, the payment result and the order status do not match.
                    $msg = "Invalid payment result received for already saved order #{$order->id}.";
                    $msg .= " Payment result: {$transStatus}, order status : {$currentStateName} ({$currentState}).";
                    LyraTools::getLogger()->logError("{$ipnSrc} {$msg}");

                    die('KO-Order status does not match the payment result.');
                } else {
                    LyraTools::getLogger()->logWarning("{$ipnSrc} Unmanaged order state ID {$currentStateName} ({$currentState}) for order #$orderId. Managed by merchant.");

                    die('KO-Unmanaged order state.');
                }
            } else {
                if (LyraRestHelper::isAbandonedPayment($answer['orderStatus'])) {
                    LyraTools::getLogger()->logWarning("{$ipnSrc} Abandoned or expired payment IPN ignored for already saved order #$orderId.");

                    die('KO-Payment abandoned or expired.');
                }

                if (LyraRestHelper::isCancelledPayment($paymentResult)) {
                    LyraTools::getLogger()->logInfo("{$ipnSrc} Payment cancellation for already saved order #$orderId.");
                    $this->module->setOrderState($paymentResult, $order);
                    $this->module->savePayment($order, $paymentResult);

                    die('KO-Payment failure, order has been cancelled.');
                }

                if (in_array($currentState, [Configuration::get('PS_OS_ERROR'), Configuration::get('PS_OS_CANCELLED')])) {
                    LyraTools::getLogger()->logInfo("{$ipnSrc} Payment failure confirmed for already saved order #$orderId.");

                    die('KO-Payment failure, already registered.');
                } elseif (LyraTools::isSuccessState($order)) {
                    LyraTools::getLogger()->logInfo("{$ipnSrc} Payment failed for already saved order #$orderId.");
                    $this->module->setOrderState($paymentResult, $order);
                    $this->module->savePayment($order, $paymentResult);

                    die('KO-Payment failure.');
                } else {
                    LyraTools::getLogger()->logWarning("{$ipnSrc} Unmanaged order state ID {$currentStateName} ({$currentState}) for order #$orderId. Managed by merchant.");

                    die('KO-Unmanaged order state.');
                }
            }
        }
    }
}