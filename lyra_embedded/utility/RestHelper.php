<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

namespace Lyranetwork\LyraEmbedded\Utility;

if (! defined('_PS_VERSION_')) {
    exit;
}

use Lyranetwork\LyraEmbedded\Sdk\Rest\Api as LyraRest;
use Lyranetwork\LyraEmbedded\Sdk\Form\Api as LyraApi;
use Lyranetwork\LyraEmbedded\Utility\Tools as LyraTools;

class RestHelper
{
    public static $abandonedStatuses = [
        'ABANDONED',
        'EXPIRED'
    ];

    /**
     * Request a payment form token from the gateway REST API.
     * Logs the operation and stores any error message in the session cookie
     * for display to the merchant.
     *
     * @param array<string, mixed> $data       API request payload.
     * @param string               $webService REST endpoint suffix (e.g. "CreatePayment", "CreateToken").
     * @param string               $metadata   Human-readable label used in log messages.
     * @return string The form token string, or an empty string on failure.
     */
    public static function getFormToken($data, $webService, $metadata)
    {
        $token = '';

        $params = json_encode($data);
        LyraTools::getLogger()->logInfo("Creating form token for $metadata with parameters: $params");

        try {
            $client = new LyraRest(
                LyraTools::getRestUrl(),
                self::getShopId(),
                self::getPrivateKey()
            );

            $result = $client->post('V4/Charge/'. $webService, $params);

            // Check if token was created successfully else throw exception.
            self::checkRestResult($result);

            LyraTools::getLogger()->logInfo("Form token created successfully for {$metadata}");

            $token = $result['answer']['formToken'];
        } catch (\Exception $e) {
            LyraTools::getLogger()->logError("{$e->getMessage() }" . ($e->getCode() > 0 ? ' (' . $e->getCode() . ').' : ''));

            \Context::getContext()->cookie->lyraCreateTokenError = $e->getMessage();
        }

        return $token;
    }

    /**
     * Validate a REST API response returned by the gateway.
     *
     * @param  array    $response          Decoded response array from the REST client.
     * @param  string[] $expectedStatuses  Optional whitelist of accepted `detailedStatus` values.
     *
     * @return void
     *
     * @throws \Exception When the gateway reports an error or `detailedStatus` is outside the expected set.
     */
    public static function checkRestResult($response, $expectedStatuses = [])
    {
        $answer = $response['answer'];

        if ($response['status'] !== 'SUCCESS') {
            $errorMessage = $answer['errorMessage'] . ' (' . $answer['errorCode'] . ').';

            if (isset($answer['detailedErrorMessage']) && ! empty($answer['detailedErrorMessage'])) {
                $errorMessage .= ' Detailed message: ' . $answer['detailedErrorMessage']
                    . ' (' . $answer['detailedErrorCode'] . ').';
            }

            throw new \Exception($errorMessage);
        } elseif (! empty($expectedStatuses) && ! in_array($answer['detailedStatus'], $expectedStatuses)) {
            throw new \Exception('Unexpected transaction type received (' . $answer['detailedStatus'] . ')');
        }
    }

    /**
     * Check whether the current request contains the mandatory REST response fields.
     *
     * @return bool True when kr-hash, kr-hash-algorithm and kr-answer are all present.
     */
    public static function checkRestResponseValidity()
    {
        return \Tools::getIsset('kr-hash') && \Tools::getIsset('kr-hash-algorithm') && \Tools::getIsset('kr-answer');
    }

    /**
     * Verify the HMAC-SHA256 signature of a gateway response.
     *
     * @param array<string, string> $data  POST data containing kr-answer, kr-hash and kr-hash-algorithm.
     * @param bool                  $isIpn True when validating a server-to-server IPN (uses private key),
     *                                     false for a browser return (uses SHA key).
     * @return bool True when the computed hash matches the received hash.
     */
    public static function checkHash($data, $isIpn = true)
    {
        $supportedSignAlgos = ['sha256_hmac'];

        // Check if the hash algorithm is supported.
        if (! in_array($data['kr-hash-algorithm'], $supportedSignAlgos)) {
            LyraTools::getLogger()->logError('Hash algorithm is not supported: ' . \Tools::getValue('kr-hash-algorithm'));

            return false;
        }

        $krAnswer = str_replace('\/', '/', $data['kr-answer']);
        $key = $isIpn ? self::getPrivateKey() : self::getShaKey();

        $hash = hash_hmac('sha256', $krAnswer, $key);

        return ($hash === $data['kr-hash']);
    }

    /**
     * Load and return the currently active form configuration model from PrestaShop configuration.
     * Returns false when no models are configured or the active model cannot be found.
     *
     * @return array<string, mixed>|false The active model array, or false on failure.
     */
    public static function getActiveFormConfig()
    {
        $models = \Configuration::get('LYRA_EMBEDDED_FORM_CONFIG_MODELS');
        if (empty($models)) {
            LyraTools::getLogger()->logInfo('No models were found.');

            return false;
        }

        $models = json_decode($models, true);
        if (! isset($models['activeModel']) || ! isset($models['models']) || ! is_array($models['models'])) {
            LyraTools::getLogger()->logError('Invalid configuration models structure.');

            return false;
        }

        foreach ($models['models'] as $model) {
            if ($model['id'] === $models['activeModel']) {
                return $model;
            }
        }

        return false;
    }

    /**
     * Extract a normalised payment result array from a gateway answer payload.
     * Handles both single-transaction and multi-payment responses.
     *
     * @param array<string, mixed> $answer Decoded gateway answer (from kr-answer).
     * @return array<string, mixed> Normalised payment result including uuid, status, amount,
     *                              currency, card details, and optionally a paymentSequence.
     */
    public static function paymentResult($answer): array
    {
        $multiPayment = false;

        if (isset($answer['transactions'])) {
            $transactions = $answer['transactions'];
            $transaction = $transactions[0];
            if (count($transactions) > 1) {
                $multiPayment = isset($transaction['transactionDetails']['cardDetails']['sequenceType'])
                    && $transaction['transactionDetails']['cardDetails']['sequenceType'] == 'MULTI_PAYMENT_MEAN';
            }
        } else {
            $transaction = $answer;
        }

        $result = self::getTransactionData($transaction);

        if ($multiPayment) {
            $paymentSeq = [
                'transactions' => []
            ];

            foreach ($transactions as $trs) {
                $paymentSeq['transactions'][] = self::getTransactionData($trs);
            }

            $result['cardBrand'] = 'MULTI';
            $result['paymentSequence'] = json_encode($paymentSeq);
            $result['amount'] = $answer['orderDetails']['orderTotalAmount'];
        }

        $result['orderStatus'] = isset($answer['orderStatus']) ? $answer['orderStatus'] : '';

        return $result;
    }

    /**
     * Determine whether a payment result represents a cancelled transaction.
     * For multi-payment results, the check is applied across all individual transactions.
     *
     * @param array<string, mixed> $paymentResult Normalised payment result from {@see paymentResult()}.
     * @param bool                 $strict        When true (default), all transactions must be cancelled.
     *                                            When false, at least one cancelled transaction suffices.
     * @return bool
     */
    public static function isCancelledPayment($paymentResult, $strict = true)
    {
        if (isset($paymentResult['cardBrand']) && $paymentResult['cardBrand'] === 'MULTI' && isset($paymentResult['paymentSequence'])) {
            $paymentSequence = json_decode($paymentResult['paymentSequence'], true);
            if (! is_array($paymentSequence) || ! isset($paymentSequence['transactions']) || ! is_array($paymentSequence['transactions'])) {
                return false;
            }

            $transactions = $paymentSequence['transactions'];
            if ($strict) {
                return self::countCancelledTransactions($transactions) === count($transactions);
            }

            return self::countCancelledTransactions($transactions) > 0;
        }

        return LyraApi::isCancelledPayment($paymentResult['status']);
    }

    /**
     * Check whether a payment status represents an abandoned or expired payment.
     *
     * @param string $status Gateway order or transaction status.
     * @return bool
     */
    public static function isAbandonedPayment($status)
    {
        return in_array($status, self::$abandonedStatuses);
    }

    /**
     * Return the embedded form theme name from the active model configuration,
     * falling back to the module default theme.
     *
     * @return string
     */
    public static function getTheme()
    {
        $activeModel = self::getActiveFormConfig();
        $defaultTheme = LyraTools::getDefault('THEME');

        return $activeModel != null ? $activeModel['inteConfig']['theme']['name'] ?? $defaultTheme : $defaultTheme;
    }

    /**
     * Return the shop ID from the active form configuration model.
     *
     * @return string The shop ID, or an empty string when not configured.
     */
    public static function getShopId()
    {
        $activeModel = self::getActiveFormConfig();

        return $activeModel['shopConfig']['shopId'] ?? '';
    }

    /**
     * Return the private key for the current shop mode.
     *
     * @return string
     */
    public static function getPrivateKey()
    {
        return self::getKey('private');
    }

    /**
     * Return the public key for the current shop mode.
     *
     * @return string
     */
    public static function getPublicKey()
    {
        return self::getKey('public');
    }

    /**
     * Return the current shop mode ("test" or "production").
     *
     * @return string
     */
    public static function getMode()
    {
        return \Configuration::get('LYRA_EMBEDDED_SHOP_MODE') ?? LyraTools::getDefault('MODE');
    }

    /**
     * Extract and normalise transaction data from a single gateway transaction array.
     *
     * @param array<string, mixed> $transaction A single transaction entry from the gateway response.
     * @return array<string, mixed> Flat array with keys: uuid, status, amount, currency, operationType,
     *                              effectiveAmount, effectiveCurrency, changeRate, sequenceNumber,
     *                              transId, cardBrand, cardNumber, expiryMonth, expiryYear, cardHolder,
     *                              and optionally wallet.
     */
    private static function getTransactionData($transaction): array
    {
        $result = [
            'uuid' => $transaction['uuid'] ?? '',
            'status' => $transaction['detailedStatus'] ?? '',
            'amount' => $transaction['amount'] ?? '',
            'currency' => $transaction['currency'] ?? '',
            'operationType' => $transaction['operationType'] ?? ''
        ];

        if (isset($transaction['transactionDetails'])) {
            $transactionDetails = $transaction['transactionDetails'];
            $cardDetails = $transactionDetails['cardDetails'];

            $result['effectiveAmount'] = $transactionDetails['effectiveAmount'] ?? '';
            $result['effectiveCurrency'] = $transactionDetails['effectiveCurrency'] ?? '';
            $result['changeRate'] = $transactionDetails['changeRate'] ?? '';
            $result['sequenceNumber'] = $transactionDetails['sequenceNumber'] ?? '1';
            $result['transId'] = $cardDetails['legacyTransId'] ?? '';
            $result['cardBrand'] = $cardDetails['effectiveBrand'] ?? '';
            $result['cardNumber'] = $cardDetails['pan'] ?? '';
            $result['expiryMonth'] = $cardDetails['expiryMonth'] ?? '';
            $result['expiryYear'] = $cardDetails['expiryYear'] ?? '';
            $result['cardHolder'] = $cardDetails['cardHolderName'] ?? '';

            if (isset($transactionDetails['wallet'])) {
                $result['wallet'] = $transactionDetails['wallet'];
            }
        }

        return $result;
    }

    /**
     * Return the HMAC-SHA256 key used to verify browser-return responses.
     *
     * @return string
     */
    private static function getShaKey()
    {
        return self::getKey('sha');
    }

    private static function getKey(string $type): string
    {
        $activeModel = self::getActiveFormConfig();
        $mode = self::getMode();

        return $activeModel['shopConfig']['keys'][$mode][$type . 'Key'] ?? '';
    }

    /**
     * Count the number of cancelled transactions in a flat transactions array.
     *
     * @param array<int, array<string, mixed>> $transactions List of normalised transaction arrays.
     * @return int
     */
    private static function countCancelledTransactions(array $transactions)
    {
        return count(array_filter(
            array_column($transactions, 'status'),
            [LyraApi::class, 'isCancelledPayment']
        ));
    }
}