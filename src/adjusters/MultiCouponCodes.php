<?php

namespace webdna\commerce\multicoupon\adjusters;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\commerce\elements\Order;
use craft\commerce\adjusters\Discount as DiscountAdjuster;
use webdna\commerce\multicoupon\records\CouponCode;
use webdna\commerce\multicoupon\MultiCoupon;
use craft\commerce\helpers\Currency;
use craft\commerce\models\Discount as DiscountModel;
use craft\commerce\models\Coupon;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\records\Discount as DiscountRecord;
use craft\helpers\ArrayHelper;

class MultiCouponCodes extends DiscountAdjuster
{
	/**
	 * @var Order
	 */
	private Order $_order;
	
	/**
	 * @var float
	 */
	private float $_discountTotal = 0;
	
	/**
	 * Temporary feature flag for testing
	 *
	 * @var bool
	 */
	private bool $_spreadBaseOrderDiscountsToLineItems = true;
	
	/**
	 * @var array
	 */
	private array $_discountUnitPricesByLineItem = [];
	
	
	

	public function adjust(Order $order): array
	{
		$this->_order = $order;
		
		
		
		$adjustments = [];
		$availableDiscounts = [];
		$discounts = MultiCoupon::getInstance()->discounts->getAllActiveDiscounts($order);

		foreach ($discounts as $discount) {
			$coupons = $discount->getCoupons();
			if (count($coupons)) {
				foreach ($order->couponCodes as $code) {
					if (ArrayHelper::firstWhere($coupons, static fn(Coupon $coupon) => (strcasecmp($coupon->code, $code) == 0) && ($coupon->maxUses === null || $coupon->maxUses > $coupon->uses))) {
						$availableDiscounts[$discount->id] = $discount;
					}
				}
			}
		}
		
		foreach ($availableDiscounts as $discount) {
			$newAdjustments = $this->_getAdjustments($discount);
			if ($newAdjustments) {
				array_push($adjustments, ...$newAdjustments);
		
				if ($discount->stopProcessing) {
					break;
				}
			}
		}
		
		$order->couponCode = null;
		
		return $adjustments;
	}
	
	
	private function _createOrderAdjustment(DiscountModel $discount): OrderAdjustment
	{
		//preparing model
		$adjustment = new OrderAdjustment();
		$adjustment->type = self::ADJUSTMENT_TYPE;
		$adjustment->name = $discount->name;
		$adjustment->setOrder($this->_order);
		$adjustment->description = $discount->description;
		$snapshot = $discount->toArray();
		$snapshot['discountUseId'] = $discount->id ?? null;
		$adjustment->sourceSnapshot = $snapshot;
	
		return $adjustment;
	}
	
	/**
	 * @return OrderAdjustment[]|false
	 */
	private function _getAdjustments(DiscountModel $discount): array|false
	{
		$adjustments = [];
	
		$matchingLineIds = [];
		foreach ($this->_order->getLineItems() as $item) {
			$lineItemHashId = spl_object_hash($item);
			// Order is already a match to this discount, or we wouldn't get here.
			$matchingLineIds[] = $lineItemHashId;

			if (Commerce::getInstance()->getDiscounts()->matchLineItem($item, $discount, false)) {
				$matchingLineIds[] = $lineItemHashId;
			}
		}
	
		foreach ($this->_order->getLineItems() as $item) {
			$lineItemHashId = spl_object_hash($item);
			if ($matchingLineIds && in_array($lineItemHashId, $matchingLineIds, false)) {
				$adjustment = $this->_createOrderAdjustment($discount);
				$adjustment->setLineItem($item);
				$discountAmountPerItemPreDiscounts = 0;
				$amountPerItem = Currency::round($discount->perItemDiscount);
	
				if ($discount->percentageOffSubject == DiscountRecord::TYPE_ORIGINAL_SALEPRICE) {
					$discountAmountPerItemPreDiscounts = ($discount->percentDiscount * $item->salePrice);
				}
	
				$unitPrice = $this->_discountUnitPricesByLineItem[$lineItemHashId] ?? $item->salePrice;
	
				$lineItemSubtotal = Currency::round($unitPrice * $item->qty);
	
				$unitPrice = max($unitPrice + $amountPerItem, 0);
	
				if ($unitPrice > 0) {
					if ($discount->percentageOffSubject == DiscountRecord::TYPE_ORIGINAL_SALEPRICE) {
						$discountedUnitPrice = $unitPrice + $discountAmountPerItemPreDiscounts;
					} else {
						$discountedUnitPrice = $unitPrice + ($discount->percentDiscount * $unitPrice);
					}
	
					$discountedSubtotal = Currency::round($discountedUnitPrice * $item->qty);
					$amountOfPercentDiscount = $discountedSubtotal - $lineItemSubtotal;
					$this->_discountUnitPricesByLineItem[$lineItemHashId] = $discountedUnitPrice;
					$adjustment->amount = $amountOfPercentDiscount; //Adding already rounded
				} else {
					$adjustment->amount = -$lineItemSubtotal;
					$this->_discountUnitPricesByLineItem[$lineItemHashId] = 0;
				}
	
				if ($adjustment->amount != 0) {
					$this->_discountTotal += $adjustment->amount;
					$adjustments[] = $adjustment;
				}
			}
		}
		//Craft::dd($matchingLineIds);
	
		if ($discount->baseDiscount !== null && $discount->baseDiscount != 0) {
			$baseDiscountAdjustment = $this->_createOrderAdjustment($discount);
			$baseDiscountAdjustment->amount = $discount->baseDiscount;
			$adjustments[] = $baseDiscountAdjustment;
		}
	
		// only display adjustment if an amount was calculated
		if (!count($adjustments)) {
			return false;
		}
	
	
		return $adjustments;
	}
	
	/**
	 * @param DiscountModel $discount
	 * @return float
	 */
	private function _getBaseDiscountAmount(DiscountModel $discount): float
	{
		if ($discount->baseDiscountType == DiscountRecord::BASE_DISCOUNT_TYPE_VALUE) {
			return $discount->baseDiscount;
		}
	
		$total = $this->_order->getItemSubtotal();
	
		if ($discount->baseDiscountType == DiscountRecord::BASE_DISCOUNT_TYPE_PERCENT_TOTAL_DISCOUNTED || $discount->baseDiscountType == DiscountRecord::BASE_DISCOUNT_TYPE_PERCENT_ITEMS_DISCOUNTED) {
			$total += $this->_discountTotal;
		}
	
		if ($discount->baseDiscountType == DiscountRecord::BASE_DISCOUNT_TYPE_PERCENT_TOTAL_DISCOUNTED || $discount->baseDiscountType == DiscountRecord::BASE_DISCOUNT_TYPE_PERCENT_TOTAL) {
			$total += $this->_order->getTotalShippingCost();
		}
	
		return ($total / 100) * $discount->baseDiscount;
	}
}