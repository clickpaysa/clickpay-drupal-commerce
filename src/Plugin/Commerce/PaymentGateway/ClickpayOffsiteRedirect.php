<?php

namespace Drupal\clickpay_drupal_commerce\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect\Clickpay_core;
use Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect\ClickpayApi;
use Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect\ClickpayEnum;
use Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect\ClickpayFollowupHolder;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "clickpay_offsite_redirect",
 *   label = "Clickpay Payment Gateway",
 *   display_label = "Clickpay Payment Gateway",
 *   forms = {
 *     "offsite-payment" = "Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect\ClickpayOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "visa", "mastercard",
 *   },
 * )
 */
class ClickpayOffsiteRedirect extends OffsitePaymentGatewayBase implements SupportsRefundsInterface,SupportsAuthorizationsInterface
{
    /**
     * The logger.
     *
     * @var \Drupal\Core\Logger\LoggerChannelInterface
     */
    protected $logger;

    /**
     * The HTTP client.
     *
     * @var \GuzzleHttp\Client
     */
    protected $httpClient;

    /**
     * The time.
     *
     * @var \Drupal\Component\Datetime\TimeInterface
     */
    protected $time;

    /**
     * Module handler service.
     *
     * @var \Drupal\Core\Extension\ModuleHandlerInterface
     */
    protected $moduleHandler;


    /**
     * Constructs a new ClickpayOffsiteRedirect object.
     *
     * @param array $configuration
     *   A configuration array containing information about the plugin instance.
     * @param string $plugin_id
     *   The plugin_id for the plugin instance.
     * @param mixed $plugin_definition
     *   The plugin implementation definition.
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     *   The entity type manager.
     * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
     *   The payment type manager.
     * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
     *   The payment method type manager.
     * @param \Drupal\Component\Datetime\TimeInterface $time
     *   The time.
     * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_channel_factory
     *   The logger channel factory.
     * @param \GuzzleHttp\ClientInterface $client
     *   The client.
     * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
     *   The module handler.
     */
    public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, LoggerChannelFactoryInterface $logger_channel_factory, ClientInterface $client, ModuleHandlerInterface $module_handler)
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

        $this->logger = $logger_channel_factory->get('clickpay_drupal_commerce');
        $this->httpClient = $client;
        $this->moduleHandler = $module_handler;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('entity_type.manager'),
            $container->get('plugin.manager.commerce_payment_type'),
            $container->get('plugin.manager.commerce_payment_method_type'),
            $container->get('datetime.time'),
            $container->get('logger.factory'),
            $container->get('http_client'),
            $container->get('module_handler')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function defaultConfiguration()
    {
        return [
                'profile_id' => '',
                'server_key' => '',
                'region' => '',
                'pay_page_mode' => '',
                'iframe' => '',
                'complete_order_status' => '',
                'hide_shipping_address' => '',
            ] + parent::defaultConfiguration();
    }

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state)
    {
        $form = parent::buildConfigurationForm($form, $form_state);

        $form['profile_id'] = [
            '#type' => 'number',
            '#title' => $this->t('Merchant Profile id'),
            '#required' => TRUE,
            '#description' => $this->t('Your merchant profile id , you can find the profile id on your Clickpay Merchant’s Dashboard- profile.'),
            '#default_value' => $this->configuration['profile_id'],
        ];
        $form['server_key'] = [
            '#type' => 'textfield',
            '#required' => TRUE,
            '#title' => $this->t('Server Key'),
            '#description' => $this->t('You can find the Server key on your Clickpay Merchant’s Dashboard - Developers - Key management.'),
            '#default_value' => $this->configuration['server_key'],
        ];
        $form['region'] = [
            '#type' => 'select',
            '#required' => TRUE,
            '#title' => $this->t('Merchant region'),
            '#description' => $this->t('The region you registered in with Clickpay'),
            '#options' => [
                'SAU' => $this->t('Saudi Arabia'),
            ],
            '#default_value' => $this->configuration['region'],
        ];
        $form['pay_page_mode'] =[
            '#type' => 'select',
            '#required' => TRUE,
            '#title' => $this->t('Pay Page Mode'),
            '#description' => $this->t('The mode you need to process payment with '),
            '#options' => [
                'sale' => $this->t('Sale'),
                'auth' => $this->t('Auth'),
            ],
            '#default_value' => $this->configuration['pay_page_mode'],
        ];

        $form['iframe'] =[
            '#type' => 'select',
            '#required' => TRUE,
            '#title' => $this->t('Integration Mode'),
            '#description' => $this->t('The mode you need to integrate with '),
            '#options' => [
                'false' => $this->t('Redirect outside the site'),
                'true' => $this->t('Iframe inside the site'),
            ],
            '#default_value' => $this->configuration['integration_mode'],
        ];

        $form['hide_shipping_address'] =[
            '#type' => 'select',
            '#required' => FALSE,
            '#title' => $this->t('Hide shipping address'),
            '#description' => $this->t('Hide shipping address'),
            '#options' => [
                'false' => $this->t('Show shipping address'),
                'true' => $this->t('Hide shipping address'),
            ],
            '#default_value' => $this->configuration['hide_shipping_address'],
        ];

        $form['complete_order_status'] = [
            '#type' => 'select',
            '#required' => TRUE,
            '#title' => $this->t('Order Status'),
            '#description' => $this->t('Order status after payment is done'),
            '#options' => [
                'completed' => $this->t('completed ' . "  '(this status is used when no action is needed )'  "),
                'fulfillment' => $this->t('fulfillment ' . "  '(if you select this option you should to use order fulfillment workflow)'  "),
                'validation' => $this->t('validation ' . "  '(if you select this option you should to use order default with validation workflow)'  "),
            ],
            '#default_value' => $this->configuration['complete_order_status'],
        ];

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateConfigurationForm($form, $form_state);

        if (!$form_state->getErrors() && $form_state->isSubmitted()) {
            $values = $form_state->getValue($form['#parents']);
            $this->configuration['profile_id'] = $values['profile_id'];
            $this->configuration['server_key'] = $values['server_key'];
            $this->configuration['region'] = $values['region'];
            $this->configuration['pay_page_mode'] = $values['pay_page_mode'];
            $this->configuration['iframe'] = $values['iframe'];
            $this->configuration['hide_shipping_address'] = $values['hide_shipping_address'];
            $this->configuration['complete_order_status'] = $values['complete_order_status'];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
    {
        parent::submitConfigurationForm($form, $form_state);
        if (!$form_state->getErrors()) {
            $values = $form_state->getValue($form['#parents']);
            $this->configuration['profile_id'] = $values['profile_id'];
            $this->configuration['server_key'] = $values['server_key'];
            $this->configuration['region'] = $values['region'];
            $this->configuration['pay_page_mode'] = $values['pay_page_mode'];
            $this->configuration['iframe'] = $values['iframe'];
            $this->configuration['hide_shipping_address'] = $values['hide_shipping_address'];
            $this->configuration['complete_order_status'] = $values['complete_order_status'];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onReturn(OrderInterface $order, Request $request)
    {

        $all_response = $request->request->all();

        /**Clickpay SDK**/
        $Clickpay_core = new Clickpay_core();
        $Clickpay_api = ClickpayApi::getInstance($this->configuration['region'], $this->configuration['profile_id'], $this->configuration['server_key']);

        $is_valid = $Clickpay_api->is_valid_redirect($all_response);

        if (!$is_valid) {
            $this->messenger()->addError($this->t('not valid result from Clickpay'));
        } else {
            $trans_ref = $request->request->get('tranRef');
            $respStatus = $request->request->get('respStatus');
            $transaction_type = $Clickpay_api->verify_payment($trans_ref);
            $transaction_type = $transaction_type->tran_type;
            $this->logger->info('return Payment information. Transaction reference: ' . $trans_ref);
            if ($respStatus === 'A') {
                $message = 'Your payment was successful to Clickpay with Transaction reference ';
                if ($transaction_type === 'Sale')
                {
                    $payment_status = 'completed';
                }
                elseif ($transaction_type === 'Auth')
                {
                    $payment_status = 'authorization';
                }

                $this->messenger()->addStatus($this->t($message . ' : %trans_ref', [
                    '%trans_ref' => $trans_ref,
                ]));
            } elseif ($respStatus === 'C') {
                $message = 'Your payment was Cancelled with Transaction reference ';
                $payment_status = 'cancelled';
                $this->messenger()->addError($this->t($message . ' : %trans_ref', [
                    '%trans_ref' => $trans_ref,
                ]));
            } else {
                $message = 'Your payment was '.$all_response['respMessage'].'with Transaction reference ';
                $payment_status = $all_response['respMessage'];
                $this->messenger()->addWarning($this->t($message . ' : %trans_ref', [
                    '%trans_ref' => $trans_ref,
                ]));
            }



            //Check if order don't have payments to insert it
            $query = \Drupal::entityQuery('commerce_payment')
                ->condition('order_id', $order->id())
                ->condition('remote_id', $trans_ref)
                ->condition('remote_state', $respStatus)
                ->execute();

            if (empty($query)) {
                $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
                $payment = $payment_storage->create([
                    'state' => $payment_status,
                    'amount' => $order->getTotalPrice(),
                    'payment_gateway' => $this->entityId,
                    'order_id' => $order->id(),
                    'remote_id' => $trans_ref,
                    'remote_state' => $respStatus,
                    'authorized' => $this->time->getRequestTime(),
                ]);
                $this->logger->info('Saving Payment information. Transaction reference: ' . $trans_ref);
                $payment->save();
                $this->logger->info('Payment information saved successfully. Transaction reference: ' . $trans_ref);

            }

            $order->set('state', $this->configuration['complete_order_status']);
            $order->save();

        }


    }

    /**
     * {@inheritdoc}
     */
    public function onNotify(Request $request)
    {
        $order_id = $request->request->get('cartId');
        $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);


        $all_response = $request->request->all();

        /**Clickpay SDK**/
        $clickpay_core = new Clickpay_core();
        $clickpay_api = ClickpayApi::getInstance($this->configuration['region'], $this->configuration['profile_id'], $this->configuration['server_key']);

        $is_valid = $clickpay_api->is_valid_redirect($all_response);

        if (!$is_valid) {
            $this->messenger()->addError($this->t('not valid result from ClickPay'));
        } else {
            $trans_ref = $request->request->get('tranRef');
            $respStatus = $request->request->get('respStatus');
            $transaction_type = $clickpay_api->verify_payment($trans_ref);
            $transaction_type = $transaction_type->tran_type;

            $this->logger->info('return Payment information. Transaction reference: ' . $trans_ref);
            if ($respStatus === 'A') {
                $message = 'Your payment was successful to ClickPay with Transaction reference ';
                if ($transaction_type === 'Sale')
                {
                    $payment_status = 'completed';
                }
                elseif ($transaction_type === 'Auth')
                {
                    $payment_status = 'authorization';
                }

                $this->messenger()->addStatus($this->t($message . ' : %trans_ref', [
                    '%trans_ref' => $trans_ref,
                ]));
            } elseif ($respStatus === 'C') {
                $message = 'Your payment was Cancelled with Transaction reference ';
                $payment_status = 'cancelled';
                $this->messenger()->addError($this->t($message . ' : %trans_ref', [
                    '%trans_ref' => $trans_ref,
                ]));
            } else {
                $message = 'Your payment was '.$all_response['respMessage'].'with Transaction reference ';
                $payment_status = $all_response['respMessage'];
                $this->messenger()->addWarning($this->t($message . ' : %trans_ref', [
                    '%trans_ref' => $trans_ref,
                ]));
            }



            //Check if order don't have payments to insert it
            $query = \Drupal::entityQuery('commerce_payment')
                ->condition('order_id', $order->id())
                ->condition('remote_id', $trans_ref)
                ->condition('remote_state', $respStatus)
                ->execute();

            if (empty($query)) {
                $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
                $payment = $payment_storage->create([
                    'state' => $payment_status,
                    'amount' => $order->getTotalPrice(),
                    'payment_gateway' => $this->entityId,
                    'order_id' => $order->id(),
                    'remote_id' => $trans_ref,
                    'remote_state' => $respStatus,
                    'authorized' => $this->time->getRequestTime(),
                ]);
                $this->logger->info('Saving Payment information. Transaction reference: ' . $trans_ref);
                $payment->save();
                $this->logger->info('Payment information saved successfully. Transaction reference: ' . $trans_ref);

            }
            $order->set('state', $this->configuration['complete_order_status']);
            $order->save();
            return new JsonResponse();
        }

    }

    /**
     * {@inheritdoc}
     */
    public function onCancel(OrderInterface $order, Request $request)
    {
        $this->messenger()->addError($this->t('You have canceled checkout at @gateway but may resume the checkout process here when you are ready.', [
            '@gateway' => $this->getDisplayLabel(),
        ]));
    }

    /**
     * {@inheritdoc}
     */
    public function refundPayment(PaymentInterface $payment, Price $amount = NULL)
    {
        $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
        // If not specified, refund the entire amount.
        $amount = $amount ?: $payment->getAmount();
        $decimal_amount = $amount->getNumber();
        $currency_code = $payment->getAmount()->getCurrencyCode();
        $remote_id = $payment->getRemoteId();
        $cart_id = $payment->getOrder()->id();

        /**Clickpay SDK**/
        $clickpay_core = new Clickpay_core();
        $clickpay_api = ClickpayApi::getInstance($this->configuration['region'], $this->configuration['profile_id'], $this->configuration['server_key']);
        $refund = new ClickpayFollowupHolder();
        $this->assertRefundAmount($payment, $amount);

        // Perform the refund request here, throw an exception if it fails.
        try {
            $refund->set02Transaction(ClickpayEnum::TRAN_TYPE_REFUND, ClickpayEnum::TRAN_CLASS_ECOM)
                ->set03Cart($cart_id, $currency_code, $decimal_amount, 'refunded from drupal')
                ->set30TransactionInfo($remote_id);

            $refund_params = $refund->pt_build();
            $result = $clickpay_api->request_followup($refund_params);

            $success = $result->success;
            $message = $result->message;
            $pending_success = $result->pending_success;

            if ($success) {
                // Determine whether payment has been fully or partially refunded.
                $old_refunded_amount = $payment->getRefundedAmount();
                $new_refunded_amount = $old_refunded_amount->add($amount);
                if ($new_refunded_amount->lessThan($payment->getAmount())) {
                    $payment->setState('partially_refunded');
                } else {
                    $payment->setState('refunded');
                }
                $payment->setRefundedAmount($new_refunded_amount);
                $payment->save();
            } else if ($pending_success) {
                $this->messenger()->addError($this->t('not valid result from ClickPay'."<br>" . $message));
            }
        } catch (\Exception $e) {
            $this->logger->log('error', 'failed to proceed to refund transaction:' . $remote_id."<br>" . $message);
            throw new PaymentGatewayException($e);
        }

    }

    public function capturePayment(PaymentInterface $payment, Price $amount = NULL)
    {
        $this->assertPaymentState($payment, ['authorization']);
        // If not specified, capture the entire amount.
        $amount = $amount ?: $payment->getAmount();
        $decimal_amount = $amount->getNumber();
        $currency_code = $payment->getAmount()->getCurrencyCode();
        $remote_id = $payment->getRemoteId();
        $cart_id = $payment->getOrder()->id();

        /**Clickpay SDK**/
        $clickpay_core = new Clickpay_core();
        $clickpay_api = ClickpayApi::getInstance($this->configuration['region'], $this->configuration['profile_id'], $this->configuration['server_key']);
        $capture = new ClickpayFollowupHolder();

        //to prevent from capture more than the order value
        $this->assertAmount($payment, $amount,'Capture');

        // Perform the refund request here, throw an exception if it fails.
        try {
            $capture->set02Transaction(ClickpayEnum::TRAN_TYPE_CAPTURE, ClickpayEnum::TRAN_CLASS_ECOM)
                ->set03Cart($cart_id, $currency_code, $decimal_amount, 'Capture from drupal')
                ->set30TransactionInfo($remote_id);

            $capture_params = $capture->pt_build();
            $result = $clickpay_api->request_followup($capture_params);

            $success = $result->success;
            $message = $result->message;
            $pending_success = $result->pending_success;

            if ($success) {
                if($amount < $payment->getAmount()) //partial capture
                {
                    // update the authorized record with the remaining value
                    $new_amount = $payment->getBalance()->subtract($amount);
                    $payment->setAmount($new_amount);
                    $payment->save();

                    // create new payment record for capture transacion
                    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
                    $payment = $payment_storage->create([
                        'state' => 'completed',
                        'amount' => $amount,
                        'payment_gateway' => $this->entityId,
                        'order_id' => $result->cart_id,
                        'remote_id' => $result->tran_ref,
                        'remote_state' => $result->payment_result->response_status,
                        'authorized' => $this->time->getRequestTime(),
                    ]);
                    $this->logger->info('Saving Payment information. Transaction reference: ' . $result->tran_ref);
                    $payment->save();
                    $this->logger->info('Payment information saved successfully. Transaction reference: ' . $result->tran_ref);

                }
                else //full capture
                {
                    $payment->setState('completed');
                    $payment->setRemoteId($result->tran_ref);
                    $payment->setAmount($amount);
                    $payment->save();
                }

            } else if ($pending_success) {
                $this->messenger()->addError($this->t('not valid result from ClickPay'."<br>" . $message));
            }
        } catch (\Exception $e) {
            $this->logger->log('error', 'failed to proceed to capture transaction:' . $remote_id."<br>" . $message);
            throw new PaymentGatewayException($e);
        }
    }


    public function voidPayment(PaymentInterface $payment)
    {
        $this->assertPaymentState($payment, ['authorization']);
        // If not specified, capture the entire amount.
        $amount = $payment->getAmount();
        $decimal_amount = $amount->getNumber();
        $currency_code = $payment->getAmount()->getCurrencyCode();
        $remote_id = $payment->getRemoteId();
        $cart_id = $payment->getOrder()->id();

        /**Clickpay SDK**/
        $clickpay_core = new Clickpay_core();
        $clickpay_api = ClickpayApi::getInstance($this->configuration['region'], $this->configuration['profile_id'], $this->configuration['server_key']);
        $void = new ClickpayFollowupHolder();


        // Perform the refund request here, throw an exception if it fails.
        try {
            $void->set02Transaction(ClickpayEnum::TRAN_TYPE_VOID, ClickpayEnum::TRAN_CLASS_ECOM)
                ->set03Cart($cart_id, $currency_code, $decimal_amount, 'void from drupal')
                ->set30TransactionInfo($remote_id);

            $capture_params = $void->pt_build();
            $result = $clickpay_api->request_followup($capture_params);

            $success = $result->success;
            $message = $result->message;
            $pending_success = $result->pending_success;

            if ($success) {
                $payment->setState('authorization_voided');
                $payment->setRemoteId($result->tran_ref);
                $payment->save();

            } else if ($pending_success) {
                $this->messenger()->addError($this->t('not valid result from Clickpay'."<br>" . $message));
            }
        } catch (\Exception $e) {
            $this->logger->log('error', 'failed to proceed to void transaction:' . $remote_id. "<br>" . $message);
            throw new PaymentGatewayException($e);
        }
    }


    public function getShippingInfo(OrderInterface $order)
    {

        if (!$this->moduleHandler->moduleExists('commerce_shipping')) {
            return [];
        } else {
            // Check if the order references shipments.
            if ($order->hasField('shipments') && !$order->get('shipments')->isEmpty()) {
                $shipping_profiles = [];

                // Loop over the shipments to collect shipping profiles.
                foreach ($order->get('shipments')->referencedEntities() as $shipment) {
                    if ($shipment->get('shipping_profile')->isEmpty()) {
                        continue;
                    }
                    $shipping_profile = $shipment->getShippingProfile();
                    $shipping_profiles[$shipping_profile->id()] = $shipping_profile;
                }

                if ($shipping_profiles && count($shipping_profiles) === 1) {
                    $shipping_profile = reset($shipping_profiles);
                    /** @var \Drupal\address\AddressInterface $address */
                    $address = $shipping_profile->address->first();
                    $shipping_info = [
                        'shipping_first_name' => $address->getGivenName(),
                        'shipping_last_name' => $address->getFamilyName(),
                        'address_shipping' => $address->getAddressLine1(),
                        'city_shipping' => $address->getLocality(),
                        'state_shipping' => $address->getAdministrativeArea(),
                        'postal_code_shipping' => $address->getPostalCode(),
                        'country_shipping' => \Drupal::service('address.country_repository')->get($address->getCountryCode())->getThreeLetterCode(),
                    ];
                }
                return $shipping_info;
            }
        }
    }

    public function getClickpayEntity()
    {
        return $this->entityId;
    }

    public function assertAmount(PaymentInterface $payment, Price $amount,$type)
    {
        $balance = $payment->getBalance();
        if ($amount->greaterThan($balance)) {
            throw new InvalidRequestException(sprintf("Can't ".$type." more than %s.", $balance->__toString()));
        }
    }
}
