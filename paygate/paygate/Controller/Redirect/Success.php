<?php
/*
 * Copyright (c) 2020 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Paygate\Paygate\Controller\Redirect;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Success extends \Paygate\Paygate\Controller\AbstractPaygate
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;

    /**
     * Execute
     */
    public function execute()
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug( $pre . 'bof' );
        $page_object = $this->pageFactory->create();
        try {
            // Get the user session
            $this->_order = $this->_checkoutSession->getLastRealOrder();

            // Check to see if order already has an invoice
            $hasInvoices = $this->_order->hasInvoices();

            $baseurl = $this->_storeManager->getStore()->getBaseUrl();

            /**
             * If IPN is enabled we don't want to process any further
             * However, we need this to make the redirect to the success / cart pages
             */
            if ( $this->getConfigData( 'enable_ipn' ) != 0 ) {
                if ( isset( $_POST['TRANSACTION_STATUS'] ) && $this->isPostValid() ) {
                    $data = $this->doQuery();
                    if ( count( $data ) > 0 ) {
                        $status = $data['TRANSACTION_STATUS'];
                    } else {
                        $status = 0;
                    }

                    switch ( $status ) {
                        case 1:
                            echo '<script>parent.location="' . $baseurl . 'checkout/onepage/success";</script>';
                            exit;
                            break;
                        case 2:
                        case 0:
                        case 4:
                            $this->_checkoutSession->restoreQuote();
                            echo '<script>window.top.location.href="' . $baseurl . 'checkout/cart/";</script>';
                            exit;
                            break;
                        default:
                            break;
                    }
                } else {
                    echo '<script>window.top.location.href="' . $baseurl . 'checkout/cart/";</script>';
                }
            }

            /**
             * IPN is not enabled - process the redirect
             */
            if ( isset( $_POST['TRANSACTION_STATUS'] ) && $this->isPostValid() ) {
                $data = $this->doQuery();
                if ( count( $data ) > 0 ) {
                    $status = $data['TRANSACTION_STATUS'];
                }

                switch ( $status ) {
                    case 1:
                        $status = \Magento\Sales\Model\Order::STATE_PROCESSING;
                        if ( $this->getConfigData( 'Successful_Order_status' ) != "" ) {
                            $status = $this->getConfigData( 'Successful_Order_status' );
                        }
                        $message = __(
                            'Redirect Response, Transaction has been approved: PAY_REQUEST_ID: "%1"',
                            $_POST['PAY_REQUEST_ID']
                        );
                        $this->_order->setStatus( $status ); // Configure the status
                        $this->_order->setState( $status )->save(); // Try and configure the status
                        $this->_order->save();
                        $order = $this->_order;
                        $order->addStatusHistoryComment( $message );

                        $model                  = $this->_paymentMethod;
                        $order_successful_email = $model->getConfigData( 'order_email' );

                        if ( $order_successful_email != '0' ) {
                            $this->OrderSender->send( $order );
                            $order->addStatusHistoryComment( __( 'Notified customer about order #%1.', $order->getId() ) )->setIsCustomerNotified( true )->save();
                        }

                        // Capture invoice when payment is successful
                        if ( !$hasInvoices && $this->getConfigData( 'enable_ipn' ) != '1' ) {
                            $invoice = $this->_invoiceService->prepareInvoice( $order );
                            $invoice->setRequestedCaptureCase( \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE );
                            $invoice->register();

                            // Save the invoice to the order
                            $transaction = $this->_objectManager->create( 'Magento\Framework\DB\Transaction' )
                                ->addObject( $invoice )
                                ->addObject( $invoice->getOrder() );

                            $transaction->save();

                            // Magento\Sales\Model\Order\Email\Sender\InvoiceSender
                            $send_invoice_email = $model->getConfigData( 'invoice_email' );
                            if ( $send_invoice_email != '0' ) {
                                $this->invoiceSender->send( $invoice );
                                $order->addStatusHistoryComment( __( 'Notified customer about invoice #%1.', $invoice->getId() ) )->setIsCustomerNotified( true )->save();
                            }
                        }

                        // Invoice capture code completed
                        echo '<script>parent.location="' . $baseurl . 'checkout/onepage/success";</script>';
                        exit;
                        break;
                    case 2:
                        $this->_order->addStatusHistoryComment( __( 'Redirect Response, Transaction has been declined, Pay_Request_Id: ' . $_POST['PAY_REQUEST_ID'] ) )->setIsCustomerNotified( false );
                        $this->_order->cancel()->save();
                        $this->_checkoutSession->restoreQuote();
                        echo '<script>window.top.location.href="' . $baseurl . 'checkout/cart/";</script>';
                        exit;
                        break;
                    case 0:
                    case 4:
                        $this->_order->addStatusHistoryComment( __( 'Redirect Response, Transaction has been cancelled, Pay_Request_Id: ' . $_POST['PAY_REQUEST_ID'] ) )->setIsCustomerNotified( false );
                        $this->_order->cancel()->save();
                        $this->_checkoutSession->restoreQuote();
                        echo '<script>window.top.location.href="' . $baseurl . 'checkout/cart/";</script>';
                        exit;
                        break;
                    default:
                        break;
                }
            } else {
                $this->_order->addStatusHistoryComment( __( 'Redirect Response, Checksum not valid, Pay_Request_Id: ' . $_POST['PAY_REQUEST_ID'] ) )->setIsCustomerNotified( false );
                $this->_order->cancel()->save();
                $this->_checkoutSession->restoreQuote();
                echo '<script>window.top.location.href="' . $baseurl . 'checkout/cart/";</script>';
                exit;
            }
        } catch ( \Magento\Framework\Exception\LocalizedException $e ) {
            $this->_logger->error( $pre . $e->getMessage() );
            echo '<script>window.top.location.href="' . $baseurl . 'checkout/cart/";</script>';
        } catch ( \Exception $e ) {
            $this->_logger->error( $pre . $e->getMessage() );
            echo '<script>window.top.location.href="' . $baseurl . 'checkout/cart/";</script>';
        }

        return '';
    }

    protected function isPostValid()
    {
        $this->_logger->debug( 'Entering isPostValid' );
        $this->_logger->debug( 'POST: ' . json_encode( $_POST ) );

        $status       = filter_var( $_POST['TRANSACTION_STATUS'], FILTER_SANITIZE_STRING );
        $payRequestId = filter_var( $_POST['PAY_REQUEST_ID'], FILTER_SANITIZE_STRING );
        $checksum     = filter_var( $_POST['CHECKSUM'], FILTER_SANITIZE_STRING );
        // If NOT test mode, use normal credentials
        if ( $this->getConfigData( 'test_mode' ) != '1' ) {
            $paygateId     = $this->getConfigData( 'paygate_id' );
            $encryptionKey = $this->getConfigData( 'encryption_key' );
        } else {
            $paygateId     = '10011072130';
            $encryptionKey = 'secret';
        }
        $reference = $this->_checkoutSession->getMagReference();
        $this->_logger->debug( 'Reference: ' . $reference );

        $ourChecksum = md5( $paygateId . $payRequestId . $status . $reference . $encryptionKey );
        $this->_logger->debug( 'Our checksum: ' . $ourChecksum );

        return hash_equals( $checksum, $ourChecksum );
    }

    protected function doQuery()
    {
        $queryUrl     = 'https://secure.paygate.co.za/payweb3/query.trans';
        $payRequestId = filter_var( $_POST['PAY_REQUEST_ID'], FILTER_SANITIZE_STRING );
        // If NOT test mode, use normal credentials
        if ( $this->getConfigData( 'test_mode' ) != '1' ) {
            $paygateId     = $this->getConfigData( 'paygate_id' );
            $encryptionKey = $this->getConfigData( 'encryption_key' );
        } else {
            $paygateId     = '10011072130';
            $encryptionKey = 'secret';
        }
        $reference = $this->_checkoutSession->getMagReference();
        $checksum  = md5( $paygateId . $payRequestId . $reference . $encryptionKey );
        $data      = array(
            'PAYGATE_ID'     => $paygateId,
            'PAY_REQUEST_ID' => $payRequestId,
            'REFERENCE'      => $reference,
            'CHECKSUM'       => $checksum,
        );

        $fieldsString = http_build_query( $data );

        $queried = false;
        $cnt     = 0;
        $return  = [];

        while ( !$queried && $cnt < 5 ) {
            // Open connection
            $ch = curl_init( $queryUrl );

            // Set the url, number of POST vars, POST data
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_NOBODY, false );
            curl_setopt( $ch, CURLOPT_REFERER, $_SERVER['HTTP_HOST'] );
            curl_setopt( $ch, CURLOPT_POST, true );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $fieldsString );

            // Execute post
            $result = curl_exec( $ch );
            $error  = curl_error( $ch );

            if ( strlen( $error ) == 0 && strlen( $result ) > 0 ) {
                $queried = true;
                parse_str( $result, $return );
            }
            $cnt++;

            // Close connection
            curl_close( $ch );
        }

        return $return;
    }
}
