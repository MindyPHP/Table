<?php

/**
 * All rights reserved.
 *
 * @author Falaleev Maxim
 * @email max@studio107.ru
 * @version 1.0
 * @company Studio107
 * @site http://studio107.ru
 * @date 06/02/15 18:58
 */

namespace Tests\Cases\Orm\Mysql;

use Tests\Orm\LookupRelationTest;

class MysqlLookupRelationTest extends LookupRelationTest
{
    public $driver = 'mysql';
}
