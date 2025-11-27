<?php
declare(strict_types=1);

namespace Pryv\StockOutPredict\Model;

use Magento\AdminNotification\Model\InboxFactory;
use Magento\AdminNotification\Model\ResourceModel\Inbox\CollectionFactory as InboxCollectionFactory;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class PredictService
{
    private const NOTIFICATION_TITLE_PATTERN = 'Low Stock Warning: %1';

    private const FLAG_CODE_PREFIX = 'stockout_predict_';

    /**
     * @param ConfigService $configService
     * @param CurlFactory $curlFactory
     * @param Json $json
     * @param InboxFactory $inboxFactory
     * @param InboxCollectionFactory $inboxCollectionFactory
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConfigService $configService,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly InboxFactory $inboxFactory,
        private readonly InboxCollectionFactory $inboxCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get prediction from API
     *
     * @param string $sku
     * @param int $quantity
     * @return array|null
     */
    public function getPrediction(string $sku, int $quantity): ?array
    {
        try {
            $apiUrl = $this->getPredictApiUrl($sku, $quantity);

            $curl = $this->curlFactory->create();
            $curl->setTimeout(30);
            $curl->get($apiUrl);

            $statusCode = $curl->getStatus();
            $responseBody = $curl->getBody();

            if ($statusCode !== 200) {
                $this->logger->error('Predict API request failed', [
                    'url' => $apiUrl,
                    'status' => $statusCode,
                    'response' => $responseBody
                ]);
                return null;
            }

            $response = $this->json->unserialize($responseBody);

            return is_array($response) ? $response : null;
        } catch (\Exception $e) {
            $this->logger->error('Error calling predict API', [
                'sku' => $sku,
                'quantity' => $quantity,
                'exception' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get predict API URL
     *
     * @param string $sku
     * @param int $quantity
     * @return string
     */
    private function getPredictApiUrl(string $sku, int $quantity): string
    {
        $baseUrl = rtrim($this->configService->getApiBaseUrl(), '/');

        return "$baseUrl/predict/$sku?current_stock=$quantity";
    }

    /**
     * Create admin notification for low stock warning
     *
     * @param string $sku
     * @param int $daysRemaining
     * @return void
     */
    public function createAdminNotification(
        string $sku,
        int $daysRemaining,
    ): void {
        try {
            $title = __(self::NOTIFICATION_TITLE_PATTERN, $sku);
            $description = __('Product SKU %1 is predicted to run out of stock in %2 days.', $sku, $daysRemaining);
            $inbox = $this->inboxFactory->create();
            $inbox->parse([
                [
                    'severity' => MessageInterface::SEVERITY_MAJOR,
                    'date_added' => date('Y-m-d H:i:s'),
                    'title' => (string)$title,
                    'description' => (string)$description,
                    'url' => ''
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error creating admin notification', [
                'sku' => $sku,
                'days_remaining' => $daysRemaining,
                'exception' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if notification already exists for SKU
     *
     * @param string $sku
     * @return bool
     */
    public function hasExistingNotification(string $sku): bool
    {
        try {
            $titlePattern = __(self::NOTIFICATION_TITLE_PATTERN, $sku);
            $collection = $this->inboxCollectionFactory->create();
            $collection->addFieldToFilter('title', ['like' => $titlePattern]);
            $collection->addFieldToFilter('is_remove', 0);

            return $collection->getSize() > 0;
        } catch (\Exception $e) {
            $this->logger->error('Error checking existing notification', [
                'sku' => $sku,
                'exception' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Check if prediction was made within the cooldown period
     *
     * @param string $sku
     * @return bool
     */
    public function wasPredictionMadeRecently(string $sku): bool
    {
        $cooldownHours = $this->configService->getPredictionCooldownHours();
        if (!$cooldownHours) {
            return false;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $flagTable = $connection->getTableName('flag');
            $flagCode = self::FLAG_CODE_PREFIX . $sku;

            $cooldownHours = $this->configService->getPredictionCooldownHours();
            $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$cooldownHours} hours"));

            $select = $connection->select()
                ->from($flagTable, ['last_update'])
                ->where('flag_code = ?', $flagCode)
                ->where('last_update >= ?', $cutoffTime)
                ->limit(1);

            $result = $connection->fetchOne($select);

            return (bool)$result;
        } catch (\Exception $e) {
            $this->logger->error('Error checking prediction flag', [
                'sku' => $sku,
                'exception' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Set flag indicating prediction was made
     *
     * @param string $sku
     * @return void
     */
    public function setPredictionFlag(string $sku): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $flagTable = $connection->getTableName('flag');
            $flagCode = self::FLAG_CODE_PREFIX . $sku;

            $data = [
                'flag_code' => $flagCode,
                'state' => 1,
                'flag_data' => $this->json->serialize(['sku' => $sku, 'prediction_time' => date('Y-m-d H:i:s')]),
                'last_update' => date('Y-m-d H:i:s')
            ];

            $connection->insertOnDuplicate(
                $flagTable,
                $data,
                ['state', 'flag_data', 'last_update']
            );
        } catch (\Exception $e) {
            $this->logger->error('Error setting prediction flag', [
                'sku' => $sku,
                'exception' => $e->getMessage()
            ]);
        }
    }
}
