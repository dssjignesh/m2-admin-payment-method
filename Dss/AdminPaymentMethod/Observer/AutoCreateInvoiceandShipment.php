<?php
/**
 * Digit Software Solutions.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 *
 * @category  Dss
 * @package   Dss_AdminPaymentMethod
 * @author    Extension Team
 * @copyright Copyright (c) 2024 Digit Software Solutions. ( https://digitsoftsol.com )
 */
declare(strict_types=1);

namespace Dss\AdminPaymentMethod\Observer;

use Magento\Sales\Model\Order;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order\Invoice;
use Magento\Framework\DB\TransactionFactory;
use Magento\Shipping\Model\ShipmentNotifier;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order\Invoice\Notifier;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Model\Convert\Order as ConvertOrder;

/**
 * Class AutoCreateInvoice
 *
 */
class AutoCreateInvoiceandShipment implements ObserverInterface
{
    /**
     * AutoCreateInvoice constructor.
     *
     * @param InvoiceService $invoiceService
     * @param ManagerInterface $messageManager
     * @param TransactionFactory $transaction
     * @param ConvertOrder $convertOrder
     * @param ShipmentNotifier $shipmentNotifier
     * @param ProductMetadataInterface $productMetadata
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderItemRepositoryInterface $itemRepository
     * @param Notifier $invoiceNotifier
     */
    public function __construct(
        protected InvoiceService $invoiceService,
        protected ManagerInterface $messageManager,
        protected TransactionFactory $transaction,
        protected ConvertOrder $convertOrder,
        protected ShipmentNotifier $shipmentNotifier,
        protected ProductMetadataInterface $productMetadata,
        protected SearchCriteriaBuilder $searchCriteriaBuilder,
        protected OrderItemRepositoryInterface $itemRepository,
        protected Notifier $invoiceNotifier
    ) {}

    /**
     * Execute
     *
     * @param Observer $observer
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment()->getMethodInstance();

        // Check code payment method
        if ($payment->getCode() == 'adminpaymentmethod') {
            // Check option create shipment
            $this->createShipment($payment, $order);
            // Check option create invoice
            $this->createInvoice($payment, $order);
            //create notified invoice and shipment by Dss
            $this->displayNotified($order, $payment);
        }
    }

    /**
     * Create invoice
     *
     * @param \Dss\AdminPaymentMethod\Model\AdminPaymentMethod $payment
     * @param \Magento\Sales\Model\Order $order
     * @return void |null
     * @throws \Exception
     */
    private function createInvoice($payment, $order): void
    {
        if ($payment->getConfigData('createinvoice')) {
            try {
                if (!$order->canInvoice() || !$order->getState() == 'new') {
                    throw new LocalizedException(
                        __('You cant create the Invoice of this order.')
                    );
                }
                if ($this->productMetadata->getVersion() > "2.3.6") {
                    $this->setItemsOrder($order);
                }
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                $invoice->register();
                $invoice->getOrder()->setIsInProcess(true);
                $transaction = $this->transaction->create()->addObject($invoice)->addObject($invoice->getOrder());
                $transaction->save();
                $this->invoiceNotifier->notify($order, $invoice);
                //Show message create invoice
                $this->messageManager->addSuccessMessage(__("Automatically generated Invoice."));
            } catch (\Exception $e) {
                $order->addStatusHistoryComment('Exception message: ' . $e->getMessage(), false);
                $order->save();
            }
        }
    }

    /**
     * Create shipment
     *
     * @param \Dss\AdminPaymentMethod\Model\AdminPaymentMethod $payment
     * @param \Magento\Sales\Model\Order $order
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function createShipment($payment, $order): void
    {
        if ($payment->getConfigData('createshipment')) {
            // to check order can ship or not
            if (!$order->canShip()) {
                throw new LocalizedException(
                    __('You cant create the Shipment of this order.')
                );
            }
            $orderShipment = $this->convertOrder->toShipment($order);
            if ($this->productMetadata->getVersion() > "2.3.6") {
                $this->setItemsOrder($order);
            }
            foreach ($order->getAllItems() as $orderItem) {
                // Check virtual item and item Quantity
                if (!$orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                    continue;
                }
                $qty = $orderItem->getQtyToShip();
                $shipmentItem = $this->convertOrder->itemToShipmentItem($orderItem)->setQty($qty);

                $orderShipment->addItem($shipmentItem);
            }

            $orderShipment->register();
            $orderShipment->getOrder()->setIsInProcess(true);
            try {
                // Save created Order Shipment
                $orderShipment->save();
                $orderShipment->getOrder()->save();

                // Send Shipment Email
                $this->shipmentNotifier->notify($orderShipment);
                $orderShipment->save();

                //Show message create shipment
                $this->messageManager->addSuccessMessage(__("Automatically generated Shipment."));
            } catch (\Exception $e) {
                throw new LocalizedException(
                    __($e->getMessage())
                );
            }
        }
    }

    /**
     * Display notified
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \Dss\AdminPaymentMethod\Model\AdminPaymentMethod $payment
     * @return null
     * @throws \Exception
     */
    private function displayNotified($order, $payment)
    {
        try {
            if ($payment->getConfigData('createinvoice') && $payment->getConfigData('createshipment')) {
                return $order->addStatusHistoryComment(__('Automatically Invoice and Shipment By Dss Invoice Shipment'))
                ->save();
            } elseif ($payment->getConfigData('createinvoice')) {
                return $order->addStatusHistoryComment(__('Automatically Invoice By Dss Invoice'))->save();
            } elseif ($payment->getConfigData('createshipment')) {
                return $order->addStatusHistoryComment(__('Automatically Shipment By Dss Shipment'))->save();
            }
            return null;
        } catch (\Exception $e) {
            $order->addStatusHistoryComment('Exception message: ' . $e->getMessage(), false);
            $order->save();
            return null;
        }
    }

    /**
     * Set items order when send email shipping
     *
     * @param Order $order
     * @return void
     */
    public function setItemsOrder($order): void
    {
        $this->searchCriteriaBuilder->addFilter(OrderItemInterface::ORDER_ID, $order->getId());
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $order->setItems($this->itemRepository->getList($searchCriteria)->getItems());
    }
}
