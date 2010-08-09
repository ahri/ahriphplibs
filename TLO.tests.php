<?php
error_reporting(E_ALL | E_STRICT);
require_once('ahriphplibs/Exception.classes.php');
require_once('TLO.classes.php');
require_once('SSql.classes.php');
require_once('simpletest/autorun.php');

class Test1 extends TLO
{
        public $foo;
        public static $static;
}

abstract class Test2 extends Test1
{
        protected $bar;
        private $moo;
}

class Test3 extends Test2
{
        public $baz;
}

class TestNamed extends Test2
{
        const TestNamed_keys = 'key1, key2';

        public $key1;
        public $key2;

        public $stuff;
}

/*
assertTrue($x)                  Fail if $x is false
assertFalse($x)                 Fail if $x is true
assertNull($x)                  Fail if $x is set
assertNotNull($x)               Fail if $x not set
assertIsA($x, $t)               Fail if $x is not the class or type $t
assertNotA($x, $t)              Fail if $x is of the class or type $t
assertEqual($x, $y)             Fail if $x == $y is false
assertNotEqual($x, $y)          Fail if $x == $y is true
assertWithinMargin($x, $y, $m)  Fail if abs($x - $y) < $m is false
assertOutsideMargin($x, $y, $m) Fail if abs($x - $y) < $m is true
assertIdentical($x, $y)         Fail if $x == $y is false or a type mismatch
assertNotIdentical($x, $y)      Fail if $x == $y is true and types match
assertReference($x, $y)         Fail unless $x and $y are the same variable
assertClone($x, $y)             Fail unless $x and $y are identical copies
assertPattern($p, $x)           Fail unless the regex $p matches $x
assertNoPattern($p, $x)         Fail if the regex $p matches $x
expectError($x)                 Swallows any upcoming matching error
assert($e)                      Fail on failed expectation object $e
*/

/*
NOT TESTED: TLO::autoPropertyAlias
NOT TESTED: TLO::parentKeyName
NOT TESTED: TLO::sqlCreate
NOT TESTED: TLO::newObject
NOT TESTED: TLO::guid
NOT TESTED: TLO::keyConst
NOT TESTED: TLO::keyAuto
NOT TESTED: TLO::keyNames
NOT TESTED: TLO->getKeys
NOT TESTED: TLO->setKeys
NOT TESTED: TLO->getVar
NOT TESTED: TLO->storeKeys
NOT TESTED: TLO->setup
NOT TESTED: TLO->write
NOT TESTED: TLO->properties
*/

class TestLoops extends UnitTestCase
{
        public function testPropertyLoop()
        {
                $properties = array();
                TLO::propertyLoop('Test1', function ($p) use (&$properties) {
                        $properties[] = $p;
                });
                $this->assertEqual($properties, array('foo'));

                $properties = array();
                TLO::propertyLoop('Test2', function ($p) use (&$properties) {
                        $properties[] = $p;
                });
                $this->assertEqual($properties, array());

                $properties = array();
                TLO::propertyLoop('Test3', function ($p) use (&$properties) {
                        $properties[] = $p;
                });
                $this->assertEqual($properties, array('baz', 'bar'));
        }

        public function testConcreteClassLoop()
        {
                $classes = array();
                TLO::concreteClassLoop('Test1', function ($c) use (&$classes) {
                        $classes[] = $c;
                });
                $this->assertEqual($classes, array('Test1'));

                $classes = array();
                TLO::concreteClassLoop('Test2', function ($c) use (&$classes) {
                        $classes[] = $c;
                });
                $this->assertEqual($classes, array());

                $classes = array();
                TLO::concreteClassLoop('Test3', function ($c) use (&$classes) {
                        $classes[] = $c;
                });
                $this->assertEqual($classes, array('Test3', 'Test1'));
        }

        public function testConcreteClassBottomUpLoop()
        {
                $classes = array();
                TLO::concreteClassBottomUpLoop('Test1', function ($c) use (&$classes) {
                        $classes[] = $c;
                });
                $this->assertEqual($classes, array('Test1'));

                $classes = array();
                TLO::concreteClassBottomUpLoop('Test2', function ($c) use (&$classes) {
                        $classes[] = $c;
                });
                $this->assertEqual($classes, array());

                $classes = array();
                TLO::concreteClassBottomUpLoop('Test3', function ($c) use (&$classes) {
                        $classes[] = $c;
                });
                $this->assertEqual($classes, array('Test1', 'Test3'));
        }
}

class TestNames extends UnitTestCase
{
        public function testTransClassTable()
        {
                $this->assertEqual(TLO::transClassTable('ForXYZInABC'), 'for_x_y_z_in_a_b_c');
        }

        public function testTransTableClass()
        {
                $this->assertEqual(TLO::transTableClass('for_x_y_z_in_a_b_c'), 'ForXYZInABC');
        }

        public function testClassValid()
        {
                $this->assertNull(TLO::classValidator('Test3'));
        }
}

class TestStructure extends UnitTestCase
{
        public function testConcreteParent()
        {
                $this->assertEqual(TLO::concreteParent('Test3'), 'Test1');
        }

        public function testValidClassName()
        {
                $this->assertTrue(TLO::validClassName('FooBar'));
                $this->assertFalse(TLO::validClassName('foobar'));
                $this->assertFalse(TLO::validClassName('foo_bar'));
                $this->assertFalse(TLO::validClassName('Foo_Bar'));
                $this->assertFalse(TLO::validClassName('fooBar'));
        }

        public function testValidPropertyName()
        {
                $this->assertTrue(TLO::validPropertyName('foo_bar'));
                $this->assertFalse(TLO::validPropertyName('Foobar'));
                $this->assertFalse(TLO::validPropertyName('Foo_Bar'));
                $this->assertFalse(TLO::validPropertyName('fooBar'));
        }
}

class TestSqlConstruction extends UnitTestCase
{
        public function testSqlRead()
        {
                $this->assertEqual(TLO::sqlRead('Test1'), 'SELECT test1.id AS test1__id, foo FROM test1');
        }

        public function testSqlReadMultipleClass()
        {
                $this->assertEqual(TLO::sqlRead('Test3'), 'SELECT test3.id AS test3__id, baz, bar, test1.id AS test1__id, foo FROM test3, test1 WHERE test3.parent__key__id = test1.id');
        }

        public function testSqlWrite()
        {
                $this->assertEqual(TLO::sqlWrite('Test3'), array('Test3' => 'UPDATE test3 SET baz = :baz, bar = :bar WHERE id = :self__key__id',
                                                                 'Test1' => 'UPDATE test1 SET foo = :foo WHERE id = :self__key__id'));
        }

        public function testSqlNew()
        {
                $this->assertEqual(''.TLO::sqlNew('Test3'), 'INSERT INTO test3 () VALUES ()');
                $this->assertEqual(''.TLO::sqlNew('Test3', array('foo')), 'INSERT INTO test3 (foo) VALUES (:foo)');
        }

        public function testSqlExtend()
        {
                $this->assertEqual(''.TLO::sqlExtend('Test1', 'Test3'), 'INSERT INTO test3 (parent__key__id) VALUES (:parent__key__id)');
                $this->assertEqual(''.TLO::sqlExtend('Test1', 'Test3', array('foo')), 'INSERT INTO test3 (foo, parent__key__id) VALUES (:foo, :parent__key__id)');
        }

        public function testKeyALias()
        {
                $this->assertEqual(TLO::keyAlias('foo'), 'self__key__foo');
        }

        public function testSqlCreate()
        {
                $this->assertEqual(TLO::sqlCreate('Test1'), "CREATE TABLE test1 (id CHAR(40), foo VARCHAR(25), PRIMARY KEY (id));\n");
                $this->assertFalse(TLO::sqlCreate('Test2'));
                $this->assertEqual(TLO::sqlCreate('Test3'), "CREATE TABLE test3 (id CHAR(40), parent__key__id CHAR(40), baz VARCHAR(25), bar VARCHAR(25), PRIMARY KEY (id));\n");
                $this->assertEqual(TLO::sqlCreate('TestNamed'), "CREATE TABLE test_named (key1 VARCHAR(25), key2 VARCHAR(25), parent__key__id CHAR(40), key1 VARCHAR(25), key2 VARCHAR(25), stuff VARCHAR(25), bar VARCHAR(25), PRIMARY KEY (key1, key2));\n");
        }

        public function testSqlCreateAll()
        {
                $this->assertEqual(TLO::sqlCreateAll(), "CREATE TABLE test1 (id CHAR(40), foo VARCHAR(25), PRIMARY KEY (id));\nCREATE TABLE test3 (id CHAR(40), parent__key__id CHAR(40), baz VARCHAR(25), bar VARCHAR(25), PRIMARY KEY (id));\nCREATE TABLE test_named (key1 VARCHAR(25), key2 VARCHAR(25), parent__key__id CHAR(40), key1 VARCHAR(25), key2 VARCHAR(25), stuff VARCHAR(25), bar VARCHAR(25), PRIMARY KEY (key1, key2));\n");
        }
}

class TestDbAccess extends UnitTestCase
{
        private $db = NULL;

        public function __construct()
        {
                parent::__construct();

                TLO::init();

                $this->guid1 = TLO::guid();
                $this->guid2 = TLO::guid();
                $this->guid3 = TLO::guid();
                $this->guid4 = TLO::guid();

                SSql::setup('sqlite::memory:');
                $this->db = SSql::get();
                $this->db->exec('CREATE TABLE test1 (id CHAR('.strlen($this->guid1).') PRIMARY KEY, foo INTEGER)');
                $this->db->exec('CREATE TABLE test3 (id CHAR('.strlen($this->guid1).') PRIMARY KEY, parent__key__id CHAR('.strlen($this->guid1).'), bar INTEGER, baz INTEGER)');
                $this->db->exec('CREATE TABLE test_named (key1 VARCHAR(10), key2 VARCHAR(10), parent__key__id CHAR('.strlen($this->guid1).'), bar INTEGER, stuff VARCHAR(25), PRIMARY KEY(key1, key2))');
                $this->db->exec("INSERT INTO test1 (id, foo) VALUES ('{$this->guid1}', 1)");
                $this->db->exec("INSERT INTO test1 (id, foo) VALUES ('{$this->guid2}', 1)");
                $this->db->exec("INSERT INTO test3 (id, parent__key__id, bar, baz) VALUES ('{$this->guid3}', '{$this->guid2}', 2, 3)");
                $this->db->exec("INSERT INTO test3 (id, parent__key__id, bar, baz) VALUES ('{$this->guid4}', '{$this->guid1}', 2, 3)");
        }

        public function testReadAllItems()
        {
                $r = TLO::execRead($this->db, 'Test3');
                $this->assertIsA($r, 'TLOResult');
                $this->assertIsA($r->fetch(), 'Test3');
                $this->assertIsA($r->fetch(), 'Test3');
                $this->assertFalse($r->fetch());
        }

        public function testReadSpecifiedItems()
        {
                $r = TLO::execRead($this->db, 'Test3', array('where' => 'parent__key__id = ?'), array($this->guid2));
                $this->assertIsA($r, 'TLOResult');
                $this->assertIsA($r->fetch(), 'Test3');
                $this->assertFalse($r->fetch());
        }

        public function testGetObject()
        {
                $this->o = TLO::getObject($this->db, 'Test3', array($this->guid4));
                $this->assertIsA($this->o, 'Test3');
        }

        public function testWriting()
        {
                $this->o->baz = 10;
                $this->assertNull($this->o->write($this->db));
                $s = $this->db->query("SELECT baz FROM test3 WHERE id = '{$this->guid4}'");
                $this->assertIsA($s,'PDOStatement');
                $r = $s->fetch(PDO::FETCH_ASSOC);
                $this->assertEqual(array_pop($r), '10');
        }

        public function testNew()
        {
                $this->assertIsA(TLO::newObject($this->db, 'Test3'), 'Test3');
        }

        public function estNamed()
        {
                # create one, update and write to it, then read it into a new var and assert the changes are there
                $this->assertIsA($named = TLO::newObject($this->db, 'TestNamed', array('one', 'two')), 'TestNamed');
                $named->key1 = 'oneone';
                $named->stuff = 'testing';
                $named->write($this->db);
                $this->assertFalse(TLO::getObject($this->db, 'TestNamed', array('one', 'two')));
                $this->assertIsA($named2 = TLO::getObject($this->db, 'TestNamed', array('oneone', 'two')), 'TestNamed');
                $this->assertEqual($named2->stuff, 'testing');
                $named->key2 = 'twotwo';
                $named->write($this->db);
                $this->assertFalse(TLO::getObject($this->db, 'TestNamed', array('one', 'two')));
                $this->assertFalse(TLO::getObject($this->db, 'TestNamed', array('oneone', 'two')));
                $this->assertIsA($named3 = TLO::getObject($this->db, 'TestNamed', array('oneone', 'twotwo')), 'TestNamed');
                $this->assertEqual($named3->stuff, 'testing');
        }
}

?>
