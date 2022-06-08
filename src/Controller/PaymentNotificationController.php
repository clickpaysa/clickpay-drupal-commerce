<?php
namespace Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect;


use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;
use Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect\Clickpay_core;
use Laminas\Diactoros\Response\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect\ClickpayApi;
use Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect\ClickpayEnum;
use Drupal\clickpay_drupal_commerce\PluginForm\OffsiteRedirect\ClickpayFollowupHolder;

class PaymentNotificationController extends ControllerBase implements SupportsNotificationsInterface
{
    /**
     * The logger.
     *
     * @var \Drupal\Core\Logger\LoggerChannelInterface $logger
     *
     */
    protected $logger;


    /**
     * Processes the notification request.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The request.
     *
     * @return \Symfony\Component\HttpFoundation\Response|null
     *   The response, or NULL to return an empty HTTP 200 response.
     */

    public function onNotify(Request $request)
    {
        $order_id = $request->request->get('cartId');
        $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
        $all_response = $request->request->all();

        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        $payment = $this->entityTypeManager->getStorage('commerce_payment')->load($request->query->get('clickpay_offsite_redirect'));

        /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
        $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
        $config = $payment_gateway_plugin->getConfiguration();


        /**ClickPay SDK**/
        $clickpay_core = new Clickpay_core();
        $clickpay_api = ClickpayApi::getInstance($config['region'], $config['profile_id'], $config['server_key']);

        $is_valid = $clickpay_api->is_valid_redirect($all_response);

        if (!$is_valid) {
            $this->messenger()->addError($this->t('not valid result from ClickPay'));
        }
        else {
            $trans_ref = $request->request->get('tranRef');
            $respStatus = $request->request->get('respStatus');
            $transaction_type = $clickpay_api->verify_payment($trans_ref);
            $transaction_type = $transaction_type->tran_type;
            $amount = $request->request->get('cart_amount');

            //Listen to All transaction types
            if ($respStatus === 'A') {
                switch ($transaction_type) {
                    case 'Sale':
                        $payment_status = 'completed';
                        break;
                    case 'Auth':
                        $payment_status = 'authorization';
                        break;
                    case 'Refund':
                        $payment_status = 'refunded';
                        break;
                    case 'Capture':
                        $payment_status = 'completed';
                        break;
                    case 'Void':
                        $payment_status = 'authorization_voided';
                        break;

                    default:
                        //code to be executed if n is different from all labels;
                }
            }
            elseif ($respStatus === 'C') {
                $message = 'Your payment was Cancelled with Transaction reference ';
                $payment_status = 'cancelled';
                $this->logger->info($message . $trans_ref);
            }
            else {
                $message = 'Your payment was '.$all_response['respMessage'].'with Transaction reference ';
                $payment_status = $all_response['respMessage'];
                $this->logger->info($message . $trans_ref);
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
            else
            {
                $query->setState($payment_status);
                $query->save();
            }

            return new JsonResponse();
        }
    }
}