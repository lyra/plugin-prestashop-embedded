<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

namespace Lyranetwork\LyraEmbedded\Payment;

if (! defined('_PS_VERSION_')) {
    exit;
}

use Lyranetwork\LyraEmbedded\Sdk\Form\Api as LyraApi;
use Lyranetwork\LyraEmbedded\Utility\Tools as LyraTools;
use Lyranetwork\LyraEmbedded\Utility\RestHelper as LyraRestHelper;

class PaymentHandler
{
    /** @var string */
    protected $moduleName = 'lyra_embedded';

    /** @var \Module */
    protected $module;

    /** @var \Context */
    protected $context;

    public function __construct()
    {
        $this->module = \Module::getInstanceByName($this->moduleName);
        $this->context = \Context::getContext();
    }

    /**
     * Check if the payment method is available for the given cart.
     *
     * @param \Cart|false $cart
     * @return bool
     */
    public function isAvailable($cart)
    {
        if ($this->accountCustomerWallet()) {
            return true;
        }

        // Cart errors.
        if (! \Validate::isLoadedObject($cart) || ($cart->nbProducts() <= 0)) {
            return false;
        }

        return true;
    }

    /**
     * Build and return the PrestaShop payment option.
     *
     * @return \PrestaShop\PrestaShop\Core\Payment\PaymentOption
     */
    public function getPaymentOption()
    {
        $lang = LyraTools::getLanguageIsoCode($this->context->language->id ?? null);
        $lang .= '-' . strtoupper($lang);

        $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption;
        $option->setCallToActionText($this->getTitle($lang))
               ->setModuleName($this->moduleName);

        return $option;
    }

    /**
     * Return Smarty template variables for the payment form.
     *
     * @param \Cart|false $cart
     * @param bool $fromAccount Whether the request comes from the customer account page.
     * @return array<string, mixed>
     */
    public function getTplVars($cart = false, $fromAccount = false)
    {
        $vars = [];

        $config = LyraRestHelper::getActiveFormConfig();
        $vars['LYRA_FORM_CONFIG'] = isset($config['formConfig']) ? json_encode($config['formConfig']) :  null;

        $formToken = $this->getFormToken($cart, $fromAccount);
        $vars['LYRA_FORM_TOKEN'] = $formToken;

        $lang = LyraTools::getLanguageIsoCode($cart->id_lang ?? null);
        $lang .= '-' . strtoupper($lang);
        $vars['LYRA_TITLE'] = $this->getTitle($lang);

        if (empty($formToken)) {
            $isTest = (LyraRestHelper::getMode() === 'test');

            if ($isTest && (isset($this->context->cookie->lyraCreateTokenError))) {
                $errorMessage = $this->l($this->context->cookie->lyraCreateTokenError) . $this->l(' Please consult the documentation for more details.');
                unset($this->context->cookie->lyraCreateTokenError);
            } else {
                $errorMessage = $this->l('Something went wrong while processing your payment request. Please try again later.');
            }

            $this->context->smarty->assign('LYRA_ERROR_MSG', $errorMessage);
        }

        return $vars;
    }

    /**
     * Retrieve a payment form token for the given cart or customer account.
     *
     * @param \Cart|false $cart
     * @param bool $fromAccount Whether the token is for the customer wallet.
     * @return string|null
     */
    public function getFormToken($cart = false, $fromAccount = false)
    {
        if ($fromAccount) {
            $cust = $this->context->customer;
            $data = $this->getAccountData($cust);
            $webService = 'CreateToken';
            $metadata = "user $cust->email.";
        } else {
            $data = $this->getCartData($cart);
            $webService = 'CreatePayment';
            $metadata = "cart #$cart->id.";
        }

        return LyraRestHelper::getFormToken($data, $webService, $metadata);
    }

    /**
     * Retrieve a payment form token for the given order.
     *
     * @param \Order $order
     * @return string|null
     */
    public function getOrderToken($order)
    {
        $data = $this->getOrderData($order);
        $webService = 'CreatePayment';
        $metadata = "order #$order->id";

        return LyraRestHelper::getFormToken($data, $webService, $metadata);
    }

    /**
     * Check whether one-click (customer wallet) payment is active.
     *
     * @return bool
     */
    public function isOneClickActive()
    {
        if ($this->isFromBackend()) {
            return false;
        }

        $formConfig = LyraRestHelper::getActiveFormConfig();
        $formAction = $formConfig['shopConfig']['wsData']['formAction'] ?? null;

        return ($formAction && $formAction == 'CUSTOMER_WALLET');
    }

    /**
     * Check whether the current request is from the customer wallet controller.
     *
     * @return bool
     */
    public function accountCustomerWallet()
    {
        if (! $this->isOneClickActive()) {
            return false;
        }

        $controller = $this->context->controller;

        return ($controller instanceof \Lyra_EmbeddedWalletModuleFrontController);
    }

    /**
     * Validate and create a PrestaShop order from the given cart.
     *
     * @param \Cart $cart
     * @return \Order|null
     */
    public function createOrder($cart)
    {
        if (empty($cart->id)) {
            return null;
        }

        $order = null;

        // Retrieve customer from cart.
        $customer = new \Customer($cart->id_customer);

        // Call payment module validateOrder.
        try {
            $lang = LyraTools::getLanguageIsoCode($cart->id_lang ?? null);
            $lang .= '-' . strtoupper($lang);

            $this->module->validateOrder(
                $cart->id,
                \Configuration::get('LYRA_EMBEDDED_OS_WAITING_PAYMENT'),
                0,
                $this->getTitle($lang), // Method title.
                null, // $message.
                [], // $extraVars.
                $cart->id_currency, // $currency_special.
                true, // $dont_touch_amount.
                $customer->secure_key
            );

            $orderId = \Order::getIdByCartId($cart->id);
            if (! empty($orderId)) {
                $order = new \Order($orderId);
                LyraTools::getLogger()->logInfo("Order #$order->id has been succesfully created for cart #$cart->id.");
            } else {
                LyraTools::getLogger()->logError("Order has not been created for cart #$cart->id.");
            }
        } catch (\Exception $e) {
            LyraTools::getLogger()->logError("Error while creating order for cart #$cart->id. " . $e->getMessage());
        }

        return $order;
    }

    /**
     * Check whether the current request originates from PrestaShop Back Office.
     *
     * @return bool
     */
    private function isFromBackend()
    {
        $adminCookie = new \Cookie('psAdmin');
        if (isset($adminCookie->id_employee) && ! empty($adminCookie->id_employee) && \Tools::getIsset('recover_cart')) {
            return true;
        }

        return false;
    }

    /**
     * Return the localized payment method title for the active model.
     *
     * @return string
     */
    private function getTitle($lang)
    {
        $activeModel = LyraRestHelper::getActiveFormConfig();

        return $activeModel['cmsConfig']['cmsPaymentMethodLabel'][$lang] ?? $this->getDefaultTitle();
    }

    /**
     * Return the localized default payment method title.
     *
     * @return string
     */
    private function getDefaultTitle()
    {
        return $this->l('Payment by credit card');
    }

    /**
     * Shortcut for module translation function.
     *
     * @param string $string
     * @return string
     */
    private function l($string)
    {
        return $this->module->l($string, 'paymenthandler');
    }

    /**
     * Build the payment API request parameters from the given cart.
     *
     * @param \Cart $cart
     * @return array<string, mixed>
     */
    private function getCartData($cart)
    {
        // Update shop info in cart to avoid errors when shopping cart is shared.
        $shop = $this->context->shop;
        if ($shop && $shop->getGroup() && $shop->getGroup()->share_order && ($cart->id_shop != $shop->id)) {
            $cart->id_shop = $shop->id;
            $cart->id_shop_group = $shop->id_shop_group;
            $cart->save();
        }

        $whiteLabel = LyraTools::getWhiteLabel();

        // Detect store currency.
        $cartCurrency = new \Currency($cart->id_currency);
        $currency = LyraApi::findCurrencyByAlphaCode($cartCurrency->iso_code, $whiteLabel);

        // Amount rounded to currency decimals.
        $amount = \Tools::ps_round($cart->getOrderTotal(), $currency->getDecimals());
        $amount = $currency->convertAmountToInteger($amount);

        /* @var $cust Customer */
        $cust = new \Customer($cart->id_customer);
        $custTitle = new \Gender($cust->id_gender);

        /* @var $billing_country Address */
        $billingAddress = new \Address($cart->id_address_invoice);
        $billingCountry = new \Country($billingAddress->id_country);

        $params = [
            'orderId' => $cart->id,
            'currency' => $cartCurrency->iso_code,
            'amount' => $amount,
            'customer' => [
                'email' => $cust->email,
                'reference' => $cust->id,
                'billingDetails' => [
                    'language' => LyraTools::getLanguageIsoCode($cart->id_lang ?? null),
                    'title' => $custTitle->name[$this->context->language->id] ?? "",
                    'firstName' => $billingAddress->firstname ?? "",
                    'lastName' => $billingAddress->lastname ?? "",
                    'category' => 'PRIVATE',
                    'address' => ($billingAddress->address1 ?? "") . ' ' . ($billingAddress->address2 ?? ""),
                    'zipCode' => $billingAddress->postcode ?? "",
                    'city' => $billingAddress->city ?? "",
                    'phoneNumber' => $billingAddress->phone ?? $billingAddress->phone_mobile ?? "",
                    'country' => $billingCountry->iso_code ?? ""
                ],
                'shoppingCart' => [
                    'cartItemInfo' => $this->getShoppingCart($cart->getProducts(true), $currency)
                ]
            ],
            'contrib' => LyraTools::getContrib()
        ];

        $stateId = $billingAddress->id_state ?? null;
        if ($stateId) {
            $state = new \State($stateId);
            $params['customer']['billingDetails']['state'] = $state->iso_code ?? "";
        }

        $deliveryAddress = new \Address($cart->id_address_delivery);
        if (! $cart->isVirtualCart() && ($deliveryAddress instanceof \Address)) {
            $params['customer']['shippingDetails'] = $this->getShippingData($cart, $deliveryAddress, $cart->isVirtualCart());
        }

        $activeModel = LyraRestHelper::getActiveFormConfig();

        // Set Number of attempts in case of rejected payment.
        $restAttempts = $activeModel['shopConfig']['wsData']['transactionOptions']['cardOptions']['retry'] ?? null;
        if ($restAttempts && is_numeric($restAttempts)) {
            $params['transactionOptions']['cardOptions']['retry'] = $restAttempts;
        }

        $params['formAction'] = $activeModel['shopConfig']['wsData']['formAction'] ?? 'PAYMENT';

        return $params;
    }

    /**
     * Build the payment API request parameters from the given order.
     *
     * @param \Order $order
     * @return array<string, mixed>
     */
    private function getOrderData($order)
    {
        // Detect store currency.
        $orderCurrency = new \Currency($order->id_currency);
        $whiteLabel = LyraTools::getWhiteLabel();
        $currency = LyraApi::findCurrencyByAlphaCode($orderCurrency->iso_code, $whiteLabel);

        // Amount rounded to currency decimals.
        $amount = \Tools::ps_round($order->total_paid, $currency->getDecimals());
        $amount = $currency->convertAmountToInteger($amount);

        /* @var $cust Customer */
        $cust = new \Customer($order->id_customer);
        $custTitle = new \Gender($cust->id_gender);

        /* @var $billing_country Address */
        $billingAddress = new \Address($order->id_address_invoice);
        $billingCountry = new \Country($billingAddress->id_country);

        $params = [
            'orderId' => $order->id,
            'currency' => $orderCurrency->iso_code,
            'amount' => $amount,
            'customer' => [
                'email' => $cust->email,
                'reference' => $cust->id,
                'billingDetails' => [
                    'language' => LyraTools::getLanguageIsoCode($order->id_lang ?? null),
                    'title' => $custTitle->name[$this->context->language->id] ?? "",
                    'firstName' => $billingAddress->firstname ?? "",
                    'lastName' => $billingAddress->lastname ?? "",
                    'category' => 'PRIVATE',
                    'address' => ($billingAddress->address1 ?? "") . ' ' . ($billingAddress->address2 ?? ""),
                    'zipCode' => $billingAddress->postcode ?? "",
                    'city' => $billingAddress->city ?? "",
                    'phoneNumber' => $billingAddress->phone ?? $billingAddress->phone_mobile ?? "",
                    'country' => $billingCountry->iso_code ?? ""
                ],
                'shoppingCart' => [
                    'cartItemInfo' => $this->getShoppingCart($order->getCartProducts(), $currency)
                ]
            ],
            'transactionOptions' => [
                'cardOptions' => [
                    'paymentSource' => 'EC'
                ]
            ],
            'contrib' => LyraTools::getContrib(),
            'ipnTargetUrl' => LyraTools::getIpnUrl()
        ];

        $stateId = $billingAddress->id_state ?? null;
        if ($stateId) {
            $state = new \State($stateId);
            $params['customer']['billingDetails']['state'] = $state->iso_code ?? "";
        }

        $deliveryAddress = new \Address($order->id_address_delivery);
        if (! $order->isVirtual() && ($deliveryAddress instanceof \Address)) {
            $params['customer']['shippingDetails'] = $this->getShippingData($order, $deliveryAddress, $order->isVirtual());
        }

        $activeModel = LyraRestHelper::getActiveFormConfig();

        // Set Number of attempts in case of rejected payment.
        $restAttempts = $activeModel['shopConfig']['wsData']['transactionOptions']['cardOptions']['retry'] ?? null;
        if (($restAttempts !== null) && is_numeric($restAttempts)) {
            $params['transactionOptions']['cardOptions']['retry'] = $restAttempts;
        }

        $params['formAction'] = $activeModel['shopConfig']['wsData']['formAction'] ?? 'PAYMENT';

        return $params;
    }

    /**
     * Build the payment API request parameters for a customer wallet token.
     *
     * @param \Customer $cust
     * @return array<string, mixed>
     */
    private function getAccountData($cust)
    {
        $custTitle = new \Gender($cust->id_gender);
        $custCountry = new \Country(\Customer::getCurrentCountry($cust->id));

        return [
            'formAction' => 'CUSTOMER_WALLET',
            'currency' => $this->context->currency->iso_code,
            'customer' => [
                'reference' => $cust->id,
                'email' => $cust->email,
                'billingDetails' => [
                    'language' => LyraTools::getLanguageIsoCode($this->context->language->id ?? null),
                    'title' => $custTitle->name[$this->context->language->id] ?? "",
                    'firstName' => $cust->firstname ?? "",
                    'lastName' => $cust->lastname ?? "",
                    'category' => 'PRIVATE',
                    'country' => $custCountry->iso_code ?? ""
                ]
            ],
            'metadata' => [
                'fromAccount' => true
            ],
            'contrib' => LyraTools::getContrib()
        ];
    }

    /**
     * Build the shipping details sub-array for the payment API request.
     *
     * @param \Cart|\Order $object          The cart or order object.
     * @param \Address     $deliveryAddress The delivery address.
     * @param bool         $isVirtual       Whether the cart/order is virtual.
     * @return array<string, mixed>
     */
    private function getShippingData($object, $deliveryAddress, $isVirtual = false)
    {
        $deliveryCompanyName = '';
        $carrierId = null;

        // Retrieve carrier ID from cart or order.
        if (isset($object->id_carrier) && $object->id_carrier > 0) {
            $carrierId = $object->id_carrier;
        } else {
            $deliveryOptionList = $object->getDeliveryOptionList();

            $deliveryOption = $object->getDeliveryOption();
            $carrierKey = $deliveryOption[(int) $object->id_address_delivery];
            $carrierList = $deliveryOptionList[(int) $object->id_address_delivery][$carrierKey]['carrier_list'];
            $deliveryCompanyName = $carrierList[$carrierKey]['instance']->name ?? '';

            foreach (array_keys($carrierList) as $id) {
                $carrierId = $id;

                break;
            }
        }

        // Set shipping params.
        if ($isVirtual || ! isset($carrierId)) {
            // No shipping options or virtual cart.
            return [
                'shippingMethod' => 'ETICKET',
                'shippingSpeed' => 'EXPRESS'
            ];
        }

        $notAllowedChars = "#[^A-Z0-9ÁÀÂÄÉÈÊËÍÌÎÏÓÒÔÖÚÙÛÜÇ -]#ui";
        $addressNotAllowedChars = "#[^A-Z0-9ÁÀÂÄÉÈÊËÍÌÎÏÓÒÔÖÚÙÛÜÇ/ '.,-]#ui";

        // Get delivery company name.
        if (! empty($deliveryCompanyName)) {
            $deliveryCompanyName =  preg_replace($notAllowedChars, ' ', $deliveryCompanyName);
        }

        $address1 = $deliveryAddress->address1 ?? "";
        $address2 = $deliveryAddress->address2 ?? "";
        if ($address1) { // If there is a delivery address.
            $address1 = preg_replace($addressNotAllowedChars, ' ', $address1 . ' ' . $address2);
            $address2 = "";
        }

        $zipCode = $deliveryAddress->postcode ?? "";
        $city = $deliveryAddress->city ?? "";

        $country = "";
        $countryId = $deliveryAddress->id_country ?? null;
        if ($countryId) {
            $deliveryCountry = new \Country($countryId);
            $country = $deliveryCountry->iso_code ?? "";
        }

        $state = "";
        $stateId = $deliveryAddress->id_state ?? null;
        if ($stateId) {
            $state = new \State($stateId);
            $state = $state->iso_code ?? "";
        }

        return  [
            'firstName' => $deliveryAddress->firstname ?? "",
            'lastName' => $deliveryAddress->lastname ?? "",
            'category' => 'PRIVATE',
            'address' => $address1,
            'address2' => $address2,
            'zipCode' => $zipCode,
            'city' => $city,
            'state' => $state,
            'phoneNumber' => $deliveryAddress->phone_mobile ?? $deliveryAddress->phone ?? "",
            'country' => $country,
            'deliveryCompanyName' => $deliveryCompanyName,
            'shippingMethod' => 'PACKAGE_DELIVERY_COMPANY',
            'shippingSpeed' => 'STANDARD'
        ];
    }

    private function getShoppingCart($cartProducts, $currency)
    {
        if (! is_array($cartProducts) || count($cartProducts) == 0) {
            return [];
        }

        $productLabelRegexNotAllowed = '#[^A-Z0-9ÁÀÂÄÉÈÊËÍÌÎÏÓÒÔÖÚÙÛÜÇ ]#ui';

        $products = [];
        foreach ($cartProducts as $product) {
            $name = $product['name'] ?? $product['product_name'] ?? '';
            $price = $currency->convertAmountToInteger($product['price']);
            $rate = $product['rate'] ?? $product['tax_rate'] ?? 0;

            $product = [
                "productLabel" => \Tools::substr(preg_replace($productLabelRegexNotAllowed, ' ', $name), 0, 255),
                "productRef" => $product['id_product'],
                "productQty" => (int) $product['cart_quantity'],
                "productAmount" => $price,
                "productVat" => number_format($rate, 4, '.', '')
            ];

            array_push($products, $product);
        }

        return $products;
    }
}