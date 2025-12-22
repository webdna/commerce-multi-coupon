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
use Money\Teller;

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
		$teller = $this->_getTeller();

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
			} else {
				if (Commerce::getInstance()->getDiscounts()->matchOrder($order, $discount)) {
					$availableDiscounts[] = $discount;
				}
			}
		}
		
		if (!$availableDiscounts) {
			return [];
		}
		
		
		foreach ($this->_order->getLineItems() as $lineItem) {
			$lineItemHashId = spl_object_hash($lineItem);
			$lineItemDiscountAmount = $lineItem->getDiscount();
			if ($lineItemDiscountAmount) {
				$discountedUnitPrice = (float)$teller->add(
					$lineItem->salePrice,
					$teller->divide($lineItemDiscountAmount, $lineItem->qty)
				);
				$this->_discountUnitPricesByLineItem[$lineItemHashId] = $discountedUnitPrice;
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
		
		if ($this->_spreadBaseOrderDiscountsToLineItems) {
			$priceByLineItem = [];
			foreach ($this->_order->getLineItems() as $lineItem) {
				$lineItemHashId = spl_object_hash($lineItem);
				$priceByLineItem[$lineItemHashId] = (float)$teller->add($lineItem->getSubtotal(), $lineItem->getDiscount());
			}
		
			$orderLevelAdjustments = [];
		
			// Remove other plugins previous order level discount adjustments
			$allAdjustments = $this->_order->getAdjustments();
			foreach ($allAdjustments as $key => $previousAdjustment) {
				if ($previousAdjustment->type == self::ADJUSTMENT_TYPE && !$previousAdjustment->getLineItem()) {
					$orderLevelAdjustments[] = $previousAdjustment;
					unset($allAdjustments[$key]);
				}
			}
			$this->_order->setAdjustments($allAdjustments);
		
			// Our adjustments
			foreach ($adjustments as $key => $adjustment) {
				if ($adjustment->getLineItem()) {
					$lineItemHashId = spl_object_hash($adjustment->getLineItem());
					// Reduce the price of the line item by the amount of discount from the adjuster
					$priceByLineItem[$lineItemHashId] = (float)$teller->add($priceByLineItem[$lineItemHashId] ?? 0, $adjustment->amount);
				} else {
					// If it's an order level adjustment lets track it, but remove it from the standard adjustments.
					$orderLevelAdjustments[] = $adjustment;
					unset($adjustments[$key]);
				}
			}
		
			$lineItemsByPrice = $this->_order->getLineItems();
			ArrayHelper::multisort($lineItemsByPrice, static function($item) use ($priceByLineItem) {
				// sort by age if it exists or by name otherwise
				/** @var LineItem $item */
				$lineItemHashId = spl_object_hash($item);
				return $priceByLineItem[$lineItemHashId];
			}, SORT_DESC);
		
			// Remove non-promotable line items
			$lineItemsByPrice = ArrayHelper::where($lineItemsByPrice, fn(LineItem $lineItem) => $lineItem->getIsPromotable(), true, true);
		
			// Loop over each order level adjustment and add an adjustment to each line item until it runs out.
			foreach ($orderLevelAdjustments as $orderLevelAdjustment) {
				// Track the amount of discount (as a positive number), as we are going to deduct it as we use it up on line items.
				$currentDiscountAmountRemaining = -$orderLevelAdjustment->amount;
		
				// Lets loop over the line items and apply some or all of the discount amount
				foreach ($lineItemsByPrice as $lineItem) {
		
					// We need to know the hash ID of the line item since some line items do not have an ID yet
					$lineItemHashId = spl_object_hash($lineItem);
		
					// Do we have any discount left to use, and can the line item still be discounted?
					if ($currentDiscountAmountRemaining > 0 && $priceByLineItem[$lineItemHashId] > 0) {
		
						// The amount of the adjustment for this line item.
						$amount = 0;
		
						// Is the amount of discount greater than the price of the item
						if ($currentDiscountAmountRemaining >= $priceByLineItem[$lineItemHashId]) {
							$amount = (float)$teller->multiply($priceByLineItem[$lineItemHashId], -1); // Take the full price of the item off
							$priceByLineItem[$lineItemHashId] = 0; // Price is now free
							$currentDiscountAmountRemaining = (float)$teller->add($currentDiscountAmountRemaining, $amount); // Reduce the price of the discount remaining so it can still be used
						} else {
							// Is the current amount of discount remaining less than the current price of the item? Take the whole discount remainder off the item.
							if ($currentDiscountAmountRemaining < $priceByLineItem[$lineItemHashId]) {
								$amount = (float)$teller->multiply($currentDiscountAmountRemaining, -1); // The adjustment amount is always a negative number
								$currentDiscountAmountRemaining = 0; // Reduce the amount of discount to zero since there is none left
								$priceByLineItem[$lineItemHashId] = (float)$teller->add($priceByLineItem[$lineItemHashId], $amount); // Reduce the price of the item that we are tracking
							}
						}
		
						if ($amount) {
							/** @var OrderAdjustment $adjustment */
							$adjustment = clone $orderLevelAdjustment;
							$adjustment->amount = $amount;
							$adjustment->setLineItem($lineItem);
							$adjustments[] = $adjustment;
						}
					}
				}
			}
		}
		
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
		$teller = $this->_getTeller();
	
		$matchingLineIds = [];
		foreach ($this->_order->getLineItems() as $item) {
			$lineItemHashId = spl_object_hash($item);
			// Order is already a match to this discount, or we wouldn't get here.
			if (MultiCoupon::getInstance()->discounts->matchLineItem($item, $discount, false)) {
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
					$discountAmountPerItemPreDiscounts = (float)$teller->multiply($item->salePrice, $discount->percentDiscount);
				}
	
				$unitPrice = $this->_discountUnitPricesByLineItem[$lineItemHashId] ?? $item->salePrice;
	
				$lineItemSubtotal = (float)$teller->multiply($item->qty, $unitPrice);
	
				$unitPrice = max((float)$teller->add($unitPrice, $amountPerItem), 0);
	
				if ($unitPrice > 0) {
					if ($discount->percentageOffSubject == DiscountRecord::TYPE_ORIGINAL_SALEPRICE) {
						$discountedUnitPrice = (float)$teller->add($unitPrice, $discountAmountPerItemPreDiscounts);
					} else {
						$discountedUnitPrice = (float)$teller->add(
							$unitPrice,
							$teller->multiply($unitPrice, $discount->percentDiscount)
						);
					}
	
					$discountedSubtotal = (float)$teller->multiply($discountedUnitPrice, $item->qty);
					$amountOfPercentDiscount = (float)$teller->subtract($discountedSubtotal, $lineItemSubtotal);
					$this->_discountUnitPricesByLineItem[$lineItemHashId] = $discountedUnitPrice;
					$adjustment->amount = $amountOfPercentDiscount; //Adding already rounded
				} else {
					$adjustment->amount = -$lineItemSubtotal;
					$this->_discountUnitPricesByLineItem[$lineItemHashId] = 0;
				}
	
				if ($adjustment->amount != 0) {
					$this->_discountTotal = (float)$teller->add($this->_discountTotal, $adjustment->amount);
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
	
	private function _getTeller(): Teller
	{
		return Commerce::getInstance()->getCurrencies()->getTeller($this->_order->currency);
	}
}