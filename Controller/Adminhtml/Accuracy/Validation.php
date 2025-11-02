<?php
namespace Pryv\StockOutPredict\Controller\Adminhtml\Accuracy;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Validation extends Action
{
    /**
     * The ACL resource ID for this controller
     */
    const string ADMIN_RESOURCE = 'Pryv_StockOutPredict::stockout_predict';

    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    /**
     * Execute view action
     *
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        /** @var \Magento\Framework\View\Result\Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Pryv_StockOutPredict::stockout_predict');
        $resultPage->getConfig()->getTitle()->prepend(__('Stockout Accuracy Validation'));

        return $resultPage;
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
