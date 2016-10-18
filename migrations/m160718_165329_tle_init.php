<?php

use yii\db\Migration;

class m160718_165329_tle_init extends Migration
{
    protected static $tableTle = '{{%tle}}';

    public function safeUp()
    {
        $this->createTable(self::$tableTle, [
            'id' => $this->primaryKey()->notNull(),
            'norad_id' => $this->integer()->notNull(),
            'epoch_time' => $this->dateTime()->notNull(),
            'line_1' => $this->string(69)->notNull(),
            'line_2' => $this->string(69)->notNull(),
            'updated_at' => $this->integer()->notNull()
        ]);
    }

    public function safeDown()
    {
        $this->dropTable(self::$tableTle);
    }
}
