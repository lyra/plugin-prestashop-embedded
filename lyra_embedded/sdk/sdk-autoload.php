<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

spl_autoload_register('lyraEmbeddedSdkAutoload', true, true);

function lyraEmbeddedSdkAutoload($className)
{
    if (empty($className) || strpos($className, 'Lyranetwork\\LyraEmbedded\\Sdk') !== 0) {
        // Not Lyra SDK classes.
        return;
    }

    $className = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $className);
    require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . $className . '.php';
}