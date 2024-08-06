<?php

declare(strict_types=1);

/**
 * Digit Software Solutions.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 *
 * @category  Dss
 * @package   Dss_AdminPaymentMethod
 * @author    Extension Team
 * @copyright Copyright (c) 2024 Digit Software Solutions. ( https://digitsoftsol.com )
 */

namespace Dss\AdminPaymentMethod\Plugin;

use Dss\AdminPaymentMethod\Model\AdminPaymentMethod;
use Magento\Sales\Block\Adminhtml\Order\Create\Billing\Method\Form;

class PreSelect
{
    /**
     * PreSelect constructor
     *
     * @param \Dss\AdminPaymentMethod\Model\AdminPaymentMethod $model
     */
    public function __construct(
        private AdminPaymentMethod $model
    ) {
    }

    /**
     * After get select method code
     *
     * @param  \Magento\Sales\Block\Adminhtml\Order\Create\Billing\Method\Form $block
     * @param  string                                                          $result
     * @return bool|string
     */
    public function afterGetSelectedMethodCode(
        Form $block,
        $result
    ) {
        if ($result && $result != 'free') {
            return $result;
        }

        $data = $this->model->getDataPreSelect();
        if ($data) {
            return AdminPaymentMethod::CODE;
        }
        return false;
    }
}
