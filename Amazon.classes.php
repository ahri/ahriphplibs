<?php
/*******************************************************************************
 *
 *        Title:  Amazon
 *
 *  Description:  Class to easily search Amazon by medium
 *
 * Requirements:  PHP 5.2.0+
 *                (Node classes -- only for productLinkNode() method)
 *
 *       Author:  Adam Piper (adam@ahri.net)
 *
 *      Version:  1.3
 *
 *         Date:  2008-05-22
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

  /*
    Amazon Operations:
      ItemSearch
      ItemLookup (by ASIN)
      SimilarityLookup
      SellerListingSearch
      SellerListingLookup

      CartCreate
      CartAdd
      CartModify
      CartClear
      CartGet

      CustomerContentSearch
      CustomerContentLookup
      ListLookup
      ListSearch

      SellerLookup

      BrowseNodeLookup
      Help
      TransactionLookup


      Translation of medium to SearchIndex
        Book -> Books
        PC Game -> VideoGames
        Movie -> DVD
        TV Series ->
        Music -> Music
  */

  class Amazon {
    public static $associate_tag = '';
    public static $default_image_url = '';
    public static $default_image_width = 0;
    public static $default_image_height = 0;

    public static function productLinkUrl($asin, $offer = false) {
      return sprintf(
        'http://www.amazon.co.uk/gp/%s/%s'.
        '?'.
        'ie=UTF8'.              # why?
        '&'.
        'tag=%s'.
        '&'.
        'linkCode=%s'.          # why?
        '&'.
        'camp=1634'.            # why?
        '&'.
        'creative=6738'.        # why?
        '&'.
        'creativeASIN=%s',      # why?

        $offer? 'offer-listing' : 'product',
        $asin,
        self::$associate_tag,
        $offer? 'am2' : 'as2',
        $asin
      );
    }

    public static function productLink($asin, $name, $offer = false) {
      return sprintf('<a href="%s">%s</a>', str_replace('&', '&#38;', self::productLinkUrl($asin, $offer)), $name);
    }

    public static function productLinkNode($asin, $name, $offer = false) {
      $a = new EntityNode('a', $name, true);
      $a->href = self::productLinkUrl($asin, $offer);
      return $a;
    }
  }

  abstract class AmazonRequest {
    public static $base_url = 'http://ecs.amazonaws.co.uk/onca/xml?Service=AWSECommerceService';
    public static $aws_access_key = '';
    protected $xml;

    public function __construct($request_arr = array()) {
      $url = sprintf(
        '%s&AWSAccessKeyId=%s',
        self::$base_url,
        urlencode(self::$aws_access_key)
      );

      foreach($request_arr as $key => $val) {
        $url .= sprintf('&%s=%s', urlencode($key), urlencode($val));
      }

      $this->xml = new SimpleXmlElement(file_get_contents($url));
    }

    public abstract function getObjects();
  }

  abstract class AmazonSearch extends AmazonRequest {
    public function __construct($search) {
      parent::__construct(array(
        'Operation' => 'ItemSearch',
        'SearchIndex' => get_class($this),
        'Keywords' => $search,
        'ResponseGroup' => 'Medium'
      ));
    }

    public static function getDefaultObject() {
        $o = new stdClass();

        $o->asin = '';
        $o->image = array('url' => Amazon::$default_image_url, 'width' => Amazon::$default_image_width, 'height' => Amazon::$default_image_height);
        $o->title = '';
        $o->publisher = '';

        return $o;
    }
  }

  class VideoGames extends AmazonSearch {
    public static function getDefaultObject() {
        $o = parent::getDefaultObject();

        $o->release_date = '';

        return $o;
    }

    public function getObjects() {
      $a = array();
      foreach($this->xml->Items->Item as $i) {
        $o = self::getDefaultObject();

        $o->asin = (string)$i->ASIN;
        if(!empty($i->MediumImage->URL))
          $o->image = array('url' => (string)$i->MediumImage->URL, 'width' => (int)$i->MediumImage->Width, 'height' => (int)$i->MediumImage->Height);
        $o->title = (string)$i->ItemAttributes->Title;
        $o->publisher = (string)$i->ItemAttributes->Publisher;
        $o->release_date = (string)$i->ItemAttributes->ReleaseDate;

        $a[] = $o;
      }
      return $a;
    }
  }

  class DVD extends AmazonSearch {
    public static function getDefaultObject() {
        $o = parent::getDefaultObject();

        $o->release_date = '';

        return $o;
    }

    public function getObjects() {
      $a = array();
      foreach($this->xml->Items->Item as $i) {
        $o = self::getDefaultObject();

        $o->asin = (string)$i->ASIN;
        if(!empty($i->MediumImage->URL))
          $o->image = array('url' => (string)$i->MediumImage->URL, 'width' => (int)$i->MediumImage->Width, 'height' => (int)$i->MediumImage->Height);
        $o->title = (string)$i->ItemAttributes->Title;
        $o->publisher = (string)$i->ItemAttributes->Publisher;
        $o->release_date = (string)$i->ItemAttributes->ReleaseDate;

        $a[] = $o;
      }
      return $a;
    }
  }

  class Books extends AmazonSearch {
    public static function getDefaultObject() {
        $o = parent::getDefaultObject();

        $o->author = '';
        $o->publication_date = '';
        $o->isbn = 0;

        return $o;
    }

    public function getObjects() {
      $a = array();
      foreach($this->xml->Items->Item as $i) {
        $o = self::getDefaultObject();

        $o->asin = (string)$i->ASIN;
        if(!empty($i->MediumImage->URL))
          $o->image = array('url' => (string)$i->MediumImage->URL, 'width' => (int)$i->MediumImage->Width, 'height' => (int)$i->MediumImage->Height);
        $o->title = (string)$i->ItemAttributes->Title;
        $o->author = (string)$i->ItemAttributes->Author;
        $o->publisher = (string)$i->ItemAttributes->Publisher;
        $o->publication_date = (string)$i->ItemAttributes->PublicationDate;
        $o->isbn = (int)$i->ItemAttributes->ISBN;

        $a[] = $o;
      }
      return $a;
    }
  }

  class Music extends AmazonSearch {
    public static function getDefaultObject() {
        $o = parent::getDefaultObject();
        $o->artist = '';
        $o->label = '';
        $o->release_date = '';
        $o->image_medium = array('url' => Amazon::$default_image_url, 'width' => Amazon::$default_image_width, 'height' => Amazon::$default_image_height);
        $o->image_large = array('url' => Amazon::$default_image_url, 'width' => Amazon::$default_image_width, 'height' => Amazon::$default_image_height);

        return $o;
    }

    public function getObjects() {
      $a = array();
      foreach($this->xml->Items->Item as $i) {
        $o = self::getDefaultObject();

        $o->asin = (string)$i->ASIN;
        if(!empty($i->MediumImage->URL))
          $o->image_medium = array('url' => (string)$i->MediumImage->URL, 'width' => (int)$i->MediumImage->Width, 'height' => (int)$i->MediumImage->Height);
        if(!empty($i->LargeImage->URL))
          $o->image_large = array('url' => (string)$i->LargeImage->URL, 'width' => (int)$i->LargeImage->Width, 'height' => (int)$i->LargeImage->Height);
        $o->title = (string)$i->ItemAttributes->Title;
        $o->artist = (string)$i->ItemAttributes->Artist;
        $o->label = (string)$i->ItemAttributes->Label;
        $o->release_date = (string)$i->ItemAttributes->ReleaseDate;

        $a[] = $o;
      }
      return $a;
    }
  }
?>
