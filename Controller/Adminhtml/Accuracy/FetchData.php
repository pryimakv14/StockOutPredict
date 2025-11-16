<?php
namespace Pryv\StockOutPredict\Controller\Adminhtml\Accuracy;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Pryv\StockOutPredict\Model\ConfigService;

class FetchData extends Action
{
    /**
     * The ACL resource ID for this controller
     */
    const string ADMIN_RESOURCE = 'Pryv_StockOutPredict::stockout_predict';

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var CurlFactory
     */
    protected $curlFactory;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ConfigService
     */
    private $configService;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param CurlFactory $curlFactory
     * @param Json $json
     * @param LoggerInterface $logger
     * @param ConfigService $configService
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CurlFactory $curlFactory,
        Json $json,
        LoggerInterface $logger,
        ConfigService $configService
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->curlFactory = $curlFactory;
        $this->json = $json;
        $this->logger = $logger;
        $this->configService = $configService;
    }

    /**
     * Execute AJAX request to fetch chart data from external API
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        try {
            $sku = $this->getRequest()->getParam('sku');
            if (!$sku) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => 'SKU parameter is required'
                ]);
            }

            $skuParameters = $this->configService->getSkuParameters($sku);
            $apiBaseUrl = $this->configService->getApiBaseUrl() . '/validate-period-accuracy/' . urlencode($sku);

            $postData = [];
            if ($skuParameters) {
                foreach (ConfigService::ALL_PARAMS_FIELDS as $field) {
                    if (!empty($skuParameters[$field])) {
                        if (in_array($field, ConfigService::BOOLEAN_FIELDS)) {
                            $postData[$field] = $skuParameters[$field] === '1' ||
                                $skuParameters[$field] === 1 || $skuParameters[$field] === true;
                        } else {
                            $postData[$field] = $skuParameters[$field];
                        }
                    }
                }
            }

            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->post($apiBaseUrl, $this->json->serialize($postData));

            $statusCode = $curl->getStatus();
            $responseBody = $curl->getBody();

            if ($statusCode !== 200) {
                $this->logger->error('API request failed', [
                    'url' => $apiBaseUrl,
                    'status' => $statusCode,
                    'response' => $responseBody,
                    'post_data' => $postData
                ]);

                return $resultJson->setData([
                    'success' => false,
                    'message' => 'API request failed with status: ' . $statusCode
                ]);
            }

            $apiResponse = $this->json->unserialize($responseBody);
            $predicted = $apiResponse['predicted'] ?? [];
            $actual = $apiResponse['actual'] ?? [];
            $metrics = $apiResponse['metrics'] ?? null;

            if (empty($predicted) && empty($actual)) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => 'No data found in API response'
                ]);
            }

            $numDays = max(count($predicted), count($actual));
            $labels = [];
            for ($i = 1; $i <= $numDays; $i++) {
                $labels[] = 'Day ' . $i;
            }

            $allValues = array_merge($predicted, $actual);
            $minY = floor(min($allValues)) - 5;
            $maxY = ceil(max($allValues)) + 5;

            $chartData = [
                'labels' => $labels,
                'xAxisLabel' => 'Day',
                'yAxisLabel' => 'Quantity',
                'yAxisMin' => max(0, $minY),
                'yAxisMax' => $maxY,
                'yAxisFormat' => '',
                'datasets' => []
            ];

            if (!empty($predicted)) {
                $chartData['datasets'][] = [
                    'label' => 'Predicted',
                    'data' => $predicted,
                    'color' => 'rgb(75, 192, 192)'
                ];
            }

            if (!empty($actual)) {
                $chartData['datasets'][] = [
                    'label' => 'Actual',
                    'data' => $actual,
                    'color' => 'rgb(255, 99, 132)'
                ];
            }

            $responseData = [
                'success' => true,
                'data' => $chartData
            ];

            if ($metrics !== null) {
                $responseData['metrics'] = $metrics;
            }

            return $resultJson->setData($responseData);
        } catch (\Exception $e) {
            $this->logger->error('Error fetching chart data', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }


    /**
     * Check permission
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}

