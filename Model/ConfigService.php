<?php
declare(strict_types=1);

namespace Pryv\StockOutPredict\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

class ConfigService
{
    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var Json
     */
    private Json $json;

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
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Json $json
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->json = $json;
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
}

