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

require_once(_PS_MODULE_DIR_ . 'lyra_embedded/autoload.php');
require_once(_PS_MODULE_DIR_ . 'lyra_embedded/sdk/sdk-autoload.php');

use Lyranetwork\LyraEmbedded\Sdk\Form\Api as LyraApi;
use Lyranetwork\LyraEmbedded\Payment\PaymentHandler as PaymentHandler;
use Lyranetwork\LyraEmbedded\Utility\Tools as LyraTools;
use Lyranetwork\LyraEmbedded\Utility\RestHelper as LyraRestHelper;
use Lyranetwork\LyraEmbedded\Utility\RefundProcessor as LyraRefundProcessor;

/**
 * Lyra Embedded payment module for PrestaShop 1.7 – 9.x.
 *
 * Integrates the Lyra Collect payment gateway into PrestaShop via an
 * embedded payment form.
 *
 * This module is the main entry point that PrestaShop loads when the
 * module is installed, configured, or invoked during checkout.
 */
class Lyra_Embedded extends PaymentModule
{
    // Supported PHP version (7.0.0 = 70000).
    const SUPPORTED_PHP_VERSION = '70000';

    /**
     * Constructor.
     *
     * Initialises all PrestaShop module metadata, registers the controllers,
     * checks version compatibility, and calls the parent constructor so that
     * PrestaShop completes the module setup.
     */
    public function __construct()
    {
        $this->name = 'lyra_embedded';
        $this->tab = 'payments_gateways';
        $this->version = LyraTools::getDefault('PLUGIN_VERSION');
        $this->author = 'Lyra Network';
        $this->need_instance = 1;
        $this->bootstrap = true;
        $this->controllers = ['checkout', 'wallet', 'return', 'validation'];

        if (property_exists($this, 'is_eu_compatible')) {
            $this->is_eu_compatible = 1;
        }

        parent::__construct();

        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
        $this->displayName = 'Lyra Embedded';
        $this->description = $this->l('Accept payments by credit cards and more');
        $this->confirmUninstall = $this->l('Are you sure you want to delete your module details?');
    }

    /**
     * Install Lyra payment module.
     *
     * Registers all required hooks and delegates the actual installation to the parent.
     *
     * @return bool  `true` when all hooks were registered successfully,
     *               `false` on a critical failure.
     */
    public function install()
    {
        if (version_compare(_PS_VERSION_, '1.7', '<')) {
            $this->_errors[] = $this->trans('You\'re using an unsupported PrestaShop version. Upgrade your PrestaShop version to use this module. The Lyra module supports PrestaShop 1.7 and higher.', [], 'Modules.Lyra_Embedded.configuration');

            return false;
        }

        if (self::SUPPORTED_PHP_VERSION > PHP_VERSION_ID) {
            $this->_errors[] = $this->trans('You\'re using an unsupported PHP version. Upgrade your PHP version to use this module. The Lyra module supports PHP 7.0 and higher.', [], 'Modules.Lyra_Embedded.configuration');

            return false;
        }

        if (! parent::install()) {
            $this->_errors[] = $this->trans('Unable to install module. Please check the error log for details.', [], 'Modules.Lyra_Embedded.configuration');

            return false;
        }

        $installError = false;
        if (version_compare(_PS_VERSION_, '8.0', '>=')) {
            if (! $this->registerHook('displayHeader')) {
                $installError = true;
            }
        } elseif (! $this->registerHook('header')) { // Deprecated in PS 8.x.
            $installError = true;
        }

        if (! $this->registerHook('displayCustomerAccount')
            || ! $this->registerHook('paymentOptions')
            || ! $this->registerHook('actionObjectOrderSlipAddBefore')) {
            $installError = true;
        }

        if (! Configuration::get('LYRA_EMBEDDED_OS_WAITING_PAYMENT')) {
            LyraTools::getLogger()->logInfo('Creating « Waiting for payment » customized order state.');

            // Create initial order state.
            $name = [
                'en' => 'Waiting for payment',
                'fr' => 'En attente de paiement',
                'de' => 'Warten auf Zahlung',
                'es' => 'En espera de pago',
                'br' => 'En espera de pagamento',
                'pt' => 'En espera de pagamento'
            ];

            $ppState= new OrderState();
            $ppState->name = LyraTools::convertIsoArrayToIdArray($name);
            $ppState->invoice = false;
            $ppState->send_email = false;
            $ppState->module_name = $this->name;
            $ppState->color = '#FF8C00';
            $ppState->unremovable = true;
            $ppState->hidden = false;
            $ppState->logable = false;
            $ppState->delivery = false;
            $ppState->shipped = false;
            $ppState->paid = false;

            if (! $ppState->save() || ! Configuration::updateGlobalValue('LYRA_EMBEDDED_OS_WAITING_PAYMENT', $ppState->id)) {
                LyraTools::getLogger()->logError('Error while creating customized order state « Waiting for payment ».');

                $this->_errors[] = sprintf($this->l('Error while creating customized order state « %s ».'), $this->l('Waiting for payment'));
                $installError = true;
            }
        }

        if (! Configuration::get('LYRA_EMBEDDED_OS_TO_VALIDATE')) {
            LyraTools::getLogger()->logInfo('Creating « To validate payment » customized order state.');

            // Create to validate payment order state.
            $name = [
                'en' => 'To validate payment',
                'fr' => 'Paiement à valider',
                'de' => 'Um zu überprüfen Zahlung',
                'es' => 'Para validar el pago',
                'br' => 'Pagamento para validar',
                'pt' => 'Pagamento para validar'
            ];

            $tvpState = new OrderState();
            $tvpState->name = LyraTools::convertIsoArrayToIdArray($name);
            $tvpState->invoice = false;
            $tvpState->send_email = false;
            $tvpState->module_name = $this->name;
            $tvpState->color = '#FAEF23';
            $tvpState->unremovable = true;
            $tvpState->hidden = false;
            $tvpState->logable = false;
            $tvpState->delivery = false;
            $tvpState->shipped = false;
            $tvpState->paid = false;

            if (! $tvpState->save() || ! Configuration::updateGlobalValue('LYRA_EMBEDDED_OS_TO_VALIDATE', $tvpState->id)) {
                LyraTools::getLogger()->logError('Error while creating customized order state « To validate payment ».');
                $this->_errors[] = sprintf($this->l('Error while creating customized order state « %s ».'), $this->l('To validate payment'));

                $installError = true;
            }
        }

        if (! Configuration::get('LYRA_EMBEDDED_OS_PENDING_AUTHORIZATION')) {
            LyraTools::getLogger()->logInfo('Creating « Pending authorization » customized order state.');

            // Create pending authorization order state.
            $name = [
                'en' => 'Pending payment',
                'fr' => 'Paiement en attente',
                'de' => 'Ausstehende Zahlung',
                'es' => 'Pago pendiente',
                'br' => 'Pagamento pendente',
                'pt' => 'Pagamento pendente'
            ];

            $paState = new OrderState();
            $paState->name = LyraTools::convertIsoArrayToIdArray($name);
            $paState->invoice = false;
            $paState->send_email = false;
            $paState->module_name = $this->name;
            $paState->color = '#2155FF';
            $paState->unremovable = true;
            $paState->hidden = false;
            $paState->logable = false;
            $paState->delivery = false;
            $paState->shipped = false;
            $paState->paid = false;

            if (! $paState->save() || ! Configuration::updateGlobalValue('LYRA_EMBEDDED_OS_PENDING_AUTHORIZATION', $paState->id)) {
                LyraTools::getLogger()->logError('Error while creating customized order state « Pending authorization ».');
                $this->_errors[] = sprintf($this->l('Error while creating customized order state « %s ».'), $this->l('Pending authorization'));

                $installError = true;
            }
        }

        if (! Configuration::get('LYRA_EMBEDDED_OS_PARTIAL_REFUND')) {
            LyraTools::getLogger()->logInfo('Creating « Partially refunded » customized order state.');

            // Create partial refund order state.
            $name = [
                'en' => 'Partially refunded',
                'fr' => 'Partiellement remboursée',
                'de' => 'Teilweise erstattet',
                'es' => 'Parcialmente reembolsado',
                'br' => 'Parcialmente reembolsado',
                'pt' => 'Reembolsado parcialmente'
            ];

            $prState = new OrderState();
            $prState->name = LyraTools::convertIsoArrayToIdArray($name);
            $prState->invoice = false;
            $prState->send_email = false;
            $prState->module_name = $this->name;
            $prState->color = '#7EFBAE';
            $prState->unremovable = true;
            $prState->hidden = false;
            $prState->logable = false;
            $prState->delivery = false;
            $prState->shipped = false;
            $prState->paid = false;

            if (! $prState->save() || ! Configuration::updateGlobalValue('LYRA_EMBEDDED_OS_PARTIAL_REFUND', $prState->id)) {
                LyraTools::getLogger()->logError('Error while creating customized order state « Partial refund ».');
                $this->_errors[] = sprintf($this->l('Error while creating customized order state « %s ».'), $this->l('Partial refund'));

                $installError = true;
            }
        }

        if ($installError) {
            LyraTools::getLogger()->logError('One or more hooks necessary for the module could not be saved.');

            $this->_errors[] = $this->l('One or more hooks necessary for the module could not be saved.');
        }

        return ! $installError;
    }

    /**
     * Uninstall the module.
     *
     * Removes all configuration entries from the database and calls the
     * parent uninstall.
     *
     * @return bool  `true` on success, `false` if the DB cleanup failed.
     */
    public function uninstall()
    {
        $result = Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . "configuration` WHERE `name` LIKE 'LYRA_EMBEDDED_%'  AND `name` NOT LIKE 'LYRA_EMBEDDED_OS_%'"
        );

        return $result && parent::uninstall();
    }

    /**
     * Render the module configuration page.
     * Registers required CSS/JS assets, exposes the admin controller URL to JavaScript,
     * and returns the rendered configuration template HTML.
     *
     * @return string Rendered HTML of the configuration page.
     */
    public function getContent()
    {
        $this->addCss('lyraEmbedded.css');
        $this->addJS('widget.js');

        $controllerUrl = $this->context->link->getAdminLink('LyraEmbeddedAdmin');
        Media::addJsDef(['LYRA_ADMIN_CONTROLLER_URL' => $controllerUrl]);

        $this->context->smarty->assign($this->getTemplateVars());

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/admin/configuration.tpl');
    }

     /**
     * Build the Smarty template variable array for the back-office configuration page.
     *
     * The returned array is passed directly to `Smarty::assign()` so that the
     * `configuration.tpl` template can use the values.
     *
     * @return array<string, string> Associative array of Smarty variable name => value.
     */
    private function getTemplateVars()
    {
        $widgetText = 'Configure the payment form in real time with our intuitive tool. ' .
            'Adjust colors, fonts and fields via a visual interface, and immediately preview the result on all devices. ' .
            'Simple and efficient, it speeds up validation and deployment while guaranteeing an optimal user experience.';

        $domain = Tools::usingSecureMode() ? Tools::getShopDomainSsl(true, true) : Tools::getShopDomain(true, true);

        return [
            'LYRA_EPSILON_URL' => LyraTools::getEpsilonUrl(),
            'LYRA_WIDGET_DESC' => $this->trans($widgetText, [], 'Modules.Lyra_Embedded.configuration'),
            'LYRA_BRUSH_IMG' => $domain . __PS_BASE_URI__ . basename(_PS_MODULE_DIR_) . '/' . $this->name . '/views/img/brush.png',
            'LYRA_REST_NOTIFY_URL' => LyraTools::getIpnUrl(),
            'LYRA_SUPPORT_MAIL' => LyraApi::formatSupportEmails(LyraTools::getDefault('SUPPORT_EMAIL'), $this->trans('support client', [], 'Modules.Lyra_Embedded.configuration')),
            'LYRA_PLUGIN_VERSION' => LyraTools::getDefault('PLUGIN_VERSION'),
            'LYRA_DOC_FILES' => LyraTools::getDocFiles(),
            'IS_WHITE_LABEL_ALL' => LyraTools::$pluginFeatures['whitelabelall'] ?? false,
        ];
    }

    /**
     * Payment method selection page header.
     *
     * @param array $params
     * @return string|void
     */
    public function hookHeader($params)
    {
        $controller = $this->context->controller;

        if ($controller instanceof OrderController || $controller instanceof Lyra_EmbeddedWalletModuleFrontController) {
            $html = '';

            $paymentHandler = new PaymentHandler();
            if ($paymentHandler->isAvailable($this->context->cart)) {
                $staticUrl = LyraTools::getStaticUrl() . 'js/krypton-client/V4.0';
                $pubKey = LyraRestHelper::getPublicKey();
                $restTheme = LyraRestHelper::getTheme();
                $languageIsoCode = LyraTools::getLyraSupportedLanguageIsoCode($this->context->cart->id_lang ?? null);
                $returnUrl = $this->context->link->getModuleLink($this->name, 'return', [], true);

                $html .= '<script>
                            var LYRA_LANGUAGE = "' . $languageIsoCode . '";
                          </script>';

                $html .= '<script src="' . $staticUrl . '/stable/kr-payment-form.min.js"
                              kr-public-key="' . $pubKey . '"
                              kr-post-url-success="' . $returnUrl . '"
                              kr-post-url-refused="' . $returnUrl . '"
                              kr-language="' . $languageIsoCode . '">
                          </script>' . "\n";

                // Theme and plugins, should be loaded after the javascript library.
                $html .= '<link rel="stylesheet" href="' . $staticUrl . '/ext/' . $restTheme . '-reset.css">
                          <script src="' . $staticUrl . '/ext/' . $restTheme . '.js"></script>';

                $checkoutUrl = $this->context->link->getModuleLink($this->name, 'checkout', [], true);
                Media::addJsDef(
                    [
                        'lyraEmbedded' => [
                            'checkoutUrl' => $checkoutUrl
                        ]
                    ]
                );

                $this->addJs('lyraEmbedded.js');
                $this->addCss('lyraEmbedded.css');
            }

            return $html;
        }
    }

    /**
     * Payment method selection page header for PrestaShop 8.
     *
     * @param array $params
     * @return string|void
     */
    public function hookDisplayHeader($params)
    {
        return $this->hookHeader($params);
    }

    /**
     * Render the wallet link displayed in the customer account dashboard.
     *
     * @param array<string, mixed> $params Hook parameters passed by PrestaShop.
     * @return string Rendered HTML of the customer account wallet entry.
     */
    public function hookDisplayCustomerAccount($params)
    {
        $url = Context::getContext()->link->getModuleLink($this->name, 'wallet', [], true);

        $this->context->smarty->assign(
            [
                'front_controller' => $url
            ]
        );

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name . '/views/templates/hook/customerAccount.tpl');
    }

    /**
     * Get the checkout process of the order controller.
     * OrderController::getCheckoutProcess() only exists since PrestaShop 1.7.7.
     *
     * @param OrderController $controller
     * @return CheckoutProcess|null
     */
    private function getCheckoutProcess($controller)
    {
        if (method_exists($controller, 'getCheckoutProcess')) {
            return $controller->getCheckoutProcess();
        }

        try {
            $property = new ReflectionProperty($controller, 'checkoutProcess');
            $property->setAccessible(true);

            return $property->getValue($controller);
        } catch (ReflectionException $e) {
            return null;
        }
    }

    /**
     * Get the current step of the checkout process.
     * CheckoutProcess::getCurrentStep() only exists since PrestaShop 1.7.7.
     *
     * @param CheckoutProcess $checkoutProcess
     * @return CheckoutStepInterface|null
     */
    private function getCurrentCheckoutStep($checkoutProcess)
    {
        if (method_exists($checkoutProcess, 'getCurrentStep')) {
            try {
                return $checkoutProcess->getCurrentStep();
            } catch (RuntimeException $e) {
                return null;
            }
        }

        foreach ($checkoutProcess->getSteps() as $step) {
            if ($step->isCurrent()) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Payment function, display payment form in PrestaShop 1.7 and higher.
     *
     * @param array $params
     * @return void|array[\PrestaShop\PrestaShop\Core\Payment\PaymentOption]
     */
    public function hookPaymentOptions($params)
    {
        $controller = $this->context->controller;
        if ($controller instanceof OrderController) {
            $checkoutProcess = $this->getCheckoutProcess($controller);
            $currentStep = $checkoutProcess ? $this->getCurrentCheckoutStep($checkoutProcess) : null;
            if (! $currentStep instanceof CheckoutPaymentStep) {
                // Do not create form token until payment step.
                return;
            }
        } else {
            return;
        }

        $options = [];

        $cart = $this->context->cart;
        $payment = new PaymentHandler();
        if ($payment->isAvailable($cart)) {
            $option = $payment->getPaymentOption();

            $this->context->smarty->assign($payment->getTplVars($cart));
            $form = $this->fetch('module:' . $this->name . '/views/templates/hook/checkout.tpl');
            $option->setAdditionalInformation($form);

            $options[] = $option;
        }

        return $options;
    }

    /**
     * Save payment information.
     *
     * @param Order $order
     * @param array $paymentResult
     */
    public function savePayment($order, $paymentResult)
    {
        $payments = $order->getOrderPayments();
        $whiteLabel = LyraTools::getWhiteLabel();
        $currency = LyraApi::findCurrencyByAlphaCode($paymentResult['currency'], $whiteLabel);
        $decimals = $currency->getDecimals();

        // Delete payments created by default and cancelled payments.
        if (is_array($payments) && ! empty($payments)) {
            $sequenceNumber = $paymentResult['sequenceNumber'] ?? '1';
            $transId = $sequenceNumber . (isset($paymentResult['transId']) ? '-' . $paymentResult['transId'] : '');
            $cancelled = LyraRestHelper::isCancelledPayment($paymentResult);

            $update = false;

            foreach ($payments as $payment) {
                if (! $payment->transaction_id || (($payment->transaction_id == $transId) && $cancelled)) {
                    $order->total_paid_real = Tools::ps_round($order->total_paid_real - $payment->amount, $decimals);

                    // Delete payment and invoice reference.
                    $this->deleteOrderPayment($payment);
                    $update = true;
                }
            }

            if ($update) {
                if ($order->total_paid_real < 0) {
                    $order->total_paid_real = 0;
                }

                $order->update();
            }
        }

        if (! LyraTools::isSuccessState($order) && ! LyraApi::isAcceptedPayment($paymentResult['status'])) {
            // No payment creation.
            return;
        }

        // Save transaction info.
        LyraTools::getLogger()->logInfo("Save payment information for order #{$order->id}.");

        $invoices = $order->getInvoicesCollection();
        $invoice = ($invoices && $invoices->getFirst()) ? $invoices->getFirst() : null;
        $paymentIds = [];

        if ($paymentResult['cardBrand'] === 'MULTI') {
            $sequences = json_decode($paymentResult['paymentSequence'], true);
            $transactions = array_filter($sequences['transactions'], function ($trans) {
                return self::filterTransactions($trans);
            });
            $lastTrs = end($transactions); // Last transaction.

            foreach ($transactions as $trs) {
                $transId = $trs['sequenceNumber'] . '-' .  $trs['transId'];

                if (LyraApi::isCancelledPayment($trs['status'])) {
                    if ($payment = $this->findOrderPayment($order->reference, $transId)) {
                        $order->total_paid_real = Tools::ps_round($order->total_paid_real - $payment->amount, $decimals);

                        // Delete payment and invoice reference.
                        $this->deleteOrderPayment($payment);
                    }

                    break;
                }

                $amount = $currency->convertAmountToFloat($trs['amount']);
                if ($trs === $lastTrs) {
                    $remaining = $order->total_paid - $order->total_paid_real;
                    if (number_format($remaining, $decimals) == number_format($amount, $decimals)) {
                        // To avoid rounding problems and pass PaymentModule::validateOrder() check.
                        $amount = $remaining;
                    }
                }

                $timestamp = isset($trs['presentationDate']) ? strtotime($trs['presentationDate'] . ' UTC') : time();

                $data = [
                    'cardNumber' => $trs['cardNumber'] ?? "",
                    'cardHolder' => $trs['cardHolder'] ?? "",
                    'cardBrand' => $trs['wallet'] ?? $trs['cardBrand'] ?? "",
                    'expiryMonth' => $trs['expiryMonth'] ?? "",
                    'expiryYear' => $trs['expiryYear']?? ""
                ];

                if (isset($trs['changeRate'])) {
                    $data['changeRate'] = $trs['changeRate'];
                }

                if (! ($orderPayment = $this->addOrderPayment($order, $invoice, $transId, $amount, $timestamp, $data))) {
                    return;
                }

                $paymentIds[] = $orderPayment;
            }
        } else {
            // Real paid total on gateway.
            $amountInCents = $paymentResult['amount'];
            $amount = $currency->convertAmountToFloat($amountInCents);

            if (number_format($order->total_paid, $decimals) == number_format($amount, $decimals)) {
                // To avoid rounding problems and pass PaymentModule::validateOrder() check.
                $amount = $order->total_paid;
            }

            if ($paymentResult['operationType'] === 'CREDIT') {
                if (version_compare(_PS_VERSION_, '1.7.7', '>=')) {
                    if ($order->total_paid_real <= 0) {
                        // Order already cancelled or refunded.
                        LyraTools::getLogger()->logInfo("Order #{$order->id} was already cancelled or refunded.");

                        return;
                    }

                    // Create a credit slip if it is not already created from BO PrestaShop.
                    $orderCurrency = new Currency($order->id_currency);
                    $formattedAmount = $amount . ' ' . $orderCurrency->sign;
                    LyraTools::getLogger()->logInfo("Creating order slip with $formattedAmount amount for order #{$order->id}");

                    try {
                        if ($this->lyraCreateOrderSlip($order, $amount)) {
                            LyraTools::getLogger()->logInfo("Order slip with $formattedAmount amount was successfully created for order #{$order->id}");
                        } else {
                           LyraTools::getLogger()->logInfo("Order slip with $formattedAmount amount could not be created for order #{$order->id}");
                       }
                    } catch (Exception $e) {
                        LyraTools::getLogger()->logError("Error while creating order slip with $formattedAmount amount for order #{$order->id} : {$e->getMessage()}" . ($e->getCode() > 0 ? ' (' . $e->getCode() . ').' : ''));
                    }

                    // Update order total paid real.
                    $order->total_paid_real -= $amount;

                    if ($order->total_paid_real < 0) {
                        $order->total_paid_real = 0;
                    }

                    $order->update();
                    return;
                } else {
                    // This is a refund, set transaction amount to negative.
                    $amount = $amount * -1;
                }
            }

            $timestamp = isset($paymentResult['presentationDate']) ? strtotime($paymentResult['presentationDate'] . ' UTC') : time();

            $data = [
                'cardNumber' => $paymentResult['cardNumber'] ?? "",
                'cardHolder' => $paymentResult['cardHolder'] ?? "",
                'cardBrand' => $paymentResult['wallet'] ?? $paymentResult['cardBrand'] ?? "",
                'expiryMonth' => $paymentResult['expiryMonth'] ?? "",
                'expiryYear' => $paymentResult['expiryYear'] ?? ""
            ];

            if (isset($paymentResult['changeRate']) && ($paymentResult['changeRate'] !== null)) {
                $data['changeRate'] = $paymentResult['changeRate'];
            }

            if (! ($orderPayment = $this->addOrderPayment($order, $invoice, $paymentResult['transId'], $amount, $timestamp, $data))) {
                return;
            }

            $paymentIds[] = $orderPayment;
        }

        $paymentIds = implode(', ', $paymentIds);
        LyraTools::getLogger()->logInfo(
            "Payment information with ID(s) {$paymentIds} saved successfully for order #{$order->id}."
        );
    }

    /**
     * Update the PrestaShop order state according to the gateway payment result.
     * Skips the update when the order is already in a final state or when the
     * new state is identical to the current one.
     *
     * @param array<string, mixed> $paymentResult Normalised payment result from {@see RestHelper::paymentResult()}.
     * @param \Order               $order         The PrestaShop order to update.
     * @return void
     */
    public function setOrderState($paymentResult, $order)
    {
        $finalStates = [
            Configuration::get('PS_OS_CANCELED')
        ];

        $oldState = $order->getCurrentState();
        if (in_array($oldState, $finalStates)) {
            // PrestaShop order is already in a final state.
            LyraTools::getLogger()->logInfo("No state change for order #{$order->id}, order remains in state {$oldState}.");

            return;
        }

        $transStatus = $paymentResult['status'];

        if (LyraApi::isAcceptedPayment($transStatus)) {
            switch (true) {
                case LyraApi::isToValidatePayment($transStatus):
                    // To validate payment order state.
                    $newState = 'LYRA_EMBEDDED_OS_TO_VALIDATE';

                    break;
                case LyraApi::isPendingPayment($transStatus):
                    // Pending authorization order state.
                    $newState = 'LYRA_EMBEDDED_OS_PENDING_AUTHORIZATION';

                    break;
                default:
                    $isPartial = false; // To handle fractionned payment and partial refund.

                    // Check if it is a partial payment.
                    $whiteLabel = LyraTools::getWhiteLabel();
                    $currency = LyraApi::findCurrency($paymentResult['currency'], $whiteLabel);
                    $paidTotal = isset($paymentResult['amount']) ? $currency->convertAmountToFloat($paymentResult['amount']) : null;
                    if ($paidTotal && $order->total_paid_real > $paidTotal) {
                        if ($transStatus === 'CANCELLED') {
                            $payments = $order->getOrderPayments();
                            $isPartial = is_array($payments) && (count($payments) > 1);
                        } else {
                            $isPartial = true;
                        }
                    }

                    if ($paymentResult['operationType'] === 'CREDIT') {
                        $newState = $isPartial ? 'LYRA_EMBEDDED_OS_PARTIAL_REFUND' : 'PS_OS_REFUND';
                    } else {
                        if ($isPartial) {
                            return;
                        } else {
                            $newState = 'PS_OS_PAYMENT';
                        }
                    }

                    break;
            }
        } elseif (LyraRestHelper::isCancelledPayment($paymentResult) || LyraRestHelper::isAbandonedPayment($paymentResult['orderStatus'])) {
            // Do not cancel PrestaShop order, it stays in its current state.
            if ($paymentResult['operationType'] === 'CREDIT') {
                LyraTools::getLogger()->logInfo("No state change for order #{$order->id}, order remains in state {$oldState}.");

                return;
            } else {
                $newState = 'PS_OS_CANCELED';
            }
        } elseif (LyraRestHelper::isCancelledPayment($paymentResult, false)) {
            // PrestaShop order stays in its current state.
            LyraTools::getLogger()->logInfo("No state change for order #{$order->id}, order remains in state {$oldState}.");

            return;
        } else {
            $newState = 'PS_OS_ERROR';
        }

        $newStateId = Configuration::get($newState);
        if ($oldState === $newStateId) {
            LyraTools::getLogger()->logInfo("No state change for order #{$order->id}, order remains in state {$newState} ({$newStateId}).");

            return;
        }

        $order->setCurrentState($newStateId);

        LyraTools::getLogger()->logInfo(
            "Order state {$newState} ({$newStateId}) set successfully for order #{$order->id}."
        );
    }

    /**
     * Before order slip add in backend.
     *
     * @param OrderSlip $orderSlip
     */
    public function hookActionObjectOrderSlipAddBefore($orderSlip)
    {
        if (! Tools::isSubmit('cancel_product') // PrestaShop >= 1.7.7
            && ! Tools::isSubmit('partialRefund') // PrestaShop < 1.7.7
            && ! Tools::isSubmit('cancelProduct')) { // PrestaShop < 1.7.7
            return;
        }

        $orderSlipObject = $orderSlip['object'] ?? null;
        if (! $orderSlipObject) {
            return;
        }

        $orderId = $orderSlipObject->id_order ?? null;
        if (! $orderId) {
            return;
        }

        $order = new Order($orderId);
        if (! $this->active || ! $order || ($order->module != $this->name)) {
            return;
        }

        /* Stop the refund if the merchant want to generate a discount:
         - If in POST we receive generateDiscount OR generateDiscountRefund for versions < 1.7.7.
         - If in POST we receive cancel_product['voucher'] for versions >= 1.7.7.
         */
        if (Tools::isSubmit('generateDiscount') // PrestaShop >= 1.7.7
            || Tools::isSubmit('generateDiscountRefund') // PrestaShop < 1.7.7
            || (($cancelProduct = Tools::getValue('cancel_product')) && isset($cancelProduct['voucher']))) {
            return;
        }

        // Get amount from OrderSlip.
        $amount = $orderSlipObject->total_products_tax_incl;

        if (version_compare(_PS_VERSION_, '1.7.7', '>=')) {
            $cancelProduct = Tools::getValue('cancel_product');
            $voucherType = isset($cancelProduct['voucher_refund_type']) ? (int) $cancelProduct['voucher_refund_type'] : 0;
        } else {
            $voucherType = (int) Tools::getValue('refund_voucher_off', 0);
        }

        $updateOrderSlip = false;
        $applyDiscount = ($voucherType === 1) && ($order->total_discounts > 0) && ($order->getTaxCalculationMethod() == PS_TAX_INC);
        if ($voucherType === 2) {
            $amount = $orderSlipObject->amount;
            $updateOrderSlip = true;
        } elseif ($applyDiscount) {
            $amount -= $order->total_discounts;
            $updateOrderSlip = true;
        }

        // Add shipping cost amount.
        $amount += $orderSlipObject->shipping_cost_amount;

        $client = new Lyranetwork\LyraEmbedded\Sdk\Refund\Api(
            new LyraRefundProcessor(),
            LyraRestHelper::getPrivateKey(),
            LyraTools::getRestUrl(),
            LyraRestHelper::getShopId(),
            'PrestaShop',
            LyraTools::getDefault("BACKOFFICE_NAME")
        );

        $orderCurrency = new Currency($order->id_currency);

        $orderRefundBean = new Lyranetwork\LyraEmbedded\Sdk\Refund\OrderInfo();
        $orderRefundBean->setOrderRemoteId($order->id);
        $orderRefundBean->setOrderId($order->id);
        $orderRefundBean->setOrderReference($order->reference);
        $orderRefundBean->setOrderCurrencyIsoCode($orderCurrency->iso_code);
        $orderRefundBean->setOrderCurrencySign($orderCurrency->sign);
        $orderRefundBean->setOrderUserInfo($this->getUserInfo());

        // If any error during WS refund, redirect to order details to avoid creation of a credit and displaying success message.
        if (! $client->refund($orderRefundBean, $amount, LyraTools::getWhiteLabel())) {
            // No refund, so get back refunded products quantities, and available products stock quantities.
            $orderDetailsId = Tools::isSubmit('generateCreditSlip') ? Tools::getValue('cancelQuantity') :
                Tools::getValue('partialRefundProductQuantity');
            if (is_array($orderDetailsId) && ! empty($orderDetailsId)) {
                // Prestashop versions < 1.7.7.
                foreach ($orderDetailsId as $id => $quantity) {
                    // Update order detail.
                    $orderDetail = new OrderDetail($id);

                    if ($orderDetail->product_quantity_refunded && is_int($orderDetail->product_quantity_refunded)) {
                        $orderDetail->product_quantity_refunded -= (int) $quantity;
                        $orderDetail->update();
                    }

                    // Update product available quantity.
                    StockAvailable::updateQuantity($orderDetail->product_id, $orderDetail->product_attribute_id, -$quantity, $order->id_shop);
                }
            }

            if (version_compare(_PS_VERSION_, '1.7.7', '<')) {
                // Prestashop versions < 1.7.7.
                $token = Tools::getValue('token') ?? Tools::getAdminTokenLite('AdminOrders');
                Tools::redirectAdmin(AdminController::$currentIndex . '&id_order=' . $order->id . '&vieworder&token=' . $token);
            } else {
                // Display warning to customer if any.
                if (isset($this->context->cookie->lyraEmbeddedRefundWarn)) {
                    $this->displayAdminMessage($this->context->cookie->lyraEmbeddedRefundWarn);
                    unset($this->context->cookie->lyraEmbeddedRefundWarn);
                }

                $adminOrdersUrl = $this->context->link->getAdminLink('AdminOrders');
                $adminOrdersUrl = str_replace('/?_token=', '/' . $order->id . '/view?_token=', $adminOrdersUrl);

                Tools::redirectAdmin($adminOrdersUrl);
            }
         } elseif (version_compare(_PS_VERSION_, '1.7.7', '>=') && isset($this->context->cookie->lyraEmbeddedRefundWarn)) {
            // Display warning to customer if any for Prestashop versions >= 1.7.7.
            $this->displayAdminMessage($this->context->cookie->lyraEmbeddedRefundWarn);
            unset($this->context->cookie->lyraEmbeddedRefundWarn);
         }

         if ($updateOrderSlip) {
             LyraTools::getLogger()->logWarning("Updated order slip for order #{$orderId}.");

             $orderSlipObject->order_slip_type = 1;
             $orderSlipObject->amount = $amount;

             if ($applyDiscount) {
                 $orderSlipObject->total_products_tax_incl -= $order->total_discounts;
             }
         }

         return true;
    }

    /**
     * Delete payment and invoice reference.
     *
     * @param OrderPayment $payment
     */
    private function deleteOrderPayment($payment)
    {
        // Delete payment.
        $payment->delete();

        // Delete invoice reference.
        $result = Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'order_invoice_payment` WHERE `id_order_payment` = ' . $payment->id
        );

        if (! $result) {
            LyraTools::getLogger()->logWarning(
                "An error occurred when deleting invoice reference for payment #{$payment->id}:."
            );
        }
    }

    /**
     * Create or update an OrderPayment record and link it to the given invoice.
     *
     * @param \Order             $order     The PrestaShop order.
     * @param \OrderInvoice|null $invoice   The invoice to link the payment to, or null.
     * @param string             $transId   Gateway transaction ID used as the payment reference.
     * @param float              $amount    Payment amount in the order currency.
     * @param int                $timestamp Unix timestamp of the transaction.
     * @param array<string, mixed> $data    Card details (cardNumber, cardBrand, cardHolder, expiryMonth, expiryYear, changeRate).
     * @return int|false The OrderPayment ID on success, false on failure.
     */
    private function addOrderPayment($order, $invoice, $transId, $amount, $timestamp, $data)
    {
        $date = date('Y-m-d H:i:s', $timestamp);

        if (! ($orderPayment = $this->findOrderPayment($order->reference, $transId))) {
            // Order payment not created yet, let's create it.
            $method = sprintf($this->l('%s payment'), $data['cardBrand']);
            if (! $order->addOrderPayment($amount, $method, $transId, null, $date, $invoice) || ! ($orderPayment = $this->findOrderPayment($order->reference, $transId))) {
                LyraTools::getLogger()->logWarning("Payment information for order #{$order->id} cannot be saved.");

                return false;
            }

            LyraTools::getLogger()->logInfo("Payment successfully created for order #{$order->id}.");
        } elseif (Validate::isLoadedObject($invoice)) {
            $result = Db::getInstance()->execute(
                'REPLACE INTO `' . _DB_PREFIX_ . 'order_invoice_payment`
                 VALUES(' . $invoice->id . ', ' . $orderPayment->id . ', ' . $order->id . ')'
            );

            if (! $result) {
                LyraTools::getLogger()->logWarning(
                    "An error has occurred during updating invoice reference for payment #{$orderPayment->id}."
                );
            }
        }

        // Set card info.
        $orderPayment->amount = $amount;
        $orderPayment->card_number = $data['cardNumber'] ?? '';
        $orderPayment->card_brand = $data['cardBrand'] ?? '';
        $orderPayment->card_holder = $data['cardHolder'] ?? '';
        if ($data['expiryMonth'] && $data['expiryYear']) {
            $orderPayment->card_expiration = str_pad($data['expiryMonth'], 2, '0', STR_PAD_LEFT) . '/' . $data['expiryYear'];
        }

        // Set conversion_rate.
        if (isset($data['changeRate'])) {
            $orderPayment->conversion_rate = $data['changeRate'];
        }

        if ($orderPayment->update()) {
            LyraTools::getLogger()->logInfo("Payment successfully updated with transaction information for order #{$order->id}.");

            return $orderPayment->id;
        }

        LyraTools::getLogger()->logWarning("Payment information for order #{$order->id} cannot be saved.");

        return false;
    }

    /**
     * Find an existing OrderPayment by order reference and gateway transaction ID.
     *
     * @param string $orderRef The PrestaShop order reference.
     * @param string $transId  The gateway transaction ID.
     * @return \OrderPayment|false The matching OrderPayment object, or false when not found.
     */
    private function findOrderPayment($orderRef, $transId)
    {
        $paymentId = Db::getInstance()->getValue(
            'SELECT `id_order_payment` FROM `' . _DB_PREFIX_ . 'order_payment`
            WHERE `order_reference` = \'' . pSQL($orderRef) . '\' AND transaction_id = \'' . pSQL($transId) . '\''
        );

        if (! $paymentId) {
            return false;
        }

        return new OrderPayment($paymentId);
    }

    /** Register a CSS stylesheet with the active page controller.
    *
    * Uses the PrestaShop 1.7 `registerStylesheet()` API when available,
    * otherwise falls back to the legacy `addCss()` method.
    *
    * @param  string $cssFile  File name relative to `views/css/`.
    *
    * @return void
    */
    private function addCss($cssFile)
    {
        $controller = $this->context->controller;

        if (method_exists($controller, 'registerStylesheet')) { // PrestaShop 1.7.
            $controller->registerStylesheet(
                'module-' . $this->name . '-' . basename($cssFile, '.css'),
                'modules/' . $this->name . '/views/css/' . $cssFile,
                ['media' => 'all', 'priority' => 90]
            );
        } else {
            $controller->addCss($this->_path . 'views/css/' . $cssFile, 'all');
        }
    }

    /**
     * Register a JS file on the active page controller.
     * Uses the PrestaShop 1.7 `registerJavascript()` API when available,
     * otherwise falls back to the legacy `addJs()` method.
     *
     * @param string $jsFile File name relative to `views/js/`.
     * @return void
     */
    private function addJs($jsFile)
    {
        $controller = $this->context->controller;

        if (method_exists($controller, 'registerJavascript')) { // PrestaShop 1.7.
            $controller->registerJavascript(
                'module-' . $this->name,
                'modules/' . $this->name . '/views/js/' . $jsFile,
                ['position' => 'bottom', 'priority' => 150]
            );
        } else {
            $controller->addJs($this->_path . 'views/js/' . $jsFile);
        }
    }

    /**
     * Create an OrderSlip record for a gateway-initiated refund when no credit-slip
     * has been created from the PrestaShop back-office.
     * The full refund amount is set on the shipping cost fields so that the slip
     * does not interfere with individual product quantities.
     *
     * @param \Order $order  The order being refunded.
     * @param float  $amount Refund amount in the order currency.
     * @return bool True when the slip was saved successfully.
     */
    private function lyraCreateOrderSlip($order, $amount)
    {
        $currency = new Currency($order->id_currency);

        $orderSlip = new OrderSlip();
        $orderSlip->id_customer = $order->id_customer;
        $orderSlip->id_order = $order->id;
        $orderSlip->amount = 0;
        $orderSlip->shipping_cost = false;
        $orderSlip->shipping_cost_amount = 0;
        $orderSlip->conversion_rate = $currency->conversion_rate;
        $orderSlip->partial = 0;
        $orderSlip->total_products_tax_excl = 0;
        $orderSlip->total_products_tax_incl = 0;
        $orderSlip->total_shipping_tax_excl = $amount;
        $orderSlip->total_shipping_tax_incl = $amount;

        return $orderSlip->add();
    }

    /**
     * Filter callback used to retain only manageable DEBIT transactions.
     * Keeps transactions whose operation type is DEBIT and whose status belongs
     * to the set of success, pending, or cancelled statuses.
     *
     * @param array<string, mixed> $transaction A single transaction entry from the gateway response.
     * @return bool True when the transaction should be kept.
     */
    private static function filterTransactions($transaction)
    {
        $managedStates = array_merge(
            LyraApi::getSuccessStatuses(),
            LyraApi::getPendingStatuses(),
            LyraApi::getCancelledStatuses()
        );

        return $transaction['operationType'] === 'DEBIT' && in_array($transaction['status'], $managedStates);
    }

    /**
     * Build a human-readable user info string for refund audit purposes.
     * Includes the back-office employee e-mail (when available) and the remote IP address.
     *
     * @return string
     */
    private function getUserInfo()
    {
        $commentText = isset($this->context->employee) ? "PrestaShop user: {$this->context->employee->email} ; ": '';
        $commentText .= "IP address: " . Tools::getRemoteAddr();

        return $commentText;
    }

    /**
     * Add a flash message to the Symfony session for display in the back-office.
     *
     * @param string $message The message text to display.
     * @param string $type    Flash message type: "warning", "success", "error", etc.
     * @return void
     */
    private function displayAdminMessage($message, $type = 'warning')
    {
        $container = PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();

        // Get session.
        $session = version_compare(_PS_VERSION_, '9.0.0', '>=') ? $container->get('request_stack')->getSession()
            : $container->get('session');

        $session->getFlashBag()->add($type, $message);
    }
}