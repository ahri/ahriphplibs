<?php
 /******************************************************************************
 * Copyright (c) 2009, Adam Piper
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

class Test {
        private static $total_count = array();
        private static $pass_count  = array();
        private static $funcs       = array();

        # Some predefined test cases
        const RESULT_NULL      = 'return is_null($result);';
        const RESULT_TRUE      = 'return $result === true;';
        const RESULT_FALSE     = 'return $result === false;';
        const RESULT_EXCEPTION = 'return $result instanceof Exception;';

        public static function init()
        {
                set_error_handler(array('Test', 'errorHandler'));
        }

        public static function errorHandler($errno, $errstr, $errfile, $errline)
        {
                $errors = array(1 => 'E_ERROR', 2 => 'E_WARNING', 4 => 'E_PARSE', 8 => 'E_NOTICE', 16 => 'E_CORE_ERROR', 32 => 'E_CORE_WARNING', 64 => 'E_COMPILE_ERROR', 128 => 'E_COMPILE_WARNING', 256 => 'E_USER_ERROR', 512 => 'E_USER_WARNING', 1024 => 'E_USER_NOTICE', 2048 => 'E_STRICT', 4096 => 'E_RECOVERABLE_ERROR');
                if (!isset($errors[$errno])) throw new TestException("Unknown error code: $errno");
                printf("ERROR (%s): %s in %s:%s\n", $errors[$errno], $errstr, $errfile, $errline);
        }

        private static function funcIndexLookup($func)
        {
                foreach (self::$funcs as $i => $f) {
                        if ($func == $f) return $i;
                }
                throw new TestException('Could not find function index');
        }

        public static function t($name, $func, $params, $against_func_str, $hide_stack_trace = false)
        {
                # track what we've tested
                if (!in_array($func, self::$funcs)) self::$funcs[] = $func;
                $fi = self::funcIndexLookup($func);
                if (!isset(self::$total_count[$fi])) self::$total_count[$fi] = 0;
                if (!isset(self::$pass_count [$fi])) self::$pass_count [$fi] = 0;

                $result = null;
                $pstrings = array();

                foreach ($params as $i => $p) {
                        $pstrings[] = '$params['.$i.']';
                }

                if (is_array($func) && sizeof($func) == 2) {
                        switch (gettype($func[0])) {
                                case 'object':
                                        $o = $func[0];
                                        $func = '$o->'.$func[1];
                                        break;
                                case 'string':
                                        $func = $func[0].'::'.$func[1];
                                        break;
                                default:
                                        throw new TestInputException('Passed function is of unrecognisable type: '.gettype($func[0]));
                        }
                }

                try {
                        # execute the test
                        ob_start();
                        eval ('$result = '.$func.'('.implode(', ', $pstrings).');');
                        ob_end_clean(); # we're not interested in the textual output of a testee
                } catch (Exception $result) {
                        printf("EXCEPTION (%s): %s\n", get_class($result), $result->getMessage());
                        if (!$hide_stack_trace) echo $result->getTraceAsString()."\n";
                }

                try {
                        $against = create_function('$result', $against_func_str);
                        self::$total_count[$fi]++;

                        $check = $against($result);

                        if ($check === true) {
                                self::$pass_count[$fi]++;
                                printf("Passed: %s\n", $name);
                        } elseif ($check === false) {
                                printf("FAILED: %s\n", $name);
                        } else {
                                ob_start();
                                var_dump($check);
                                $check_dump = ob_get_contents();
                                ob_end_clean();
                                throw new TestInputException(sprintf('Test functions may only return true or false. Received: %s', $check_dump));
                        }
                } catch (Exception $e) {
                        # TODO: this isn't working.............
                        printf("FATAL EXCEPTION (%s): %s\n", get_class($e), $e->getMessage());
                        $e->getTraceAsString();
                        die();
                }

                return $result;
        }

        public static function returner($return)
        {
                return $return;
        }

        public static function summary()
        {
                if (func_num_args() == 0) { # no args = old style summary of all tests
                        $pass_count  = array_sum(self::$pass_count );
                        $total_count = array_sum(self::$total_count);
                        echo "--------------------------------------------------------------------------------\n";
                        echo "Summary\n";
                        echo "--------------------------------------------------------------------------------\n";
                        printf("%d/%d tests passed - %.2f%% correct\n", $pass_count, $total_count, $total_count > 0? $pass_count/$total_count*100 : 100);
                        echo "--------------------------------------------------------------------------------\n";
                } else {
                        foreach(func_get_args() as $class) {
                                $c = new ReflectionClass($class);
                                $pass_count  = 0;
                                $total_count = 0;

                                echo "--------------------------------------------------------------------------------\n";
                                printf("Class: %s (%d lines; %s:%d-%d)\n", $c->getName(), $c->getEndLine() - $c->getStartLine(), preg_replace('#^.*/#', '', $c->getFileName()), $c->getStartLine(), $c->getEndLine());
                                echo "--------------------------------------------------------------------------------\n";

                                $missed = false;

                                $mc_count = 0;
                                $mc_tested = 0;
                                foreach ($c->getMethods() as $m) {
                                        if (!$m->isPublic()) continue;
                                        $mc_count++;
                                        $m_pass_count  = 0;
                                        $m_total_count = 0;
                                        # for static methods find in self::$funcs array('class', 'method') -- case insensitive -- strcasecmp()
                                        foreach (self::$funcs as $i => $f) {
                                                if (!is_array($f))     continue;
                                                if ($m->isStatic()) {
                                                        if (!is_string($f[0])) continue;
                                                        $class_name = $f[0];
                                                } else {
                                                        if (!is_object($f[0])) continue;
                                                        $class_name = get_class($f[0]);
                                                }
                                                if (strcasecmp($class_name, $c->getName()) == 0 && strcasecmp($f[1], $m->getName()) == 0) {
                                                        $m_pass_count  = self::$pass_count [$i];
                                                        $m_total_count = self::$total_count[$i];
                                                }
                                        }

                                        if ($m_total_count == 0) {
                                                printf("NOT TESTED: %s%s%s\n", $c->getName(), $m->isStatic()? '::' : '->', $m->getName());
                                                $missed = true;
                                        } else {
                                                $mc_tested++;
                                                $pass_count  += $m_pass_count;
                                                $total_count += $m_total_count;
                                        }
                                }

                                if ($missed) echo "--------------------------------------------------------------------------------\n";
                                printf("%d/%d methods tested - %.2f%% coverage\n", $mc_tested, $mc_count, $mc_count > 0? $mc_tested/$mc_count*100 : 100);
                                printf("%d/%d tests passed - %.2f%% correct\n", $pass_count, $total_count, $total_count > 0? $pass_count/$total_count*100 : 100);
                                echo "--------------------------------------------------------------------------------\n";
                        }
                }
        }

        public static function selfTest($no_recurse = false)
        {
                # Due to recursion the following 9 tests will result in 19 tests reported

                Test::t('Initilisation',         array('Test', 'init'),         array(),                                                                Test::RESULT_NULL);
                Test::t('Error Handler (Fail)',  array('Test', 'errorHandler'), array(-1, 'Test', __FILE__, __LINE__),                                  Test::RESULT_EXCEPTION, true);
                Test::t('Error Handler',         array('Test', 'errorHandler'), array( 1, 'Test', __FILE__, __LINE__),                                  Test::RESULT_NULL);
                Test::t('Helper -- returner',    array('Test', 'returner'),     array('test'),                                                          'return $result === "test";');
                Test::t('Summary',               array('Test', 'summary'),      array(),                                                                Test::RESULT_NULL);
                Test::t('Summary of Class',      array('Test', 'summary'),      array(__CLASS__),                                                       Test::RESULT_NULL);
                Test::t('Summary of Fake Class', array('Test', 'summary'),      array('Fake Class'),                                                    Test::RESULT_EXCEPTION, true);
                Test::t('Actual test method',    array('Test', 't'),            array('Summary', array('Test', 'summary'), array(), Test::RESULT_NULL), Test::RESULT_NULL);
                if (!$no_recurse)
                Test::t('Self Test',             array('Test', 'selfTest'),     array(true),                                                            Test::RESULT_NULL);
                Test::summary('Test');
        }
}

class TestException      extends Exception     {}
class TestInputException extends TestException {}

?>
