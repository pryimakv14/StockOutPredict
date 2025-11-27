<?php
declare(strict_types=1);

namespace Pryv\StockOutPredict\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Html\Select;

class LockParams extends Select
{
    const OPTION_LOCK_PARAMS = 'params';
    const OPTION_LOCK_MODEL = 'model';

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
        return [
            ['value' => '', 'label' => __('None')],
            ['value' => self::OPTION_LOCK_PARAMS, 'label' => __('Params')],
            ['value' => self::OPTION_LOCK_MODEL, 'label' => __('Model')],
        ];
    }
}

