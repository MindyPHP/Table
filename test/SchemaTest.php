<?php
/**
 * Created by PhpStorm.
 * User: max
 * Date: 30/06/16
 * Time: 10:07
 */

namespace Mindy\QueryBuilder\Tests;

use Exception;
use Mindy\Helper\Creator;
use Mindy\Query\Connection;
use Mindy\Query\Schema\ColumnSchema;
use Mindy\Query\Schema\TableSchema;
use Mindy\QueryBuilder\LookupBuilder\Legacy;
use Mindy\QueryBuilder\QueryBuilderFactory;
use PDO;
use Mindy\QueryBuilder\Database\Pgsql\Adapter as PgsqlAdapter;
use Mindy\QueryBuilder\Database\Mysql\Adapter as MysqlAdapter;
use Mindy\QueryBuilder\Database\Sqlite\Adapter as SqliteAdapter;

abstract class SchemaTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var QueryBuilderFactory
     */
    public $factory;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @return PgsqlAdapter|MysqlAdapter|SqliteAdapter
     */
    abstract protected function getAdapter();

    /**
     * @return \PDO
     */
    abstract protected function createDriver();

    protected function setUp()
    {
        parent::setUp();
        $driver = $this->createDriver();

        $adapter = $this->getAdapter();
        $adapter->setDriver($driver);

        $lb = new Legacy($this->getAdapter()->getLookupCollection()->getLookups());

        $this->factory = new QueryBuilderFactory($adapter, $lb);

        if ($adapter instanceof MysqlAdapter) {
            $file = 'Mysql/config.php';
        } else if ($adapter instanceof PgsqlAdapter) {
            $file = 'Pgsql/config.php';
        } else if ($adapter instanceof SqliteAdapter) {
            $file = 'Sqlite/config.php';
        } else {
            throw new Exception('Unknown adapter');
        }
        $this->connection = Creator::createObject(require(__DIR__ . '/' . $file));
    }

    protected function getQueryBuilder()
    {
        return $this->factory->getQueryBuilder();
    }

    public function testAddColumn()
    {
        $c = $this->connection;
        $qb = $c->getQueryBuilder();
        $this->assertNotNull($qb->getAdapter()->getDriver());

        $schema = $c->getSchema();
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertInstanceOf(TableSchema::class, $tableSchema);
        $this->assertNull($tableSchema->getColumn('user_id'));

        $c->createCommand($qb->addColumn('profile', 'user_id', 'int'))->execute();
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertInstanceOf(ColumnSchema::class, $tableSchema->getColumn('user_id'));
    }

    public function testRenameColumn()
    {
        $c = $this->connection;
        $qb = $c->getQueryBuilder();
        $this->assertNotNull($qb->getAdapter()->getDriver());

        $schema = $c->getSchema();
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertInstanceOf(ColumnSchema::class, $tableSchema->getColumn('description'));

        $c->createCommand($qb->renameColumn('profile', 'description', 'name'))->execute();
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertInstanceOf(ColumnSchema::class, $tableSchema->getColumn('name'));
        $this->assertNull($tableSchema->getColumn('description'));
    }

    public function testRenameTable()
    {
        $c = $this->connection;
        $qb = $c->getQueryBuilder();
        $this->assertNotNull($qb->getAdapter()->getDriver());

        $schema = $c->getSchema();
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertInstanceOf(TableSchema::class, $tableSchema);

        $c->createCommand($qb->renameTable('profile', 'user_profile'))->execute();
        $tableSchema = $schema->getTableSchema('user_profile', true);
        $this->assertInstanceOf(TableSchema::class, $tableSchema);
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertNull($tableSchema);

        $c->createCommand($qb->renameTable('user_profile', 'profile'))->execute();
    }

    public function testDropColumn()
    {
        $c = $this->connection;
        $qb = $c->getQueryBuilder();
        $this->assertNotNull($qb->getAdapter()->getDriver());

        $schema = $c->getSchema();
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertInstanceOf(ColumnSchema::class, $tableSchema->getColumn('description'));

        $c->createCommand($qb->dropColumn('profile', 'description'))->execute();
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertNull($tableSchema->getColumn('description'));
    }

    public function testDropTable()
    {
        $c = $this->connection;
        $qb = $c->getQueryBuilder();
        $this->assertNotNull($qb->getAdapter()->getDriver());

        $schema = $c->getSchema();
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertInstanceOf(TableSchema::class, $tableSchema);

        $c->createCommand($qb->dropTable('profile'))->execute();
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertNull($tableSchema);
    }

    public function testCreateTable()
    {
        $c = $this->connection;
        $qb = $c->getQueryBuilder();
        $this->assertNotNull($qb->getAdapter()->getDriver());

        $schema = $c->getSchema();
        $c->createCommand($qb->dropTableIfExists('foo'))->execute();

        $tableSchema = $schema->getTableSchema('foo', true);
        $this->assertNull($tableSchema);

        $c->createCommand($qb->createTable('foo', [
            'id' => $schema->getColumnType('pk')
        ]))->execute();
        $tableSchema = $schema->getTableSchema('foo', true);
        $this->assertInstanceOf(TableSchema::class, $tableSchema);
        $this->assertInstanceOf(ColumnSchema::class, $tableSchema->getColumn('id'));
    }
    
    public function testForeignKey()
    {
        $c = $this->connection;
        $qb = $c->getQueryBuilder();
        $this->assertNotNull($qb->getAdapter()->getDriver());

        $schema = $this->connection->getSchema();
        $tableSchema = $schema->getTableSchema('order_item', true);
        $this->assertInstanceOf(TableSchema::class, $tableSchema);
        
        $c->createCommand($qb->dropForeignKey('order_item', 'FK_order_item_order_id'))->execute();
    }

    public function testTruncate()
    {
        $c = $this->connection;
        $qb = $c->getQueryBuilder();
        $this->assertNotNull($qb->getAdapter()->getDriver());

        $schema = $this->connection->getSchema();
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertInstanceOf(TableSchema::class, $tableSchema);

        $rows = $c->createCommand($qb->select('COUNT(*)')->from('profile')->toSQL())->queryScalar();
        $this->assertEquals(2, $rows);

        $c->createCommand($qb->truncate('profile'))->execute();

        $rows = $c->createCommand($qb->select('COUNT(*)')->from('profile')->toSQL())->queryScalar();
        $this->assertEquals(0, $rows);
    }

    public function testDropIndex()
    {
        $c = $this->connection;
        $qb = $c->getQueryBuilder();
        $this->assertNotNull($qb->getAdapter()->getDriver());

        $schema = $c->getSchema();
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertInstanceOf(TableSchema::class, $tableSchema);

        $c->createCommand($qb->addColumn('profile', 'customer_id', $schema->getColumnType('int')))->execute();

        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertInstanceOf(ColumnSchema::class, $tableSchema->getColumn('customer_id'));

        $this->assertEquals([], $schema->findUniqueIndexes($tableSchema));
        $c->createCommand($qb->createIndex('profile', 'uniq_customer_id', ['customer_id'], true))->execute();

        $this->assertEquals(['uniq_customer_id'], array_keys($schema->findUniqueIndexes($tableSchema)));

//        $c->createCommand($qb->dropIndex('profile', 'uniq_customer_id'))->execute();
//        $this->assertEquals([], $schema->findUniqueIndexes($tableSchema));
    }

    public function testDropPrimaryKey()
    {
        $c = $this->connection;
        $qb = $c->getQueryBuilder();
        $this->assertNotNull($qb->getAdapter()->getDriver());

        $schema = $c->getSchema();
        $tableSchema = $schema->getTableSchema('drop_primary_test', true);
        $this->assertInstanceOf(TableSchema::class, $tableSchema);

        $tableSchema = $schema->getTableSchema('drop_primary_test', true);
        $this->assertTrue($tableSchema->getColumn('order_id')->isPrimaryKey);
        $this->assertTrue($tableSchema->getColumn('item_id')->isPrimaryKey);

        $c->createCommand($qb->dropPrimaryKey('drop_primary_test', 'drop_primary_test_pkey'))->execute();

        $tableSchema = $schema->getTableSchema('drop_primary_test', true);
        $this->assertEmpty($tableSchema->getColumn('order_id')->isPrimaryKey);
        $this->assertEmpty($tableSchema->getColumn('item_id')->isPrimaryKey);
    }

    public function testResetSequence()
    {
        $c = $this->connection;
        $qb = $c->getQueryBuilder();
        $this->assertNotNull($qb->getAdapter()->getDriver());

        $schema = $c->getSchema();
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertInstanceOf(TableSchema::class, $tableSchema);

        $sequenceName = $c->driverName == 'pgsql' ? 'profile_id_seq' : 'profile';
        $c->createCommand($qb->resetSequence($sequenceName, 1))->execute();
    }

    public function testLimitOffset()
    {
        $c = $this->connection;
        $qb = $c->getQueryBuilder();
        $this->assertNotNull($qb->getAdapter()->getDriver());

        $schema = $c->getSchema();
        $tableSchema = $schema->getTableSchema('profile', true);
        $this->assertInstanceOf(TableSchema::class, $tableSchema);

        $rows = $c->createCommand($qb->from('profile')->toSQL())->queryAll();
        $this->assertEquals(2, count($rows));

        $row = $c->createCommand($qb->from('profile')->limit(1)->offset(0)->toSQL())->queryOne();
        $this->assertEquals(1, $row['id']);

        $row = $c->createCommand($qb->from('profile')->limit(1)->offset(1)->toSQL())->queryOne();
        $this->assertEquals(2, $row['id']);

        $rows = $c->createCommand($qb->from('profile')->limit(0)->offset(0)->toSQL())->queryAll();
        $this->assertEquals(2, count($rows));

        $sql = $qb->from('profile')->offset(1)->toSQL();
        if ($c->driverName == 'sqlite') {
            $this->assertEquals($c->getAdapter()->quoteSql('SELECT * FROM [[profile]] LIMIT -1 OFFSET 1'), $sql);
        } else if ($c->driverName == 'mysql') {
            $this->assertEquals($c->getAdapter()->quoteSql('SELECT * FROM [[profile]] LIMIT 1, 18446744073709551615'), $sql);
        } else {
            $this->assertEquals($c->getAdapter()->quoteSql('SELECT * FROM [[profile]] LIMIT ALL OFFSET 1'), $sql);
        }
        $rows = $c->createCommand($sql)->queryAll();
        $this->assertEquals(1, count($rows));
    }

    public function testRandomOrder()
    {
        $c = $this->connection;
        switch ($c->driverName) {
            case 'sqlite':
                $this->assertEquals('RANDOM()' , $c->getAdapter()->getRandomOrder());
                break;
            case 'mysql':
                $this->assertEquals('RAND()' , $c->getAdapter()->getRandomOrder());
                break;
            case 'pgsql':
                $this->assertEquals('RANDOM()' , $c->getAdapter()->getRandomOrder());
                break;
        }
    }
}