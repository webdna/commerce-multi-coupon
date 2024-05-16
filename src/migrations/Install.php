<?php

namespace webdna\commerce\multicoupon\migrations;

use Craft;
use craft\commerce\db\Table;
use craft\commerce\elements\Order;
use craft\db\Migration;
use craft\db\Table as CraftTable;
use craft\helpers\MigrationHelper;
use Exception;
use ReflectionClass;
use yii\base\NotSupportedException;

/**
 * Installation Migration
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Install extends Migration
{
	public function safeUp(): bool
	{
		$this->createTables();
		$this->createIndexes();
		$this->addForeignKeys();
		$this->insertDefaultData();
	
		return true;
	}
	
	public function safeDown(): bool
	{
		$this->dropForeignKeys();
		$this->dropTables();
	
		return true;
	}
	
	public function createTables(): void
	{
		$this->createTable('{{%commerce-multi-coupon_couponcodes}}', [
			'code' => $this->string()->notNull(),
			'discountId' => $this->integer()->notNull(),
			'orderId' => $this->integer()->notNull(),
		]);
	}
	
	public function createIndexes(): void
	{
		$this->createIndex(null, '{{%commerce-multi-coupon_couponcodes}}', 'code', false);
		$this->createIndex(null, '{{%commerce-multi-coupon_couponcodes}}', 'discountId', false);
		$this->createIndex(null, '{{%commerce-multi-coupon_couponcodes}}', 'orderId', false);
		$this->createIndex(null, '{{%commerce-multi-coupon_couponcodes}}', ['discountId', 'orderId'], true);
	}
	
	public function addForeignKeys(): void
	{
		$this->addForeignKey(null, '{{%commerce-multi-coupon_couponcodes}}', ['orderId'], Table::ORDERS, ['id'], 'CASCADE', 'CASCADE');
		$this->addForeignKey(null, '{{%commerce-multi-coupon_couponcodes}}', ['discountId'], Table::DISCOUNTS, ['id'], 'CASCADE', 'CASCADE');
	}
	
	public function insertDefaultData(): void
	{
		
	}
	
	public function dropForeignKeys(): void
	{
		MigrationHelper::dropAllForeignKeysOnTable('{{%commerce-multi-coupon_couponcodes}}', $this);
	}
	
	public function dropTables(): void
	{
		$this->dropTableIfExists('{{%commerce-multi-coupon_couponcodes}}');
	}
}