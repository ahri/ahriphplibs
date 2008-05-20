<?php
/*******************************************************************************
 *
 *        Title:  Node - Construct valid XML dynamically
 *
 *  Description:  Idea from PHPSprockets, re-implemented for fun since they
 *                didn't reply to my suggestion for a __toString() implmentation
 *                :(
 *
 *                Also added the iterator support on EntityNode since that
 *                seemed wise, and we have some child addition/removal to boot.
 *                Yay!
 *
 *         Help:  $html = new EntityNode('html');
 *                $head = $html->head();
 *                $body = $html->body();
 *                $empty_div = $body->div('');  # since '<div />' won't render
 *                                              # this forces '<div></div>'
 *                $inline_p = $body->p('foo bar baz', true);
 *                $head->title('Something Pertinent');
 *                echo $html;
 *
 *      Methods:  TextNode::
 *                  __construct($content, inline = false)
 *                  __toString()
 *                  getContent()
 *
 *                EntityNode::
 *                  __construct($name, $content, $inline)
 *                  __toString()
 *                  __call()  -- calls create new child EntityNodes
 *                  __set()   -- vars are converted to properties
 *                  __get()
 *                  addChild(Node $node)
 *                  removeChild(Node $node)
 *
 *                  (note that objects of this class are iterable with foreach)
 *                
 *
 * Requirements:  PHP 5.2.0+
 *
 *       Author:  Adam Piper (adamp@ahri.net)
 *
 *      Version:  1.13
 *
 *         Date:  2008-05-19
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

  abstract class Node {
    public static $pre_indent = '';
    public static $indent = '  ';

    protected static function indent($size) {
      $indent = '';
      for($i = 0; $i < $size; $i++) $indent .= self::$indent;
      return self::$pre_indent.$indent;
    }

    public function __toString() {
      return $this->render();
    }

    protected abstract function render($indent_val = 0, $inline_val = 0, $preserve_content = false);
  }

  class TextNode extends Node {
    private $content = '';

    public function __construct($content = '') {
      $this->content = $content;
    }

    public function getContent() {
      return $this->content;
    }

    protected function render($indent_val = 0, $inline_val = 0, $preserve_content = false) {
      if(preg_match('#^\s*$#', $this->content)) return $inline_val && strlen($this->content) > 0? ' ' : '';

      # content
      $return = '';
      $indent = $inline_val? '' : parent::indent($indent_val);
      $last = ($size = sizeof($lines = explode("\n", $this->content))) - 1;

      for($i = 0; $i < $size; $i++) {
        $line = str_replace('  ', ' ', $lines[$i]);
        if(!$inline_val) $line = preg_replace('#^\s*#', '', $line);
        $return .= sprintf('%s%s', $indent, htmlspecialchars($line, ENT_QUOTES, 'UTF-8'));
        if($inline_val && !$preserve_content) {
          if($i != $last) $return .= ' ';
        }
        else $return .= "\n";
      }

      return $return;
    }
  }

  class EntityNode extends Node implements Iterator {
    private $tag = '';
    private $children = array();
    private $properties = array();
    private $inline = false;
    private $preserve_content = false;

    #Iteration
    public function rewind() {
      reset($this->children);
    }

    public function current() {
      return current($this->children);
    }

    public function key() {
      return key($this->children);
    }

    public function next() {
      return next($this->children);
    }

    public function valid() {
      return $this->current() !== false;
    }
    #/Iteration

    public function addChild(Node $n) {
      $this->children[] = $n;
      return $n;
    }

    public function removeChild(Node $n) {
      $found = false;
      foreach($this->children as $i => $child) {
        if($n === $child) {
          $found = true;
          unset($this->children[$i]);
        }
      }
      if(!$found) throw new Exception('Child node not found');
      return $n;
    }

    public function __construct($tag, $content = null, $inline = false, $preserve_content = false) {
      $this->tag = $tag;
      if($content !== null) $this->addChild(new TextNode($content));
      if($inline) $this->inline = true;
      if($preserve_content) $this->preserve_content = true;
    }

    public function __get($property) {
      if(!isset($this->properties[$property])) throw new Exception('Property '.$property.' is not set.');
      return $this->properties[$property];
    }

    public function __set($property, $value) {
      $this->properties[$property] = $value;
    }

    public function __isset($property) {
      return isset($this->properties[$property]);
    }

    protected function render($indent_val = 0, $inline_val = 0, $preserve_content = false) {
      $indent = parent::indent($indent_val);
      # what's the inline setting?
      if($inline_val || $this->inline) $inline_val++;

      $properties = array();
      foreach($this->properties as $p => $v)
        $properties[] = sprintf('%s="%s"', $p, htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
      $properties = sizeof($properties) > 0? ' '.implode(' ', $properties) : '';

      if(sizeof($this->children) == 0) {
        # self closing tag
        return sprintf('%s<%s%s />%s', $inline_val? '' : $indent, $this->tag, $properties, $inline_val > 0? '' : "\n");
      }
      else {
        $return = sprintf('%s<%s%s>%s', $inline_val > 1? '' : $indent, $this->tag, $properties, $inline_val? '' : "\n");

        # children
        foreach($this->children as $child)
          $return .= $child->render($indent_val+1, $inline_val > 0? $inline_val+1 : 0, $this->preserve_content);

        return $return.sprintf('%s</%s>%s', $inline_val? '' : $indent, $this->tag, $inline_val > 1? '' : "\n");
      }
    }

    public function __call($tag, $args) {
      # args: 0 => content, 1 => inline
      if(!isset($args[0])) $args[0] = null;
      if(!isset($args[1])) $args[1] = null;
      if(!isset($args[2])) $args[2] = null;
      $class = __CLASS__;
      return $this->addChild(new $class($tag, $args[0], $args[1], $args[2]));
    }
  }

  /* Tests
  $html = new EntityNode('html');
  $head = $html->head('foo');
  $head->bar = 'baz';
  $head->title('foo', true);
  $bah = $head->bah();
  $bah->wah = 'fah';

  $body = $html->body("foo    bar & <test>
    baz waz
    doo
    dah");

  $form = $body->form();
  $form->method = 'POST';
  $form->action = '';
  $input = $form->input();
  $input->type = 'text';
  $input->name = 'foo';
  $submit = $form->input();
  $submit->type = 'submit';
  $submit->value = 'Go!';

  $form->addChild(new TextNode('foo'));

  $u = $body->u(null, true);
  $b = $u->b('bold & underlined');

  #XmlNode::$indent = "\t";
  echo $html;

  $html->removeChild($body);
  echo $html;

  foreach($form as $node) {
    printf("%s\n", get_class($node));
  }

  $e = new EntityNode('foo');
  $e->bar(null, true)->baz('moo')->roo();
  echo $e;
  */
?>
