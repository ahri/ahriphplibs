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
 *                # or in PHP 5.3.0:
 *                # $html = Node::html();
 *                $head = $html->head();
 *                $body = $html->body();
 *                $empty_div = $body->div('');  # since '<div />' won't render
 *                                              # this forces '<div></div>'
 *                $inline_p = $body->p('foo bar baz', true);
 *                $head->title('Something Pertinent');
 *                echo $html;
 *
 *      Methods:  TextNode
 *                  ->__construct($content)
 *                  ->__toString()
 *                  ->getContent()
 *                  ->setContent($content)
 *
 *                Node options:
 *                  INLINED          -- do not add linebreaks
 *                  UNINDENTED       -- do not indent
 *                  UNSTRIPPED       -- do not strip whitespace
 *                  UNESCAPED        -- do not escape HTML characters
 *                  NOT_SELF_CLOSING -- do not self-close: <div /> will become <div></div>
 *                Aggregate Options:
 *                  UNMANGLED:  INLINED, UNSTRIPPED
 *                  UNTOUCHED:  INLINED, UNSTRIPPED, UNESCAPED
 *                  JAVASCRIPT: UNSTRIPPED, UNESCAPED
 *                  JS_INCLUDE:  NOT_SELF_CLOSING, INLINED
 *
 *                Node
 *                  ->__construct($name, $content = NULL, $options = Node::NORMAL)
 *                  ->__toString()
 *                  ->__call()       -- calls create new child EntityNodes
 *                  ::__callStatic() -- facilitates use of "Node::html()" instead of "new Node('html')", only in PHP 5.3.0+
 *                  ->__set()        -- vars are converted to properties
 *                  ->__get()
 *                  ->addChild(Node $node)
 *                  ->addText($text)
 *                  ->removeChild(Node $node)
 *
 *                  (note that objects of this class are iterable with foreach,
 *                  but that use of ->removeChild() while looping isn't advised)
 *
 *
 * Requirements:  PHP 5.2.0+, ideally 5.3.0+
 *
 *       Author:  Adam Piper (adamp@ahri.net)
 *
 *      Version:  2.03
 *
 *         Date:  2009-09-27
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
        # options
        const NORMAL              =  0;
        const INLINED             =  1;
        const UNINDENTED          =  2;
        const UNSTRIPPED          =  4;
        const UNESCAPED           =  8;
        const NOT_SELF_CLOSING    = 16;

        # aggregate options
        const UNMANGLED           =  7;
        const UNTOUCHED           = 15;
        const JAVASCRIPT          = 12;
        const JS_INCLUDE          = 17;

        public static $indent     = 4;
        public static $pre_indent = 0;

        abstract protected function renderLines($indent = 0, $options = Node::NORMAL);

        public function __toString()
        {
                return $this->renderLines();
        }

        protected static function getSpaces($indent)
        {
                return str_pad('', self::$indent*(self::$pre_indent + $indent));
        }
}

class Node extends NodeCommon implements Iterator
{
        private $children   = array();
        private $properties = array();
        private $options    = 0;
        private $tag        = '';

        public function __construct($tag, $content = NULL, $options = Node::NORMAL)
        {
                $this->tag = $tag;
                if (!is_null($content))
                        $this->children[] = new NodeText($content);

                if ($options === TRUE)
                        $options = parent::INLINED;

                $this->options |= $options;
                return $this;
        }

        public static function __callStatic($tag, $args) # added support for PHP 5.3.0's callStatic
        {
                $content = isset($args[0])? $args[0] : NULL;
                $options = isset($args[1])? $args[1] : 0;

                $class = __CLASS__;
                $o = new $class($tag, $content, $options);
                return $o;
        }

        public function __call($tag, $args)
        {
                $o = self::__callStatic($tag, $args);
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

        protected function renderLines($indent = 0, $options = Node::NORMAL)
        {
                $options |= $this->options;
                $self_closing = ((sizeof($this->children) == 0) && !($options & parent::NOT_SELF_CLOSING));
                $text = '';

                if (!($options & parent::INLINED)) {
                        $spaces = parent::getSpaces($indent);
                        $text .= $spaces;
                }

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

        public function addText($text)
        {
                $this->addChild(new NodeText($text));
        }

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

        static function stripper(Node $node, $input, array $allowed)
        {
                if (!($input instanceof DOMNode)) {
                        $d = new DOMDocument();
                        $d->loadHTML($input);
                        $input = $d;
                        unset($d);

                        # hacky, but DOMDocument adds <html><body>blah</body></html> around the given HTML, which I want to skip
                        $input = $input->childNodes->item(1);
                        $input = $input->childNodes->item(0);
                }

                if (!$input->hasChildNodes()) {
                        return;
                }

                $allowed_tags = array();
                $allowed_spec = array();
                foreach ($allowed as $spec) {
                        $spec = explode(' ', $spec);
                        if (!isset($allowed_spec[$spec[0]])) {
                                $allowed_tags[] = $spec[0];
                                $allowed_spec[$spec[0]] = array();
                        }

                        if (isset($spec[1]) && !in_array($spec[1], $allowed_spec[$spec[0]]))
                                $allowed_spec[$spec[0]][] = $spec[1];
                }

                foreach ($input->childNodes as $child) {
                        switch (get_class($child)) {
                                case 'DOMText':
                                        $node->addText($child->nodeValue);
                                        break;
                                case 'DOMElement':
                                        $tag = $child->tagName;
                                        if (in_array($tag, $allowed_tags)) {
                                                $childnode = $node->$tag();

                                                # iterate over attrs
                                                if ($child->hasAttributes()) {
                                                        foreach ($child->attributes as $attr) {
                                                                $attr_name = $attr->nodeName;

                                                                if (!in_array($attr_name, $allowed_spec[$tag]))
                                                                        continue;

                                                                $attr_val  = $attr->nodeValue;
                                                                $childnode->$attr_name = $attr_val;
                                                        }
                                                }
                                                self::stripper($childnode, $child, $allowed);
                                        } else {
                                                self::stripper($node, $child, $allowed);
                                        }
                                        break;
                        }
                }
        }
}

class NodeText extends NodeCommon
{
        private $content = '';

        public function __construct($content)
        {
                $this->content = $content;
        }

        protected function renderLines($indent = 0, $options = Node::NORMAL)
        {
                $content = $this->content;

                if (!($options & parent::UNSTRIPPED)) {
                        $content = preg_replace(array('#(^\s+|\s+$)#m',
                                                      '#[ \t\r]+#m'),
                                                array(' ',
                                                      ' '),
                                                $content);
                }

                if (!($options & parent::UNINDENTED)) {
                        if (!($options & parent::INLINED)) {
                                $content = preg_replace('#^#m',
                                                        parent::getSpaces($indent),
                                                        $content);
                        } else {
                                $arr = explode("\n", $content);
                                $content = $arr[0];
                                $tail = array_slice($arr, 1);
                                if (sizeof($tail) > 0) {
                                        $content .= "\n";
                                        $content .= preg_replace('#^#m',
                                                                 parent::getSpaces($indent),
                                                                 implode("\n", $tail));
                                }
                        }
                }

                if (!($options & parent::INLINED)) {
                        $content = sprintf("%s\n", $content);
                }

                if (!($options & parent::UNESCAPED)) {
                        $content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
                }

                return $content;
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
