<?php

namespace webdna\commerce\multicoupon\services;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\base\Model;
use craft\db\Query;
use DateTime;
use Throwable;
use yii\db\Expression;
use yii\base\Component;
use craft\commerce\Plugin as Commerce;
use craft\commerce\db\Table;
use craft\commerce\elements\Order;
use craft\commerce\models\Coupon;
use craft\commerce\models\Discount;
use webdna\commerce\multicoupon\MultiCoupon;
use webdna\commerce\multicoupon\records\Discount as DiscountRecord;

/**
 * Discounts service
 */
class Discounts extends Component
{
    
    /**
     * @var Discount[][]|null
     */
    private ?array $_activeDiscountsByKey = null;
    
    
    public function isValidCode(string $code): mixed
    {
        $availableDiscounts = [];
        $discounts = Commerce::getInstance()->getDiscounts()->getAllActiveDiscounts();
        
        foreach ($discounts as $discount) {
            $coupons = $discount->getCoupons();
            if (!empty($coupons)) {
                if (ArrayHelper::firstWhere($coupons, static fn(Coupon $coupon) => (strcasecmp($coupon->code, $code) == 0) && ($coupon->maxUses === null || $coupon->maxUses > $coupon->uses))) {
                    return $discount;
                }
            }
        }
        
        return false;
    }
    
    
    public function getAllActiveDiscounts(Order $order = null): array
    {
        $purchasableIds = [];
        if ($order) {
            $purchasableIds = collect($order->getLineItems())->pluck('purchasableId')->unique()->all();
        }
    
        // Date condition for use with key
        if ($order && $order->dateOrdered) {
            $date = $order->dateOrdered;
        } else {
            // We use a round the time so we can have a cache within the same request (rounded to 1 minute flat, no seconds)
            $date = new DateTime();
            $date->setTime((int)$date->format('H'), (int)(round($date->format('i') / 1) * 1));
        }
        
        $store = $order ? $order->getStore() : Plugin::getInstance()->getStores()->getCurrentStore();
    
        // Coupon condition key
        $dateKey = DateTimeHelper::toIso8601($date);
        $storeKey = $order ? $order->getStore()->id : '*';
        $purchasablesKey = !empty($purchasableIds) ? md5(serialize($purchasableIds)) : '';
        $cacheKeys = [];
        
        $couponCodes = $order ? $order->couponCodes : [];

        $couponKeys = ($order && count($couponCodes)) ? $couponCodes : ['*'];
        foreach ($couponKeys as $couponKey) {
            $cacheKeys[] = implode(':', array_filter([$dateKey, $couponKey, $purchasablesKey]));
        }
    
        foreach ($cacheKeys as $cacheKey) {
            if (isset($this->_activeDiscountsByKey[$cacheKey])) {
                //return $this->_activeDiscountsByKey[$cacheKey];
            }
        }
    
        $discountQuery = $this->_createDiscountQuery()
            // Restricted by enabled discounts
            ->where([
                'enabled' => true,
            ])
            // Restricted by store
            ->andWhere(['storeId' => $store->id])
            // Restrict by things that a definitely not in date
            ->andWhere([
                'or',
                ['dateFrom' => null],
                ['<=', 'dateFrom', Db::prepareDateForDb($date)],
            ])
            ->andWhere([
                'or',
                ['dateTo' => null],
                ['>=', 'dateTo', Db::prepareDateForDb($date)],
            ])
            ->andWhere([
                'or',
                ['totalDiscountUseLimit' => 0],
                ['<', 'totalDiscountUses', new Expression('[[totalDiscountUseLimit]]')],
            ]);
    
        // Pre-qualify discounts based on purchase total
        if ($order) {
            if ($order->getEmail()) {
                $emailUsesSubQuery = (new Query())
                    ->select([new Expression('COALESCE(SUM([[edu.uses]]), 0)')])
                    ->from(['edu' => Table::EMAIL_DISCOUNTUSES])
                    ->where(new Expression('[[edu.discountId]] = [[discounts.id]]'))
                    ->andWhere(['email' => $order->getEmail()]);
    
                $discountQuery->andWhere([
                    'or',
                    ['perEmailLimit' => 0],
                    ['and', ['>', 'perEmailLimit', 0], ['>', 'perEmailLimit', $emailUsesSubQuery]],
                ]);
            } else {
                $discountQuery->andWhere(['perEmailLimit' => 0]);
            }
    
            $discountQuery->andWhere([
                'or',
                ['purchaseTotal' => 0],
                ['and', ['allPurchasables' => true], ['allCategories' => true], ['<=', 'purchaseTotal', $order->getItemSubtotal()]],
                ['allPurchasables' => false],
                ['allCategories' => false],
            ]);
     
            $discountQuery->andWhere([
                'or',
                ['purchaseQty' => 0, 'maxPurchaseQty' => 0],
                ['and', ['allPurchasables' => true], ['allCategories' => true], ['>', 'purchaseQty', 0], ['maxPurchaseQty' => 0], ['<=', 'purchaseQty', $order->getTotalQty()]],
                ['and', ['allPurchasables' => true], ['allCategories' => true], ['>', 'maxPurchaseQty', 0], ['purchaseQty' => 0], ['>=', 'maxPurchaseQty', $order->getTotalQty()]],
                ['and', ['allPurchasables' => true], ['allCategories' => true], ['>', 'maxPurchaseQty', 0], ['>', 'purchaseQty', 0], ['<=', 'purchaseQty', $order->getTotalQty()], ['>=', 'maxPurchaseQty', $order->getTotalQty()]],
                ['allPurchasables' => false],
                ['allCategories' => false],
            ]);
        }
    
        $couponSubQuery = (new Query())
            ->from(Table::COUPONS)
            ->where(new Expression('[[discountId]] = [[discounts.id]]'));
    
        // If the order has a coupon code let's only get discounts for that code, or discounts that do not require a code
        if ($order && count($couponCodes)) {
            if (Craft::$app->getDb()->getIsPgsql()) {
                $codeWhere = ['ilike', 'code', $couponCodes];
            } else {
                $codeWhere = ['code' => $couponCodes];
            }
    
            $discountQuery->andWhere(
                [
                    'or',
                    // Find discount where the coupon code matches
                    [
                        'exists', (clone $couponSubQuery)
                        ->andWhere($codeWhere)
                        ->andWhere([
                                'or',
                                ['maxUses' => null],
                                new Expression('[[uses]] < [[maxUses]]'),
                            ]
                        ),
                    ],
                    // OR find discounts that do not have a coupon code requirement
                    ['not exists', $couponSubQuery],
                ]
            );
        } elseif ($order && !count($couponCodes)) {
            $discountQuery->andWhere(
            // only discounts that do not have a coupon code requirement
                ['not exists', $couponSubQuery]
            );
        }
    
        if ($order && !empty($purchasableIds)) {
            $matchPurchasableSubQuery = (new Query())
                ->from(['subdp' => Table::DISCOUNT_PURCHASABLES])
                ->where(new Expression('[[subdp.discountId]] = [[discounts.id]]'))
                ->andWhere(['subdp.purchasableId' => $purchasableIds]);
    
            $discountQuery->andWhere(
                [
                    'or',
                    ['allPurchasables' => true],
                    [
                        'exists', $matchPurchasableSubQuery,
                    ],
                ]
            );
        }
    
        $this->_activeDiscountsByKey[$cacheKey] = $this->_populateDiscounts($discountQuery->all());
    
        return $this->_activeDiscountsByKey[$cacheKey];
    }
    

    /**
     * @param array $discounts
     * @return array
     * @throws InvalidConfigException
     * @since 2.2.14
     */
    private function _populateDiscounts(array $discounts): array
    {
        foreach ($discounts as &$discount) {
            // @TODO remove this when we can widen the accepted params on the setters
            $discount['purchasableIds'] = !empty($discount['purchasableIds']) ? StringHelper::split($discount['purchasableIds']) : [];
            // IDs can be either category ID or entry ID due to the entryfication
            $discount['categoryIds'] = !empty($discount['categoryIds']) ? StringHelper::split($discount['categoryIds']) : [];
            $discount['orderCondition'] = $discount['orderCondition'] ?? '';
            $discount['customerCondition'] = $discount['customerCondition'] ?? '';
            $discount['billingAddressCondition'] = $discount['billingAddressCondition'] ?? '';
            $discount['shippingAddressCondition'] = $discount['shippingAddressCondition'] ?? '';
    
            $discount = Craft::createObject([
                'class' => Discount::class,
                'attributes' => $discount,
            ]);
        }
    
        return $discounts;
    }
    
    /**
     * Returns a Query object prepped for retrieving discounts
     */
    private function _createDiscountQuery(): Query
    {
        $query = (new Query())
            ->select([
                '[[discounts.allCategories]]',
                '[[discounts.allPurchasables]]',
                '[[discounts.appliedTo]]',
                '[[discounts.baseDiscount]]',
                '[[discounts.categoryRelationshipType]]',
                '[[discounts.couponFormat]]',
                '[[discounts.dateCreated]]',
                '[[discounts.dateFrom]]',
                '[[discounts.dateTo]]',
                '[[discounts.dateUpdated]]',
                '[[discounts.description]]',
                '[[discounts.enabled]]',
                '[[discounts.excludeOnPromotion]]',
                '[[discounts.hasFreeShippingForMatchingItems]]',
                '[[discounts.hasFreeShippingForOrder]]',
                '[[discounts.id]]',
                '[[discounts.ignorePromotions]]',
                '[[discounts.maxPurchaseQty]]',
                '[[discounts.name]]',
                '[[discounts.orderCondition]]',
                '[[discounts.orderConditionFormula]]',
                '[[discounts.percentageOffSubject]]',
                '[[discounts.percentDiscount]]',
                '[[discounts.perEmailLimit]]',
                '[[discounts.perItemDiscount]]',
                '[[discounts.perUserLimit]]',
                '[[discounts.purchaseTotal]]',
                '[[discounts.purchaseQty]]',
                '[[discounts.sortOrder]]',
                '[[discounts.stopProcessing]]',
                '[[discounts.storeId]]',
                '[[discounts.totalDiscountUseLimit]]',
                '[[discounts.totalDiscountUses]]',
                '[[discounts.customerCondition]]',
                '[[discounts.shippingAddressCondition]]',
                '[[discounts.billingAddressCondition]]',
                '[[discounts.purchasableIds]]',
                '[[discounts.categoryIds]]',
            ])
            ->from(['discounts' => Table::DISCOUNTS])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->leftJoin(Table::DISCOUNT_PURCHASABLES . ' dp', '[[dp.discountId]]=[[discounts.id]]')
            ->leftJoin(Table::DISCOUNT_CATEGORIES . ' dpt', '[[dpt.discountId]]=[[discounts.id]]')
            ->groupBy(['discounts.id']);
        
        return $query;
    }

}
