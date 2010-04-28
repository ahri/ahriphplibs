<?php
/*******************************************************************************
 *
 *         Name:  CodeListing
 *
 *  Description:  A module for use on the command-line to provide helpful,
 *                concise listings of functions/classes currently loaded.
 *
 * Requirements:  PHP 5.2.0+
 *
 *      Methods:  classes()   -- varargs, no args implies "all"
 *                functions() -- varargs, no args implies "all"
 *                all()       -- calls classes() and functions() with no args
 *
 *       Author:  Adam Piper (adamp@ahri.net)
 *
 *      Version:  0.1
 *
 *         Date:  2009-08-27
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

/** Generic exception for use by CodeListing related classes **/
class CodeListingException      extends SPFException         {}
/** Generic exception for use by CodeListing related classes where user input appears to be in error **/
class CodeListingInputException extends CodeListingException {}

/**
 Module for use on the command-line to provide helpful, concise listings of functions/classes currently loaded
 **/
class CodeListing
{
        /** Provide a list of functions or classes given a "spec" -- should only be called by getReflections() **/
        private static function getList($spec, $func)
        {
                if (sizeof($spec) == 0) {
                        $list = call_user_func($func);

                        # hack for functions
                        if ($func == 'get_defined_functions')
                                $list = $list['user'];
                } else {
                        $list = $spec;
                }

                return $list;
        }

        /** Provide an array of Reflection objects, given a list of strings and a type -- should only be called by getReflections() **/
        private static function getReflectionsFor($list, $type)
        {
                $class      = sprintf('Reflection%s', $type);
                $check_func = sprintf('%s_exists',    $type);

                $reflections = array();
                foreach ($list as $name) {
                        if (!call_user_func($check_func, $name))
                                throw new CodeListingInputException('%s %s does not exist', $type, $name);

                        $reflections[] = new $class($name);
                }

                return $reflections;
        }

        /** Abstraction around getList() and getReflectionsFor **/
        private static function getReflections($spec, $type)
        {
                switch ($type) {
                        case 'Class':
                                $func = 'get_declared_classes';
                                break;

                        case 'Function':
                                $func = 'get_defined_functions';
                                break;

                        default:
                                throw new CodeListingException('Type %s not expected', $type);
                }

                return self::getReflectionsFor(self::getList($spec, $func), $type);
        }

        /** Print an 80 character dash-line to screen **/
        private static function drawLine()
        {
                echo "--------------------------------------------------------------------------------\n";
        }

        /** Indent text by substring **/
        private static function indent(&$lines, $op)
        {
                # indent code
                $oi = 0;
                foreach ($lines as $line)
                        if (is_int($pos = strpos($line, $op)) && $pos > $oi)
                                $oi = $pos;

                foreach ($lines as &$line) {
                        if (is_int($pos = strpos($line, $op)) && $pos < $oi) {
                                $spaces = '';
                                for ($i = 0; $i < ($oi - $pos); $i++)
                                        $spaces .= ' ';

                                $line = sprintf('%s', substr_replace($line, $spaces, $pos, 0));
                        }
                }
        }

        /** For use with array_map to create an array of strings from an array of Reflection objects via their ->getName() methods **/
        private static function getName(Reflector $o)
        {
                return $o->getName();
        }

        /** List classes, supply: no args for all, a string (class name) or multiple string args (class names) **/
        public static function classes()
        {
                $type = 'Class';
                $spec = NULL;
                if (func_num_args() == 1 && !is_array($spec = func_get_arg(0)))
                        $spec = func_get_args();

                foreach(self::getReflections($spec, $type) as $c) {
                        if (!$c->isUserDefined())
                                continue;

                        $comment = $c->getDocComment();

                        $abstract   = $c->isAbstract()?            'abstract '                       : '';
                        $final      = $c->isFinal()?               'final '                          : '';
                        $ctype      = $c->isInterface()?           'interface '                      : 'class ';
                        $parent     = ($p = $c->getParentClass())? ' extends '.$p->getName()         : '';
                        $interfaces = ($is = $c->getInterfaces())? ' implements '.implode(', ', array_map(array('self', 'getName'), $is)) : '';

                        self::drawLine();
                        if ($comment)
                                printf("%s\n", $comment);

                        printf("%s%s%s%s%s%s [%d lines; %s:%d-%d]\n", $final, $abstract, $ctype, $c->getName(), $parent, $interfaces, $c->getEndLine() - $c->getStartLine(), preg_replace('#^.*/#', '', $c->getFileName()), $c->getStartLine(), $c->getEndLine());
                        self::drawLine();

                        if (sizeof($consts = $c->getConstants()) > 0) {
                                $out = array();
                                foreach ($consts as $const => $constval)
                                        $out[] = sprintf('const %s = %s', $const, var_export($constval, true));

                                self::indent($out, '=');
                                echo implode("\n", $out)."\n";

                                self::drawLine();
                        }

                        $out = array();
                        foreach ($c->getProperties() as $p) {
                                if ($p->getDeclaringClass() != $c)
                                        continue;

                                if ($comment = $p->getDocComment()) {
                                        $comment = preg_replace(array('#\s+#m', '#  #'), ' ', $comment);
                                        $comment = ' '.$comment;
                                } else {
                                        $comment = '';
                                }

                                $visibility = '';
                                if     ($p->isPublic())
                                      $visibility = 'public ';
                                elseif ($p->isPrivate())
                                      $visibility = 'private ';
                                elseif ($p->isProtected())
                                      $visibility = 'protected ';
                                else
                                      throw new CodeListingException('Unknown visibility');

                                $static = $p->isStatic()? 'static '   : '';
                                $modifiers = trim(sprintf('%s%s', $visibility, $static));
                                $out[] = sprintf('%s: $%s%s', $modifiers, $p->getName(), $comment); #TODO: $prop is actually a reflection object here
                        }

                        if (sizeof($out) > 0) {
                                self::indent($out, ':');

                                # a bit of a hack, but it suited the situation to just stick a character in to align by and later strip it
                                foreach ($out as &$line)
                                        $line = preg_replace('#:#', '', $line, 1);

                                echo implode("\n", $out)."\n";

                                self::drawLine();
                        }


                        $out = array();
                        foreach ($c->getMethods() as $m) {
                                if ($m->getDeclaringClass() != $c)
                                        continue;

                                if ($comment = $m->getDocComment()) {
                                        $comment = preg_replace(array('#\s+#m', '#  #'), ' ', $comment);
                                        $comment = ' '.$comment;
                                } else {
                                        $comment = '';
                                }

                                $visibility = '';
                                if     ($m->isPublic())
                                      $visibility = 'public ';
                                elseif ($m->isPrivate())
                                      $visibility = 'private ';
                                elseif ($m->isProtected())
                                      $visibility = 'protected ';
                                else
                                      throw new CodeListingException('Unknown visibility');

                                $static   = $m->isStatic()?   'static '   : '';
                                $final    = $m->isFinal()?    'final '    : '';
                                $abstract = $m->isAbstract()? 'abstract ' : '';

                                $params = array();
                                foreach ($m->getParameters() as $p) {
                                        $param = '';
                                        if ($p->isPassedByReference())
                                                $param .= '&';

                                        $param .= '$'.$p->getName();

                                        if ($p->isDefaultValueAvailable())
                                                $param .= sprintf(' = %s', preg_replace(array('#\s+#m', '#  #', '#array \(#', '#\( \)#'), array(' ', ' ', 'array(', '()'), var_export($p->getDefaultValue(), TRUE)));

                                        $params[] = $param;
                                }

                                $modifiers = trim(sprintf('%s%s%s%s', $abstract, $visibility, $static, $final));
                                $out[] = sprintf('%s: %s (%s) [% 4d % 3d]%s', $modifiers, $m->getName(), implode(', ', $params), $m->getStartLine(), $m->getEndLine() - $m->getStartLine(), $comment);
                        }

                        if (sizeof($out) > 0) {
                                foreach (array(':', '(', '[', ']', '/**') as $op)
                                        self::indent($out, $op);

                                # a bit of a hack, but it suited the situation to just stick a character in to align by and later strip it
                                foreach ($out as &$line) {
                                        $line = preg_replace('#:#', '', $line, 1);
                                        $line = preg_replace('#\[(\s*)(\d+)(\s*)(\d+)\]#', '[\1:\2\3#\4]', $line, 1);
                                }

                                echo implode("\n", $out)."\n";
                                self::drawLine();
                        }

                        echo "\n";
                }
        }

        /** List functions, supply: no args for all, a string (function name) or multiple string args (function names) **/
        public static function functions()
        {
                $type = 'Function';
                self::drawLine();
                printf("Functions\n");
                self::drawLine();
                $spec = NULL;
                if (func_num_args() == 1 && !is_array($spec = func_get_arg(0)))
                        $spec = func_get_args();
                $out = array();
                foreach(self::getReflections($spec, $type) as $f) {
                        if ($comment = $f->getDocComment()) {
                                $comment = preg_replace(array('#\s+#m', '#  #'), ' ', $comment);
                                $comment = ' '.$comment;
                        } else {
                                $comment = '';
                        }

                        $params = array();
                        foreach ($f->getParameters() as $p) {
                                $param = '';
                                if ($p->isPassedByReference())
                                        $param .= '&';

                                $param .= '$'.$p->getName();

                                if ($p->isDefaultValueAvailable())
                                        $param .= sprintf(' = %s', preg_replace(array('#\s+#m', '#  #', '#array \(#', '#\( \)#'), array(' ', ' ', 'array(', '()'), var_export($p->getDefaultValue(), TRUE)));

                                $params[] = $param;
                        }
                        $out[] = sprintf('%s (%s) [%d lines; %s:%d-%d]%s', $f->getName(), implode(', ', $params), $f->getEndLine() - $f->getStartLine(), preg_replace('#^.*/#', '', $f->getFileName()), $f->getStartLine(), $f->getEndLine(), $comment);
                }

                foreach (array('(', '[', ']', '/**') as $op)
                        self::indent($out, $op);

                echo implode("\n", $out)."\n";
                self::drawLine();
        }

        /** Wrapper around classes() and functions()  **/
        public static function all()
        {
                self::classes();
                self::functions();
        }
}

?>
