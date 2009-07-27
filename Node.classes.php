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
 *         Help:  $html = new Node('html');
 *                $head = $html->head();
 *                $body = $html->body();
 *                $empty_div = $body->div('');  # since '<div />' won't render
 *                                              # this forces '<div></div>'
 *                $inline_p = $body->p('foo bar baz', true);
 *                $head->title('Something Pertinent');
 *                echo $html;
 *
 *      Methods:  TextNode::
 *                  __construct($content, $inline = false, $preserve_content = false)
 *                  __toString()
 *                  getContent()
 *                  setContent($content)
 *
 *                Node::
 *                  __construct($name, $content, $inline, $preserve_content = false)
 *                  __toString()
 *                  __call()  -- calls create new child EntityNodes
 *                  __set()   -- vars are converted to properties
 *                  __get()
 *                  addChild(Node $node)
 *                  removeChild(Node $node)
 *
 *                  (note that objects of this class are iterable with foreach,
 *                  but that use of ->removeChild() while looping isn't advised)
 *                
 *
 * Requirements:  PHP 5.2.0+
 *
 *       Author:  Adam Piper (adamp@ahri.net)
 *
 *      Version:  2.00
 *
 *         Date:  2009-07-27
 *
 *      License:  BSD (3 clause, 1999-07-22)
 *
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

class NodeException      extends Exception {}
class NodeInputException extends NodeException {}

abstract class NodeCommon
{
        const INLINED             = 1;
        const UNTOUCHED           = 3;

        public static $indent     = 4;
        public static $pre_indent = 0;

        abstract protected function renderLines($indent = 0, $options = 0);

        public function __toString()
        {
                return $this->renderLines();
        }

        protected static function getSpaces($indent)
        {
                return str_pad('', self::$indent*(self::$pre_indent + $indent));
        }
}

class Node extends NodeCommon
{
        private $children   = array();
        private $properties = array();
        private $options    = 0;
        private $tag        = '';

        public function __construct($tag, $content = NULL, $options = 0)
        {
                $this->tag = $tag;
                if (!is_null($content))
                        $this->children[] = new NodeText($content);

                if ($options === TRUE)
                        $options = parent::INLINED;

                $this->options |= $options;
                return $this;
        }

        public function __call($tag, $args)
        {
                $content = isset($args[0])? $args[0] : NULL;
                $options = isset($args[1])? $args[1] : 0;

                $class = __CLASS__;
                $o = new $class($tag, $content, $options);
                $this->children[] = $o;
                return $o;
        }

        private function propertiesAsString()
        {
                $strs = array();
                foreach ($this->properties as $key => $value)
                        $strs[] = sprintf('%s = "%s"', htmlspecialchars($key, ENT_QUOTES, 'UTF-8'), htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));

                return implode(' ', $strs);
        }

        protected function renderLines($indent = 0, $options = 0)
        {
                $options |= $this->options;
                $self_closing = (sizeof($this->children) == 0);
                $text = '';
                $spaces = parent::getSpaces($indent);

                $text .= $spaces;

                $text .= sprintf('<%s', $this->tag);

                if (sizeof($this->properties) > 0)
                        $text .= sprintf(' %s', $this->propertiesAsString());

                if ($self_closing) {
                        $text .= " />\n";
                } else {
                        $text .= '>';
                        if (!($options & parent::INLINED))
                                $text .= "\n";

                        foreach ($this->children as $child)
                                $text .= $child->renderLines($indent+1, $options);

                        if (!($options & parent::INLINED))
                                $text .= $spaces;

                        $text .= sprintf("</%s>\n", $this->tag);
                }

                return $text;
        }

        public function __set($key, $value)
        {
                $this->properties[$key] = $value;
        }

        public function __get($property)
        {
                if (!isset($this->properties[$property]))
                        throw new NodeInputException('Property '.$property.' is not set.');

                return $this->properties[$property];
        }

        public function __isset($property)
        {
                return isset($this->properties[$property]);
        }

        #Iteration
        public function rewind()
        {
                reset($this->children);
        }

        public function current()
        {
                return current($this->children);
        }

        public function key()
        {
                return key($this->children);
        }

        public function next()
        {
                return next($this->children);
        }

        public function valid()
        {
                return $this->current() !== false;
        }
        #/Iteration

        public function addChild(NodeCommon $n)
        {
                $this->children[] = $n;
                return $n;
        }

        public function removeChild(NodeCommon $n)
        {
                $found = false;
                foreach ($this->children as $i => $child) {
                        if ($n === $child) {
                                $found = true;
                                unset($this->children[$i]);
                        }
                }
                if (!$found)
                        throw new NodeInputException('Child node not found');

                return $n;
        }
 
}

class NodeText extends NodeCommon
{
        private $content = '';

        public function __construct($content)
        {
                $this->content = $content;
        }

        protected function renderLines($indent = 0, $options = 0)
        {
                if ($options == parent::UNTOUCHED) {
                        return $this->content;

                } elseif ($options & parent::INLINED) {
                        return htmlspecialchars($this->content, ENT_QUOTES, 'UTF-8');

                } else {
                        return sprintf("%s\n",
                                       preg_replace(array('#(^\s+|\s+$)#m',
                                                          '#[ \t\r]+#m',
                                                          '#^#m'),
                                                    array('',
                                                          ' ',
                                                          parent::getSpaces($indent)),
                                                    htmlspecialchars($this->content, ENT_QUOTES, 'UTF-8')));
                }
        }

        public function getContent()
        {
                return $this->content;
        }

        public function setContent($content)
        {
                $this->content = $content;
        }
}

/*
$html = new Node('html');
$html->title('inlined', true);
$body = $html->body();
$body->pre('this text ought to
be protected from
                          </>   screwing around with', true)->class = 'foo';
$body->br();
$body->p('<foo>', true);
$body->p('   <foo>
bar
                  baz');
echo $html;
*/

?>
