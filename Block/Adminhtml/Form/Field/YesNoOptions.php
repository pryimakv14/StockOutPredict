<?php
declare(strict_types=1);

namespace Pryv\StockOutPredict\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Html\Select;
use Magento\Framework\View\Element\Context;
use Magento\Config\Model\Config\Source\Yesno;

class YesNoOptions extends Select
{
    /**
     * @var Yesno
     */
    private $yesNoSource;

    /**
     * @param Context $context
     * @param Yesno $yesNoSource
     * @param array $data
     */
    public function __construct(
        Context $context,
        Yesno $yesNoSource,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->yesNoSource = $yesNoSource;
    }

    /**
     * Set input name
     *
     * @param string $value
     * @return $this
     */
    public function setInputName($value)
    {
        return $this->setName($value);
    }

    /**
     * Set input id
     *
     * @param string $value
     * @return $this
     */
    public function setInputId($value)
    {
        return $this->setId($value);
    }

    /**
     * Render block HTML
     *
     * @return string
     */
    protected function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->getSourceOptions());
        }
        return parent::_toHtml();
    }

    /**
     * Get source options
     *
     * @return array
     */
    private function getSourceOptions(): array
    {
        return $this->yesNoSource->toOptionArray();
    }
}
