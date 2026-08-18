<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

/**
 * Register the Lyra Embedded module class autoloader.
 *
 * Prepends the custom autoloader so Lyra Embedded classes are resolved before
 * any other registered autoloader.
 */
spl_autoload_register('lyraEmbeddedLoadClass', true, true);

/**
 * Autoload callback for all Lyra Embedded module classes.
 *
 * Resolves classes whose names contain "Lyra" by looking them up in the
 * following directories (in order):
 *   1. classes/admin/
 *   2. classes/payment/
 *   3. classes/
 *
 * @param string $class Fully-qualified or short class name to load.
 *
 * @return void
 */
function lyraEmbeddedLoadClass($class)
{
    if (stripos($class, 'LyraEmbedded') === false) {
        return;
    }

    $parts = explode('\\', $class);
    $shortClass = end($parts);

    $generalPath = _PS_MODULE_DIR_ . 'lyra_embedded' . DIRECTORY_SEPARATOR;
    $utilityPath = $generalPath . 'utility' . DIRECTORY_SEPARATOR . $shortClass . '.php';
    $paymentPath = $generalPath . 'classes' . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . $shortClass . '.php';

    if (is_file($utilityPath)) {
        require_once $utilityPath;
    } elseif (is_file($paymentPath)) {
        require_once $paymentPath;
    }
}