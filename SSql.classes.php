<?php
/*******************************************************************************
 *
 *         Name:  SSql - Transparent Singleton SQL Layer
 *
 *    Supported:  Dummy, MySQL, MySQLi, SQLite, SQLite3, PostgreSQL, MSSQL
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
 *      Methods:  connect         (type, database[, host, port, username,
 *                                 password, name, debug_all])
 *                string    escape          (var  [, name])
 *                string    getVar          (query[, name])
 *                string[]  getCol          (query[, name])
 *                object    getRow          (query[, name])
 *                object[]  getResults      (query[, name])
 *                string    getVarFor       (result[, name])
 *                string[]  getColFor       (result[, name])
 *                object    getRowFor       (result[, name])
 *                object[]  getResultsFor   (result[, name])
 *                int       timestamp       (date_or_datetime)
 *
 *                result    query           (query[, name])
 *                int       getInsertId     ([name])
 *                int       getNumRows      (result, [name])
 *                int       getAffectedRows ([name])
 *
 *                string    getInput        ([name])
 *                mixed     getOutput       ([name])
 *                resource  getHandle       ([name])
 *                result    getResult       ([name])
 *                string    getType         ([name])
 *
 *                void      useName         ([name])
 *                void      debug           ([name]) !!! NOT YET CODED !!!
 *                bool      validName       ([name])
 *                string    format          (query)
 *                void      rewind          (result[, name])
 *
 *       Author:  Adam Piper (adamp@ahri.net)
 *
 *      Version:  0.11
 *
 *         Date:  2009-08-10
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

class SSql
{
        const default_name = '-';

        private static $debug_all = false;

        private static $connection  = NULL;
        private static $connections = array();

        private function __construct() {}

        public static function connect($type, $database, $host = NULL, $port = NULL, $username = NULL, $password = NULL, $name = NULL, $debug_all = false)
        {
                $public_name = $name;
                self::$debug_all = $debug_all;
                self::name($name);

                self::$connections[$name] = new stdClass();
                self::$connections[$name]->type = $type;

                switch($type) {
                        case 'Dummy':
                                self::$connections[$name]->handle = 1;
                                break;
                        case 'MySQL':
                                if ($port)
                                        $host = sprintf('%s:%d', $host, $port);

                                self::$connections[$name]->handle = mysql_connect($host, $username, $password);
                                break;
                        case 'MySQLi':
                                self::$connections[$name]->handle = new MySQLi($host, $username, $password, $database, $port);
                                break;
                        case 'SQLite':
                                self::$connections[$name]->handle = sqlite_open($database);
                                break;
                        case 'SQLite3':
                                self::$connections[$name]->handle = new SQLite3($database);
                        case 'PostgreSQL':
                                # build a connection string
                                $pg_connect = array('dbname' => $database);
                                foreach (array('host', 'port', 'username', 'password') as $var)
                                        if ($$var)
                                                $pg_connect[] = sprintf('%s=%s', $var = 'username'? 'user' : $var, $$var);

                                $pg_connect = implode(' ', $pg_connect);
                                self::$connections[$name]->handle = pg_connect($pg_connect);
                                break;
                        case 'MSSQL':
                                if ($port)
                                        $host = sprintf('%s,%s', $host, $port);

                                self::$connections[$name]->handle = mssql_connect($host, $username, $password);
                                break;
                        default:
                                throw new SSqlInputException('Type: %s is not in list of supported database types', $type);
                }

                if (!self::$connections[$name]->handle)
                        throw new SSqlException('Connection failed');

                # DB selection, if required
                switch($type) {
                        case 'MySQL':
                                @mysql_select_db($database, self::getHandle($public_name)) || self::throwSqlException($public_name);
                                break;
                        case 'MSSQL':
                                @mssql_select_db($database, self::getHandle($public_name)) || self::throwSqlException($public_name);
                                break;
                }
        }

        private static function getConn($name = NULL)
        {
                self::name($name);
                if ($name == self::default_name && !is_null(self::$connection)) # if we have a connection specified via useName()
                        $name = self::$connection;

                if (!isset(self::$connections[$name]))
                        throw new SSqlInputException('The %s connection is not set', $name == self::default_name? $name.' (DEFAULT)' : $name);

                return self::$connections[$name];
        }

        public static function useName($name = NULL)
        {
                self::$connection = $name;
        }

        public static function getHandle($name = NULL)
        {
                return self::getConn($name)->handle;
        }

        public static function getResult($name = NULL)
        {
                return self::getConn($name)->result;
        }

        public static function getType($name = NULL)
        {
                return self::getConn($name)->type;
        }

        public static function getInput($name = NULL)
        {
                return self::getConn($name)->input;
        }

        private static function putInput($input, $name = NULL)
        {
                self::getConn($name)->input = $input;
        }

        public static function getOutput($name = NULL)
        {
                return self::getConn($name)->output;
        }

        private static function putOutput($output, $name = NULL)
        {
                self::getConn($name)->output = $output;
        }

        private static function printDebug($func, $str)
        {
                if (self::$debug_all)
                        printf("SSql::debug %s : %s\n", $func, $str);
        }

        private static function throwSqlException($name)
        {
                $err = '';

                switch(self::getType($name)) {
                        case 'Dummy':
                                $err = 'Dummy Error';
                                break;
                        case 'MySQL':
                                $err = sprintf('MySQL Error(%d): %s', mysql_errno(self::getHandle($name)), mysql_error(self::getHandle($name)));
                                break;
                        case 'MySQLi':
                                $err = sprintf('MySQLi Error(%d): %s', self::getHandle($name)->errno, self::getHandle($name)->error);
                                break;
                        case 'SQLite':
                                $err = sprintf('SQLite Error(%d): %s', $errcode = sqlite_last_error(self::getHandle($name)), sqlite_error_string($errcode));
                                break;
                        case 'SQLite3':
                                $err = sprintf('SQLite3 Error(%d): %s', self::getHandle($name)->ultrrorCode, self::getHandle($name)->lastErrorMsg);
                                break;
                        case 'PostgreSQL':
                                $err = sprintf('PostgreSQL Error: %s', pg_result_error(self::getResult($name)));
                                break;
                        case 'MSSQL':
                                $err = sprintf('MSSQL Error: %s', mssql_get_last_message()); # there doesn't appear to be any way to get the last message for a given result of even handle
                        default:
                                throw new SSqlInputException('Method throwSqlException does not have behaviour specified for DB type %s', self::getType($name));
                }

                throw new SSqlSqlException($err);
        }

        public static function query($query, $name = NULL)
        {
                self::putInput($query, $name);
                self::printDebug('query', $query);
                $c = self::getConn($name);
                switch(self::getType($name)) {
                        case 'Dummy':
                                $c->result = 1;
                                printf("################################################################################\n");
                                printf("SSql Dummy Query: \n        \n%s\n\n", preg_replace('#^#m', '    ', self::format($query)));
                                break;
                        case 'MySQL':
                                $c->result = mysql_query($query, self::getHandle($name));
                                break;
                        case 'MySQLi':
                                $c->result = self::getHandle($name)->query($query);
                                break;
                        case 'SQLite':
                                $c->result = @sqlite_query(self::getHandle($name), $query);
                                break;
                        case 'SQLite3':
                                $c->result = self::getHandle($name)->query($query);
                                break;
                        case 'PostgreSQL':
                                pg_send_query(self::getHandle($name), $query);
                                $c->result = pg_get_result(self::getHandle($name));
                                break;
                        case 'MSSQL':
                                $c->result = @mssql_query($query, self::getHandle($name));
                                break;
                        default:
                                throw new SSqlInputException('Method query does not have behaviour specified for DB type %s', self::getType($name));
                }
                if (!$c->result)
                        self::throwSqlException($name);

                return $c->result;
        }

        public static function escape($var, $name = NULL)
        {
                switch(self::getType($name)) {
                        case 'Dummy':
                                return str_replace("'", "''", $var); # A very basic escape just for demo purposes
                        case 'MySQL':
                                return mysql_real_escape_string("$var", self::getHandle($name));
                        case 'MySQLi':
                                return self::getHandle($name)->escape_string($var);
                        case 'SQLite':
                                return sqlite_escape_string("$var");
                        case 'SQLite3':
                                return self::getHandle($name)->escapeString("$var");
                        case 'PostgreSQL':
                                return pg_escape_string(self::getHandle($name), "$var");
                        case 'MSSQL':
                                return str_replace(array('\'', "\0"), array('\'\'', '[NULL]'), "$var"); # copied from a comment on php.net by vollmer@ampache.org
                        default:
                                throw new SSqlInputException('Method escape does not have behaviour specified for DB type %s', self::getType($name));
                }
        }

        public static function getInsertId($name = NULL)
        {
                switch(self::getType($name)) {
                        case 'Dummy':
                                $id = 0;
                                break;
                        case 'MySQL':
                                $id = @mysql_insert_id(self::getResult($name));
                                break;
                        case 'MySQLi':
                                $id = self::getHandle($name)->insert_id;
                                break;
                        case 'SQLite':
                                $id = @sqlite_last_insert_rowid(self::getHandle($name));
                                break;
                        case 'SQLite3':
                                $id = self::getHandle($name)->lastInsertRowId();
                                break;
                        case 'PostgreSQL':
                                $id = @pg_last_oid(self::getResult($name));
                                break;
                        case 'MSSQL':
                                $id = self::getVar('SELECT @@IDENTITY');        # there is no *_insert_id equivalent for MSSQL
                        default:
                                throw new SSqlInputException('Method getInsertId does not have behaviour specified for DB type %s', self::getType($name));
                }
                if (is_null($id))
                        self::throwSqlException($name);

                return $id;
        }

        public static function getNumRows($result, $name = NULL)
        {
                switch(self::getType($name)) {
                        case 'Dummy':
                                $rows = 0;
                                break;
                        case 'MySQL':
                                $rows = @mysql_num_rows($result);
                                break;
                        case 'MySQLi':
                                $rows = $result->num_rows;
                                break;
                        case 'SQLite':
                                $rows = @sqlite_num_rows($result);
                                break;
                        case 'SQLite3':
                                # TODO
                                $rows = $result->changes();
                                break;
                        case 'PostgreSQL':
                                $rows = @pg_num_rows($result);
                                break;
                        case 'MSSQL':
                                $rows = @mssql_num_rows($result);
                                break;
                        default:
                                throw new SSqlInputException('Method getNumRows does not have behaviour specified for DB type %s', self::getType($name));
                }
                if (is_null($rows))
                        self::throwSqlException($name);

                return $rows;
        }

        public static function getAffectedRows($name = NULL)
        {
                $handle = self::getHandle($name);
                switch(self::getType($name)) {
                        case 'Dummy':
                                $rows = 0;
                                break;
                        case 'MySQL':
                                $rows = @mysql_affected_rows($handle);
                                break;
                        case 'MySQLi':
                                $rows = $handle->affected_rows;
                                break;
                        case 'SQLite':
                                $rows = @sqlite_changes($handle);
                                break;
                        case 'SQLite3':
                                $rows = $handle->changes();
                                break;
                        case 'PostgreSQL':
                                $rows = @pg_affected_rows(self::getResult($name));
                                break;
                        case 'MSSQL':
                                $rows = @mssql_rows_affected($handle);
                                break;
                        default:
                                throw new SSqlInputException('Method getAffectedRows does not have behaviour specified for DB type %s', self::getType($name));
                }
                if (is_null($rows))
                        self::throwSqlException($name);

                return $rows;
        }

        private static function name(&$name)
        {
                if (!is_null($name) && (!is_string($name) || preg_match('#[^a-zA-Z0-9_]#', $name)))
                        throw new SSqlInputException('$name must be a string of alphanumerics and underscores, you passed %s', $name);

                if (is_null($name))
                        $name = self::default_name;
        }

        # get a single variable
        public static function getVarFor($res, $name = NULL)
        {
                switch(self::getType($name)) {
                        case 'Dummy':
                                $var = 'Dummy';
                                break;
                        case 'MySQL':
                                $row = mysql_fetch_row($res);
                                $var = $row? reset($row) : NULL;
                                break;
                        case 'MySQLi':
                                $row = $res->fetch_row($res);
                                $var = $row? reset($row) : NULL;
                                break;
                        case 'SQLite':
                                $var = sqlite_fetch_single($res);
                                break;
                        case 'SQLite3':
                                $row = $res->fetchArray();
                                $var = $row? reset($row) : NULL;
                                break;
                        case 'PostgreSQL':
                                $row = pg_fetch_row($res);
                                $var = $row? reset($row) : NULL;
                                break;
                        case 'MSSQL':
                                $row = mssql_fetch_row($res);
                                $var = $row? reset($row) : NULL;
                                break;
                        default:
                                throw new SSqlInputException('Method getVar does not have behaviour specified for DB type %s', self::getType($name));
                }
                self::putOutput($var, $name);
                return $var;
        }

        public static function getVar($query, $name = NULL)
        {
                return self::getVarFor(self::query($query, $name), $name);
        }

        # get a single column, always returns an array
        public static function getColFor($res, $name = NULL)
        {
                $col = array();
                switch(self::getType($name)) {
                        case 'Dummy':
                                break;
                        case 'MySQL':
                                while ($row = mysql_fetch_row($res))
                                        $col[] = reset($row);
                                break;
                        case 'MySQLi':
                                while ($row = $res->fetch_row($res))
                                        $col[] = reset($row);
                                break;
                        case 'SQLite':
                                if ($column = sqlite_fetch_single($res))
                                        $col = $column;
                                break;
                        case 'SQLite3':
                                while ($row = $res->fetchArray())
                                        $col[] = reset($row);
                                $r->finalize();
                                break;
                        case 'PostgreSQL':
                                if ($column = pg_fetch_all_columns($res))
                                        $col = $column;
                                break;
                        case 'MSSQL':
                                while ($row = mssql_fetch_row($res))
                                        $col[] = reset($row);
                                break;
                        default:
                                throw new SSqlInputException('Method getCol does not have behaviour specified for DB type %s', self::getType($name));
                }
                self::putOutput($col, $name);
                return $col;
        }

        public static function getCol($query, $name = NULL)
        {
                return self::getColFor(self::query($query, $name), $name);
        }

        # get a single row, always returns a stdClass object or NULL
        public static function getRowFor($res, $name = NULL)
        {
                switch(self::getType($name)) {
                        case 'Dummy':
                                $o = new stdClass();
                                break;
                        case 'MySQL':
                                $o = mysql_fetch_object($res);
                                break;
                        case 'MySQLi':
                                $o = $res->fetch_object();
                                break;
                        case 'SQLite':
                                $o = sqlite_fetch_object($res);
                                break;
                        case 'SQLite3':
                                $o = (object) $res->fetchArray(SQLITE3_ASSOC);
                                break;
                        case 'PostgreSQL':
                                $o = pg_fetch_object($res);
                                break;
                        case 'MSSQL':
                                $o = mssql_fetch_object($res);
                                break;
                        default:
                                throw new SSqlInputException('Method getRow does not have behaviour specified for DB type %s', self::getType($name));
                }
                $row = $o? $o : NULL;
                self::putOutput($row, $name);
                return $row;
        }

        public static function getRow($query, $name = NULL)
        {
                return self::getRowFor(self::query($query, $name), $name);
        }

        # get results, always returns an array of objects
        public static function getResultsFor($res, $name = NULL)
        {
                $array = array();
                switch(self::getType($name)) {
                        case 'Dummy':
                                break;
                        case 'MySQL':
                                while ($o = mysql_fetch_object($res))
                                        $array[] = $o;
                                break;
                        case 'MySQLi':
                                while ($o = $res->fetch_object())
                                        $array[] = $o;
                                break;
                        case 'SQLite':
                                while ($o = sqlite_fetch_object($res))
                                        $array[] = $o;
                                break;
                        case 'SQLite3':
                                $r = self::getResult($name);
                                while ($o = $r->fetchArray(SQLITE3_ASSOC))
                                        $array[] = $o;
                                break;
                        case 'PostgreSQL':
                                while ($o = pg_fetch_object($res))
                                        $array[] = $o;
                                break;
                        case 'MSSQL':
                                while ($o = mssql_fetch_object($res))
                                        $array[] = $o;
                                break;
                        default:
                                throw new SSqlInputException('Method getResults does not have behaviour specified for DB type %s', self::getType($name));
                }
                self::putOutput($array, $name);
                return $array;
        }

        public static function getResults($query, $name = NULL)
        {
                return self::getResultsFor(self::query($query, $name), $name);
        }

        public static function timestamp($date_or_datetime)
        {
                if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $date_or_datetime, $m))
                        return mktime(0, 0, 0, $m[2], $m[3], $m[1]);
                elseif (preg_match('#^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$#', $date_or_datetime, $m))
                        return mktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]);
                else
                        throw new SSqlInputException('Must pass either a date (YYYY-MM-DD) or datetime (YYYY-MM-DD HH:MM:SS)');
        }

        # output debug information on the previous input/output
        public static function debug($name = NULL)
        {
                # use self::getInput($name) and self::getOuput($name)
        }

        public static function validName($name = NULL)
        {
                self::name($name);
                return isset(self::$connections[$name]);
        }

        public static function format($query) {
                # first sort out some prettier spacing of known SQL elements
                $out = explode("\n", preg_replace(array('#(^\s+|\s+$)#',
                                                        '#\s+#m',
                                                        '#, #',
                                                        '#,#',
                                                        '#^SELECT #',
                                                        '#^UPDATE #',
                                                        '#^DELETE #',
                                                        '# AND #',
                                                        '# OR #',
                                                        '#\(SELECT #',
                                                        '# FROM #',
                                                        '# WHERE #',
                                                        '# INTO #',
                                                        '# VALUES #',
                                                        '# ORDER BY #',
                                                        '# GROUP BY #',
                                                        '# INNER JOIN #',
                                                        '# OUTER JOIN #',
                                                        '# LEFT JOIN #',
                                                        '# RIGHT JOIN #'),
                                                  array('',
                                                        ' ',
                                                        ',',
                                                        ",\n           ",
                                                        "SELECT     ",
                                                        "UPDATE     ",
                                                        "DELETE     ",
                                                        "\nAND        ",
                                                        "\nOR         ",
                                                        "\n(SELECT    ",
                                                        "\nFROM       ",
                                                        "\nWHERE      ",
                                                        "\nINTO       ",
                                                        "\nVALUES     ",
                                                        "\nORDER BY   ",
                                                        "\nGROUP BY   ",
                                                        "\nINNER JOIN ",
                                                        "\nOUTER JOIN ",
                                                        "\nLEFT JOIN  ",
                                                        "\nRIGHT JOIN "),
                                                  $query));

                # then figure out where maximum index for the operators might be
                $oi = 0;
                $ops = array('AS',
                             '=',
                             '<>',
                             'IN',
                             'NOT IN',
                             'LIKE',
                             'NOT LIKE',
                             'IS NULL',
                             'IS NOT NULL',
                             'BETWEEN',
                             'NOT BETWEEN',
                             'EXISTS',
                             'NOT EXISTS');
                $oplen = 0;
                foreach ($out as &$line) {
                        foreach ($ops as $op)
                                if (is_int($pos = strpos($line, $op)) && $pos > $oi) {
                                        $oi = $pos;
                                        if (($len = strlen($op)) > $oplen)
                                                $oplen = $len;
                                }
                }
                # then pad out any AS or = signs that need it in order to line up
                foreach ($out as &$line) {
                        foreach ($ops as $op)
                                if (is_int($pos = strpos($line, $op)) && $pos < ($oi + ($oplen-strlen($op)))) {
                                        $spaces = '';
                                        for ($i = 0; $i < (($oi - $pos) + $oplen-strlen($op)); $i++)
                                                $spaces .= ' ';

                                        $line = sprintf("%s", substr_replace($line, $spaces, $pos, 0));
                                }
                }

                return implode("\n", $out);
        }

        # rewind the current result set to the start (for re-iteration)
        public static function rewind($result, $name = NULL)
        {
                switch(self::getType($name)) {
                        case 'Dummy':
                                break;
                        case 'MySQL':
                                mysql_data_seek($result, 0);
                                break;
                        case 'MySQLi':
                                $result->data_seek(0);
                                break;
                        case 'SQLite':
                                sqlite_rewind($result);
                                break;
                        case 'SQLite3':
                                $result->reset();
                                break;
                        case 'PostgreSQL':
                                pg_result_seek($result, 0);
                                break;
                        case 'MSSQL':
                                mssql_data_seek($result, 0);
                                break;
                        default:
                                throw new SSqlInputException('Method rewind does not have behaviour specified for DB type %s', self::getType($name));
                }
                return NULL;
        }
}

class SSqlException      extends SPFException  {}
class SSqlInputException extends SSqlException {}
class SSqlSqlException   extends SSqlException {}

?>
