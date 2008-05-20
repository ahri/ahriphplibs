<?php
/*******************************************************************************
 *
 *        Title:  Parser
 *
 *  Description:  Class to format decorated text into a Node tree, usable as
 *                XHTML or any other XML-derived output
 *
 * Requirements:  PHP 5.2.0+
 *                Node classes
 *
 *       Author:  Adam Piper (adamp@ahri.net)
 *
 *      Version:  1.21
 *
 *         Date:  2008-05-17
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

  class Parser {
    private static $tag_data = array();     #objects
    private static $start_pts = array();    #chars
    private static $reserved = array('\\', '|', "\0", "\n");
    public static $debug = false;

    private function __construct() {}

    public static function addTag($start, $end, $func, $args = 0) {
      if(!is_string($start) || strlen($start) != 1) throw new ParserInputException('$start must be a string of length 1');
      if(!is_string($end) || strlen($end) != 1) throw new ParserInputException('$end must be a string of length 1');
      if(!is_callable($func)) throw new ParserInputException('$func must be callable by PHP');
      if(!is_int($args)) throw new ParserInputException('$args must be an integer');


      if(in_array($start, self::$reserved) || in_array($end, self::$reserved)) throw new ParserInputException('Sorry, but the pipe "|", newline (\\n),  null (\\0) and backslash (\\) are reserved characters');

      if(in_array($start, self::$start_pts)) throw new ParserInputException('That $start has already been registered');

      self::$start_pts[] = $start;

      self::$tag_data[$start] = new stdClass();
      self::$tag_data[$start]->end = $end;
      self::$tag_data[$start]->func = $func;
      self::$tag_data[$start]->args = $args;
    }

    public static function format($str) {
      if(self::$debug) echo "<pre>\n";
      $str = preg_replace(array("#\r#", "#\n\n#", '#  #', "# \n#", "#\n$#"), array('', "\n", ' ', "\n", ''), $str);

      $start_stack = array();  #objects
      $cur_end = '';
      $cur_args = 0;
      $escape = false;
      $last_char = '';
      $char_buffer = '';
      $char_node_buffer = array(); # chars and nodes

      $cur = new stdClass();
      $cur->pos = 0;
      $cur->char = "\0";
      $cur->string_buffer = '';
      $cur->node_buffer = array();

      $last_i = ($strlen = strlen($str)) - 1;
      for($i = 0; $i < $strlen; $i++) {
        $c = substr($str, $i, 1);

        if($char_buffer != '') {
          /*
          if(($c == "\r" || $c == "\n") && $char_buffer == ' ') {
            if(self::$debug) echo "DEBUG: not buffering space before newline\n";
          }
          else {
          */
            if(self::$debug) printf("DEBUG: buffer increase (%03d: %s)\n", ord($char_buffer), $char_buffer);

            $cur->string_buffer .= $char_buffer;
            $char_buffer = '';
          /*
          }
          */
        }

        # sort out escapeage
        $escape = ($last_char == '\\')? true : false;

        if($c == '\\') {
          if(self::$debug) echo "DEBUG: ignoring escape character (\\) for next loop to pick up\n";
        }
        /*
        elseif($c == "\r") {
          if(self::$debug) echo "DEBUG: ignoring return character (\\r)\n";
          continue; # to avoid $last_char being set
        }
        elseif($c == ' ' && $last_char == ' ') {
          if(self::$debug) echo "DEBUG: ignoring multiple spaces\n";
        }
        elseif($c == "\n" && $last_char == "\n") {
          if(self::$debug) echo "DEBUG: ignoring multiple newline\n";
        }
        elseif($c == "\n" && $i == $last_i) {
          if(self::$debug) echo "DEBUG: ignoring newline at end of string\n";
        }
        */
        elseif($c == "\n") {
          if(self::$debug) echo "DEBUG: newline\n";

          $tmp_buffer = '';
          while($popped = array_pop($start_stack)) {
            $tmp_buffer .= $popped->string_buffer;
            $cur = $popped->parent;
          }

          $cur->string_buffer .= $tmp_buffer;

          if(strlen($cur->string_buffer) > 0) {
            $cur->node_buffer[] = new TextNode($cur->string_buffer);
            $cur->string_buffer = '';
          }

          $cur_end = '';

          $cur->node_buffer[] = $c;
        }
        elseif($c == '|' && $cur_args > 0 && !$escape) { #args
          $cur_args--;
          $char_buffer = $c;
        }
        elseif($c == $cur_end && !$escape) { #end
          if(self::$debug) {
            echo "DEBUG: end ($c)\n";
            foreach($start_stack as $place => $object) printf("[%d] = '%s'\n", $place, $object->char);
            echo "------------------------------------------------------\n";
          }

          # add the string_buffer to the node_buffer
          if(strlen($cur->string_buffer) > 0) {
            $cur->node_buffer[] = new TextNode($cur->string_buffer);
            $cur->string_buffer = '';
          }

          if($node = call_user_func(self::$tag_data[$cur->char]->func, $cur->node_buffer)) { # success
            if(strlen($cur->parent->string_buffer) > 0) {
              $cur->parent->node_buffer[] = new TextNode($cur->parent->string_buffer);
              $cur->parent->string_buffer = '';
            }

            # add to the parent's node buffer
            $cur->parent->node_buffer[] = $node;
          }
          else { # failure
            # reset $i
            $i = $cur->pos + 1;

            # add start char to parent string buffer
            if(isset($cur->parent)) {
              $cur->parent->string_buffer .= $cur->char;
            }
          }

          # make sure the stack is in order
          $cur = array_pop($start_stack);
          $cur = $cur->parent;
          $cur_args = 0;
          $cur_end = isset(self::$tag_data[$cur->char])? self::$tag_data[$cur->char]->end : '';

          if(self::$debug) {
            foreach($start_stack as $place => $object) printf("[%d] = '%s'\n", $place, $object->char);
            echo "------------------------------------------------------\n";
          }
        }
        elseif($cur_args == 0 && in_array($c, self::$start_pts) && !$escape) { #start
          if(self::$debug) {
            echo "DEBUG: start ($c)\n";
            echo "Stack Transition:\n";
            echo "------------------------------------------------------\n";
            foreach($start_stack as $place => $object) printf("[%d] = '%s'\n", $place, $object->char);
            echo "------------------------------------------------------\n";
          }

          $parent = $cur;

          $cur = new stdClass();
          $cur->parent = $parent;
          $cur->char = $c;
          $cur->pos = $i;
          $cur->string_buffer = '';
          $cur->node_buffer = array();

          $start_stack[] = $cur;
          $cur_args = self::$tag_data[$c]->args;
          $cur_end = self::$tag_data[$c]->end;

          if(self::$debug) {
            foreach($start_stack as $place => $object) printf("[%d] = '%s'\n", $place, $object->char);
            echo "------------------------------------------------------\n";
          }
        }
        else {
          $char_buffer = $c;
        }
        $last_char = $c;
      }

      if($char_buffer != '') {
        if(self::$debug) printf("DEBUG: buffer increase (%03d: %s)\n", ord($char_buffer), $char_buffer);

        $cur->string_buffer .= $char_buffer;
        $char_buffer = '';
      }

      if(strlen($cur->string_buffer) > 0) {
        $cur->node_buffer[] = new TextNode($cur->string_buffer);
        $cur->string_buffer = '';
      }

      $root = new EntityNode('div');
      $root->class = 'parsed';
      $para = $root->p(null, true);

      foreach($cur->node_buffer as $node) { 
        if($node == "\n") $para = $root->p(null, true);
        else              $para->addChild($node);
      }

      if(self::$debug) echo "</pre>\n";
      return $root;
    }
  }

  class ParserException extends Exception {}
  class ParserInputException extends ParserException {}

  /*
  class Formatting {
    # $nodes should only contain Nodes
    public static function emphasized($nodes) {
      $n = new EntityNode('i', null, true);

      foreach($nodes as $node) {
        if(!($node instanceof Node)) throw new Exception('Sorry; can\'t parse anything but Nodes, certainly not '.gettype($node));
        $n->addChild($node);
      }

      return $n;
    }

    # $nodes should only contain Nodes
    public static function heading($nodes) {
      $n = new EntityNode('h4', null, true);

      foreach($nodes as $node) {
        if(!($node instanceof Node)) throw new Exception('Sorry; can\'t parse anything but Nodes, certainly not '.get_class($node));
        $n->addChild($node);
      }

      return $n;
    }

    # $nodes should only contain Nodes
    public static function strong($nodes) {
      $n = new EntityNode('strong', null, true);

      foreach($nodes as $node) {
        if(!($node instanceof Node)) throw new Exception('Sorry; can\'t parse anything but Nodes, certainly not '.get_class($node));
        $n->addChild($node);
      }

      return $n;
    }

    # $nodes should only contain Nodes
    public static function struckout($nodes) {
      $n = new EntityNode('strike', null, true);

      foreach($nodes as $node) {
        if(!($node instanceof Node)) throw new Exception('Sorry; can\'t parse anything but Nodes, certainly not '.get_class($node));
        $n->addChild($node);
      }

      return $n;
    }

    # $nodes should only contain Nodes
    public static function url($nodes) {
      $n = new EntityNode('a', null, true);

      if(!isset($nodes[0]) || !($nodes[0] instanceof TextNode)) return;

      preg_match('#^([^\|]+)\|?(.*)#', trim($nodes[0]), $m);
      
      if(!isset($m[1])) return;
      $n->href = $m[1];

      if((isset($m[2]) && !empty($m[2])) || sizeof($nodes) > 1) $nodes[0] = new TextNode($m[2]);

      foreach($nodes as $node) {
        if(!($node instanceof Node)) throw new Exception('Sorry; can\'t parse anything but Nodes, certainly not '.get_class($node));
        $n->addChild($node);
      }

      return $n;
    }

    # $nodes should only contain Nodes
    public static function img($nodes) {
      $n = new EntityNode('img', null, true);

      if(!isset($nodes[0]) || !($nodes[0] instanceof TextNode)) return;

      preg_match('#^([^\|]+)\|?(.*)#', trim($nodes[0]), $m);
      
      if(!isset($m[1])) return;
      $n->src = $m[1];
      $n->alt = $m[1];

      if(isset($m[2]) && !empty($m[2])) {
        $align = strtolower($m[2]);
        $n->class = 'align-'.$align;
      }

      return $n;
    }
  }

  require_once('Node.classes.php');
  $test = "*Th\ne* _quick, [brown.com#anchor|_red_]_ fox *jumps*\n over <foo> \#<bar|baz> <morebar\|morebaz> {the} #lazy# dog.\n";
  Parser::addTag('{', '}', array('Formatting', 'heading'));
  Parser::addTag('#', '#', array('Formatting', 'struckout'));
  Parser::addTag('[', ']', array('Formatting', 'url'), 1);
  Parser::addTag('<', '>', array('Formatting', 'img'), 1);
  Parser::addTag('_', '_', array('Formatting', 'emphasized'));
  Parser::addTag('*', '*', array('Formatting', 'strong'));
  Node::$pre_indent = '  ';
  #Parser::$debug = true;
  echo Parser::format($test);
  echo $test;
  */
?>
