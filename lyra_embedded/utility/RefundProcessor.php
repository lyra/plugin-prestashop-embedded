<?php
/**
 * Copyright © Lyra Network and contributors.
 * This file is part of Lyra plugin for Prestashop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network and contributors
 * @license   http://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU General Public License (GPL v2)
 */

namespace Lyranetwork\LyraEmbedded\Utility;

use Lyranetwork\LyraEmbedded\Utility\Tools as LyraTools;
use Lyranetwork\LyraEmbedded\Utility\RestHelper as LyraRestHelper;

if (! defined('_PS_VERSION_')) {
    exit;
}

class RefundProcessor implements \Lyranetwork\LyraEmbedded\Sdk\Refund\Processor
{
    /** @var \Lyra_Embedded */
    protected $module;

    /** @var \Context */
    protected $context;

    /**
     * Initialise the processor with the module instance and the current PrestaShop context.
     */
    public function __construct()
    {
        $this->module = new \Lyra_Embedded();
        $this->context = \Context::getContext();
    }

    /**
     * Handle a recoverable refund error by allowing the merchant to process the refund
     * offline and storing a warning message in the session cookie.
     *
     * @param string $errorCode Gateway error code.
     * @param string $message   Human-readable error description.
     * @return void
     */
    public function doOnError($errorCode, $message)
    {
        // Allow offline refund and display warning message.
        $this->context->cookie->lyraEmbeddedRefundWarn = $message;
    }

    /**
     * Action to do after a successful refund process.
     * Updates the PrestaShop order state and saves the refund transaction according
     * to the operation type (cancel, refund, partial refund, split-payment update).
     *
     * @param array<string, mixed> $operationResponse Gateway operation response payload.
     * @param string               $operationType     Operation type (e.g. "cancel", "refund", "frac_update").
     * @return void
     * @throws \Exception When a split-payment partial refund is attempted.
     */
    public function doOnSuccess($operationResponse, $operationType)
    {
        // Retrieve Order from its Id.
        $orderId = isset($operationResponse['orderDetails']['orderId']) ? $operationResponse['orderDetails']['orderId'] : null;
        if (! $orderId) {
            return;
        }

        $order = new \Order($orderId);
        if (! $order || ! $order->id) {
            return;
        }

        $whiteLabel = LyraTools::getWhiteLabel();

        // Retrieve order currency.
        $orderCurrency = new \Currency($order->id_currency);
        $currency = \Lyranetwork\LyraEmbedded\Sdk\Form\Api::findCurrencyByAlphaCode($orderCurrency->iso_code, $whiteLabel);

        // Total amount paid by the client.
        $orderAmount = \Tools::ps_round($order->total_paid, $currency->getDecimals());
        $orderAmountInCents = $currency->convertAmountToInteger($orderAmount);

        $orderRefundedAmount = 0;

        foreach ($order->getOrderSlipsCollection() as $orderSlip) {
            $orderRefundedAmount += $orderSlip->total_products_tax_incl + $orderSlip->total_shipping_tax_incl;
        }

        $orderRefundedAmountInCents = $currency->convertAmountToInteger($orderRefundedAmount);

        // Sum of refund request amount and amount already refunded in PrestaShop.
        $refundAmount = $operationResponse['amount'] + $orderRefundedAmountInCents;

        // Amount refunded on the Back Office.
        $transRefundedAmount = 0;
        if (isset($operationResponse['refundedAmount']) && $operationResponse['refundedAmount']) {
            $transRefundedAmount = $operationResponse['refundedAmount'];
        }

        if (isset($operationResponse['refundedAmountMulti']) && $operationResponse['refundedAmountMulti']) {
            $refundAmount = $operationResponse['refundedAmountMulti'] + $orderRefundedAmountInCents;
        }

        switch ($operationType) {
            case 'frac_update':
                if ($transRefundedAmount == $refundAmount && $refundAmount == $orderAmountInCents) {
                    $this->context->cookie->lyraSplitPaymentUpdateRefundStatus = "True";
                    $order->setCurrentState(\Configuration::get('PS_OS_REFUND'));
                } elseif(! ($transRefundedAmount == $refundAmount && $transRefundedAmount < $orderAmountInCents)) {
                    $msg = sprintf($this->translate('Refund of split payment is not supported. Please, consider making necessary changes in %1$s Back Office.'), LyraTools::getDefault("BACKOFFICE_NAME"));

                    throw new \Exception($msg);
                }

                return;

            case 'already_cancel':
                $this->context->cookie->lyraEmbeddedRefundWarn = $this->translate('Transaction already cancelled on payment gateway.');
                $operationType = 'cancel';

                break;

            case 'already_refund':
                $this->context->cookie->lyraEmbeddedRefundWarn = $this->translate('Transaction already refunded on payment gateway.');
                $operationType = 'refund';

                break;

            default:
                break;
        }

        // Save refund transaction in PrestaShop.
        $paymentResult = LyraRestHelper::paymentResult($operationResponse);
        if ($paymentResult['operationType'] !== 'CREDIT') {
            $this->module->savePayment($order, $paymentResult);
            $this->module->setOrderState($paymentResult, $order);
        } else {
            $oldStateId = $order->getCurrentState();
            $newState = ($refundAmount < $orderAmountInCents) ? 'LYRA_EMBEDDED_OS_PARTIAL_REFUND' : 'PS_OS_REFUND';
            $newStateId = \Configuration::get($newState);

            if ($oldStateId == $newStateId) {
                LyraTools::getLogger()->logInfo("No state change for order #{$order->id}, order remains in state {$newState} ({$newStateId}).");

                return;
            }

            $order->setCurrentState($newStateId);

            LyraTools::getLogger()->logInfo("Order state {$newState} ({$newStateId}) set successfully for order #{$order->id}.");
        }
    }

    /**
     * Handle a fatal refund failure by storing a warning message in the session cookie
     * and delegating to {@see doOnError()}.
     *
     * @param string $errorCode Gateway error code.
     * @param string $message   Human-readable error description.
     * @return void
     */
    public function doOnFailure($errorCode, $message)
    {
        $this->context->cookie->lyraEmbeddedRefundWarn = $message;

        $this->doOnError($errorCode, $message);
    }

    /**
     * Write a message to the module logger at the given severity level.
     *
     * @param string $message Log message.
     * @param string $level   Severity level: "ERROR", "WARNING", "INFO", or any other value
     *                        for the default log level.
     * @return void
     */
    public function log($message, $level)
    {
        switch ($level) {
            case "ERROR":
                LyraTools::getLogger()->logError($message);

                break;

            case "WARNING":
                LyraTools::getLogger()->logWarning($message);

                break;

            case "INFO":
                LyraTools::getLogger()->logInfo($message);

                break;

            default:
                LyraTools::getLogger()->log($message);

                break;
        }
    }

    /**
     * Translate a message using the module's localisation helper.
     *
     * @param string $message The string to translate.
     * @return string The localised string.
     */
    public function translate($message)
    {
        return $this->module->l($message);
    }
}