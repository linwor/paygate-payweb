<?php
/*
 * Copyright (c) 2020 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *
 * Magento v2.3.0+ implement CsrfAwareActionInterface but not earlier versions
 */

namespace Paygate\Paygate\Controller\Notify;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

class Indexm230 extends \Paygate\Paygate\Controller\AbstractPaygate implements CsrfAwareActionInterface
{
    private $storeId;
    protected $_messageManager;

    /**
     * indexAction
     *
     */
    public function execute()
    {
        ob_start();
        // PayGate API expects response of 'OK' for Notify function
        echo "OK";

        if ( $this->getConfigData( 'enable_ipn' ) != '0' ) {
            $baseurl = $this->_storeManager->getStore()->getBaseUrl();
            $this->_logger->debug( 'Base URL: ' . $baseurl );

            $errors       = false;
            $paygate_data = array();

            $notify_data = array();
            $post_data   = '';
            // Get notify data
            if ( !$errors ) {
                $paygate_data = $this->getPostData();
                if ( $paygate_data === false ) {
                    $errors = true;
                }
            }
            $this->_logger->debug( 'Notify: PayGate Data: ' . json_encode( $paygate_data ) );

            // Verify security signature
            $checkSumParams = '';
            if ( !$errors ) {

                foreach ( $paygate_data as $key => $val ) {
                    $post_data .= $key . '=' . $val . "\n";
                    $notify_data[$key] = $val;

                    if ( $key == 'PAYGATE_ID' ) {
                        $checkSumParams .= $val;
                    }
                    if ( $key != 'CHECKSUM' && $key != 'PAYGATE_ID' ) {
                        $checkSumParams .= $val;
                    }

                    if ( sizeof( $notify_data ) == 0 ) {
                        $errors = true;
                    }
                }
                if ( $this->getConfigData( 'test_mode' ) != '0' ) {
                    $encryption_key = 'secret';
                } else {
                    $encryption_key = $this->getConfigData( 'encryption_key' );
                }
                $checkSumParams .= $encryption_key;
            }

            // Verify security signature
            if ( !$errors ) {
                $this->_logger->debug( 'Notify: Checksum Parameters: ' . $checkSumParams );
                $checkSumParams = md5( $checkSumParams );
                $this->_logger->debug( 'Notify: Checksum Parameters: ' . $checkSumParams );
                if ( $checkSumParams != $notify_data['CHECKSUM'] ) {
                    $errors = true;
                }
            }

            if ( !$errors ) {
                // Check if order process by IPN or Redirect
                $this->_logger->debug( 'Notify: Enable IPN: ' . $this->getConfigData( 'enable_ipn' ) );

                if ( $this->getConfigData( 'enable_ipn' ) != '0' ) {
                    // Prepare PayGate Data
                    $status        = intval( filter_var( $paygate_data['TRANSACTION_STATUS'], FILTER_SANITIZE_STRING ) );
                    $reference     = filter_var( $paygate_data['REFERENCE'], FILTER_SANITIZE_STRING );
                    $transactionId = filter_var( $paygate_data['TRANSACTION_ID'], FILTER_SANITIZE_STRING );
                    $payRequestId  = filter_var( $paygate_data['PAY_REQUEST_ID'], FILTER_SANITIZE_STRING );

                    // Load order
                    $orderId       = $reference;
                    $this->_order  = $this->_orderFactory->create()->loadByIncrementId( $orderId );
                    $this->storeId = $this->_order->getStoreId();

                    //Check to see if order already has an invoice
                    $hasInvoices = $this->_order->hasInvoices();
                    $this->_logger->debug( 'Notify: Has Invoices: ' . $hasInvoices );

                    // Update order additional payment information

                    if ( $status == 1 ) {
                        $message = __(
                            'Notify Response, Transaction has been approved: PAY_REQUEST_ID: "%1"',
                            $payRequestId
                        );
                        $this->_order->setStatus( \Magento\Sales\Model\Order::STATE_PROCESSING );
                        $this->_order->save();
                        $this->_order->addStatusHistoryComment( $message, \Magento\Sales\Model\Order::STATE_PROCESSING )->setIsCustomerNotified( false )->save();

                        $order                  = $this->_order;
                        $order_successful_email = $this->_paymentMethod->getConfigData( 'order_email' );
                        if ( $order_successful_email != '0' ) {
                            $this->OrderSender->send( $order );
                            $order->addStatusHistoryComment( __( 'Notified customer about order #%1.', $order->getId() ) )->setIsCustomerNotified( true )->save();
                        }

                        if ( !$hasInvoices ) {

                            // Capture invoice when payment is successful
                            $invoice = $this->_invoiceService->prepareInvoice( $order );
                            $invoice->setRequestedCaptureCase( \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE );
                            $invoice->register();

                            // Save the invoice to the order
                            $transaction = $this->_objectManager->create( 'Magento\Framework\DB\Transaction' )
                                ->addObject( $invoice )
                                ->addObject( $invoice->getOrder() );

                            $transaction->save();

                            // Magento\Sales\Model\Order\Email\Sender\InvoiceSender
                            $send_invoice_email = $this->_paymentMethod->getConfigData( 'invoice_email' );
                            if ( $send_invoice_email != '0' ) {
                                $this->invoiceSender->send( $invoice );
                                $order->addStatusHistoryComment( __( 'Notified customer about invoice #%1.', $invoice->getId() ) )->setIsCustomerNotified( true )->save();
                            }
                        }
                    } else if ( $status == 2 ) {
                        $this->_order->setStatus( \Magento\Sales\Model\Order::STATE_CANCELED );
                        $this->_order->save();
                        $this->_order->addStatusHistoryComment( "Notify Response, The transaction was declined by the bank, PayRequestID: " . $payRequestId, \Magento\Sales\Model\Order::STATE_PROCESSING )->setIsCustomerNotified( false )->save();
                        $this->_checkoutSession->restoreQuote();
                    } else if ( $status == 0 || $status == 4 ) {
                        $this->_order->setStatus( \Magento\Sales\Model\Order::STATE_CANCELED );
                        $this->_order->save();
                        $this->_order->addStatusHistoryComment( "Notify Response, The transaction was cancelled by the user, PayRequestID: " . $payRequestId, \Magento\Sales\Model\Order::STATE_CANCELED )->setIsCustomerNotified( false )->save();
                        $this->_checkoutSession->restoreQuote();
                    }
                }
            }
        }
    }

    // Retrieve post data
    public function getPostData()
    {
        // Posted variables from ITN
        $nData = $_POST;

        // Strip any slashes in data
        foreach ( $nData as $key => $val ) {
            $nData[$key] = stripslashes( $val );
        }

        // Return "false" if no data was received
        if ( sizeof( $nData ) == 0 || !isset( $nData['CHECKSUM'] ) ) {
            return ( false );
        } else {
            return ( $nData );
        }

    }

    /**
     * saveInvoice
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function saveInvoice()
    {
        // Check for mail msg
        $invoice = $this->_order->prepareInvoice();

        $invoice->register()->capture();

        /**
         * @var \Magento\Framework\DB\Transaction $transaction
         */
        $transaction = $this->_transactionFactory->create();
        $transaction->addObject( $invoice )
            ->addObject( $invoice->getOrder() )
            ->save();

        $this->_order->addStatusHistoryComment( __( 'Notified customer about invoice #%1.', $invoice->getIncrementId() ) );
        $this->_order->setIsCustomerNotified( true );
        $this->_order->save();
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException( RequestInterface $request ):  ? InvalidRequestException
    {
        // TODO: Implement createCsrfValidationException() method.
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf( RequestInterface $request ) :  ? bool
    {
        return true;
    }
}
