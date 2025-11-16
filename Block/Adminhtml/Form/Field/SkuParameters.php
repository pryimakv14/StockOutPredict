<?php
declare(strict_types=1);

namespace Pryv\StockOutPredict\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Pryv\StockOutPredict\Model\ConfigService;

class SkuParameters extends AbstractFieldArray
{
    /**
     * @var SeasonalityMode
     */
    private $seasonalityModeRenderer;

    /**
     * @var LockParams
     */
    private $lockParamsRenderer;

    /**
     * @var YesNoOptions
     */
    private $yesNoOptionsRenderer;

    /**
     * Prepare rendering the new field by adding all the needed columns
     * @throws LocalizedException
     */
    protected function _prepareToRender()
    {
        $this->addColumn('sku', [
            'label' => __('Product SKU'),
            'class' => 'required-entry'
        ]);

        $this->addColumn(ConfigService::FIELD_TEST_ALERT_THRESHOLD, [
            'label' => __('Alert Threshold'),
            'class' => 'validate-number validate-zero-or-greater'
        ]);

        $this->addColumn(ConfigService::FIELD_CHANGEPOINT_PRIOR_SCALE, [
            'label' => __('CPS'),
            'class' => 'validate-number'
        ]);

        $this->addColumn(ConfigService::FIELD_SEASONALITY_PRIOR_SCALE, [
            'label' => __('SPS'),
            'class' => 'validate-number'
        ]);

        $this->addColumn(ConfigService::FIELD_HOLIDAYS_PRIOR_SCALE, [
            'label' => __('HPS'),
            'class' => 'validate-number'
        ]);

        $this->addColumn(ConfigService::FIELD_SEASONALITY_MODE, [
            'label' => __('SM'),
            'renderer' => $this->getSeasonalityModeRenderer()
        ]);

        $this->addColumn(ConfigService::FIELD_YEARLY_SEASONALITY, [
            'label' => __('Yearly Seasonality'),
            'renderer' => $this->getYesNoOptionsRenderer()
        ]);

        $this->addColumn(ConfigService::FIELD_WEEKLY_SEASONALITY, [
            'label' => __('Weekly Seasonality'),
            'renderer' => $this->getYesNoOptionsRenderer()
        ]);

        $this->addColumn('lock_params', [
            'label' => __('Lock'),
            'renderer' => $this->getLockParamsRenderer()
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add');
    }

    /**
     * Prepare existing row data object
     *
     * @param DataObject $row
     * @throws LocalizedException
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];

        $seasonalityMode = $row->getSeasonalityMode();
        if ($seasonalityMode !== null) {
            $options['option_' . $this->getSeasonalityModeRenderer()->calcOptionHash($seasonalityMode)] = 'selected="selected"';
        }

        $lockParams = $row->getLockParams();
        if ($lockParams !== null) {
            $options['option_' . $this->getLockParamsRenderer()->calcOptionHash($lockParams)] = 'selected="selected"';
        }

        $yearlySeasonality = $row->getYearlySeasonality();
        if ($yearlySeasonality !== null) {
            $options['option_' . $this->getYesNoOptionsRenderer()->calcOptionHash($yearlySeasonality)] = 'selected="selected"';
        }

        $weeklySeasonality = $row->getWeeklySeasonality();
        if ($weeklySeasonality !== null) {
            $options['option_' . $this->getYesNoOptionsRenderer()->calcOptionHash($weeklySeasonality)] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }

    /**
     * @return SeasonalityMode
     * @throws LocalizedException
     */
    private function getSeasonalityModeRenderer(): SeasonalityMode
    {
        if (!$this->seasonalityModeRenderer) {
            $this->seasonalityModeRenderer = $this->getLayout()->createBlock(
                SeasonalityMode::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->seasonalityModeRenderer;
    }

    /**
     * @return LockParams
     * @throws LocalizedException
     */
    private function getLockParamsRenderer(): LockParams
    {
        if (!$this->lockParamsRenderer) {
            $this->lockParamsRenderer = $this->getLayout()->createBlock(
                LockParams::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->lockParamsRenderer;
    }

    /**
     * @return YesNoOptions
     * @throws LocalizedException
     */
    private function getYesNoOptionsRenderer(): YesNoOptions
    {
        if (!$this->yesNoOptionsRenderer) {
            $this->yesNoOptionsRenderer = $this->getLayout()->createBlock(
                YesNoOptions::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->yesNoOptionsRenderer;
    }
}

