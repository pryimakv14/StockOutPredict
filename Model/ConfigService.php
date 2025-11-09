<?php
declare(strict_types=1);

namespace Pryv\StockOutPredict\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class ConfigService
{
    public const string FIELD_TEST_PERIOD_DAYS = 'test_period_days';
    public const string FIELD_CHANGEPOINT_PRIOR_SCALE = 'changepoint_prior_scale';
    public const string FIELD_SEASONALITY_PRIOR_SCALE = 'seasonality_prior_scale';
    public const string FIELD_HOLIDAYS_PRIOR_SCALE = 'holidays_prior_scale';
    public const string FIELD_SEASONALITY_MODE = 'seasonality_mode';
    public const string FIELD_YEARLY_SEASONALITY = 'yearly_seasonality';
    public const string FIELD_WEEKLY_SEASONALITY = 'weekly_seasonality';
    public const string FIELD_DAILY_SEASONALITY = 'daily_seasonality';

    public const array BOOLEAN_FIELDS = [
        ConfigService::FIELD_DAILY_SEASONALITY,
        ConfigService::FIELD_WEEKLY_SEASONALITY,
        ConfigService::FIELD_YEARLY_SEASONALITY
    ];

    public const array ALL_FIELDS = [
        self::FIELD_TEST_PERIOD_DAYS,
        self::FIELD_CHANGEPOINT_PRIOR_SCALE,
        self::FIELD_SEASONALITY_PRIOR_SCALE,
        self::FIELD_HOLIDAYS_PRIOR_SCALE,
        self::FIELD_SEASONALITY_MODE,
        self::FIELD_YEARLY_SEASONALITY,
        self::FIELD_WEEKLY_SEASONALITY,
        self::FIELD_DAILY_SEASONALITY
    ];

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var Json
     */
    private Json $json;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var WriterInterface
     */
    private WriterInterface $configWriter;

    /**
     * API base URL configuration path
     */
    private const CONFIG_PATH_API_BASE_URL = 'stockout/accuracy_validation/api_base_url';

    /**
     * SKU parameters configuration path
     */
    private const CONFIG_PATH_SKU_PARAMETERS = 'stockout/accuracy_validation/sku_parameters';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $json
     * @param StoreManagerInterface $storeManager
     * @param WriterInterface $configWriter
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Json $json,
        StoreManagerInterface $storeManager,
        WriterInterface $configWriter
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->json = $json;
        $this->storeManager = $storeManager;
        $this->configWriter = $configWriter;
    }

    /**
     * Get all SKUs from configuration
     *
     * @return array Array of SKU strings
     */
    public function getAllSkus(): array
    {
        $configValue = $this->scopeConfig->getValue(
            self::CONFIG_PATH_SKU_PARAMETERS,
            ScopeInterface::SCOPE_STORE
        );

        if (!$configValue) {
            return [];
        }

        $skuParameters = $this->json->unserialize($configValue);
        if (!is_array($skuParameters)) {
            return [];
        }

        $skus = [];
        foreach ($skuParameters as $row) {
            if (isset($row['sku']) && !empty($row['sku'])) {
                $skus[] = (string)$row['sku'];
            }
        }

        return $skus;
    }

    /**
     * Get SKU parameters from config
     *
     * @param string $sku
     * @return array|null
     */
    public function getSkuParameters(string $sku): ?array
    {
        $configValue = $this->scopeConfig->getValue(
            self::CONFIG_PATH_SKU_PARAMETERS,
            ScopeInterface::SCOPE_STORE
        );

        if (!$configValue) {
            return null;
        }

        $skuParameters = $this->json->unserialize($configValue);
        if (!is_array($skuParameters)) {
            return null;
        }

        // Find the row matching the SKU
        foreach ($skuParameters as $row) {
            if (isset($row['sku']) && $row['sku'] === $sku) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Get the base API URL
     *
     * @return string
     */
    public function getApiBaseUrl(): string
    {
        $url = $this->scopeConfig->getValue(
            self::CONFIG_PATH_API_BASE_URL,
            ScopeInterface::SCOPE_STORE
        );

        return $url ?: 'http://host.docker.internal:5000';
    }

    /**
     * Get the upload API URL (base URL + /upload-data)
     *
     * @return string
     */
    public function getUploadApiUrl(): string
    {
        return rtrim($this->getApiBaseUrl(), '/') . '/upload-data';
    }

    /**
     * Get the train API URL (base URL + /train/{sku})
     *
     * @param string $sku
     * @return string
     */
    public function getTrainApiUrl(string $sku): string
    {
        return rtrim($this->getApiBaseUrl(), '/') . '/train/' . urlencode($sku);
    }

    /**
     * Get all SKU parameters from configuration
     *
     * @return array Array of SKU parameter rows
     */
    public function getAllSkuParameters(): array
    {
        $configValue = $this->scopeConfig->getValue(
            self::CONFIG_PATH_SKU_PARAMETERS,
            ScopeInterface::SCOPE_STORE
        );

        if (!$configValue) {
            return [];
        }

        $skuParameters = $this->json->unserialize($configValue);
        if (!is_array($skuParameters)) {
            return [];
        }

        return $skuParameters;
    }

    /**
     * Update SKU parameters for a specific SKU
     *
     * @param string $sku
     * @param array $parameters Parameters to update
     * @return bool
     */
    public function updateSkuParameters(string $sku, array $parameters): bool
    {
        try {
            $allParameters = $this->getAllSkuParameters();
            $updated = false;

            // Find and update the row for this SKU
            foreach ($allParameters as $index => $row) {
                if (isset($row['sku']) && $row['sku'] === $sku) {
                    // Merge new parameters with existing row data
                    $allParameters[$index] = array_merge($row, $parameters);
                    $updated = true;
                    break;
                }
            }

            // If SKU not found, add a new row
            if (!$updated) {
                $allParameters[] = array_merge(['sku' => $sku], $parameters);
            }

            // Save the updated configuration
            $store = $this->storeManager->getStore();
            $serializedValue = $this->json->serialize($allParameters);

            // Use config writer to save the value
            $this->configWriter->save(
                self::CONFIG_PATH_SKU_PARAMETERS,
                $serializedValue,
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            );

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

