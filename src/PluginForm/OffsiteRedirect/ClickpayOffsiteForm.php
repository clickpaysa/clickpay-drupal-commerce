<?php

namespace Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect\Clickpay_core;
use Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect\ClickpayApi;
use Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect\ClickpayRequestHolder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\Yaml\Yaml;

class ClickpayOffsiteForm extends BasePaymentOffsiteForm
{

    /**
     * The logger.
     *
     * @var \Drupal\Core\Logger\LoggerChannelInterface $logger
     *
     */
    protected $logger;

    /**
     * {@inheritdoc}
     */

    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $form = parent::buildConfigurationForm($form, $form_state);

        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        $payment = $this->entity;

        /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
        $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
        $config = $payment_gateway_plugin->getConfiguration();

        /**Clickpay SDK**/
        $clickpay_init = new Clickpay_core();
        $clickpay_core = new ClickpayRequestHolder();
        $clickpay_api = ClickpayApi::getInstance($config['region'], $config['profile_id'], $config['server_key']);



        /** @var \Drupal\commerce_price\Price $amount */
        $payment_amount = $payment->getAmount();


        /** @var \Drupal\profile\Entity\ProfileInterface $profile */
        $profile = $payment->getOrder()->getBillingProfile();


        /** @var \Drupal\user\Entity\User $user */
        $language = \Drupal::languageManager()->getCurrentLanguage()->getName();

        $user_email = \Drupal::currentUser()->getEmail();


        /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $billing_info */
        $billing_info = $profile->get('address')->first();


        /** @var \Drupal\telephone\Plugin\Field\FieldType\TelephoneItem $phone */
        if($profile->hasField('field_phone'))
        {
            $phone = $profile->get('field_phone')->value;
        }
        elseif ($profile->hasField('telephone'))
            $phone = $profile->get('telephone')->value;
        else
        {
            $phone = 00000000000;
        }


        /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
        $order = $payment->getOrder();
        $order_number = $order->id();
        $transaction_title = t('Order Number: ') . $order_number;


        $raw_amount = $payment_amount->getNumber();
        $amount = floatval($raw_amount);

        $currency_code = $payment_amount->getCurrencyCode();
        $order_country_code = $billing_info->getCountryCode();
        $country = \Drupal::service('address.country_repository')->get($order_country_code)->getThreeLetterCode();

        $billing_full_name = $billing_info->getGivenName() . ' ' . $billing_info->getFamilyName();
        $site_url = Url::fromUri('internal:/', ['absolute' => TRUE])->toString();
        $payment_entity = $payment_gateway_plugin->getClickpayEntity();
        $call_back = $site_url . '/payment/notify/' . $payment_entity;

        $platform_info = Yaml::parseFile(DRUPAL_ROOT . '/modules/contrib/commerce/commerce.info.yml');
        $platform_version = $platform_info['version'];

        $plugin_info = Yaml::parseFile(DRUPAL_ROOT . '/modules/contrib/clickpay_drupal_commerce/clickpay_drupal_commerce.info.yml');
        $plugin_version = $plugin_info['version'];
        $payment_page_mode = $config['pay_page_mode'];

        $clickpay_core
            ->set01PaymentCode('all') // 'card', 'stcpay', 'amex' ...
            ->set02Transaction($payment_page_mode, 'ecom')
            ->set03Cart($order_number, $currency_code, $amount, $transaction_title)
            ->set04CustomerDetails($billing_full_name, $user_email, $phone, $billing_info->getAddressLine1(), $billing_info->getLocality(), $billing_info->getAdministrativeArea() ? str_replace(" Governorate", "", $billing_info->getAdministrativeArea()) : $billing_info->getLocality(), $country, $billing_info->getPostalCode() ? $billing_info->getPostalCode() : '00000', gethostbyname($site_url))
            ->set05ShippingDetails(true)
            ->set06HideShipping(false)
            ->set07URLs($form['#return_url'], $call_back)
            ->set08Lang($language)
            ->set09Framed(false)
            ->set99PluginInfo('DrupalCommerce',$platform_version,$plugin_version);


        $pp_params = $clickpay_core->pt_build();
        $response = $clickpay_api->create_pay_page($pp_params);

        if ($response->success) {
            $redirect_url = $response->redirect_url;
            $form['commerce_message']['#action'] = $redirect_url;
            $redirect_method = 'post';

            return $this->buildRedirectForm($form, $form_state, $redirect_url, $pp_params, $redirect_method);
        } else {
            \Drupal::messenger()->addStatus($this->t('Something went wrong, please try again later'));
            $this->logger->error('failed to create payment page for order : and response from Clickpay is :' . $response);
        }

    }

    /**
     * Gets the unit price for each order item.
     * @param \Drupal\commerce_order\Entity\OrderInterface $order
     *
     * @return array
     */
    protected function getOrderItemsUnitPrice(OrderInterface $order)
    {
        $order_item_unit = [];
        $order_items = $order->getItems();
        foreach ($order_items as $order_item) {
            if (!empty($order_item)) {
                $order_item_unit[] = number_format($order_item->getUnitPrice()->getNumber(), 3);
            }
        }
        return $order_item_unit;
    }

    /**
     * Gets the quantity for each order item.
     * @param \Drupal\commerce_order\Entity\OrderInterface $order
     *
     * @return array
     */
    protected function getOrderItemsQuantity(OrderInterface $order)
    {
        $order_item_quantity = [];
        $order_items = $order->getItems();
        foreach ($order_items as $order_item) {
            if (!empty($order_item)) {
                $order_item_quantity[] = number_format($order_item->getQuantity());
            }
        }
        return $order_item_quantity;
    }

    /**
     * Gets the title for each order item.
     * @param \Drupal\commerce_order\Entity\OrderInterface $order
     *
     * @return array
     */
    protected function getItemsTitleName(OrderInterface $order)
    {
        $order_item_title = [];
        $order_items = $order->getItems();
        foreach ($order_items as $order_item) {
            if (!empty($order_item)) {
                $order_item_title[] = $order_item->getTitle();
            }
        }
        return $order_item_title;
    }

    /**
     * Get the discount out of the total adjustments
     * @param \Drupal\commerce_order\Entity\OrderInterface $order
     *
     * @return string
     */
    protected function getPromotionsTotal(OrderInterface $order)
    {
        foreach ($order->collectAdjustments() as $adjustment) {
            $type = $adjustment->getType();
            if ($type == 'Promotion') {
                return $promotion = number_format($type->getAmount()->getNumber(), 3);
            }
        }
    }

    /**
     * Get the all other charges (e.g. shipping charges, taxes, VAT, etc) minus discounts
     * @param \Drupal\commerce_order\Entity\OrderInterface $order
     *
     * @return array
     */
    protected function getOtherCharges(OrderInterface $order)
    {
        $other_charges = [];
        foreach ($order->collectAdjustments() as $adjustment) {
            if ($adjustment->isPositive()) {
                $other_charges[] = number_format($adjustment->getAmount()->getNumber(), 3);
            }
        }
        return $other_charges;
    }

}
