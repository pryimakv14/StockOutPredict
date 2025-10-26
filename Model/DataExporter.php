<?php
declare(strict_types=1);

namespace Pryv\StockOutPredict\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;

class DataExporter
{
    const SKUS = [
        'test1',
        'test2',
        'test3',
        'test4',
        'test5',
        'test6',
        'test7',
        'test8',
        'test9',
        'test10',
    ];

    /**
     * The CSV file will be saved to var/export/sales_history.csv
     */
    private const string EXPORT_FILE_NAME = 'export/sales_history.csv';

    private const int BATCH_SIZE = 1000;

    private OrderItemCollectionFactory $itemCollectionFactory;

    private Filesystem $filesystem;

    public function __construct(
        OrderItemCollectionFactory $itemCollectionFactory,
        Filesystem $filesystem
    ) {
        $this->itemCollectionFactory = $itemCollectionFactory;
        $this->filesystem = $filesystem;
    }

    /**
     * Fetches all sales data and writes it to a CSV file.
     *
     * @return string The absolute path to the generated file.
     * @throws FileSystemException
     */
    public function exportSalesHistory(): string
    {
        $directoryWrite = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);

        $stream = $directoryWrite->openFile(self::EXPORT_FILE_NAME, 'w+');
        $stream->lock();

        $header = ['sku', 'qty_ordered', 'created_at'];
        $stream->writeCsv($header);

        $collection = $this->itemCollectionFactory->create();
        $collection->setOrder('order_id', 'ASC')
            ->addFieldToFilter('sku', ['in' => self::SKUS])
//            ->addFieldToFilter('created_at', ['gt' => '2024-10-25'])
            ->setPageSize(self::BATCH_SIZE);

        $currentPage = 1;
        $lastPage = $collection->getLastPageNumber();

        do {
            $collection->setCurPage($currentPage);
            $collection->load();

            foreach ($collection as $item) {
                if (!$item->getSku()) {
                    continue;
                }

                $data = [
                    (string)$item->getSku(),
                    (float)$item->getQtyOrdered(),
                    (string)$item->getCreatedAt()
                ];
                $stream->writeCsv($data);
            }

            $currentPage++;
            $collection->clear();
        } while ($currentPage <= $lastPage);

        // Release the file
        $stream->unlock();
        $stream->close();

        return $directoryWrite->getAbsolutePath(self::EXPORT_FILE_NAME);
    }
}
