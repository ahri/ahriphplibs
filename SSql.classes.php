<?php
/*******************************************************************************
 *
 *        Title:  SSql (singleton SQL)
 *
 *  Description:  Thinly wrap PDO with a singleton
 *
 * Requirements:  PHP 5.2.0+
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

class SSqlException extends SPFException {}
class SSqlInputException extends SSqlException {}

class SSql
{
        private static $default = NULL;
        private static $named = array();

        private static function create($args)
        {
                foreach (array('dsn',
                               'username',
                               'password',
                               'driver_options') as $i => $var_name)

                        $$var_name = isset($args[$i])? $args[$i] : NULL;

                return new PDO($dsn, $username, $password, $driver_options);
        }

        private static function config(PDO $db)
        {
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        public static function setup()
        {
                $args = func_get_args();
                $db = self::$default = self::create($args);
                self::config($db);
                return $db;
        }

        public static function setupNamed()
        {
                $args = func_get_args();
                $name = array_shift($args);
                $db = self::$named[$name] = self::create($args);
                self::config($db);
                return $db;
        }

        public static function instance()
        {
                return self::$default;
        }

        public static function namedInstance($name)
        {
                return self::$named[$name];
        }

        public static function __callStatic($method, $args)
        {
                return call_user_func_array(array(self::$default, $method), $args);
        }

        /** Format SQL nicely **/
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
                                                        ",\n ",
                                                        "SELECT ",
                                                        "UPDATE ",
                                                        "DELETE ",
                                                        "\nAND ",
                                                        "\nOR ",
                                                        "\n(SELECT ",
                                                        "\nFROM ",
                                                        "\nWHERE ",
                                                        "\nINTO ",
                                                        "\nVALUES ",
                                                        "\nORDER BY ",
                                                        "\nGROUP BY ",
                                                        "\nINNER JOIN ",
                                                        "\nOUTER JOIN ",
                                                        "\nLEFT JOIN ",
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
}

?>
