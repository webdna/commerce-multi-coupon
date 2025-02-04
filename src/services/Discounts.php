<?php

namespace webdna\commerce\multicoupon\services;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\base\Model;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use DateTime;
use Throwable;
use yii\db\Expression;
use yii\base\Component;
use craft\commerce\Plugin as Commerce;
use craft\commerce\db\Table;
use craft\commerce\elements\Order;
use craft\commerce\enums\LineItemType;
use craft\commerce\events\DiscountEvent;
use craft\commerce\events\MatchLineItemEvent;
use craft\commerce\events\MatchOrderEvent;
use craft\commerce\models\Coupon;
use craft\commerce\models\Discount;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\records\Coupon as CouponRecord;
use craft\commerce\records\CustomerDiscountUse;
use craft\commerce\records\Discount as DiscountRecord;
use craft\commerce\records\DiscountCategory as DiscountCategoryRecord;
use craft\commerce\records\DiscountPurchasable as DiscountPurchasableRecord;
use craft\commerce\records\EmailDiscountUse as EmailDiscountUseRecord;
use webdna\commerce\multicoupon\MultiCoupon;
use function in_array;

/**
 * Discounts service
 */
class Discounts extends Component
{
    
    /**
     * @var Collection<Discount>[]|null
     */
    private ?array $_allDiscounts = null;
    
    /**
     * @var Discount[][]|null
     */
    private ?array $_activeDiscountsByKey = null;
    
    /**
     * @var array|null
     */
    private ?array $_matchingLineItemCategoryCondition = null;
    
    
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
        
        $store = $order ? $order->getStore() : Commerce::getInstance()->getStores()->getCurrentStore();
    
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
     * Match a line item against a discount.
     *
     * @throws \Exception
     */
    public function matchLineItem(LineItem $lineItem, Discount $discount, bool $matchOrder = false): bool
    {
        if ($matchOrder && !$this->matchOrder($lineItem->order, $discount)) {
            return false;
        }
    
        $siteId = $lineItem->order->orderSiteId ?? Craft::$app->getSites()->getCurrentSite()->id;
    
        if ($lineItem->getOnPromotion() && $discount->excludeOnPromotion) {
            return false;
        }
    
        if (!$lineItem->getIsPromotable()) {
            return false;
        }
    
        if ($lineItem->type === LineItemType::Purchasable) {
            // can't match something not promotable
            /** @var Purchasable|null $purchasable */
            $purchasable = $lineItem->getPurchasable();
    
            if (!$discount->allPurchasables && !in_array($purchasable->id, $discount->getPurchasableIds(), false)) {
                return false;
            }

            // TODO: Rename to allEntries in Commerce 5
            if (!$discount->allCategories) {
                $key = 'relationshipType:' . $discount->categoryRelationshipType . ':purchasableId:' . $purchasable->getId() . ':categoryIds:' . implode('|', $discount->getCategoryIds());
    
                if (!isset($this->_matchingLineItemCategoryCondition[$key])) {
                    $relatedTo = [$discount->categoryRelationshipType => $purchasable->getPromotionRelationSource()];
    
                    $relatedEntries = Entry::find()->siteId($siteId)->relatedTo($relatedTo)->ids();
                    $relatedCategories = Category::find()->siteId($siteId)->relatedTo($relatedTo)->ids();
    
                    $relatedCategoriesOrEntries = array_merge($relatedEntries, $relatedCategories);
                    $purchasableIsRelateToOneOrMoreCategories = (bool)array_intersect($relatedCategoriesOrEntries, $this->getCategoryIds($discount->id));
                    
                    if (!$purchasableIsRelateToOneOrMoreCategories) {
                        return $this->_matchingLineItemCategoryCondition[$key] = false;
                    }
                    $this->_matchingLineItemCategoryCondition[$key] = true;
                } elseif ($this->_matchingLineItemCategoryCondition[$key] === false) {
                    return false;
                }
            }
        }
    
        $event = new MatchLineItemEvent(compact('lineItem', 'discount'));
    
        if ($this->hasEventHandlers(craft\commerce\services\Discounts::EVENT_DISCOUNT_MATCHES_LINE_ITEM)) {
            $this->trigger(craft\commerce\services\Discounts::EVENT_DISCOUNT_MATCHES_LINE_ITEM, $event);
        }
    
        return $event->isValid;
    }
    
    private function getCategoryIds(int $id): array
    {
        $categoryIds = (new Query())->select(['dpt.categoryId'])
            ->from(Table::DISCOUNTS . ' discounts')
            ->leftJoin(Table::DISCOUNT_CATEGORIES . ' dpt', '[[dpt.discountId]]=[[discounts.id]]')
            ->where(['discounts.id' => $id])
            ->column();
        return $categoryIds;
    }
    
    
    /**
     * @throws \Exception
     */
    public function matchOrder(Order $order, Discount $discount): bool
    {
        if (!$discount->enabled) {
            return false;
        }
        
    
        $allItemsMatch = ($discount->allPurchasables && $discount->allCategories);
    
        if ($discount->hasOrderCondition() && !$discount->getOrderCondition()->matchElement($order)) {
            return false;
        }
    
        if ($discount->hasCustomerCondition() && (!$order->getCustomer() || !$discount->getCustomerCondition()->matchElement($order->getCustomer()))) {
            return false;
        }
    
        if ($discount->hasShippingAddressCondition() && (!$order->getShippingAddress() || !$discount->getShippingAddressCondition()->matchElement($order->getShippingAddress()))) {
            return false;
        }
    
        if ($discount->hasBillingAddressCondition() && (!$order->getBillingAddress() || !$discount->getBillingAddressCondition()->matchElement($order->getBillingAddress()))) {
            return false;
        }
    
        if (!$this->_isDiscountCouponCodeValid($order, $discount)) {
            return false;
        }
    
        if (!$this->_isDiscountDateValid($order, $discount)) {
            return false;
        }
    
        if (!$this->_isDiscountTotalUseLimitValid($discount)) {
            return false;
        }
    
        if (!$this->_isDiscountPerUserUsageValid($discount, $order->getCustomer())) {
            return false;
        }
    
        if (!$this->_isDiscountEmailRequirementValid($discount, $order)) {
            return false;
        }
    
        if (!$this->_isDiscountPerEmailLimitValid($discount, $order)) {
            return false;
        }
    
        if (!$this->_isDiscountConditionFormulaValid($order, $discount)) {
            return false;
        }
    
        if ($allItemsMatch && $discount->purchaseTotal > 0 && $order->getItemSubtotal() < $discount->purchaseTotal) {
            return false;
        }
    
        if ($allItemsMatch && $discount->purchaseQty > 0 && $order->getTotalQty() < $discount->purchaseQty) {
            return false;
        }
    
        if ($allItemsMatch && $discount->maxPurchaseQty > 0 && $order->getTotalQty() > $discount->maxPurchaseQty) {
            return false;
        }
        
    
        // Check to see if we need to match on data related to the lineItems
        if (!$discount->allPurchasables || !$discount->allCategories) {
    
            // Get matching line items but don't match the order again
            $matchingItems = collect($order->getLineItems())
                ->filter(fn($item) => $this->matchLineItem($item, $discount));
    
            if ($matchingItems->isEmpty()) {
                return false;
            }
    
            $matchingQty = $matchingItems->sum('qty');
            $matchingTotal = $matchingItems->sum('subtotal');
    
            if ($discount->purchaseTotal > 0 && $matchingTotal < $discount->purchaseTotal) {
                return false;
            }
    
            if ($discount->purchaseQty > 0 && $matchingQty < $discount->purchaseQty) {
                return false;
            }
    
            if ($discount->maxPurchaseQty > 0 && $matchingQty > $discount->maxPurchaseQty) {
                return false;
            }
        }
        
    
        // Raise the 'beforeMatchLineItem' event
        $event = new MatchOrderEvent(compact('order', 'discount'));
    
        if ($this->hasEventHandlers(self::EVENT_DISCOUNT_MATCHES_ORDER)) {
            $this->trigger(self::EVENT_DISCOUNT_MATCHES_ORDER, $event);
        }
    
        return $event->isValid;
    }
    
    
    /**
     * @param Order $order
     * @param Discount $discount
     * @return bool
     * @throws InvalidConfigException
     */
    private function _isDiscountCouponCodeValid(Order $order, Discount $discount): bool
    {

        // If the discount does not require a coupon code, it's valid
        if (!$discount->requireCouponCode) {
            return true;
        }
    
        $coupons = $discount->getCoupons();

        // Protect against empty coupon code list if the discount requires a coupon code
        if (empty($coupons)) {
            return false;
        }
        
        foreach ($order->couponCodes as $code) {
            if (ArrayHelper::firstWhere($coupons, static fn(Coupon $coupon) => (strcasecmp($coupon->code, $code) == 0) && ($coupon->maxUses === null || $coupon->maxUses > $coupon->uses))) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * @throws \Exception
     */
    private function _isDiscountDateValid(Order $order, Discount $discount): bool
    {
        $now = new DateTime();
    
        if ($order->isCompleted && $order->dateOrdered) {
            $now = $order->dateOrdered;
        }
    
        $from = $discount->dateFrom;
        $to = $discount->dateTo;
    
        return !(($from && $from > $now) || ($to && $to < $now));
    }
    
    /**
     * @throws InvalidConfigException
     * @throws LoaderError
     * @throws SyntaxError
     */
    private function _isDiscountConditionFormulaValid(Order $order, Discount $discount): bool
    {
        if ($discount->orderConditionFormula) {
            $fieldsAsArray = $order->getSerializedFieldValues();
            $orderAsArray = $order->toArray([], ['lineItems.snapshot', 'shippingAddress', 'billingAddress']);
            $orderConditionParams = [
                'order' => array_merge($orderAsArray, $fieldsAsArray),
            ];
            return Commerce::getInstance()->getFormulas()->evaluateCondition($discount->orderConditionFormula, $orderConditionParams, 'Evaluate Order Discount Condition Formula');
        }
    
        return true;
    }
    
    private function _isDiscountTotalUseLimitValid(Discount $discount): bool
    {
        if ($discount->totalDiscountUseLimit > 0) {
            if ($discount->totalDiscountUses >= $discount->totalDiscountUseLimit) {
                return false;
            }
        }
    
        return true;
    }
    
    /**
     * @param Discount $discount
     * @param User|null $user
     * @return bool
     */
    private function _isDiscountPerUserUsageValid(Discount $discount, ?User $user): bool
    {
        if ($discount->perUserLimit > 0) {
            if (!$user) {
                return false;
            }
    
            if (Craft::$app->getRequest()->getIsSiteRequest()) {
                $currentUser = Craft::$app->getUser()->getIdentity();
                $isCustomerCurrentUser = ($currentUser && $currentUser->id == $user->id);
    
                if (!$isCustomerCurrentUser) {
                    return false;
                }
            }
    
            $usage = (new Query())
                ->select(['uses'])
                ->from([Table::CUSTOMER_DISCOUNTUSES])
                ->where(['[[customerId]]' => $user->id, 'discountId' => $discount->id])
                ->scalar();
    
            if ($usage && $usage >= $discount->perUserLimit) {
                return false;
            }
        }
    
        return true;
    }
    
    private function _isDiscountEmailRequirementValid(Discount $discount, Order $order): bool
    {
        if ($discount->perEmailLimit > 0 && !$order->getEmail()) {
            return false;
        }
    
        return true;
    }
    
    private function _isDiscountPerEmailLimitValid(Discount $discount, Order $order): bool
    {
        if ($discount->perEmailLimit > 0 && $order->getEmail()) {
            $usage = (new Query())
                ->select(['uses'])
                ->from([Table::EMAIL_DISCOUNTUSES])
                ->where(['email' => $order->getEmail(), 'discountId' => $discount->id])
                ->scalar();
    
            if ($usage && $usage >= $discount->perEmailLimit) {
                return false;
            }
        }
    
        return true;
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
                '[[discounts.requireCouponCode]]',
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
