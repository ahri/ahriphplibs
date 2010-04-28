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
 *                  UNMANGLED:       INLINED, UNSTRIPPED
 *                  UNTOUCHED:       INLINED, UNSTRIPPED, UNESCAPED
 *                  SCRIPT_EMBEDDED: UNSTRIPPED, UNESCAPED
 *                  SCRIPT_INCLUDE:  NOT_SELF_CLOSING, INLINED
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
 *      Version:  2.1
 *
 *         Date:  2009-09-27
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
        const SCRIPT_EMBEDDED     = 12;
        const SCRIPT_INCLUDE      = 17;

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

        protected function renderLines($indent = 0, $parent_opts = Node::NORMAL)
        {
                $options = $parent_opts | $this->options;
                $self_closing = ((sizeof($this->children) == 0) && !($options & parent::NOT_SELF_CLOSING));
                $text = '';

                if (!($parent_opts & parent::INLINED)) {
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

                        $text .= sprintf("</%s>", $this->tag);

                        if (!($parent_opts & parent::INLINED))
                                $text .= "\n";
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
                new NodeStrip($node, $input, $allowed);
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

class NodeStrip
{
        private $allowed_tags = array();
        private $allowed_spec = array();

        public function __construct(Node $node, $input, array $allowed)
        {
                $d = new DOMDocument();
                $d->loadHTML($input);
                $input = $d;
                unset($d);

                # hacky, but DOMDocument adds <html><body>blah</body></html> around the given HTML, which I want to skip
                $input = $input->childNodes->item(1);
                $input = $input->childNodes->item(0);

                # build up the allowed tags and specs
                foreach ($allowed as $spec) {
                        $spec = explode(' ', $spec);
                        if (!isset($this->allowed_spec[$spec[0]])) {
                                $this->allowed_tags[] = $spec[0];
                                $this->allowed_spec[$spec[0]] = array();
                        }

                        if (isset($spec[1]) && !in_array($spec[1], $this->allowed_spec[$spec[0]]))
                                $this->allowed_spec[$spec[0]][] = $spec[1];
                }

                $this->stripper($node, $input);
                unset($this);
        }

        private function stripper(Node $node, $input)
        {
                if (!$input->hasChildNodes()) {
                        return;
                }

                foreach ($input->childNodes as $child) {
                        switch (get_class($child)) {
                                case 'DOMText':
                                        $node->addText($child->nodeValue);
                                        break;
                                case 'DOMElement':
                                        $tag = $child->tagName;
                                        if (in_array($tag, $this->allowed_tags)) {
                                                $childnode = $node->$tag();

                                                # iterate over attrs
                                                if ($child->hasAttributes()) {
                                                        foreach ($child->attributes as $attr) {
                                                                $attr_name = $attr->nodeName;

                                                                if (!in_array($attr_name, $this->allowed_spec[$tag]))
                                                                        continue;

                                                                $attr_val  = $attr->nodeValue;
                                                                $childnode->$attr_name = $attr_val;
                                                        }
                                                }
                                                self::stripper($childnode, $child);
                                        } else {
                                                self::stripper($node, $child);
                                        }
                                        break;
                        }
                }
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

/*
$n = new Node('div', NULL, Node::INLINED);
$n->id = 'test';
Node::stripper($n,
               '<p>The way to <a onClick="alert(\'XSS\');" href="http://google.com/search?q=produce">produce</a> an ampersand (&amp;) in HTML is to type: &amp;amp;<br> -- easy eh?</p>',
               array('p', 'a href'));

printf("%s\n", $n);
*/

?>
