<?php
declare(strict_types=1);

namespace Pryv\StockOutPredict\Console\Command;

use Pryv\StockOutPredict\Model\DataExporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Console\Cli;

class ExportData extends Command
{
    private DataExporter $dataExporter;

    public function __construct(DataExporter $dataExporter)
    {
        $this->dataExporter = $dataExporter;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('stockout-predict:data:export')
            ->setDescription('Exports sales history (SKU, Qty, Date) for the ML model.');

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
            return Cli::RETURN_SUCCESS;

        } catch (\Exception $e) {
            $output->writeln('<error>An error occurred:</error>');
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Cli::RETURN_FAILURE;
        }
    }
}
