<?php
/*******************************************************************************
 *
 *        Title:  NodeTpl - Singleton tool to create Nodal templates for
 *                          consistent look and feel
 *
 *  Description:  Supplies tools such as named hooks for specific Nodes to make
 *                a Nodal template easy to work with.
 *
 *         Help:  NodeTpl::setRoot($html = new Node('html'));
 *                NodeTpl::hook('title', $title = $html->title());
 *                NodeTpl::content('title', 'example.com - {page_title}');
 *                NodeTpl::variable('page_title', 'default page title');
 *                ...
 *                NodeTpl::variable('page_title', 'Blog Listing');
 *                NodeTpl::output();
 *
 *      Methods:  NodeTpl
 *                ::setRoot(Node root_node)
 *                ::getRoot()
 *                ::hook(hook_name[, Node node)
 *                ::content(string hook_name, string format)
 *                ::variable(string var_name[, mixed value])
 *                ::output()
 *
 * Requirements:  PHP 5.2.0+, ideally 5.3.0+
 *
 *       Author:  Adam Piper (adamp@ahri.net)
 *
 *      Version:  0.1
 *
 *         Date:  2009-04-28
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

class NodeTplException extends SPFException {}

class NodeTpl
{
        private static $root = NULL;
        private static $header = '<!DOCTYPE HTML>';
        private static $named = array();
        private static $contents = array();
        private static $vars = array();

        private function __construct() {}

        /** Set the root node **/
        public static function setRoot(Node $root)
        {
                self::$root = $root;
        }

        /** Get the root node **/
        public static function getRoot()
        {
                return self::$root;
        }

        /** Set the header for the template (e.g. HTML5 or XML namespace declaration) **/
        public static function setHeader($str)
        {
                self::$header = $str;
        }

        /** Set the header to be output prior to HTML itself **/
        public static function getHeader()
        {
                return self::$header;
        }

        /** Output template **/
        public static function output()
        {
                self::doContents();
                printf("%s\n%s", self::getHeader(), self::getRoot());
        }

        /** Set a named node **/
        private static function setHook($name, Node $node)
        {
                self::$named[$name] = $node;
        }

        /** Get a named node **/
        private static function getHook($name)
        {
                self::hookcheck($name);
                return self::$named[$name];
        }

        private static function hookCheck($name)
        {
                if (!isset(self::$named[$name]))
                        throw new NodeTplException('No node named "%s" exists', $name);
        }

        private static function varCheck($name)
        {
                if (!isset(self::$vars[$name]))
                        throw new NodeTplException('No var named "%s" exists', $name);
        }

        /** Assign or retrieve a named Node (a hook into the Node structure),
            uses jQuery-style guessing of meaning of args **/
        public static function hook()
        {
                $args = func_get_args();
                switch(func_num_args()) {
                case 1:
                        return call_user_func_array(array('self', 'getHook'), $args);
                        break;
                case 2:
                        return call_user_func_array(array('self', 'setHook'), $args);
                        break;
                default:
                        throw new NodeTplException('Function "hook" takes either one or two args');
                }
        }

        /** Specify the content for a text node to be added to the given hook,
            variables in the form {var_name} **/
        public static function content($hook, $format)
        {
                self::hookCheck($hook);
                self::$contents[$hook] = $format;
        }

        private static function doContents()
        {
                foreach (self::$contents as $hook => $format) {
                        $node = self::hook($hook);
                        $node->addText(preg_replace_callback('#(?<!\{)\{([a-z][a-z0-9_]+)\}#', array('self', 'callbackReplacer'), $format));
                }
        }

        private static function callbackReplacer($matches)
        {
                $var = self::variable($matches[1]);
                if (!is_string($var)) {
                        ob_start();
                        var_dump($var);
                        $var = ob_get_contents();
                        ob_end_clean();
                }
                return $var;
        }

        /** Assign or retrieve a template variable,
            jQuery-style guessing of the meaning of args **/
        public static function variable($name)
        {
                switch(func_num_args()) {
                case 1:
                        self::varCheck($name);
                        return self::$vars[$name];
                        break;
                case 2:
                        self::$vars[$name] = func_get_arg(1);
                        break;
                default:
                        throw new NodeTplException('Function "variable" takes either one or two args');
                }
        }
}

?>