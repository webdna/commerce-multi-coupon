<?php

namespace webdna\commerce\multicoupon;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\base\Element;
use craft\commerce\Plugin as Commerce;
use craft\commerce\elements\Order;
use craft\commerce\services\OrderAdjustments;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineRulesEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\ElementHelper;
use craft\helpers\Db;
use craft\services\Fields;
use craft\web\UrlManager;
use craft\web\Response;
use webdna\commerce\multicoupon\adjusters\MultiCouponCodes as MultiCouponCodesAdjuster;
use webdna\commerce\multicoupon\behaviors\OrderBehavior;
use webdna\commerce\multicoupon\services\Discounts;
use yii\base\Event;

/**
 * Multi Coupon plugin
 *
 * @method static MultiCoupon getInstance()
 * @method Settings getSettings()
 * @author webdna <info@webdna.co.uk>
 * @copyright webdna
 * @license https://craftcms.github.io/license/ Craft License
 */
class MultiCoupon extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = false;
    public bool $hasCpSection = false;
    
    public static function config(): array
    {
        return [
            'components' => [
                'discounts' => Discounts::class,
            ],
        ];
    }

    public function init()
    {
        parent::init();

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function() {
            $this->attachEventHandlers();
        });
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_DEFINE_BEHAVIORS,
            function(DefineBehaviorsEvent $event) {
                $event->behaviors['commerce:order'] = OrderBehavior::class;
            }
        );
        
        Event::on(
            OrderAdjustments::class, 
            OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS, 
            function(RegisterComponentTypesEvent $e) {
                $e->types[] = MultiCouponCodesAdjuster::class;
            }
        );

            
        Event::on(
            Order::class, 
            Order::EVENT_BEFORE_SAVE, 
            function(ModelEvent $event) {
                $order = $event->sender;
                $request = Craft::$app->getRequest();
                    
                if (!Craft::$app->request->isConsoleRequest && $couponCodes = $request->getParam('couponCodes')) {
                    $removeCodes = [];
                    foreach ($couponCodes as $key => $couponCode) {
                        if ($remove = $request->getParam("couponCodes.$key.remove", false)) {
                            $removeCodes[] = $key;
                        }
                    }
                    
                    if (count($removeCodes)) {
                        Db::delete('{{%commerce-multi-coupon_couponcodes}}', [
                            'orderId' => $order->id,
                            'code' => $removeCodes,
                        ]);
                    }
                }
                
                
                if ($order->couponCode) {
                    if ($discount = $this::getInstance()->discounts->isValidCode($order->couponCode)) {
                        
                        Db::upsert('{{%commerce-multi-coupon_couponcodes}}',
                        [
                            'code' => $order->couponCode,
                            'discountId' => $discount->id,
                            'orderId' => $order->id,
                        ], false);
                    }
                }
                
                $order->couponCode = null;
            }
        );

    }
}
