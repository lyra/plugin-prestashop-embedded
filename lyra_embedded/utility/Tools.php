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

use Lyranetwork\LyraEmbedded\Sdk\Form\Api as LyraApi;

/**
 * Utility / helper class for the Lyra payment module.
 *
 * Provides static gateway configuration constants, a shared file-logger instance,
 * REST-response validation helpers, and convenience accessors for PrestaShop's
 * configuration table.
 *
 * This class is not meant to be instantiated.
 */
class Tools
{
    /** @var string The gateway code identifier */
    private static $GATEWAY_CODE = 'Lyra';

    /** @var string The gateway product name shown in the module. */
    private static $GATEWAY_NAME = 'Lyra Collect';

    /** @var string The merchant Back Office name */
    private static $BACKOFFICE_NAME = 'Lyra Expert';

    /** @var string The REST API base URL */
    private static $REST_URL = 'https://api.lyra.com/api-payment/';

    /** @var string The static resources base URL */
    private static $STATIC_URL = 'https://static.lyra.com/static/';

    /** @var string The path of the epsilon project */
    private static $EPSILON_PATH = 'js/epsilon/stable/';

    /** @var string The embedded form default theme */
    private static  $THEME = 'neon';

    /** @var string The default language */
    private static $LANGUAGE = 'en';

    /** @var string The CMS identifier for tracking */
    private static $CMS_IDENTIFIER = 'PrestaShop_Embedded_1.7-9.x';

    /** @var string The support contact information */
    private static $SUPPORT_EMAIL = 'https://support.lyra.com/hc/fr/requests/new';

    /** @var string The current plugin version */
    private static $PLUGIN_VERSION = '1.0.0';

    /** @var string The default context mode */
    private static $MODE = 'test';

    /** @var FileLogger Shared logger instance (lazy-initialised) */
    private static $logger;

    public static $pluginFeatures = array(
        'whitelabelall' => false
    );

    /**
     * Return the value of a static configuration constant by name.
     *
     * Allows callers to read private constants without exposing individual getters for each one.
     *
     * @param  string $name  Name of the static property to read.
     *
     * @return mixed  The property value, or an empty string when the name is
     *                invalid or the property does not exist.
     */
    public static function getDefault($name): string
    {
        if (! is_string($name)) {
            return '';
        }

        if (! isset(self::$$name)) {
            return '';
        }

        return self::$$name;
    }

    /**
     * Return the absolute URL to the JS and CSS static assets.
     *
     * @return string
     */
    public static function getStaticUrl(): string
    {
        return LyraApi::getWhiteLabelUrl(self::getWhiteLabel(), 'staticUrl') ?? self::$STATIC_URL;
    }

    /**
     * Return the absolute URL to the REST API URL.
     *
     * @return string
     */
    public static function getRestUrl($whiteLabel = null): string
    {
        if ($whiteLabel === null) {
            $whiteLabel = self::getWhiteLabel();
        }

        return LyraApi::getWhiteLabelUrl($whiteLabel, 'restUrl') ?? self::$REST_URL;
    }

    /**
     * Return the absolute URL to the Epsilon JS library static assets.
     *
     * @return string
     */
    public static function getEpsilonUrl(): string
    {
        return self::getStaticUrl() . self::$EPSILON_PATH;
    }

    public static function getWhiteLabel(): string
    {
        if (! (self::$pluginFeatures['whitelabelall'] ?? false)) {
            return '';
        }

        $activeModel = RestHelper::getActiveFormConfig();
        $whiteLabel = $activeModel['shopConfig']['payzenWhiteLabel'] ?? '';
        if ($whiteLabel !== '' && $whiteLabel !== 'EU') {
            return $whiteLabel;
        }

        return '';
    }

    /**
     * Return the shared module logger, creating it on first call (lazy singleton).
     * The logger is configured to write to a file named `YYYY_MM_lyra_embedded.log` in the
     * PrestaShop `var/logs/` directory.
     *
     * @return FileLogger
     */
    public static function getLogger()
    {
        if (! self::$logger) {
            self::$logger = new \FileLogger();

            $logs_dir = _PS_ROOT_DIR_ . '/var/logs/';

            self::$logger->setFilename($logs_dir . date('Y_m') . '_lyra_embedded.log');
        }

        return self::$logger;
    }

    /**
     * Build the IPN (Instant Payment Notification) URL for the default shop.
     *
     * The URL points to the `validation` front controller of this module and
     * uses HTTPS when SSL is enabled for the default shop.
     *
     * @return string  Absolute URL to `<shop_domain>/module/lyra_embedded/validation`.
     */
    public static function getIpnUrl(): string
    {
        $shop = new \Shop(\Configuration::get('PS_SHOP_DEFAULT'));

        // SSL enabled on default shop?
        $id_shop_group = $shop->id_shop_group ?? $shop->id_group_shop;
        $ssl = \Configuration::get('PS_SSL_ENABLED', null, $id_shop_group, $shop->id);

        return ($ssl ? 'https://' . $shop->domain_ssl : 'http://' . $shop->domain)
            . $shop->getBaseURI() . 'module/lyra_embedded/validation';
    }

    /**
     * Return an associative array of documentation links keyed by language name.
     * Each entry maps a human-readable language label to the corresponding sitemap URL.
     *
     * @return array<string, string>
     */
    public static function getDocFiles(): array
    {
        // Get documentation links.
        $docLanguages = [
            'fr' => 'Français',
            'en' => 'English',
            'es' => 'Español',
            'de' => 'Deutsch',
            'br' => 'Português',
            'pt' => 'Português'
            // Complete when other languages are managed.
        ];

        $docFiles = [];
        $whiteLabel = self::getWhiteLabel();
        foreach (LyraApi::getOnlineDocUri($whiteLabel) as $lang => $docUri) {
            $docFiles[$docLanguages[$lang]] = $docUri . 'prestashop_embedded/sitemap.html';
        }

        return $docFiles;
    }

    /**
     * Build the contrib string sent to the payment gateway for tracking purposes.
     * The string includes the CMS identifier, plugin version, PrestaShop version,
     * PHP version, and a Cloud flag when running on PrestaShop's hosted platform.
     *
     * @return string
     */
    public static function getContrib(): string
    {
        $contrib = self::getDefault('CMS_IDENTIFIER') . '_' . self::getDefault('PLUGIN_VERSION') . '/' . _PS_VERSION_ . '/'
            . LyraApi::shortPhpVersion();

        if (defined('_PS_HOST_MODE_')) {
            $contrib .= '/Cloud';
        }

        return $contrib;
    }

    /**
     * Return the two-letter ISO 639-1 language code for the given PrestaShop language ID.
     *
     * @param int $langId PrestaShop language ID.
     * @return string Lowercase two-letter language code (e.g. "fr", "en").
     */
    public static function getLanguageIsoCode($langId): string
    {
        if ($langId === null) {
            return self::getDefault('LANGUAGE');
        }

        $language = \Language::getLanguage($langId);
        $languageIsoCode = $language['language_code'] ? \Tools::substr($language['language_code'], 0, 2) : $language['iso_code'];
        $languageIsoCode = \Tools::strtolower($languageIsoCode);

        return $languageIsoCode;
    }

    /**
     * Return the two-letter ISO 639-1 language code for the given PrestaShop language ID,
     * falling back to the module default language when the resolved code is not supported
     * by the Lyra payment gateway.
     *
     * @param int $langId PrestaShop language ID.
     * @return string Lowercase two-letter language code supported by the gateway.
     */
    public static function getLyraSupportedLanguageIsoCode($langId): string
    {
        $languageIsoCode = self::getLanguageIsoCode($langId);
        if (LyraApi::isSupportedLanguage($languageIsoCode)) {
            return $languageIsoCode;
        }

        return self::getDefault('LANGUAGE');
    }

    /**
     * Convert an array keyed by ISO 639-1 language codes to an array keyed by
     * PrestaShop language IDs, using the "en" entry as fallback when a language
     * code is not present in the input array.
     *
     * @param array<string, mixed> $array Input array keyed by ISO language code.
     * @return array<int, mixed>          Output array keyed by PrestaShop language ID.
     */
    public static function convertIsoArrayToIdArray($array)
    {
        if (! is_array($array) || empty($array)) {
            return [];
        }

        $converted = [];
        foreach (\Language::getLanguages(false) as $language) {
            $key = key_exists($language['iso_code'], $array) ? $language['iso_code'] : 'en';

            $converted[$language['id_lang']] = $array[$key];
        }

        return $converted;
    }

    /**
     * Rebuild the shop context from the given order's shop ID.
     * Sets the PrestaShop context shop instance and the active shop scope so that
     * subsequent configuration reads use the correct shop.
     *
     * @param \Order $order The order whose shop ID is used to restore the context.
     * @return void
     */
    public static function rebuildShopContext($order)
    {
        if (isset($order->id_shop)) {
            $shopInstance = new \Shop($order->id_shop);
            \Context::getContext()->shop = $shopInstance;
            \Shop::setContext(\Shop::CONTEXT_SHOP, $order->id_shop);
        }
    }

    /**
     * Return true if order is in a successful state.
     *
     * @param Order $order
     * @return bool
     */
    public static function isSuccessState($order)
    {
        $os = new \OrderState((int) $order->getCurrentState());
        if (! $os->id) {
            return false;
        }

        $successStates = [
            \Configuration::get('LYRA_EMBEDDED_OS_TO_VALIDATE'),
            \Configuration::get('LYRA_EMBEDDED_OS_PENDING_AUTHORIZATION'),
            \Configuration::get('LYRA_EMBEDDED_OS_PARTIAL_REFUND'),
            \Configuration::get('PS_OS_PAYMENT'),
            \Configuration::get('PS_OS_OUTOFSTOCK_UNPAID'),
            \Configuration::get('PS_OS_OUTOFSTOCK_PAID')
        ];

        return in_array($os->id, $successStates) || (bool) $os->paid;
    }
}