<?php
/*******************************************************************************
 *
 *         Name:  SSql - Transparent Singleton SQL Layer
 *
 *    Supported:  MySQL, MySQLi, SQLite, PostgreSQL, MSSQL
 *         Note:  !!!!! NEEDS TESTING ON ALL PLATFORMS !!!!!
 *
 *  Description:  I wanted an easy-to-use static DB connector I could tweak to
 *                use different DBs transparently and leave all the
 *                configuration to the connection stage.
 *
 *                This is a Static Singleton class to allow easy DB manipulation
 *                across different connections, with a requirement to use a
 *                user-specified connection name when querying.
 *
 *                While specified as a requirement, the name is optional if
 *                you wish to use the fallback connection, which is usually set
 *                to the default connection name. This can be altered with the
 *                useName method specified below.
 *
 * Requirements:  PHP 5.2.0+
 *
 *      Methods:  connect         (type, database[, host, username, password,
 *                                                           name, debug_all])
 *                string    escape          (var[, name])
 *                string    getVar          (query[, name])
 *                string[]  getCol          (query[, name])
 *                object    getRow          (query[, name])
 *                object[]  getResults      (query[, name])
 *
 *                resource  query           (query[, name])
 *                int       getInsertId     ([name])
 *                int       getAffectedRows ([name])
 *
 *                string    getInput        ([name])
 *                mixed     getOutput       ([name])
 *                resource  getHandle       ([name])
 *                resource  getResource     ([name])
 *                string    getType         ([name])
 *
 *                void      useName         ([name])
 *                void      debug           ([name]) !!! NOT YET CODED !!!
 *
 *       Author:  Adam Piper (adamp@ahri.net)
 *
 *      Version:  1.0
 *
 *         Date:  2008-05-13
 *
 *      License:  BSD (3 clause, 1999-07-22)
 *
 * Copyright (c) 2008, Adam Piper
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

  class SSql {
    private static $default_name = '-';

    private static $debug_all = false;

    private static $connection;
    private static $connections = array();

    private function __construct() {}

    public static function connect($type, $database, $host = null, $port = null, $username = null, $password = null, $name = null, $debug_all = false) {
      self::$debug_all = $debug_all;
      self::checkName($name);
      $name = $name? $name : self::$default_name;

      self::$connections[$name] = new stdClass();
      self::$connections[$name]->type = $type;

      switch($type) {
        case 'MySQL':
          if($port) $host = sprintf('%s:%d', $host, $port);
          self::$connections[$name]->handle = @mysql_connect($host, $username, $password);
          break;
        case 'MySQLi':
          self::$connections[$name]->handle = @mysqli_connect($host, $username, $password, $database);
          break;
        case 'SQLite':
          self::$connections[$name]->handle = @sqlite_open($database);
          break;
        case 'PostgreSQL':
          # build a connection string
          $pg_connect = array('dbname' => $database);
          foreach(array('host', 'port', 'username', 'password') as $var) if($$var) $pg_connect[] = sprintf('%s=%s', $var = 'username'? 'user' : $var, $$var);
          $pg_connect = implode(' ', $pg_connect);
          self::$connections[$name]->handle = @pg_connect($pg_connect);
          break;
        case 'MSSQL':
          if($port) $host = sprintf('%s,%s', $host, $port);
          self::$connections[$name]->handle = @mssql_connect($host, $username, $password);
          break;
        default:
          throw new SSqlInputException(sprintf('Type: %s is not in list of supported database types: MySQL, sqlite', $type));
      }
      if(!self::$connections[$name]->handle) self::throwSqlException($name);

      # DB selection, if required
      switch($type) {
        case 'MySQL':
          @mysql_select_db($database, self::getHandle($name)) || self::throwSqlException($name);
          break;
        case 'MSSQL':
          @mssql_select_db($database, self::getHandle($name)) || self::throwSqlException($name);
          break;
      }
    }

    private static function getConn($name = null) {
      self::checkName($name);
      self::$connection = self::$connection? self::$connection : self::$default_name;
      $name = $name? $name : self::$connection;

      if(!isset(self::$connections[$name])) throw new SSqlInputException(sprintf('The %s connection is not set', $name == self::$default_name? $name.' (DEFAULT)' : $name));
      return self::$connections[$name];
    }

    public static function useName($name = null) {
      self::checkName($name);
      $name = $name? $name : self::$default_name;
      if(!isset($name)) throw new SSqlInputException('You must specify a valid named connection');
      self::$connection = $name;
    }

    public static function getHandle($name = null) {
      return self::getConn($name)->handle;
    }

    public static function getResource($name = null) {
      return self::getConn($name)->resource;
    }

    public static function getType($name = null) {
      return self::getConn($name)->type;
    }

    public static function getInput($name = null) {
      return self::getConn($name)->input;
    }

    private static function putInput($input, $name = null) {
      self::getConn($name)->input = $input;
    }

    public static function getOutput($name = null) {
      return self::getConn($name)->output;
    }

    private static function putOutput($output, $name = null) {
      self::getConn($name)->output = $output;
    }

    private static function printDebug($func, $str) {
      if(self::$debug_all) printf("SSql::debug %s : %s\n", $func, $str);
    }

    private static function throwSqlException($name) {
      $err = '';

      switch(self::getType($name)) {
        case 'MySQL':
          $err = sprintf('MySQL Error(%d): %s', mysql_errno(self::getResource()), mysql_error(self::getResource()));
          break;
        case 'MySQLi':
          $err = sprintf('MySQLi Error(%d): %s', mysqli_errno(self::getHandle()), mysqli_error(self::getHandle()));
          break;
        case 'SQLite':
          $err = sprintf('sqlite Error(%d): %s', $errcode = sqlite_last_error(self::getHandle($name)), sqlite_error_string($errcode));
          break;
        case 'PostgreSQL':
          $err = sprintf('PostgreSQL Error: %s', pg_result_error(self::getResource($name)));
          break;
        case 'MSSQL':
          $err = sprintf('MSSQL Error: %s', mssql_get_last_message()); # there doesn't appear to be any way to get the last message for a given resource of even handle
        default:
          throw new SSqlInputException('Method throwSqlException does not have behaviour specified for DB type '.self::getType($name));
      }

      throw new SSqlSqlException($err);
    }

    public static function query($query, $name = null) {
      self::putInput($query, $name);
      self::printDebug('query', $query);
      $c = self::getConn($name);
      switch(self::getType($name)) {
        case 'MySQL':
          $c->resource = @mysql_query(self::getHandle($name), $query);
          break;
        case 'MySQLi':
          $c->resource = @mysqli_query(self::getHandle($name), $query);
          break;
        case 'SQLite':
          $c->resource = @sqlite_query(self::getHandle($name), $query);
          break;
        case 'PostgreSQL':
          pg_send_query(self::getHandle($name), $query);
          $c->resource = pg_get_result(self::getHandle($name));
          break;
        case 'MSSQL':
          $c->resource = @mssql_query($query, self::getHandle($name));
          break;
        default:
          throw new SSqlInputException('Method query does not have behaviour specified for DB type '.self::getType($name));
      }
      if(!$c->resource) self::throwSqlException($name);
      return $c->resource;
    }

    public static function escape($var, $name = null) {
      switch(self::getType($name)) {
        case 'MySQL':
          return mysql_real_escape_string("$var", self::getHandle($name));
        case 'MySQLi':
          return mysqli_real_escape_string(self::getHandle($name), "$var");
        case 'SQLite':
          return sqlite_escape_string("$var");
        case 'PostgreSQL':
          return pg_escape_string(self::getHandle($name), "$var");
        case 'MSSQL':
          return str_replace(array('\'', "\0"), array('\'\'', '[NULL]'), "$var"); # copied from a comment on php.net by vollmer@ampache.org
        default:
          throw new SSqlInputException('Method escape does not have behaviour specified for DB type '.self::getType($name));
      }
    }

    public static function getInsertId($name = null) {
      switch(self::getType($name)) {
        case 'MySQL':
          $id = @mysql_insert_id(self::getResource($name));
          break;
        case 'MySQLi':
          $id = @mysqli_insert_id(self::getHandle($name));
          break;
        case 'SQLite':
          $id = @sqlite_last_insert_rowid(self::getHandle($name));
          break;
        case 'PostgreSQL':
          $id = @pg_last_oid(self::getResource($name));
          break;
        case 'MSSQL':
          $id = self::getVar('SELECT @@IDENTITY');  # there is no *_insert_id equivalent for MSSQL
        default:
          throw new SSqlInputException('Method getInsertId does not have behaviour specified for DB type '.self::getType($name));
      }
      if(!$id) self::throwSqlException($name);
      return $id;
    }

    public static function getAffectedRows($name = null) {
      switch(self::getType($name)) {
        case 'MySQL':
          $rows = @mysql_affected_rows(self::getResource($name));
          break;
        case 'MySQLi':
          $rows = @mysqli_affected_rows(self::getHandle($name));
          break;
        case 'SQLite':
          $rows = @sqlite_changes(self::getResource($name));
          break;
        case 'PostgreSQL':
          $rows = @pg_affected_rows(self::getResource($name));
          break;
        case 'MSSQL':
          $rows = @mssql_rows_affected(self::getResource($name));
          break;
        default:
          throw new SSqlInputException('Method getAffectedRows does not have behaviour specified for DB type '.self::getType($name));
      }
      if(!$rows) self::throwSqlException($name);
      return $rows;
    }

    private static function checkName($name) {
      if($name !== null && (!is_string($name) || preg_match('#[^a-zA-Z0-9_]#', $name))) throw new SSqlInputException('$name must be a string of alphanumerics and underscores, you passed '.$name);
    }

    # get a single variable
    public static function getVar($query, $name = null) {
      $res = self::query($query, $name);
      switch(self::getType($name)) {
        case 'MySQL':
          $row = mysql_fetch_row($res);
          $var = $row? reset($row) : null;
          break;
        case 'MySQLi':
          $row = mysqli_fetch_row($res);
          $var = $row? reset($row) : null;
          break;
        case 'SQLite':
          $var = sqlite_fetch_single($res);
          break;
        case 'PostgreSQL':
          $row = pg_fetch_row($res);
          $var = $row? reset($row) : null;
          break;
        case 'MSSQL':
          $row = mssql_fetch_row($res);
          $var = $row? reset($row) : null;
          break;
        default:
          throw new SSqlInputException('Method getVar does not have behaviour specified for DB type '.self::getType($name));
      }
      self::putOutput($var, $name);
      return $var;
    }

    # get a single column, always returns an array
    public static function getCol($query, $name = null) {
      $res = self::query($query, $name);
      $col = array();
      switch(self::getType($name)) {
        case 'MySQL':
          while($row = mysql_fetch_row($res)) $col[] = reset($row);
          break;
        case 'MySQLi':
          while($row = mysqli_fetch_row($res)) $col[] = reset($row);
          break;
        case 'SQLite':
          if($column = sqlite_fetch_single($res)) $col = $column;
          break;
        case 'PostgreSQL':
          if($column = pg_fetch_all_columns($res)) $col = $column;
          break;
        case 'MSSQL':
          while($row = mssql_fetch_row($res)) $col[] = reset($row);
          break;
        default:
          throw new SSqlInputException('Method getCol does not have behaviour specified for DB type '.self::getType($name));
      }
      self::putOutput($col, $name);
      return $col;
    }

    # get a single row, always returns a stdClass object or null
    public static function getRow($query, $name = null) {
      $res = self::query($query, $name);
      switch(self::getType($name)) {
        case 'MySQL':
          $o = mysql_fetch_object($res);
          break;
        case 'MySQLi':
          $o = mysqli_fetch_object($res);
          break;
        case 'SQLite':
          $o = sqlite_fetch_object($res);
          break;
        case 'PostgreSQL':
          $o = pg_fetch_object($res);
          break;
        case 'MSSQL':
          $o = mssql_fetch_object($res);
          break;
        default:
          throw new SSqlInputException('Method getRow does not have behaviour specified for DB type '.self::getType($name));
      }
      $row = $o? $o : null;
      self::putOutput($row, $name);
      return $row;
    }

    # get results, always returns an array of objects
    public static function getResults($query, $name = null) {
      $res = self::query($query, $name);
      $array = array();
      switch(self::getType($name)) {
        case 'MySQL':
          while($o = mysql_fetch_object($res)) $array[] = $o;
          break;
        case 'MySQLi':
          while($o = mysqli_fetch_object($res)) $array[] = $o;
          break;
        case 'SQLite':
          while($o = sqlite_fetch_object($res)) $array[] = $o;
          break;
        case 'PostgreSQL':
          while($o = pg_fetch_object($res)) $array[] = $o;
          break;
        case 'MSSQL':
          while($o = mssql_fetch_object($res)) $array[] = $o;
          break;
        default:
          throw new SSqlInputException('Method getResults does not have behaviour specified for DB type '.self::getType($name));
      }
      self::putOutput($array, $name);
      return $array;
    }

    # output debug information on the previous input/output
    public static function debug($name = null) {
      # use self::getInput($name) and self::getOuput($name)
    }
  }

  class SSqlInputException extends Exception {}
  class SSqlSqlException extends Exception {}
?>
