<?php
/*******************************************************************************
 *
 *        Title:  Templater
 *
 *  Description:  A really basic templating engine, use rewriting to point to
 *                this script.
 *
 *         Help:  Must define the following constants in settings ini file
 *                (defaults to templater_settings.ini):
 *                  template      -- HTML template file, see last few lines of
 *                                                       this file for variables
 *                  default_page  -- Which page to display if no page is
 *                                                                     specified
 *                  includes      -- a php script to include your libs
 *
 *
 *     Optional:  Define O_DEN, O_UNK, O_404 as the files you want to use to
 *                                                       override error messages
 *                e.g.
 *                  define('O_404', 'o_404.inc.php');
 *                    o_404.inc.php contains:
 *                      <?php $__content = 
 *                                "Sorry we don't have a file by that name!"; ?>
 *
 *  Definitions:  Simple File Name: Name made up of alphanumerics, numbers,
 *                 dashes, slashes and underscores followed by a short extension
 *
 *                e.g. simple_file_name.html, file.txt, script.php
 *
 *                DENIED: file.inc.php, foo
 *
 *         Tips:  Want to override the title? Just define an includes file and
 *                alter $__title as you see fit.
 *
 *                Use $__request to...
 *
 *                If you want to add more to the template without hacking this
 *                script just use the %Globals% array;
 *                  e.g.1. add %Globals foo% to your html template and declare a
 *                                variable $foo somewhere like the includes file
 *                  e.g.2. add %Globals foo,bar% and declare $foo['bar'] =
 *                                                                     'foobar';
 *
 *                Want to call a function? %Function foo bar,baz%
 *
 *                If you want exceptions (and errors) to be quietly emailed to
 *                an admin, define EXCEPTION_EMAIL_TO as your email address.
 *
 *                Add function names to the arrays $__on_entry and $__on_exit
 *                for expected behaviour.
 *
 *        Notes:  The page title is merely a captitalized-by-word version of
 *                Simple File Name, with underscores replaced for spaces.Only
 *                .txt, .html and .php extensions are supported out-of-the-box.
 *                This is unlikely to change.
 *
 *                Won't allow recursed output of self
 *                Won't allow output of files with the same name as the template
 *                Won't allow output of files that don't have a Simple File Name
 *                NB. The above implies that foo.inc.php will be DENIED because
 *                                                       its `name' is "foo.inc"
 *
 *       Apache:  mod_rewrite directives to pass all root dir requests:
 *                  (because then /images/foo.png is still readable...)
 *
 *                  RewriteEngine on
 *                  # So long as it's a file (or the index)
 *                  RewriteCond %{REQUEST_FILENAME} -f [OR]
 *                  RewriteCond %{REQUEST_URI} ^/$
 *                  # and it's not a special file that robots/browsers look for
 *                  RewriteCond %{REQUEST_URI} !^/(robots.txt|favicon.ico)
 *                  # and it's not circular
 *                  RewriteCond %{REQUEST_URI} !^/templater.php$
 *                  # and it's in the root directory?
 *                  RewriteCond %{REQUEST_URI} !^/.+/.*$
 *                    RewriteRule .* /templater.php [L]
 *
 * Requirements:  PHP 5.2.0+
 *
 *       Author:  Adam Piper (adamp@ahri.net)
 *
 *      Version:  2.0.5
 *
 *         Date:  2008-05-20
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

  # Because I think that PHP's error handling is crap I'm wrapping it in the nice new Exception handling -- ah! taste the delicious traces
  function __templaterErrorHandler($errno, $errstr, $errfile, $errline) {
    $errors = array(1 => 'E_ERROR', 2 => 'E_WARNING', 4 => 'E_PARSE', 8 => 'E_NOTICE', 16 => 'E_CORE_ERROR', 32 => 'E_CORE_WARNING', 64 => 'E_COMPILE_ERROR', 128 => 'E_COMPILE_WARNING', 256 => 'E_USER_ERROR', 512 => 'E_USER_WARNING', 1024 => 'E_USER_NOTICE', 2048 => 'E_STRICT', 4096 => 'E_RECOVERABLE_ERROR');
    throw new Exception(sprintf('Handling %s: %s in %s:%s.', $errors[$errno], $errstr, $errfile, $errline));
  }
  set_error_handler('__templaterErrorHandler');

  # it seems like a good idea to just supply UTF8, maybe this'll come back to bite me...
  header('Content-Type: text/html; charset=UTF-8');

  $__settings = parse_ini_file('templater_settings.ini');
  define('TEMPLATE', $__settings['template']);
  define('DEFAULT_PAGE', $__settings['default_page']);

  # includes file is optional
  if(isset($__settings['includes'])) define('INCLUDES', $__settings['includes']);
  unset($__settings);

  # Something you might want to change, but probably shouldn't
  define('FILE_NAME_REQ',     '#^[A-z0-9_/-]+\.[A-z]{1,5}$#');  # Regular expression to match against filename

  define('TYPE_DENIED',       0);
  define('TYPE_UNKNOWN',      1);
  define('TYPE_NONEXISTENT',  2);
  define('TYPE_TEXT',         3);
  define('TYPE_HTML',         4);
  define('TYPE_SCRIPT',       5);

  preg_match('#^/([^\?]*)#', isset($_SERVER['REDIRECT_URL'])? $_SERVER['REDIRECT_URL'] : $_SERVER['REQUEST_URI'], $m);
  $__request = $m[1];
  unset($m);
  $__startt = microtime(true);

  if(!isset($__request) || empty($__request)) $__request = DEFAULT_PAGE;

  if(is_readable(TEMPLATE))   $__template = file_get_contents(TEMPLATE);
  else                        die('Template Unavailable.');

  $type = TYPE_UNKNOWN;
  preg_match('#(\..+)$#', $__request, $m);
  # Detection of filetypes; just add case statements to support more
  if(sizeof($m) > 0) {
    switch($m[1]) {
      case '.txt':
        $type = TYPE_TEXT;
        break;
      case '.html':
        $type = TYPE_HTML;
        break;
      case '.php':
        $type = TYPE_SCRIPT;
        break;
    }
  }
  else $type = TYPE_UNKNOWN;
  unset($m);

  if(!is_readable($__request)) $type = TYPE_NONEXISTENT;

  # Alteration: decided to begin allowing slashes (ie. directories)
  if(/*preg_match('#/#', $__request) ||*/ !preg_match(FILE_NAME_REQ, $__request) || in_array($__request, array(basename(__FILE__), basename(TEMPLATE)))) $type = TYPE_DENIED;

  # Format the title from the filename
  $__title = $__request;
  $__title = str_replace(array('_', '/'), array(' ', ' / '), preg_replace('#(.+)\..{1,5}#', '$1', $__title));
  $__title = ucwords(trim($__title));

  # Create variables
  $__root = $__head = $__script = $__onload = $__content = '';
  $__on_entry = array();
  $__on_exit = array();

  defined('INCLUDES') && require_once(INCLUDES);
  foreach($__on_entry as $__on_entry_func) $__on_entry_func();
  switch($type) {
    case TYPE_DENIED:
      header('HTTP/1.0 403 Forbidden');
      (defined('O_DEN') && require_once(O_DEN)) || $__content = 'Denied: '.$__request;
      break;
    case TYPE_UNKNOWN:
      header('HTTP/1.0 403 Forbidden');
      (defined('O_UNK') && require_once(O_UNK)) || $__content = 'Unknown filetype.';
      break;
    case TYPE_NONEXISTENT:
      header('HTTP/1.0 404 Not Found');
      (defined('O_404') && require_once(O_404)) || $__content = 'The page does not exist.';
      break;
    case TYPE_TEXT:
      $__content = '
<!-- Style hack reference: http://cheeaun.phoenity.com/weblog/2005/06/whitespace-and-generated-content.html -->
<span class="plaintext" style="
  white-space: pre; /* CSS2 */
  white-space: -moz-pre-wrap; /* Mozilla */
  white-space: -hp-pre-wrap; /* HP printers */
  white-space: -o-pre-wrap; /* Opera 7 */
  white-space: -pre-wrap; /* Opera 4-6 */
  white-space: pre-wrap; /* CSS 2.1 */
  white-space: pre-line; /* CSS 3 (and 2.1 as well, actually) */
  word-wrap: break-word; /* IE */
">'.file_get_contents($__request).'</span>';
      break;
    case TYPE_HTML:
      $__content = file_get_contents($__request);
      break;
    case TYPE_SCRIPT:
      unset($type);
      ob_start();
      try {
        require_once($__request);
        if(strlen($str = ob_get_contents()) > 0) $__content = &$str;
        else                                     $__content = 'Script returned no content';
        unset($str);
      }
      catch(Exception $e) {
        $__content = (defined('EXCEPTION_MAIL_TO') && mail(EXCEPTION_MAIL_TO, $_SERVER['SERVER_NAME'].' Exception', sprintf("%s\n%s\n%s", $e->getMessage(), $e->getTraceAsString(), print_r($GLOBALS, true))))?
          "We're very sorry; an error has occurred. An admin has been notified and will attend to it in due course."
          : sprintf("An exception has occurred: <pre>%s\n%s</pre>", $e->getMessage(), $e->getTraceAsString());
      }
      ob_end_clean();
      break;
    default:
      $__content = "Unknown value for \$type: $type.";
      break;
  }

  # Substitute %Globals _SERVER,URL% or whatever with the relevant variable from $GLOBALS with a quick regexp and callback
  # eg. Running on "%Globals _SERVER,SERVER_NAME%" using %Globals _SERVER,SERVER_SOFTWARE%
  $__template = preg_replace_callback(
    '#%Globals (.+?)%#',
    create_function('$m', '$arr = explode(\',\', $m[1]); $var = $GLOBALS; while(sizeof($arr) > 0) { $index = array_shift($arr); if(!isset($var[$index])) { return false; } $var = $var[$index]; } return $var;'),
    $__template
  );

  $__template = preg_replace_callback(
    '#%Function ([^ %]+) *([^%]*)%#',
    create_function('$m', 'return call_user_func_array($m[1], explode(\',\', $m[2]));'),
    $__template
  );

  $__endt = microtime(true);

  foreach($__on_exit as $__on_exit_func) $__on_exit_func();
  echo str_replace(array('%Title%', '%Root%', '%Head%', '%Script%', '%OnLoad%', '%Content%', '%Time%'), array($__title, $__root, $__head, $__script, $__onload, $__content, $__timetaken = round($__endt-$__startt, 3)), $__template);
?>
