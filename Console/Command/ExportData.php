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

class ExportData extends Command
{
    private DataExporter $dataExporter;
    private ConfigService $configService;
    private LoggerInterface $logger;

    public function __construct(
        DataExporter $dataExporter,
        ConfigService $configService,
        LoggerInterface $logger
    ) {
        $this->dataExporter = $dataExporter;
        $this->configService = $configService;
        $this->logger = $logger;
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
}
