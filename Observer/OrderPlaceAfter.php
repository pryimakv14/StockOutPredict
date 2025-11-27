<?php
declare(strict_types=1);

namespace Pryv\StockOutPredict\Observer;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Pryv\StockOutPredict\Model\ConfigService;
use Pryv\StockOutPredict\Model\PredictService;
use Psr\Log\LoggerInterface;

class OrderPlaceAfter implements ObserverInterface
{
    /**
     * @param PredictService $predictService
     * @param LoggerInterface $logger
     * @param StockRegistryInterface $stockRegistry
     * @param ConfigService $configService
     */
    public function __construct(
        private readonly PredictService $predictService,
        private readonly LoggerInterface $logger,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ConfigService $configService,
    ) {
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            /** @var OrderInterface $order */
            $order = $observer->getEvent()->getData('order');
            if (!$order || !$order->getId()) {
                return;
            }

            $items = $order->getAllItems();
            foreach ($items as $item) {
                $sku = $item->getSku();
                $params = $this->configService->getSkuParameters($sku);
                if (empty($params) || !is_numeric($params[ConfigService::FIELD_TEST_ALERT_THRESHOLD] ?? '')) {
                    continue;
                }
                if ($this->predictService->hasExistingNotification($sku) ||
                    $this->predictService->wasPredictionMadeRecently($sku)) {
                    continue;
                }
                $this->handlePrediction($item, (int)$params[ConfigService::FIELD_TEST_ALERT_THRESHOLD]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error in StockOutPredict OrderPlaceAfter observer', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * @param OrderItemInterface $item
     * @param int $alertThreshold
     * @return void
     */
    private function handlePrediction(OrderItemInterface $item, int $alertThreshold): void
    {
        $stockQty = $this->getStockQuantity($item);
        $prediction = $this->predictService->getPrediction($item->getSku(), (int)$stockQty);
        if ($prediction && isset($prediction['days_of_stock_remaining'])) {
            $daysRemaining = (int)$prediction['days_of_stock_remaining'];
            $this->predictService->setPredictionFlag($item->getSku());
            if ($daysRemaining < $alertThreshold) {
                $this->predictService->createAdminNotification(
                    $item->getSku(),
                    $daysRemaining,
                );
            }
        }
    }

    /**
     * Get stock quantity for order item
     *
     * @param OrderItemInterface $item
     * @return float|null
     */
    private function getStockQuantity(OrderItemInterface $item): ?float
    {
        try {
            $product = $item->getProduct();
            if (!$product) {
                return null;
            }

            $extensionAttributes = $product->getExtensionAttributes();
            if ($extensionAttributes && $extensionAttributes->getStockItem()) {
                return (float)$extensionAttributes->getStockItem()->getQty();
            }

            $stockItem = $this->stockRegistry->getStockItem($product->getId());

            return $stockItem ? (int)$stockItem->getQty() : null;
        } catch (\Exception $e) {
            $this->logger->warning('Could not get stock quantity for item', [
                'sku' => $item->getSku(),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
