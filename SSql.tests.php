<?php

error_reporting(E_ALL | E_STRICT);
require_once('ahriphplibs/Exception.classes.php');
require_once('SSql.classes.php');
require_once('simpletest/autorun.php');

class TestBasics extends UnitTestCase
{
        public function testSetup()
        {
                $this->assertIsA(SSql::setup('sqlite::memory:'), 'PDO');
        }

        public function testGetInstance()
        {
                $this->assertIsA(SSql::instance(), 'PDO');
        }

        public function testSetupNamed()
        {
                $this->assertIsA(SSql::setupNamed('test', 'sqlite::memory:'), 'PDO');
        }

        public function testGetNamedInstance()
        {
                $this->assertIsA(SSql::namedInstance('test'), 'PDO');
        }

        public function testCallStatic()
        {
                SSql::setup('sqlite::memory:');
                $this->assertEqual(SSql::__callStatic('lastInsertId', array()), '0');
        }
}

?>
