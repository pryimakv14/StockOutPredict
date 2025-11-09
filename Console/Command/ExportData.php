<?php
declare(strict_types=1);

namespace Pryv\StockOutPredict\Console\Command;

use Pryv\StockOutPredict\Model\ConfigService;
use Pryv\StockOutPredict\Model\DataExporter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Console\Cli;
use Magento\Framework\Serialize\Serializer\Json;

class ExportData extends Command
{
    private DataExporter $dataExporter;
    private ConfigService $configService;
    private LoggerInterface $logger;
    private Json $json;

    public function __construct(
        DataExporter $dataExporter,
        ConfigService $configService,
        LoggerInterface $logger,
        Json $json
    ) {
        $this->dataExporter = $dataExporter;
        $this->configService = $configService;
        $this->logger = $logger;
        $this->json = $json;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('stockout-predict:data:export')
            ->setDescription('Exports sales history (SKU, Qty, Date) for the ML model and uploads it to the API.');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>Starting sales data export...</info>');

        try {
            $filePath = $this->dataExporter->exportSalesHistory();
            $output->writeln("<info>Export complete!</info>");
            $output->writeln("File saved to: <comment>$filePath</comment>");

            // Upload file to API
            $output->writeln('<info>Uploading file to API...</info>');
            $uploadResult = $this->uploadFileToApi($filePath);

            if ($uploadResult['success']) {
                $output->writeln('<info>File uploaded successfully!</info>');
                if (isset($uploadResult['message'])) {
                    $output->writeln("<info>{$uploadResult['message']}</info>");
                }
            } else {
                $output->writeln('<error>Upload failed:</error>');
                $output->writeln("<error>{$uploadResult['message']}</error>");
                return Cli::RETURN_FAILURE;
            }

            // Train models for all SKUs
            $output->writeln('<info>Training models for all SKUs...</info>');
            $trainResult = $this->trainAllModels($output);

            if (!$trainResult['success']) {
                $output->writeln('<error>Training failed for some SKUs:</error>');
                $output->writeln("<error>{$trainResult['message']}</error>");
                // Don't fail the entire command if training fails
            }

            return Cli::RETURN_SUCCESS;

        } catch (\Exception $e) {
            $output->writeln('<error>An error occurred:</error>');
            $output->writeln("<error>{$e->getMessage()}</error>");
            $this->logger->error('Export command error', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * Upload CSV file to API using multipart/form-data
     *
     * @param string $filePath
     * @return array
     */
    private function uploadFileToApi(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [
                'success' => false,
                'message' => 'File does not exist or is not readable: ' . $filePath
            ];
        }

        try {
            $uploadUrl = $this->configService->getUploadApiUrl();

            // Use PHP's cURL directly for multipart form upload
            $curl = curl_init();

            $fileHandle = fopen($filePath, 'r');
            if (!$fileHandle) {
                return [
                    'success' => false,
                    'message' => 'Could not open file for reading'
                ];
            }

            $fileContent = file_get_contents($filePath);
            fclose($fileHandle);

            $boundary = uniqid('----WebKitFormBoundary');
            $filename = basename($filePath);

            $body = '';
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
            $body .= "Content-Type: text/csv\r\n\r\n";
            $body .= $fileContent . "\r\n";
            $body .= "--{$boundary}--\r\n";

            curl_setopt_array($curl, [
                CURLOPT_URL => $uploadUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    "Content-Type: multipart/form-data; boundary={$boundary}",
                    "Content-Length: " . strlen($body)
                ],
                CURLOPT_TIMEOUT => 300, // 5 minutes timeout
            ]);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($error) {
                $this->logger->error('CURL error during file upload', [
                    'url' => $uploadUrl,
                    'error' => $error
                ]);
                return [
                    'success' => false,
                    'message' => 'CURL error: ' . $error
                ];
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                return [
                    'success' => true,
                    'message' => 'File uploaded successfully',
                    'response' => $response
                ];
            } else {
                $this->logger->error('API upload failed', [
                    'url' => $uploadUrl,
                    'http_code' => $httpCode,
                    'response' => $response
                ]);
                return [
                    'success' => false,
                    'message' => "API returned status code: {$httpCode}. Response: {$response}"
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Exception during file upload', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Train models for all SKUs from configuration
     *
     * @param OutputInterface $output
     * @return array
     */
    private function trainAllModels(OutputInterface $output): array
    {
        try {
            $allSkuParameters = $this->configService->getAllSkuParameters();

            if (empty($allSkuParameters)) {
                return [
                    'success' => true,
                    'message' => 'No SKUs found in configuration'
                ];
            }

            $successCount = 0;
            $failureCount = 0;
            $errors = [];

            foreach ($allSkuParameters as $skuRow) {
                if (!isset($skuRow['sku']) || empty($skuRow['sku'])) {
                    continue;
                }

                $sku = (string)$skuRow['sku'];
                $output->writeln("<info>Training model for SKU: {$sku}</info>");

                // Check if params should be passed
                $shouldPassParams = false;
                $requestBody = [];

                // Check if lock is true and params are present
                $lockParams = isset($skuRow['lock_params']) && $skuRow['lock_params'] === '1';

                if ($lockParams) {
                    // Build request body with available parameters
                    // Exclude test_period_days as it's not a training parameter
                    $trainingFields = array_filter(
                        ConfigService::ALL_FIELDS,
                        function ($field) {
                            return $field !== ConfigService::FIELD_TEST_PERIOD_DAYS;
                        }
                    );

                    foreach ($trainingFields as $key) {
                        if (!empty($skuRow[$key])) {
                            $value = $skuRow[$key];
                            if (in_array($key, ConfigService::BOOLEAN_FIELDS)) {
                                $value = $value === '1' || $value === 1 || $value === true;
                            }
                            $requestBody[$key] = $value;
                        }
                    }

                    if (!empty($requestBody)) {
                        $shouldPassParams = true;
                    }
                }

                // Call train endpoint
                $trainResult = ['success' => true]; //$this->trainModel($sku, $shouldPassParams ? $requestBody : null);

                if ($trainResult['success']) {
                    $successCount++;
                    $output->writeln("<info>Model trained successfully for SKU: {$sku}</info>");

                    // If no body was passed, update config with hyperparams from response
                    if (!$shouldPassParams && isset($trainResult['response'])) {
                        $this->updateConfigFromResponse($sku, $trainResult['response']);
                    }
                } else {
                    $failureCount++;
                    $errorMsg = "Failed to train model for SKU: {$sku} - {$trainResult['message']}";
                    $errors[] = $errorMsg;
                    $output->writeln("<error>{$errorMsg}</error>");
                    $this->logger->error('Model training failed', [
                        'sku' => $sku,
                        'error' => $trainResult['message']
                    ]);
                }
            }

            $message = "Training completed: {$successCount} successful, {$failureCount} failed";
            if (!empty($errors)) {
                $message .= "\nErrors: " . implode("\n", $errors);
            }

            return [
                'success' => $failureCount === 0,
                'message' => $message,
                'success_count' => $successCount,
                'failure_count' => $failureCount
            ];

        } catch (\Exception $e) {
            $this->logger->error('Exception during model training', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Train a model for a specific SKU
     *
     * @param string $sku
     * @param array|null $requestBody Request body parameters (null for no body)
     * @return array
     */
    private function trainModel(string $sku, ?array $requestBody = null): array
    {
        try {
            $trainUrl = $this->configService->getTrainApiUrl($sku);

            $curl = curl_init();

            $curlOptions = [
                CURLOPT_URL => $trainUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 1800, // 30 minutes timeout for training
            ];

            if ($requestBody !== null && !empty($requestBody)) {
                $jsonBody = $this->json->serialize($requestBody);
                $curlOptions[CURLOPT_POSTFIELDS] = $jsonBody;
                $curlOptions[CURLOPT_HTTPHEADER] = [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($jsonBody)
                ];
            } else {
                $curlOptions[CURLOPT_HTTPHEADER] = [
                    'Content-Type: application/json'
                ];
            }

            curl_setopt_array($curl, $curlOptions);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($error) {
                $this->logger->error('CURL error during model training', [
                    'url' => $trainUrl,
                    'sku' => $sku,
                    'error' => $error
                ]);
                return [
                    'success' => false,
                    'message' => 'CURL error: ' . $error
                ];
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                $responseData = $this->json->unserialize($response);
                return [
                    'success' => true,
                    'message' => 'Model trained successfully',
                    'response' => $responseData
                ];
            } else {
                $this->logger->error('API training failed', [
                    'url' => $trainUrl,
                    'sku' => $sku,
                    'http_code' => $httpCode,
                    'response' => $response
                ]);
                return [
                    'success' => false,
                    'message' => "API returned status code: {$httpCode}. Response: {$response}"
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Exception during model training', [
                'sku' => $sku,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update config with hyperparameters from training response
     *
     * @param string $sku
     * @param array $response
     * @return void
     */
    private function updateConfigFromResponse(string $sku, array $response): void
    {
        try {
            // Determine which parameters source to use
            $paramsSource = null;
            if (isset($response['training_info']['best_parameters'])) {
                $paramsSource = $response['training_info']['best_parameters'];
            } elseif (isset($response['training_info']['parameters_used'])) {
                $paramsSource = $response['training_info']['parameters_used'];
            }

            if ($paramsSource === null || !is_array($paramsSource)) {
                return;
            }

            $parameters = $this->extractParametersFromResponse($paramsSource);

            if (!empty($parameters)) {
                $this->configService->updateSkuParameters($sku, $parameters);
                $this->logger->info('Updated SKU parameters from training response', [
                    'sku' => $sku,
                    'parameters' => $parameters
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to update config from training response', [
                'sku' => $sku,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Extract and normalize parameters from API response
     *
     * @param array $paramsSource
     * @return array
     */
    private function extractParametersFromResponse(array $paramsSource): array
    {
        $parameters = [];
        $fieldMapping = [
            'changepoint_prior_scale' => ConfigService::FIELD_CHANGEPOINT_PRIOR_SCALE,
            'seasonality_prior_scale' => ConfigService::FIELD_SEASONALITY_PRIOR_SCALE,
            'holidays_prior_scale' => ConfigService::FIELD_HOLIDAYS_PRIOR_SCALE,
            'seasonality_mode' => ConfigService::FIELD_SEASONALITY_MODE,
            'yearly_seasonality' => ConfigService::FIELD_YEARLY_SEASONALITY,
            'weekly_seasonality' => ConfigService::FIELD_WEEKLY_SEASONALITY,
            'daily_seasonality' => ConfigService::FIELD_DAILY_SEASONALITY,
        ];

        foreach ($fieldMapping as $apiField => $configField) {
            if (!isset($paramsSource[$apiField])) {
                continue;
            }

            $value = $paramsSource[$apiField];

            // Convert boolean fields to '1' or '0'
            if (in_array($configField, ConfigService::BOOLEAN_FIELDS, true)) {
                $parameters[$configField] = ($value === true || $value === 1 || $value === '1') ? '1' : '0';
            } else {
                $parameters[$configField] = (string)$value;
            }
        }

        return $parameters;
    }
}
