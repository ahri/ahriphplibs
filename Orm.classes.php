<?php
/*******************************************************************************
 *
 *        Title:  Object Relational Model
 *
 *  Description:  Makes mapping of objects to ER designed database quite simple.
 *
 *                The idea is to bundle as much complexity as possible away from
 *                the end coder and into this class, then the second layer
 *                should deal with all interfacing and database access, finally
 *                the end coder ought to have an easy time of it.
 *
 *                Ideally the database should also be set up to use InnoDB or
 *                similar foreign relations; this will make it _even easier_
 *                (who'd've thought?!) keep your data integrity high.
 *
 *                Note that data integrity can be managed in SQLite with
 *                triggers, rather than (unsupported) foreign keys
 *
 * Requirements:  PHP 5.2.0+ (because reflection prior to 5.2.0 is broken)
 *                SSql 1.0
 *
 *       Author:  Adam Piper (adamp@ahri.net)
 *
 *      Version:  0.9.8
 *
 *         Date:  2007-11-08
 *
 *      License:  BSD (3 clause, 1999-07-22)
 *
 * Copyright (c) 2007, Adam Piper
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of the <organization> nor the
 *       names of its contributors may be used to endorse or promote products
 *       derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY Adam Piper ``AS IS'' AND ANY
 * EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL Adam Piper BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 ******************************************************************************/

  /*
    @Title:         

    @License:       Currently unlicensed, work in progress
                    Copyright 2006-2007 Adam Piper, adamp@ahri.net

    @Version:       0.9.8

    @Altered:       2007-11-08
  */

  /*
  TODO:

    Count on mtos - ${rname}_count field?

    Do constraints actually work properly on mto/otm?

    Notes; update
    -
  Notes:
    To use, just extend Orm. To register relations override the constructor, eg.
      class Example extends Orm {
        public $var;
        private $hidden;

        public function __construct($id = null) {
          parent::__construct($id);
          $this->registerRelations('otm', __CLASS__, array('SomeOtmClass'));
          $this->registerRelations('mto', __CLASS__, array('SomeMtoClass', 'OtherMtoClass'));
          $this->registerRelations('mtm', __CLASS__, array('SomeMtmClass'));
        }
      }

    Please note that in order to relate one object to another in this manner they must both be subclasses of the Orm superclass

    In order to have member variables that are not synced with the database just make them private -- ALL others are synced to the respective column names

    Note on naming of tables for classes:
      FooBar objects are stored in table foo_bars
      References to object FooBar are kept in a column foo_bar_id
      MTM relations between FooBar and BarFoo are kept in a table r__bar_foos__foo_bars, which would have 3 columns; bar_foo_id, foo_bar_id and count

    Note about the location of id reference in relations;
      otm == id in other table, mto == id in this table, mtm == seperate table with both ids (r_arel_brel -- table MUST be named in correct alphabetical order)

    Note on database design;
      do ER diagram
      if you have 1 to 1 relations then make sure the relation is in the less important table (then you can specify recursive delete on the forign key)
      each non-private member property will be synced, so the neccessary columns must be available
      each MTO relation (eg. FooBar) requires a column in which to store the foreign ID (eg. foo_bar_id refering to foo_bars.id)

    Example usage of end classes;
      $e = new Example();     // creates new object, no db interaction
      $e->var = 'foo';
      $e->save();             // syncs to db
      $e->var = 'bar';
      $e->load();             // syncs from db
      echo $e->var;           // outputs "foo", because the object was re-loaded from the database
      $e->var = 'bar';
      $id = $e->getId();

      $e = new Example($id);  // creates a new Example object and autoloads the data from the database
      echo $e->var;           // outputs "foo", because the object was autoloaded from the database

    Bonus Note:
      For any 'otm' relations, if the related class has a property 'name' then the collection will be ordered by that name alphabetically
        in any other case the colleciton is ordered by insert order
      Putting 'ns_' before any member variable makes it non-synchronous, ie. it doesn't get stored in the database even if it's public/protected
  */

  abstract class Orm {
    private $id = null;
    private $created = null;
    private $altered = null;

    private $ssql_name;

    private $relations;

    public function __construct($id = null, $ssql_name = null) {
      $this->created = self::dateTimeString();

      $this->ssql_name = $ssql_name;
      if($id) {
        $this->id = SSql::escape($id);
        $this->load();
      }
    }

    public final function getId() {
      return $this->id;
    }

    public final function getCreated() {
      return $this->created;
    }

    public final function getAltered() {
      return $this->altered;
    }

    public static final function dateTimeString($timestamp = null) {
      if($timestamp && !is_long($timestamp)) throw new OrmInputException('Pass only types null or long');
      $format = 'Y-m-d H:i:s';
      return $timestamp? date($format, $timestamp) : date($format);
    }

    public static final function dateString($timestamp = null) {
      if($timestamp && !is_long($timestamp)) throw new OrmInputException('Pass only types null or long');
      $format = 'Y-m-d';
      return $timestamp? date($format, $timestamp) : date($format);
    }

    public static final function timeString($timestamp = null) {
      if($timestamp && !is_long($timestamp)) throw new OrmInputException('Pass only types null or long');
      $format = 'H:i:s';
      return $timestamp? date($format, $timestamp) : date($format);
    }

    public static final function timestamp($date_or_datetime) {
      if(!is_string($date_or_datetime)) throw new OrmInputException('Pass only type string');
      if(preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $date_or_datetime, $m)) {
        return mktime(0, 0, 0, $m[2], $m[3], $m[1]);
      }
      elseif(preg_match('#^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$#', $date_or_datetime, $m)) {
        return mktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]);
      }
      else {
        throw new OrmInputException('Must pass either a date (YYYY-MM-DD) or datetime (YYYY-MM-DD HH:MM:SS)');
      }
    }

    /***************************************************************************************************************
     *  Relation functions for use by Orm subclasses
     */

    private final function getRelGroupByRName($pclass, $rname) {
      if(!isset($this->relations[$pclass]))                     throw new OrmInputException('No relations for '.$pclass.' are registered');
      if(!isset($this->relations[$pclass]['by_rname'][$rname])) throw new OrmInputException('No relations named '.$rname.' for '.$pclass.' are registered');
      return $this->relations[$pclass]['by_rname'][$rname];
    }

    private final function getRelGroupByType($pclass, $type) {
      if(!in_array($type, array('otm', 'mto', 'mtm'))) throw new OrmInputException('Allowed relation types are: otm, mto, mtm');
      return isset($this->relations[$pclass])? $this->relations[$pclass]['by_type'][$type] : array();
    }

    protected final function registerRelation($type, $pname, $rname, $pclass = null, $rclass = null, $min = 0, $max = null, $meta = null) {
      if(!$pclass) $pclass = $pname;
      if(!$rclass) $rclass = $rname;

      # check for duplicate names
      if(isset($this->relations[$pclass]['by_rname'][$rname])) throw new OrmInputException('Duplicate relation names for one class are not allowed; '.$rname.' already exists');

      $r = new OrmRelationGroup($type, $pname, $rname, $pclass, $rclass, $min, $max, $meta);

      # now add to 
      # $relations[pclass][by_rname][rname][objs] and
      # $relations[pclass][by_type][type][objs]

      if(!isset($this->relations[$pclass])) $this->relations[$pclass] = array(
        'by_rname' => array(),  # these are named
        'by_type' => array(
          'otm' => array(),     #\
          'mto' => array(),     # these are pools
          'mtm' => array()      #/
        )
      );

      $this->relations[$pclass]['by_type'][$type][] = $this->relations[$pclass]['by_rname'][$rname] = $r;
    }

    protected final function addRelation($pclass, $rname, Orm $o, $count = 1, $meta = null) {
      $rg = $this->getRelGroupByRName($pclass, $rname);
      switch($rg->getType()) {
        case 'mto':
          # remove whatever's there already
          $all = $rg->getRelsAll(true)->getArrays();
          foreach($all->keys as $i => $r) {
            # remove all objects that have a different id (or if null are not the same obj)
            if(($r->id === null && $r !== $o) || ($r->id != $o->id)) $rg->remove($r, true, $all->values[$i]);
          }
        case 'otm':
        case 'mtm':
          # add
          $c = $rg->add($o, $count, $meta);
      }
      if($rg->getMax() && $c > $rg->getMax()) throw new OrmConstraintException(sprintf('Must supply a maximum of %d objects for relation "%s"', $rg->getMax(), $rg->getRName()));
      return $c;
    }

    protected final function removeRelation($pclass, $rname, Orm $o, $count = 1) {
      $rg = $this->getRelGroupByRName($pclass, $rname);
      # as a speed hack could remove this next line and alter rSchemaRemove so that it adds $o to deleted whether or not $o exists in loaded or added
      $this->loadRelations($pclass, $rname);
      return $rg->remove($o, false, $count);
    }

    protected final function getRelationCount($pclass, $rname, Orm $o) {
      $rg = $this->getRelGroupByRName($pclass, $rname);
      return $rg->getRelsAll(true)->get($o);
    }

    protected final function getRelations($pclass, $rname, $with_count = false) {
      $rg = $this->getRelGroupByRName($pclass, $rname);
      if(!$rg->isLoaded()) {
        $this->loadRelations($pclass, $rname);
      }
      switch($rg->getType()) {
        case 'mto':
          $rs = $rg->getRelsAll(true);
          return $rs->size() > 0? $rs->firstKey() : null;
        case 'otm':
        case 'mtm':
          return $rg->getRelsAll($with_count);
      }
    }

    private final function loadRelations($pclass, $rname) {
      if($this->id == null) return;

      $r = $this->getRelGroupByRName($pclass, $rname);
      if($r->isLoaded()) return;

      $pclass_sql = self::phpToSqlName($pclass);
      $pname_sql = self::phpToSqlName($r->getPName());
      $rname_sql = self::phpToSqlName($rname);
      $mtm_table_name = $this->mtmRelationsTableName($pclass, $r->getRClass(), $r->getPName(), $rname);
      switch($r->getType()) {
        case 'mto':
          $sql = sprintf("SELECT `%s_id` AS `id` FROM `%ss` WHERE `id`='%s'", $rname_sql, $pclass_sql, $this->id);
          break;
        case 'mtm':
          $select = array('count');
          $select = array_merge(array_map(array('SSql', 'escape'), $r->getMeta()), $select);
          # mod loadAdd() to be like Add and accept $meta
          $sql = sprintf("SELECT %s_id AS id, %s FROM `%s` WHERE `%s_id`='%s'", $rname_sql, implode(', ', $select), $mtm_table_name, $pname_sql, $this->id);
          break;
        case 'otm':
          $sql = sprintf("SELECT `id` FROM `%ss` WHERE `%s_id`='%s'", $rname_sql, $pclass_sql, $this->id);
          break;
        default:
          throw new OrmInputException("Unknown relationship type `$type'");
      }
      if(strlen($sql) > 0 && sizeof($rows = SSql::getResults($sql, $this->ssql_name)) > 0) {
        foreach($rows as $row) {
          if($row->id == null) continue;
          $rclass = $r->getRClass();

          $meta = array();
          foreach($r->getMeta() as $mk) $meta[$mk] = $row->$mk;

          $r->loadAdd(new $rclass($row->id), isset($row->count)? (int)$row->count : 1, $meta);
        }
      }
      $r->setLoaded();
    }

    private final function saveExternalRelations($class) {
      $rgs = array_merge($this->getRelGroupByType($class, 'otm'), $this->getRelGroupByType($class, 'mtm'));
      foreach($rgs as $rg) {
        if($rg->getCount() < $rg->getMin()) throw new OrmConstraintException(sprintf('Must supply a minimum of %d objects for relation %s', $rg->getMin(), $rg->getRName()));

        $pname_sql = self::phpToSqlName($rg->getPName());
        $rname_sql = self::phpToSqlName($rg->getRName());
        $rclass_sql = self::phpToSqlName($rg->getRClass());
        $mtm_table_name = $this->mtmRelationsTableName($rg->getPClass(), $rg->getRClass(), $rg->getPName(), $rg->getRName());

        switch($rg->getType()) {
          case 'otm':
            # TODO: add counts
            foreach($rg->getRelsDeleted() as $rel) {
              if($rel->id == null) continue;
              SSql::query(sprintf("UPDATE `%ss` SET `%s_id`=NULL WHERE `id`='%s'", $rclass_sql, $pname_sql, $rel->id), $this->ssql_name);
            }
            foreach($rg->getRelsAdded() as $rel) {
              # add $this as a relation and save it
              $rel->addRelation($rg->getRClass(), $rg->getPName(), $this);
              $rel->save();
            }
            break;
          case 'mtm':

#############################
#echo print_r($s[1]->getArrays()->values, true).' : '.print_r($s[2]->getArrays()->values, true).' : '.print_r($s[3]->getArrays()->values, true);
#############################

            # - LOADED, ADDED, DELETED  - some have been added and deleted, so count up :)                            -- update
            # - LOADED, ADDED           - extras have been added, update the db                                       -- update
            # - LOADED, DELETED         - some loaded ones have been deleted, update the db                           -- update
            # - ADDED, DELETED          - loaded ones have been deleted, but a new one added, so just update the db   -- update
            # - LOADED                  - ignore                                                                      -- -
            # - ADDED                   - insert into db                                                              -- insert
            # - DELETED                 - delete from db                                                              -- delete
            #
            # strategy;
            # - iterate over ALL (LOADED & ADDED & DELETED)
            #   - establish above 'update'/'ignore'/'insert' situation
            #   - update/insert as neccessary
            # - iterate over DELETE
            #   - look for 'delete' situation and apply

            $loaded = $rg->getRelsLoaded(true);
            $added = $rg->getRelsAdded(true);
            $deleted = $rg->getRelsDeleted(true);

            $all_a = $rg->getRelsAll(true, true)->getArrays(); # now we have all objects connected to pclass and rclass via an mtm relationship

            foreach($all_a->keys as $i => $rel) {
              if($rel->getId() === null) $rel->save();

              # get counts, since PHP works like C a 0 == false in the tests below
              $l = $loaded->get($rel);
              $a = $added->get($rel);
              $d = $deleted->get($rel);

              if (
                  ( $l &&  $a &&  $d) ||
                  ( $l &&  $a && !$d) ||
                  ( $l && !$a &&  $d) ||
                  (!$l &&  $a &&  $d) ||
                  (!$l &&  $a && !$d)
                 ) {
                # insert or update
                $count = $l + $a;
                $insert = array($pname_sql.'_id' => $this->id, $rname_sql.'_id' => $rel->id, 'count' => $count);
                $insert = array_merge(array_map(array('SSql', 'escape'), $all_a->meta[$i]), $insert);
                SSql::query(sprintf(
                  "INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE `count`='%d'",
                  $mtm_table_name, implode(', ', array_keys($insert)), "'".implode("', '", array_values($insert))."'", $count
                ), $this->ssql_name);
                /*
                SSql::query(sprintf(
                  "INSERT INTO `%s` (`%s_id`, `%s_id`, `count`) VALUES ('%s', '%s', '%d') ON DUPLICATE KEY UPDATE `count`='%d'",
                  $mtm_table_name, $pname_sql, $rname_sql, $this->id, $rel->id, $count, $count
                ), $this->ssql_name);
                */
              }
              elseif( $l && !$a && !$d) {
                # ignore
                continue;
              }
              elseif(!$l && !$a &&  $d) {
                # delete
                SSql::query(sprintf(
                  "DELETE FROM `%s` WHERE `%s_id`='%s' AND `%s_id`='%s'",
                  $mtm_table_name, $pname_sql, $this->id, $rname_sql, $rel->id
                ), $this->ssql_name);
              }
              else {
                throw new Exception('An unknown case occurred, this is a bad thing');
              }
            }
        }
        $rg->reflectSave();
      }
    }

    private final function mtmRelationsTableName($pclass, $rclass, $pname, $rname) {
      # to get the relations table (r__rel_class_1__rel_class_2__rel_name_1__rel_name_2) I'm sorting the 2 class names to ensure I'm always refering to the same table no matter which class calls this
      $pclass_sql = self::phpToSqlName($pclass).'s';
      $rclass_sql = self::phpToSqlName($rclass).'s';
      $pname_sql = self::phpToSqlName($pname);
      $rname_sql = self::phpToSqlName($rname);

      if($pclass_sql == $rclass_sql) {
        # if the classes are the same then the order is dictated by names alone
        $temp = array($pname_sql, $rname_sql);
        sort($temp);
        if($temp[0] == $pname_sql) {
          array_unshift($temp, $pclass_sql, $rclass_sql);
        }
        else {
          array_unshift($temp, $rclass_sql, $pclass_sql);
        }
      }
      else {
        $temp = array($pclass_sql, $rclass_sql);
        sort($temp);
        # after sorting the relation names need to go the right way around to stop me getting all confused
        if($temp[0] == $pclass_sql) {
          array_push($temp, $pname_sql, $rname_sql);
        }
        else {
          array_push($temp, $rname_sql, $pname_sql);
        }
      }
      return 'r__'.implode('__', $temp);
    }


    /***************************************************************************************************************
     *  General ORM functionality
     */

    public final function syncPropertiesArray($class, $skip_id = false, $skip_created = false, $skip_altered = false) {
      $array = array();
      $r = new ReflectionClass($class);
      if(!$skip_id) $array['id'] = $this->id;
      if(!$skip_created) $array['created'] = $this->created;
      if(!$skip_altered) $array['altered'] = $this->altered;

      foreach($r->getProperties() as $p) {
        $var_name = $p->name;
        if($p->getDeclaringClass()->getName() == $class && !$p->isStatic() && !$p->isPrivate() && substr($var_name, 0, 3) != 'ns_') $array[$var_name] = $this->$var_name;
      }
      return $array;
      /*
        get_class_vars() will only give us the public vars for the class
        reflection will give us all the vars for the inherited classes too
        property_exists() will only give us confirmation on public properties
        in summary: we can't fix this until php 5.2
      */
    }

    public static final function phpToSqlName($class_name) {
      if(!is_string($class_name)) throw new OrmInputException('Must pass a string');
      if(preg_match('#[^a-zA-Z0-9]#', $class_name)) throw new OrmInputException('Cannot use class names that are not alphanumeric');
      $sql_name = '';
      for($i = 0; $i < strlen($class_name); $i++) {
        $c = substr($class_name, $i, 1);
        if(preg_match('#[A-Z]#', $c)) { # uppercase => new word
          if($i != 0) $sql_name .= '_'; # don't want a leading underscore
          $sql_name .= strtolower($c);
        }
        else {  # lowercase
          $sql_name .= $c;
        }
      }
      return $sql_name; # 'BaseReport' -> 'base_report'
    }

    public static final function sqlToPhpName($sql_name) {
      if(!is_string($sql_name)) throw new OrmInputException('Must pass a string');
      if(preg_match('#[^a-z_]#', $sql_name)) throw new OrmInputException('Cannot use sql names that are not lowercase alphanumeric or with underscores');
      $class_name = '';
      $upper = false;
      for($i = 0; $i < strlen($sql_name); $i++) {
        $c = substr($sql_name, $i, 1);
        if($i == 0)       $upper = true;
        if($upper) {
          $class_name .= strtoupper($c);
          $upper = false;
        }
        elseif($c == '_') $upper = true;
        else              $class_name .= $c;
      }
      return $class_name; # 'base_report' -> 'BaseReport'
    }

    private final function saveProperties($class) {
      $this->altered = self::dateTimeString();

      $mtos = array();
      foreach($this->getRelGroupByType($class, 'mto') as $rg) {
        if($rg->getCount() < $rg->getMin()) throw new OrmConstraintException(sprintf('Must supply a minimum of %d objects for relation "%s"', $rg->getMin(), $rg->getRName()));

        # TODO: support count on mtos - ${rname}_count field?
        # if there are no rels then the id is null and count is 0
        # else the id = $r->id and the count = the count

        $osa = $rg->getRelsAdded();
        $osd = $rg->getRelsDeleted();
        if(sizeof($osa) > 0) {
          $o = reset($osa);

          # ->save() is called so that $o will have an id we can refer to
          # This could cause infinite looping if mto goes both ways;
          # - fix your data architecture (as it's probably broken, especially if you're using a FOREIGN KEY aware DB) or...
          # - lose one of the mto awarenesses in the classes
          # The 2nd option can be achieved by not registering the mto, and by ensuring the field can be NULL in the database

          if($o->id === null) $o->save();
          $mtos[self::phpToSqlName($rg->getRName()).'_id'] = $o->id;
        }
        elseif(sizeof($osd) > 0) {
          $mtos[self::phpToSqlName($rg->getRName()).'_id'] = null;
        }
        $rg->reflectSave();
      }

      # note that these 2 cases are (respectively) when the class extends Orm, when the class extends a subclass of Orm
      if(get_parent_class($class) == __CLASS__) {
        $properties = array_map(array('SSql', 'escape'), array_merge($this->syncPropertiesArray($class, true), $mtos));
        # note - can't merge this if/else into one because the INSERT can't have the id, whereas the UPDATE requires it
        if($this->id == null) {
          SSql::query(str_replace('(``)', '()', "INSERT INTO `".self::phpToSqlName($class)."s`
            (`".implode('`, `', array_keys($properties))."`)
            VALUES (".implode(', ', array_map(array($this, 'sqlOrNull'), $properties)).")")
          );

          $this->id = SSql::getInsertId($this->ssql_name);
        }
        else/*if(sizeof($properties) > 0)*/ {
          SSql::query("UPDATE `".self::phpToSqlName($class)."s` SET ".implode(', ', array_map(array($this, 'sqlPairOrNull'), array_keys($properties = array_diff_assoc($properties, array('created', $properties['created']))), array_values($properties)))." WHERE `id`='".$this->id."'", $this->ssql_name);
        }
      }
      else {
        $properties = array_map(array('SSql', 'escape'), array_merge($this->syncPropertiesArray($class, false, false, true), $mtos));
        SSql::query("INSERT INTO `".self::phpToSqlName($class)."s`
          (`".implode('`, `', array_keys($properties))."`)
          VALUES (".implode(', ', array_map(array($this, 'sqlOrNull'), $properties)).")
          ON DUPLICATE KEY UPDATE ".implode(', ', array_map(array($this, 'sqlPairOrNull'), array_keys($properties), array_values($properties))),
        $this->ssql_name);
      }
    }

    private final function sqlOrNull($in) {
      return $in == null? "NULL" : "'$in'";
    }

    private final function sqlPairOrNull($key, $val) {
      return $val == null? "`$key`=NULL" : "`$key`='$val'";
    }

    private final function notCreatedDateTime($key) {
      return $key != 'created';
    }

    private final function loadProperties($class) {
      $extension = get_parent_class($class) != __CLASS__;
      $properties = $this->syncPropertiesArray($class, $extension, false, $extension);

      if((!$data = SSql::getRow("SELECT `".implode('`, `', array_keys($properties))."` FROM `".self::phpToSqlName($class)."s`
        WHERE `id`='".$this->id."'", $this->ssql_name))/* && !$extension*/) throw new OrmInputException("$class not found with id {$this->id} from table ".self::phpToSqlName($class)."s");

      $this->loadPropertiesFromArray($class, get_object_vars($data), false, false, false);

/*
      foreach(array_keys($properties) as $var_name) {
        $this->$var_name = $data->$var_name;
      }
*/
    }

    private final function deleteUnique($class) {
      SSql::query(sprintf("DELETE FROM `%ss` WHERE `id`='%d' LIMIT 1", self::phpToSqlName($class), $this->id), $this->ssql_name);
    }

    private final function heirarchy() {
      $class = get_class($this);
      $array = array($class);
      while($class = get_parent_class($class)) {
        if($class != __CLASS__) {
          $array[] = $class;
        }
      }
      return array_reverse($array);
    }
    
    public function save() {
      foreach($this->heirarchy() as $class) {
        $this->saveProperties($class);
        $this->saveExternalRelations($class);
      }
    }

    public function load() {
      foreach($this->heirarchy() as $class) {
        $this->loadProperties($class);
      }
    }

    public function delete() {
      foreach($this->heirarchy() as $class) {
        $this->deleteUnique($class);
      }
      unset($this);
    }

    protected function loadPropertiesFromArray($class, $a, $skip_id = true, $skip_created = true, $skip_altered = true) {
      $properties = $this->syncPropertiesArray($class, $skip_id, $skip_created, $skip_altered);
      foreach(array_keys($properties) as $var_name) {
        if(isset($a[$var_name])) {
          $this->$var_name = $a[$var_name];
        }
      }
    }

    /* Removed because it's not fair to someone writing a class; it's essentially a backdoor past their controls on protected vars
    public function loadFromPost() {
      foreach($this->heirarchy() as $class) {
        $this->loadPropertiesFromArray($class, $_POST);
      }
    }
    */

    public function extend(Orm $o) {
      $class = get_class($o);
      if(!($this instanceof $class)) throw new OrmInputException('Cannot extend an instance of that class: '.$class);
      foreach(get_object_vars($o) as $k => $v) $this->$k = $v;
      $this->created = self::dateTimeString();
    }

    /***************************************************************************************************************
     *  Helper functions
     */

    protected function getBool(&$var) {
      return $var != '0';
    }
    
    protected function setBool(&$var, $val) {
       $var = $val? '1' : '0';
    }
  }

  # since PHP doesn't support keys that aren't integers or strings, this won't work
  class OrmHash /* implements Iterator */ {
    private $k = array();
    private $v = array();
    private $m = array();

/*
    public function rewind() {
      reset($this->k);
      reset($this->v);
    }

    public function current() {
      return current($this->v);
    }

    public function key() {
      #return current($this->k);
      return key($this->v);
    }

    public function next() {
      next($this->k);
      return next($this->v);
    }

    public function valid() {
      return $this->current() !== false;
    }
*/

    private function index(Orm $o) {
      foreach($this->k as $i => $k) {
        if(($k->getId() == null && $k === $o) || ($k->getId() != null && $k->getId() == $o->getId())) {
          return $i;
        }
      }
      return null;
    }

    public function set(Orm $k, $v, $meta = null) {
      if(!is_int($v)) throw new OrmInputException('Value must be an integer; dump: '.var_dump($v, true));
      if(($i = $this->index($k)) === null) {
        $this->k[] = $k;
        $this->v[] = $v;
        $this->m[] = is_array($meta)? $meta : array();
      }
      else {
        $this->v[$i] = $v;
        if(is_array($meta)) $this->m[$i] = array_merge($this->m[$i], $meta);
      }
    }

    public function remove(Orm $k) {
      if(($i = $this->index($k)) === null) {
        return;
      }
      unset($this->k[$i]);
      unset($this->v[$i]);
      unset($this->m[$i]);
    }

    public function get(Orm $k) {
      if(($i = $this->index($k)) === null) {
        return 0;
      }
      return $this->v[$i];
    }

    public function getMeta(Orm $k) {
      if(($i = $this->index($k)) === null) return array();
      return $this->m[$i];
    }

    public function firstKey() {
      return reset($this->k);
    }

    public function firstVal() {
      return reset($this->v);
    }

    public function keys() {
      return $this->k;
    }

    public function meta() {
      return $this->m;
    }

    public function increment(Orm $k, $i = 1, $meta = null) {
      if(!is_int($i) || $i < 0) throw new OrmInputException('Increment must be an integer >= 0');
      $this->set($k, $t = $this->get($k)+$i, $meta);
      return $t;
    }

    public function decrement(Orm $k, $i = 1) {
      if(!is_int($i) || $i < 0) throw new OrmInputException('Decrement must be an integer >= 0');
      # if there are enough items remove the specified number, otherwise remove only the current count
      if(($c = $this->get($k)) > $i) {
        $this->set($k, $c-$i);
        $c = $i;
      }
      else {
        $this->remove($k);
      }
      return $c;
    }

    public function merge(OrmHash $h) {
      $p = $h->getArrays();
      foreach($p->keys as $i => $k) {
        $this->increment($k, $p->values[$i], $p->meta[$i]);
      }
      return $this;
    }

    public function size() {
      return array_sum($this->v);
    }

    public function getArrays() {
      $o = new stdClass();
      $o->keys = $this->k;
      $o->values = $this->v;
      $o->meta = $this->m;
      return $o;
    }

    public function clear() {
      $this->k = array();
      $this->v = array();
      $this->m = array();
    }

    public function total() {
      return array_sum($this->v);
    }
  }

  class OrmRelationGroup {
    private $loaded = false;
    private $c_loaded;
    private $c_added;
    private $c_deleted;
    private $pname;
    private $rname;
    private $pclass;
    private $rclass;
    private $type;
    private $min;
    private $max;
    private $meta;

    public function __construct($type, $pname, $rname, $pclass, $rclass, $min, $max, $meta) {
      if(!in_array($type, array('otm', 'mto', 'mtm'))) throw new OrmInputException('Allowed relation types are: otm, mto, mtm');

      $name_test_allowed = '#^[a-zA-Z0-9]+$#';
      if(!preg_match($name_test_allowed, $pname))   throw new OrmInputException('Name must contain only alphanumerics; '.$pname .' is invalid');
      if(!preg_match($name_test_allowed, $rname))   throw new OrmInputException('Name must contain only alphanumerics; '.$rname .' is invalid');
      if(!preg_match($name_test_allowed, $pclass))  throw new OrmInputException('Name must contain only alphanumerics; '.$pclass.' is invalid');
      if(!preg_match($name_test_allowed, $rclass))  throw new OrmInputException('Name must contain only alphanumerics; '.$rclass.' is invalid');

      if(!is_int($min) || $min < 0)  throw new OrmInputException('Min constraint must be 0 or a positive integer');
      if($max !== null && (!is_int($max) || $max < 0 || $max < $min))  throw new OrmInputException('Max constraint must be null or an integer greater than or equal to the minimum constraint');

      if($type == 'mto' &&( $min > 1 || $max > 1)) throw new OrmInputException('For an MTO min is either 0 or 1 and max is 1');

      $this->type = $type;
      $this->h_loaded = new OrmHash();
      $this->h_added = new OrmHash();
      $this->h_deleted = new OrmHash();
      $this->pname = $pname;
      $this->rname = $rname;
      $this->pclass = $pclass;
      $this->rclass = $rclass;
      $this->min = $min;
      $this->max = $max;
      $this->meta = is_array($meta)? $meta : array();
    }

    public function isLoaded() {
      return $this->loaded;
    }

    public function setLoaded() {
      $this->loaded = true;
    }

    public function getPName() {
      return $this->pname;
    }

    public function getRName() {
      return $this->rname;
    }

    public function getPClass() {
      return $this->pclass;
    }

    public function getRClass() {
      return $this->rclass;
    }

    public function getType() {
      return $this->type;
    }

    public function getMin() {
      return $this->min;
    }

    public function getMax() {
      return $this->max;
    }

    public function getMeta() {
      return $this->meta;
    }

    public function getCount() {
      return $this->h_loaded->total() + $this->h_added->total();
    }

    public function getRelsLoaded($with_counts = false) {
      return $with_counts? $this->h_loaded : $this->h2a($this->h_loaded);
    }

    public function getRelsAdded($with_counts = false) {
      return $with_counts? $this->h_added : $this->h2a($this->h_added);
    }

    public function getRelsDeleted($with_counts = false) {
      return $with_counts? $this->h_deleted : $this->h2a($this->h_deleted);
    }

    public function getRelsAll($with_counts = false, $with_deleted = false) {
      $os = clone $this->h_loaded;
      $os->merge($this->h_added);
      if($with_deleted) $os->merge($this->h_deleted);

      # printf("(%s, %s) l: %d, a: %d, d: %d, t: %d\n", $this->pclass, $this->rname, $this->h_loaded->size(), $this->h_added->size(), $this->h_deleted->size(), $os->size());

      return $with_counts? $os : $this->h2a($os);
    }

    private function checkMeta($meta) {
      if($meta === null) return;
      if(!is_array($meta)) throw new OrmInputException('$meta must be an array, in this case it is of type: '.gettype($meta));
      foreach($meta as $key => $val) if(!in_array($key, $this->meta)) throw new OrmInputException('Meta key '.$key.' was not registered properly');
    }

    public function loadAdd(Orm $o, $count = 1, $meta = null) {
      $this->checkMeta($meta);
      $this->h_loaded->increment($o, $count, $meta);
    }

    public function add(Orm $o, $count = 1, $meta = null) {
      $this->checkMeta($meta);
      return $this->h_loaded->total() + $this->h_added->increment($o, $count, $meta);
    }

    public function remove(Orm $o, $dont_track = false, $count = 1) {
      $from_added = $from_loaded = 0;
      if(($from_added = $this->h_added->decrement($o, $count)) < $count) {
        $from_loaded = $this->h_loaded->decrement($o, $count - $from_added);
        if(!$dont_track && $count > 0) {
          $this->h_deleted->increment($o, $from_loaded);
        }
      }
      return $from_added + $from_loaded;
    }

    // create an array containing all the objects. note that repeats will occur when the count is > 1
    // hash to array
    private function h2a(OrmHash $hash) {
      $array = array();
      $pair = $hash->getArrays();
      foreach($pair->keys as $i => $k) {
        for($j = 0; $j < $pair->values[$i]; $j++) {
          $array[] = $k;
        }
      }
      return $array;
    }

    public function reflectSave() {
      # move all objects from added to loaded
      $this->h_loaded->merge($this->h_added);
      $this->h_added->clear();

      # remove deleted
      $this->h_deleted->clear();

      # set loaded
      $this->loaded = true;
    }

    # TODO: remove
    # metadata does not belong at this level;
    # levels:
    #   1. Orm
    #   2. OrmRelGroup (schema of rel)
    #   3. OrmHash (storage of individual objects against counts)
    #
    # so metadata for an individual relation (which is what we want with counts and whatnot) should be stored in the hash table
    # either add a new array, or use the current count array (vals?) to contain metadata arrays...
    # count has become a useful element with increment and decrement proving their uses, so perhaps adding a new array is better?
    # -> investigate use of count to make sure an educated decision is made
  }

  class OrmInputException extends Exception {}
  class OrmConstraintException extends Exception {}
?>
