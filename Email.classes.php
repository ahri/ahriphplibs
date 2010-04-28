<?php
/*******************************************************************************
 *
 *        Title:  Email made easy
 *
 *  Description:  Does the lifting for Email headers, will probably support
 *                attachments etc. too soon.
 *
 *         Help:  Email::$from = 'Foo <foo@bar.com>';
 *
 *                Email::quick('foo@bar.com', 'hi', 'test!');
 *
 *                $e = new Email('hi', 'test!');
 *                $e->addTo('foo@bar.com');
 *                $e->addCc('bar@foo.com');
 *                $e->send();
 *
 *      Methods:  Email::
 *                  STATIC quick($to, $subject, $content, $html = false)

 *                  __construct($to, $subject, $content)
 *                  addTo($to)
 *                  addCc($to)
 *                  addBcc($to)
 *                  addHeader($header)
 *                  send()
 *
 *                EmailHtml::
 *                  send()
 *
 *                EmailAddress::
 *                  __construct($string)
 *                  __toString()
 *                  getAddress()
 *
 * Requirements:  PHP 5.2.0+
 *
 *       Author:  Adam Piper (adamp@ahri.net)
 *
 *      Version:  0.01
 *
 *         Date:  2008-12-14
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

class Email
{
        public static $from = 'Email Script <foo@bar.com>';

        private   $subject = '';
        private   $content = '';
        private   $to      = array();
        private   $cc      = array();
        private   $bcc     = array();
        protected $headers = array();

        public static function quick($to, $subject, $content, $html = false)
        {
                if ($html)
                        $e = new EmailHtml($subject, $content);
                else
                        $e = new Email($subject, $content);

                $e->addTo($to);

                $e->send();
        }

        public function __construct($subject, $content)
        {
                $this->subject = $subject;
                $this->content = $content;
        }

        public function addTo($to)
        {
                $this->to[] = new EmailAddress($to);
        }

        public function addCc($to)
        {
                $this->cc[] = new EmailAddress($to);
        }

        public function addBcc($to)
        {
                $this->bcc[] = new EmailAddress($to);
        }

        public function addHeader($header)
        {
                $this->headers[] = $header;
        }

        public function send()
        {
                foreach ($this->to as $to) {
                        $to_str .= $to->getAddress().', ';
                        $this->addHeader('To: '.$to);
                }

                foreach ($this->cc as $cc) {
                        $to_str .= $cc->getAddress().', ';
                        $this->addHeader('CC: '.$cc);
                }

                foreach ($this->bcc as $bcc) {
                        $to_str .= $bcc->getAddress().', ';
                        $this->addHeader('BCC: '.$bcc);
                }

                mail(substr($to_str, 0, -2), $this->subject, $this->content, implode("\n", $this->headers));
        }
}

class EmailHtml extends Email
{
        public function send()
        {
                $this->addHeader('MIME-Version: 1.0');
                $this->addHeader('Content-type: text/html; charset=iso-8859-1');
                parent::send();
        }
}

class EmailAddress
{
        private $address = '';
        private $title   = '';

        public function __construct($string)
        {
                if (preg_match('#^\S+@\S+$#', $string, $m)) {
                        $address = $string;
                } else if (preg_match('#^([\S ]*) <(\S+@\S+>)$#', $string, $m)) {
                        $address = $m[2];
                        $title   = trim($m[1]);
                } else {
                        throw new EmailInputException('Email address must be in the form "foo@bar.com" or "<foo@bar.com>"');
                }
        }

        public function __toString()
        {
                if (strlen($title) > 0) {
                        return sprintf('%s <%s>', $title, $address);
                } else {
                        return $address;
                }
        }

        public function getAddress()
        {
                return $this->address;
        }
}

class EmailInputException extends Exception {}

?>
