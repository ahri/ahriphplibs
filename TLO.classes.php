<?php
/*******************************************************************************
 *
 *        Title:  TLO (Tiny Loadable Objects)
 *
 *  Description:  A small object-relational mapper supporting relationships and
 *                soon automatic routing too!
 *
 * Requirements:  PHP 5.3.0+
 *
 *       Author:  Adam Piper (adam@ahri.net)
 *
 *      Version:  1.00
 *
 *         Date:  2010-08-09
 *
 *      License:  AGPL (GNU AFFERO GENERAL PUBLIC LICENSE Version 3, 2007-11-19)
 *
 * Copyright (c) 2010, Adam Piper
 * All rights reserved.
 *
 *    This library is free software: you can redistribute it and/or modify
 *    it under the terms of the GNU General Public License as published by
 *    the Free Software Foundation, either version 3 of the License, or
 *    (at your option) any later version.
 *
 *    This library is distributed in the hope that it will be useful,
 *    but WITHOUT ANY WARRANTY; without even the implied warranty of
 *    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *    GNU General Public License for more details.
 *
 *    You should have received a copy of the GNU General Public License
 *    along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 ******************************************************************************/

class TloException extends SPFException {}

abstract class TLO
{
        /** Self reference **/
        const BASE = __CLASS__;

        /** Name of property for automatic id, i.e. when no custom keys are set for a class **/
        const AUTO_PROPERTY_ID = 'id';

        /** Name of special relationship for class parent **/
        const PARENT_RELATIONSHIP = 'parent';

        /** Per-run initialisations **/
        public static function init()
        {
                mt_srand(microtime(1));
        }

        ##################################
        # Loops

        /**
        Loop over properties, passing the name to a given closure
        NB. Properties inherited from immediate parent abstract classes are listed, too
        **/
        public static function propertyLoop($class, $base, $func)
        {
                $c = new ReflectionClass($class);
                if ($c->isAbstract())
                        return;

                do
                        foreach ($c->getProperties() as $p)
                                if ($p->getDeclaringClass()->getName() == $c->getName()
                                    && !$p->isPrivate()
                                    && !$p->isStatic())
                                        $func($p->getName());

                while (
                        ($c = $c->getParentClass())
                        && $c->isAbstract()
                        && $c->getName() != $base
                );
        }

        /** Loop over concrete (i.e. non abstract) classes, passing the name to a given closure **/
        public static function concreteClassLoop($class, $base, $func)
        {
                $c = new ReflectionClass($class);
                if ($c->isAbstract())
                        return;

                do {
                        if ($c->isAbstract())
                                continue;

                        $func($c->getName());

                } while (
                        ($c = $c->getParentClass())
                        && $c->getName() != $base
                );
        }

        /** Loop over (in a bottom-up fashion, i.e. extreme parent, up) concrete (i.e. non abstract) classes, passing the name to a given closure **/
        public static function concreteClassBottomUpLoop($class, $base, $func)
        {
                $c = new ReflectionClass($class);
                if ($c->isAbstract())
                        return;

                $classes = array();
                do {
                        if ($c->isAbstract())
                                continue;

                        $classes[] = $c->getName();

                } while (
                        ($c = $c->getParentClass())
                        && $c->getName() != $base
                );

                foreach (array_reverse($classes) as $c)
                        $func($c);
        }

        /** Return the next concrete parent, or NULL **/
        public static function concreteParent($class)
        {
                $parent = NULL;

                $c = new ReflectionClass($class);
                if ($c->isAbstract())
                        return;

                do {
                        if ($c->isAbstract() || ($name = $c->getName()) == $class)
                                continue;

                        $parent = $name;
                        break;

                } while (
                        ($c = $c->getParentClass())
                        && $c->getName() != self::BASE
                );

                return $parent;
        }

        ##################################
        # Names

        /** Analyse the makeup of a class, validating component class and property names **/
        public static function classValidator($class)
        {
                if (!self::validClassName($class))
                        throw new TloException('Invalid class name: "%s"', $class);

                $var_names = array();

                $base = self::BASE;
                self::propertyLoop($class, $base, function ($p) use ($base, &$var_names) {
                        if (!$base::validName($p))
                                throw new TloException('Invalid property name: "%s"', $p);

                        if (in_array($p, $var_names))
                                throw new TloException('Property name "%s" cannot be shared; overriding of public/protected class vars is not allowed', $p);

                        $var_names[] = $p;
                });
        }

        /** a-z, 0-9. Must start with a capitalised letter **/
        public static function validClassName($name)
        {
                 return !preg_match('#[^A-Za-z0-9]|^[^A-Z]#', $name);
        }

        /** a-z, 0-9 and underscore. Must start with a letter. Cannot contain two underscores in a row **/
        public static function validName($name)
        {
                return !preg_match('#[^a-z0-9_]|^[^a-z]|__#', $name);
        }

        /** Translate a class name into a table name **/
        public static function transClassTable($cname)
        {
                $tname = '';
                $c_A  = ord('A');              #65; # TODO: optimize?
                $c_Z  = ord('Z');              #90;
                $diff = ord('a') - ord('A');   #32;

                foreach (str_split($cname) as $i => $c) {
                        $ord = ord($c);

                        if ($ord >= $c_A && $ord <= $c_Z) {
                                if ($i != 0)
                                        $tname .= '_';

                                $tname .= chr($ord + $diff);

                        } else {
                                $tname .= $c;
                        }
                }

                return $tname;
        }

        /** Translate a table name into a class name **/
        public static function transTableClass($tname)
        {
                $cname = '';
                $diff = ord('a') - ord('A');

                $uc = FALSE;
                foreach (str_split($tname) as $i => $c) {
                        if ($i == 0) {
                                $uc = TRUE;
                        } else if ($c == '_') {
                                $uc = TRUE;
                                continue;
                        }

                        $ord = ord($c);

                        $cname .= $uc? chr($ord - $diff) : $c;
                        $uc = FALSE;
                }

                return $cname;
        }

        ##################################
        # Schema

        /** Return an array of reserved relationships **/
        public static function reservedRelationships()
        {
                return array('self', 'parent');
        }

        /** Return the property alias for a table with an auto generated key **/
        public static function autoPropertyAlias($table)
        {
                return sprintf('%s__%s', $table, self::AUTO_PROPERTY_ID);
        }

        /** Return the column name for a given relationship and variable type **/
        public static function developedColName($relationship, $var_type, $var_name)
        {
                return sprintf('%s__%s__%s', $relationship, $var_type, $var_name);
        }

        /** Return the alias for a key **/
        public static function keyAlias($key)
        {
                return self::developedColName('self', 'key', $key);
        }

        /** Return the parent key name **/
        public static function parentKeyName($key)
        {
                return self::developedColName('parent', 'key', $key);
        }

        ##################################
        # SQL

        /** Try to output more useful messages upon statement preparation **/
        public static function prepare(PDO $db, $query)
        {
                try {
                        $statement = $db->prepare($query);
                }
                catch (PDOException $e) {
                        if (php_sapi_name() == 'cli')
                                $msg = sprintf("Error: %s\nPreparing: \"%s\"\n", $e->getmessage(), $query);
                        else
                                $msg = sprintf("<b>Error:</b>%s<br/>\n<b>Preparing:</b> \"%s\"", $e->getmessage(), $query);

                        throw new PDOException($msg);
                }

                return $statement;
        }

        /** Try to output more useful messages upon statement execution **/
        public static function execute(PDOStatement $statement, $args = NULL)
        {
                try {
                        $statement->execute($args);
                } catch (PDOException $e) {
                        ob_start();
                        var_dump($args);
                        $dump = ob_get_contents();
                        ob_end_clean();

                        if (php_sapi_name() == 'cli')
                                $msg = sprintf("Error: %s\nExecuting: \"%s\"\nParams: %s\n", $e->getmessage(), $statement->queryString, $dump);
                        else
                                $msg = sprintf("<b>Error: </b>%s<br/>\n<b>Executing:</b> \"%s\"<br/>\n<b>Params:</b> %s", $e->getmessage(), $statement->queryString, $dump);

                        throw new PDOException($msg);
                }
        }

        /** Prepare and execute a query, returning the PDOStatement object **/
        public static function execFetchClass(PDO $db, $class, $query, $params = NULL)
        {
                $statement = TLO::prepare($db, $query);
                $statement->setFetchMode(PDO::FETCH_CLASS, $class);
                TLO::execute($statement, $params);
                return $statement;
        }

        /** Add WHERE for the keys for the given class **/
        public static function whereKeys(TLOQuery $query, $class)
        {
                foreach (TLO::keyNames($class) as $key)
                        $query->where('%s = ?', $key);
        }

        /** Generate the SQL required to create a class (including its inherited vars) in the DB **/
        public static function sqlNew($class, array $not_nulls = array())
        {
                $base = self::BASE;

                $properties = array();
                $values = array();

                $query = new TLOQuery();
                $query->{'into'}(self::transClassTable($class));
                $query->{'insert'}();
                $query->{'values'}();
                foreach ($not_nulls as $p) {
                        $query->{'insert'}($p);
                        $query->{'values'}(':%s', $p);
                }

                return $query;
        }

        /** Generate SQL required to extend a class **/
        public static function sqlExtend($base_class, $class, array $not_nulls = array())
        {
                foreach (self::keyNames($base_class) as $key)
                        $not_nulls[] = self::parentKeyName($key);

                return self::sqlNew($class, $not_nulls);
        }

        /** Generate the SQL required to read an entire class (including its inherited vars) from the DB **/
        public static function sqlRead($class)
        {
                $base = self::BASE;
                $last_c = NULL;

                $query = new TLOQuery();
                self::concreteClassLoop($class, $base, function ($c) use ($base, &$last_c, $query) {
                        $keys = $base::keyNames($c);
                        $table = $base::transClassTable($c);

                        $query->from($table);

                        # make the joins
                        if ($last_c)
                                foreach ($keys as $key)
                                        $query->where('%s.%s__key__%s = %s.%s', $base::transClassTable($last_c), $base::PARENT_RELATIONSHIP, $key, $table, $key);

                        if ($keys[0] == $base::AUTO_PROPERTY_ID)
                                $query->select('%s.%s AS %s', $table, $base::AUTO_PROPERTY_ID, $base::autoPropertyAlias($table));

                        $base::propertyLoop($c, $base, function ($p) use ($query) {
                                $query->select($p);
                        });

                        $last_c = $c;
                });

                return $query;
        }

        /** Generate the SQL required to write an entire class (including its inherited vars) to the DB **/
        public static function sqlWrite($class)
        {
                $base = self::BASE;
                $queries = array();
                self::concreteClassLoop($class, $base, function ($c) use ($base, &$queries) {
                        $query = new TLOQuery();
                        $query->update($base::transClassTable($c));
                        $assignments = array();
                        $base::propertyLoop($c, $base, function ($p) use ($base, $query) {
                                $query->set('%s = :%s', $p, $p);
                        });

                        foreach ($base::keyNames($c) as $key)
                                $query->where('%s = :%s', $key, $base::keyAlias($key));

                        $queries[$c] = $query;
                });

                return $queries;
        }

        /** Generate SQL to delete the given class **/
        public static function sqlDelete($class)
        {
                $query = new TLOQuery();
                $query->delete();
                $query->from(self::transClassTable($class));
                self::whereKeys($query, $class);
                return $query;
        }

        protected static $_tlo_classes = NULL;
        protected static $_tlo_relationship_classes = NULL;

        /** Run through all classes and cache the relevant TLO/TLORelationship classes **/
        public static function classDetect()
        {
                if (!is_null(self::$_tlo_classes))
                        return;

                self::$_tlo_classes = array();
                self::$_tlo_relationship_classes = array();
                foreach (get_declared_classes() as $class) {
                        $reflect = new ReflectionClass($class);
                        if ($reflect->isAbstract())
                                continue;

                        if (is_subclass_of($class, 'TLO'))
                                self::$_tlo_classes[] = $class;

                        if (is_subclass_of($class, 'TLORelationship')) {
                                try {
                                        $class::relationOne();
                                        $many_side = $class::relationMany();
                                } catch (TLOException $e) {
                                        throw new TLOException('Class "%s" does not have both static functions "relationMany" and "relationOne" set up', $class);
                                }
                                if (!isset(self::$_tlo_relationship_classes[$many_side]))
                                        self::$_tlo_relationship_classes[$many_side] = array();

                                self::$_tlo_relationship_classes[$many_side][] = $class;
                        }
                }
        }

        /**
        Generate some fairly generic SQL to help create the table for a class (including its inherited abstract vars)
        NB. Will not include the variables for inherited concrete classes
        **/
        public static function sqlCreate($class)
        {
                $r = new ReflectionClass($class);
                if ($r->isAbstract())
                        return '';

                $base = self::BASE;
                $keys = self::keyNames($class);
                $properties = array();
                $keyTypes = function ($class, $parent = FALSE) use ($base, &$properties) {
                        if ($base::keyAuto($class)) {
                                $keys = $base::keyNames($class);
                                $properties[] = sprintf('%s CHAR(%d)', $parent? $base::parentKeyName($keys[0]) : $keys[0], strlen($base::guid()));
                        } else {
                                foreach ($base::keyNames($class) as $key)
                                        $properties[] = $parent? $base::parentKeyName($key) : $key;
                        }
                };

                $keyTypes($class);
                if (($parent = self::concreteParent($class)))
                        $keyTypes($parent, TRUE);

                self::propertyLoop($class, $base, function ($p) use (&$properties) {
                        $properties[] = $p;
                });

                self::classDetect();
                if (isset(self::$_tlo_relationship_classes[$class])) {
                        foreach (self::$_tlo_relationship_classes[$class] as $rel) {
                                $relname = TLO::transClassTable($rel);
                                $one_side = $rel::relationOne();

                                $rel_keys = TLO::keyNames($one_side);
                                if (TLO::keyAuto($one_side))
                                        $properties[] = sprintf('%s CHAR(%d)', TLO::developedColName($relname, 'key', $rel_keys[0]), strlen($base::guid()));
                                else
                                        foreach ($rel_keys as $key)
                                                $properties[] = TLO::developedColName($relname, 'key', $key);

                                TLO::concreteClassLoop($rel, 'TLORelationship', function ($class) use ($relname, &$properties) {
                                        TLO::propertyLoop($class, 'TLORelationship', function ($p) use ($relname, &$properties) {
                                                $properties[] = TLO::developedColName($relname, 'var', $p);
                                        });
                                });
                        }
                }

                return sprintf("CREATE TABLE %s (%s, PRIMARY KEY (%s));\n",
                               self::transClassTable($class),
                               implode(', ', array_map(function ($p) {
                                   return strstr($p, ' ')? $p : $p.' VARCHAR(25)';
                               }, $properties)),
                               implode(', ', $keys));
        }

        /** Generate the SQL to create all TLO descended classes **/
        public static function sqlCreateAll()
        {
                self::classDetect();
                $sql = '';
                foreach (self::$_tlo_classes as $class)
                        $sql .= self::sqlCreate($class);

                return $sql;
        }

        ##################################
        # Factories

        /** Create a new object, generate an id if neccessary and write to db **/
        public static function newObject(PDO $db, $class, array $class_not_nulls = array())
        {
                $base = self::BASE;
                $parent = NULL;
                $parent_keys = array();
                $keys = NULL;
                self::concreteClassBottomUpLoop($class, $base, function ($c) use ($base, $db, &$parent, &$parent_keys, &$class_not_nulls, &$keys) {
                        $vars = isset($class_not_nulls[$c])? $class_not_nulls[$c]
                                                             : array();
                        $keys = array();
                        if ($base::keyAuto($c)) {
                                $guid = $base::guid();
                                $vars[$base::AUTO_PROPERTY_ID] = $guid;
                                $keys[] = $guid;
                        } else {
                                foreach ($base::keyNames($c) as $key) {
                                        $key_val = array_shift($class_not_nulls);
                                        $vars[$key] = $key_val;
                                        $keys[] = $key_val;
                                }
                        }

                        foreach ($parent_keys as $key => $val)
                                $vars[$base::parentKeyName($key)] = $val;

                        $not_nulls = array_keys($vars);

                        $query = $parent? $base::sqlExtend($parent, $c, $not_nulls)
                                        : $base::sqlNew($c, $not_nulls);

                        $s = $base::prepare($db, $query);
                        $base::execute($s, $vars);

                        $parent = $c;
                        $parent_keys = array();
                        foreach ($base::keyNames($c) as $key)
                                $parent_keys[$key] = $vars[$key];
                });

                return self::getObject($db, $class, $keys);
        }

        /** Prepare an execute a PDO query, returning the PDO statement for fetching **/
        public static function getObjects(PDO $db, $class, TLOQuery $additional = NULL, array $params = NULL)
        {
                $query = self::sqlRead($class);
                if ($additional)
                        $query->merge($additional);

                return new TLOObjectResult($db, self::execFetchClass($db, $class, $query, $params));
        }

        /** Load a single object from the database **/
        public static function getObject(PDO $db, $class, array $key_vals = array())
        {
                $keys = self::keyNames($class);

                if (($keycount = sizeof($keys)) != ($argcount = sizeof($key_vals)))
                        throw new TLOException('Key count mismatch: required %d keys, got %d', $keycount, $argcount);

                $query = new TLOQuery();
                foreach ($keys as $key_name)
                        $query->where('%s.%s = ?', self::transClassTable($class), $key_name);

                $r = self::getObjects($db, $class, $query, $key_vals);
                return $r->fetch();
        }

        ##################################
        # Keys

        /** Generate a GUID **/
        public static function guid()
        {
                return sha1(mt_rand());
        }

        /** Return constant specifier for class keys **/
        public static function keyConst($class)
        {
                return sprintf('%s::%s_keys', $class, $class);
        }

        /** Return boolean depending on whether the user has specified keys for a given class **/
        public static function keyAuto($class)
        {
                return !defined(self::keyConst($class));
        }

        /** Get the keys for a class from a constant defined as ClassName::ClassName_keys **/
        public static function keyNames($class)
        {
                if (self::keyAuto($class))
                        return array(self::AUTO_PROPERTY_ID);

                return array_unique(explode(', ', constant(self::keyConst($class))));
        }

        ##################################
        # Dynamic items

        private $_db = NULL;
        private $_keys = array();

        /** Keep a note of which database object was used to create this object**/
        public function setDb(PDO $db)
        {
                $this->_db = $db;
        }

        /** Keep track of keys by class, ensuring ability to track back to database row **/
        public function setKeys($class, array $keys)
        {
                if (isset($this->_keys[$class]))
                        throw new TloException('Keys for class "%s" are already set', $class);

                $this->_keys[$class] = $keys;
        }

        /** Returns the keys for the specified class of this object **/
        public function getKeys($class)
        {
                return $this->_keys[$class];
        }

        /** Get the Auto ID **/
        public function getId()
        {
                $class = get_class($this);
                if (!TLO::keyAuto($class))
                        throw new TLOException('Class "%s" does not have an Auto ID', $class);

                $keys = $this->getKeys($class);
                return reset($keys);
        }

        /** Get a variable, hack around protected vars, consider alternatives (but note that it's being called at object creation, i.e. a lot **/
        public function getVar($var)
        {
                return $this->$var;
        }

        /** Store the relevant keys for the object for accounting purposes, remove the auto-generated ID artefacts from the object **/
        public final function storeKeys()
        {
                $base = self::BASE;
                $o = $this;
                self::concreteClassLoop(get_class($this), $base, function ($c) use ($base, $o) {
                        $keys = array();
                        foreach($base::keyNames($c) as $key) {
                                if ($key == $base::AUTO_PROPERTY_ID) {
                                        $var = $base::autoPropertyAlias($base::transClassTable($c));
                                        $keys[$key] = $o->$var;
                                        # clean up the artefact
                                        unset($o->$var);
                                } else {
                                        $keys[$key] = $o->getVar($key);
                                }
                        }

                        $o->setKeys($c, $keys);
                });
        }

        /**
        Method to be called right after population of vars occurs
        NB. this is the TLO version of a "constructor", since the true __construct() will be called by PDO prior to populating the variables
        **/
        public function __setup()
        {
                # stub
        }

        /** Write the object to the database **/
        public function write(PDO $db = NULL)
        {
                if (is_null($db))
                        $db = $this->_db;

                foreach (self::sqlWrite(get_class($this)) as $class => $query) {
                        $vars = array();
                        foreach ($this->properties($class) as $p)
                                $vars[$p] = $this->$p;

                        foreach (self::getKeys($class) as $key => $stored_val) {
                                $vars[self::keyAlias($key)] = $stored_val;

                                # update stored keys if the user changes them
                                if ($key != self::AUTO_PROPERTY_ID && $stored_val != $this->$key)
                                        $this->_keys[$class][$key] = $this->$key;
                        }

                        $s = self::prepare($db, $query);
                        self::execute($s, $vars);
                }
        }

        /** Delete the top class for the current object from the database **/
        public function delete(PDO $db = NULL)
        {
                if (is_null($db))
                        $db = $this->_db;

                $s = self::prepare($db, self::sqlDelete(get_class($this)));
                self::execute($s, array_values($this->getKeys(get_class($this))));
        }

        /** Returns an associative array of properties and their values for the given class of this object **/
        public function properties($class)
        {
                $properties = array();
                self::propertyLoop($class, self::BASE, function ($p) use (&$properties) {
                        $properties[] = $p;
                });

                return $properties;
        }

        ##################################
        # Relationships

        /** Shortcut to get "many" side relationships associated with this object **/
        public function getRelsMany($relationship, PDO $db = NULL)
        {
                if (is_null($db))
                        $db = $this->_db;

                return TLORelationship::getMany($db, $relationship, $this);
        }

        /** Shortcut to get the "one" side relationship associated with this object **/
        public function getRelOne($relationship, PDO $db = NULL)
        {
                if (is_null($db))
                        $db = $this->_db;

                return TLORelationship::getOne($db, $relationship, $this);
        }

        /** Shortcut to add a relationship, passing in the "many" side **/
        public function newRelMany($relationship, TLO $relation, PDO $db = NULL)
        {
                if (is_null($db))
                        $db = $this->_db;

                return TLORelationship::newObject($db, $relationship, $this, $relation);
        }

        /** Shortcut to add a relationship, passing in the "one" side **/
        public function newRelOne($relationship, TLO $relation, PDO $db = NULL)
        {
                if (is_null($db))
                        $db = $this->_db;

                return TLORelationship::newObject($db, $relationshipv, $relation, $this);
        }
}

/** Represent a relationship **/
abstract class TLORelationship
{
        const TYPE_ONE = 1;
        const TYPE_MANY = 2;

        ##################################
        # "Abstract" static methods

        /** Return a string giving the class on the "one" side of the relationship **/
        public static function relationOne()
        {
                throw new TLOException('Must declare own static relationOne()');
        }

        /** Return a string giving the class on the "many" side of the relationship **/
        public static function relationMany()
        {
                throw new TLOException('Must declare own static relationMany()');
        }

        ##################################
        # SQL

        /** Generate the SQL for pulling in all the data on a relationship **/
        public static function sqlRead($relationship)
        {
                $relname = TLO::transClassTable($relationship);
                $location_class = $relationship::relationMany();
                $relation_class = $relationship::relationOne();
                $query = new TLOQuery();
                $query->from(TLO::transClassTable($location_class));

                foreach (TLO::keyNames($location_class) as $key)
                        $query->select($key);

                foreach (TLO::keyNames($relation_class) as $key) {
                        $keyname = TLO::developedColName($relname, 'key', $key);
                        $query->select($keyname);
                        $query->where('%s IS NOT NULL', $keyname);
                }

                TLO::concreteClassLoop($relationship, __CLASS__, function ($class) use ($relname, $query) {
                        TLO::propertyLoop($class, __CLASS__, function ($p) use ($relname, $query) {
                                $query->select('%s AS %s', TLO::developedColName($relname, 'var', $p), $p);
                        });
                });

                return $query;
        }

        /** Generate the SQL to create a relationship in the DB **/
        public static function sqlCreate($relationship)
        {
                $relname = TLO::transClassTable($relationship);
                $location_class = $relationship::relationMany();
                $relation_class = $relationship::relationOne();
                $location_name = TLO::transClassTable($location_class);
                $query = new TLOQuery();
                $query->update($location_name);


                foreach (TLO::keyNames($relation_class) as $key)
                        $query->set('%s = ?', TLO::developedColName($relname, 'key', $key));

                TLO::concreteClassLoop($relationship, __CLASS__, function ($class) use ($relname, $query) {
                        TLO::propertyLoop($class, __CLASS__, function ($p) use ($relname, $query) {
                                $query->set('%s = NULL', TLO::developedColName($relname, 'var', $p), $p);
                        });
                });

                TLO::whereKeys($query, $location_class);
                return $query;
        }

        /** Generate the SQL to write a relationship to the DB **/
        public static function sqlWrite($relationship)
        {
                $relname = TLO::transClassTable($relationship);
                $location_class = $relationship::relationMany();
                $relation_class = $relationship::relationOne();
                $location_name = TLO::transClassTable($location_class);
                $query = new TLOQuery();
                $query->update($location_name);

                TLO::concreteClassLoop($relationship, __CLASS__, function ($class) use ($relname, $query) {
                        TLO::propertyLoop($class, __CLASS__, function ($p) use ($relname, $query) {
                                $query->set('%s = ?', TLO::developedColName($relname, 'var', $p), $p);
                        });
                });

                TLO::whereKeys($query, $location_class);
                return $query;
        }

        /** Generate the SQL to write a relationship to the DB **/
        public static function sqlDelete($relationship)
        {
                $relname = TLO::transClassTable($relationship);
                $location_class = $relationship::relationMany();
                $relation_class = $relationship::relationOne();
                $location_name = TLO::transClassTable($location_class);
                $query = new TLOQuery();
                $query->update($location_name);


                foreach (TLO::keyNames($relation_class) as $key)
                        $query->set('%s = NULL', TLO::developedColName($relname, 'key', $key));

                TLO::concreteClassLoop($relationship, __CLASS__, function ($class) use ($relname, $query) {
                        TLO::propertyLoop($class, __CLASS__, function ($p) use ($relname, $query) {
                                $query->set('%s = NULL', TLO::developedColName($relname, 'var', $p), $p);
                        });
                });

                TLO::whereKeys($query, $location_class);
                return $query;
        }

        ##################################
        # Runtime methods

        /** Produce a TLORelationshipResult object **/
        protected static function getRel(PDO $db, $relationship, TLO $obj, $type)
        {
                $query = self::sqlRead($relationship);
                $c_one = $relationship::relationOne();
                $c_many = $relationship::relationMany();
                $relname = TLO::transClassTable($relationship);

                switch ($type) {
                case self::TYPE_ONE:
                        $c_obj = $c_many;
                        $c_rel = $c_one;

                        $rel_keys = array_map(function ($key) use ($relname) {
                                return TLO::developedColName($relname, 'key', $key);
                        }, TLO::keyNames($c_rel));

                        $params = array();
                        foreach ($obj->getKeys($c_obj) as $key => $val) {
                                $query->where('%s = ?', $key);
                                $params[] = $val;
                        }

                        break;

                case self::TYPE_MANY:
                        $c_obj = $c_one;
                        $c_rel = $c_many;

                        $rel_keys = TLO::keyNames($c_rel);

                        $params = array();
                        foreach ($obj->getKeys($c_obj) as $key => $val) {
                                $query->where('%s = ?', TLO::developedColName($relname, 'key', $key));
                                $params[] = $val;
                        }

                        break;

                default:
                        throw new TLOException('Unexpected value for type: "%s"', $type);
                }

                if (!is_a($obj, $c_obj))
                        throw new TLOException('Object of type "%s" is not a subclass of "%s"', get_class($obj), $c_obj);

                return new TLORelationshipResult($db, TLO::execFetchClass($db, $relationship, $query, $params), $c_rel, $rel_keys);
        }

        /** Wrap getRel() to get the "one" side of the relationship **/
        public static function getOne(PDO $db, $relationship, TLO $obj)
        {
                return self::getRel($db, $relationship, $obj, self::TYPE_ONE)->fetch();
        }

        /** Wrap getRel() to get the "many" side of the relationship **/
        public static function getMany(PDO $db, $relationship, TLO $obj)
        {
                return self::getRel($db, $relationship, $obj, self::TYPE_MANY);
        }

        /** Update the appropriate line in the DB to NULLs and load it, returning that object **/
        public static function newObject(PDO $db, $relationship, TLO $one, TLO $many)
        {
                $location_class = $relationship::relationMany();
                $relation_class = $relationship::relationOne();

                $query = self::sqlCreate($relationship);

                $params = array();
                foreach ($one->getKeys($relation_class) as $key => $val)
                        $params[] = $val;

                foreach ($many->getKeys($location_class) as $key => $val)
                        $params[] = $val;

                $s = TLO::prepare($db, $query);
                TLO::execute($s, $params);

                return TLORelationship::getOne($db, $relationship, $many);
        }

        ##################################
        # Dynamic items

        protected $_db = NULL;
        protected $_relation = NULL;
        protected $_keys = NULL;

        /** Keep a note of which database object was used to create this object**/
        public function setDb(PDO $db)
        {
                $this->_db = $db;
        }

        /** Store the relevant keys for the object for accounting purposes, remove the auto-generated ID artefacts from the object **/
        public final function storeKeys()
        {
                        # set the keys
                        $keys = array();
                        $thisclass = get_class($this);
                        foreach (TLO::keyNames($thisclass::relationMany()) as $key)
                                $keys[$key] = $this->$key;

                        $this->setKeys($keys);
        }

        /** Load and store the relation object **/
        public final function storeRelation($class, $key_names)
        {
                $rel_keys = array();
                foreach ($key_names as $key)
                        $rel_keys[] = $this->$key;

                $this->setRelation(TLO::getObject($this->_db, $class, $rel_keys));
        }

        /**
        Method to be called right after population of vars occurs
        NB. this is the TLO version of a "constructor", since the true __construct() will be called by PDO prior to populating the variables
        **/
        public function __setup()
        {
                # stub
        }

        /** Set the keys (once) with which we can track the relationship (i.e. the keys of the "many" side row) **/
        public function setKeys(array $keys)
        {
                if (!is_null($this->_keys))
                        throw new TloException('Keys are already set');

                $this->_keys = $keys;
        }

        /** Get the keys to the relationship row in the "many" side table **/
        public function getKeys()
        {
                return $this->_keys;
        }

        /** Set the relation object (once) **/
        public function setRelation(TLO $relation)
        {
                if (!is_null($this->_relation))
                        throw new TLOException('Relation already set');

                $this->_relation = $relation;
        }

        /** Get the relation object for this relationship **/
        public function getRelation()
        {
                return $this->_relation;
        }

        /** Write the relationship to the DB **/
        public function write(PDO $db = NULL)
        {
                if (is_null($db))
                        $db = $this->_db;

                $relationship = get_class($this);
                $relname = TLO::transClassTable($relationship);
                $location_class = $relationship::relationMany();
                $relation_class = $relationship::relationOne();

                $query = self::sqlWrite($relationship);

                $params = array();
                $obj = $this;
                TLO::concreteClassLoop($relationship, __CLASS__, function ($class) use ($relname, &$params, $obj) {
                        TLO::propertyLoop($class, __CLASS__, function ($p) use ($relname, &$params, $obj) {
                                $params[] = $obj->$p;
                        });
                });

                foreach ($this->getKeys() as $key => $val)
                        $params[] = $val;

                $s = TLO::prepare($db, $query);
                TLO::execute($s, $params);
        }

        /** Delete the relationship from the DB **/
        public function delete(PDO $db = NULL)
        {
                if (is_null($db))
                        $db = $this->_db;

                $relationship = get_class($this);
                $query = self::sqlDelete($relationship);
                $s = TLO::prepare($db, $query);
                TLO::execute($s, array_values($this->getKeys()));
        }
}

/** Fetch an object from the DB (via PDO) and execute ->__setup() on it before returning it **/
class TLOObjectResult implements Iterator
{
        public $_db = NULL;
        public $_statement = NULL;

        /** Wrap a PDOStatement **/
        public function __construct(PDO $db, PDOStatement $statement)
        {
                $this->_db = $db;
                $this->_statement = $statement;
        }

        /** Fetch an object from the DB and execute ->__setup() **/
        public function fetch()
        {
                if ($obj = $this->_statement->fetch()) {
                        $obj->setDb($this->_db);
                        $obj->storeKeys();
                        $obj->__setup();
                }

                return $obj;
        }

        ##################################
        # Iterator functions

        private $key = 0;
        private $fetched = NULL;

        public function current()
        {
                return $this->fetched;
        }

        public function next()
        {
                $this->fetched = $this->fetch();
                $this->key++;
                return $this->fetched;
        }

        public function key()
        {
                return $this->key;
        }

        public function valid()
        {
                return $this->fetched;
        }

        public function rewind()
        {
                $this->fetched = $this->fetch();
                $this->key = 0;
        }
}

/** Fetch a relationship's variables and the relation object from the DB (via PDO) **/
class TLORelationshipResult implements Iterator
{
        public $_db = NULL;
        public $_statement = NULL;
        public $_rel_class = NULL;
        public $_rel_key_names = NULL;

        /** Wrap a PDOStatement and give instructions on what object to load based on which keys **/
        public function __construct(PDO $db, PDOStatement $statement, $rel_class, $rel_key_names)
        {
                $this->_db = $db;
                $this->_statement = $statement;
                $this->_rel_class = $rel_class;
                $this->_rel_key_names = $rel_key_names;
        }

        /** Fetch a TLORelationship object from the database and add a TLO object to it **/
        public function fetch()
        {
                if ($obj = $this->_statement->fetch()) {
                        $obj->setDb($this->_db);
                        $obj->storeKeys();
                        $obj->storeRelation($this->_rel_class, $this->_rel_key_names);

                        # remove the keys
                        $objclass = get_class($obj);
                        foreach (array_merge(TLO::keyNames($objclass::relationMany()), $this->_rel_key_names) as $key)
                                unset($this->$key);

                        $obj->__setup();
                }

                return $obj;
        }

        ##################################
        # Iterator functions

        private $key = 0;
        private $fetched = NULL;

        public function current()
        {
                return $this->fetched;
        }

        public function next()
        {
                $this->fetched = $this->fetch();
                $this->key++;
                return $this->fetched;
        }

        public function key()
        {
                return $this->key;
        }

        public function valid()
        {
                return $this->fetched;
        }

        public function rewind()
        {
                $this->fetched = $this->fetch();
                $this->key = 0;
        }
}

/**
Allow building up of SQL queries:
$q = new TLOQuery();
$q->select('foo');
$q->select('bar');
$q->select('baz');
$q->from('table_a');
$q->from('table_b');
$q->from('table_c');
$q->where('x');
$q->where('y');
$q->where('z');
echo $q; # -> "SELECT foo, bar, baz FROM table_a, table_b, table_c WHERE x AND y AND z"
**/
class TLOQuery
{
        /** Note that queries are generated from the content in $items below in the _order of the keys_ of $joins **/

        protected $joins = array(
                'SELECT'      => ', ',
                'INSERT'      => ', ',
                'INTO'        => ', ',
                'VALUES'      => ', ',
                'UPDATE'      => ', ',
                'DELETE'      => ', ',
                'FROM'        => ', ',
                'SET'         => ', ',
                'WHERE'       => ' AND ',
                'ORDER BY'    => ', ',
                'EXTRA'       => ' '
        );

        protected $items = array();

        /** Add part of a query **/
        public function add($item, $value = NULL)
        {
                $item = strtoupper($item);
                if (!isset($this->items[$item]))
                        $this->items[$item] = array();

                if (!empty($value))
                        $this->items[$item][] = $value;
        }

        /** Neatly wrap ->add() **/
        public function __call($method, $args)
        {
                $format = array_shift($args);
                $this->add($method, vsprintf($format, $args));
        }

        /** Build the query in the correct order **/
        public function __toString()
        {
                $sql = '';
                foreach (array_keys($this->joins) as $item) {
                        if (!isset($this->items[$item]))
                                continue;

                        if (!empty($sql))
                                $sql .= ' ';

                        switch ($item) {
                        case 'INSERT':
                                if (!isset($this->items['INTO']))
                                        $this->items['INTO'] = 'missing_into';
                                $sql .= sprintf('%s INTO %s (%s)', $item, implode($this->joins['INTO'], $this->items['INTO']), implode($this->joins[$item], $this->items[$item]));
                                unset($this->items['INTO']);
                                break;
                        case 'VALUES':
                                $sql .= sprintf('%s (%s)', $item, implode($this->joins[$item], $this->items[$item]));
                                break;
                        case 'DELETE':
                                $sql .= sprintf('%s', $item);
                                break;
                        default:
                                $sql .= sprintf('%s %s', $item, implode($this->joins[$item], $this->items[$item]));
                        }
                }

                return $sql;
        }

        /** Merge in the contents of another TLOQuery **/
        public function merge(TLOQuery $other)
        {
                $this->items = array_merge_recursive($this->items, $other->items);
        }
}

?>
