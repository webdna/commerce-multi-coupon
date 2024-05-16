<?php

namespace webdna\commerce\multicoupon\behaviors;

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\events\ModelEvent;
use craft\helpers\ArrayHelper;
use webdna\commerce\multicoupon\records\CouponCode;
use RuntimeException;
use yii\base\Behavior;
use yii\base\InvalidConfigException;

/**
 * Order behavior.
 *
 * @property-read array $couponCodes
 */
class OrderBehavior extends Behavior
{
	/**
	 * @inheritdoc
	 */
	public function attach($owner)
	{
		if (!$owner instanceof Order) {
			throw new RuntimeException('OrderBehavior can only be attached to an Order element');
		}

		parent::attach($owner);
	}

	/**
	 * @return array
	 * @throws InvalidConfigException
	 */
	public function getCouponCodes(): array
	{
		return CouponCode::find()->where(['orderId' => $this->owner->id])->select('code')->column();
	}

}
