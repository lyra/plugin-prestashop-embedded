<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra plugin for PrestaShop. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

class Lyra_EmbeddedWalletModuleFrontController extends ModuleFrontController
{
    /**
     * @var bool
     */
    public $display_column_right;

    /**
     * @var bool
     */
    public $display_column_left;

    /**
     * Initialize the page content for the customer wallet view.
     * Redirects unauthenticated customers to the home page.
     * Assigns Smarty variables for the form token and any
     * session-stored success/error messages, then renders the wallet template.
     */
    public function initContent()
    {
        $this->display_column_right = false;
        $this->display_column_left = false;

        $context = Context::getContext();
        if (empty($context->customer->id)) {
            Tools::redirect('index.php');
        }

        parent::initContent();

        $showWallet = false;

        $payment = new \Lyranetwork\LyraEmbedded\Payment\PaymentHandler();
        if ($payment->accountCustomerWallet()) {
            $vars = $payment->getTplVars(false, true);
            if (isset($vars['LYRA_FORM_TOKEN']) && ! empty($vars['LYRA_FORM_TOKEN'])) {
                $this->context->smarty->assign($vars);
                $showWallet = true;
            }
        }

        if (isset($this->context->cookie->lyraPaymentMethodTokenCreationSuccess)) {
            $this->success[] = $this->context->cookie->lyraPaymentMethodTokenCreationSuccess;
            unset($this->context->cookie->lyraPaymentMethodTokenCreationSuccess);
        }

        if ($this->context->cookie->lyraPaymentMethodTokenCreationError) {
            $this->errors[] = $this->context->cookie->lyraPaymentMethodTokenCreationError;
            unset($this->context->cookie->lyraPaymentMethodTokenCreationError);
        }

        $this->context->smarty->tpl_vars['page']->value['body_classes']['page-customer-account'] = true;
        $this->context->smarty->assign('LYRA_SHOW_WALLET', $showWallet);

        return $this->setTemplate('module:lyra_embedded/views/templates/front/customerWallet.tpl');
    }

    /**
     * Build the breadcrumb links for the wallet page, appending the
     * "My account" and "My payment means" entries.
     *
     * @return array<string, mixed>
     */
    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = $this->addMyAccountToBreadcrumb();
        $breadcrumb['links'][] = [
            'title' => $this->trans('My payment means', [], 'Modules.Lyra_Embedded.Customerwallet'),
            'url' => $this->context->link->getModuleLink($this->module->name, 'wallet', [], true)
        ];

        return $breadcrumb;
    }
}