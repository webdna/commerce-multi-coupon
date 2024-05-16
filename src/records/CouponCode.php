<?php

namespace webdna\commerce\multicoupon\records;

use Craft;
use craft\db\ActiveRecord;

/**
 * Coupon Code record
 */
class CouponCode extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%commerce-multi-coupon_couponcodes}}';
    }
}
