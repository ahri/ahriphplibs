<?php
error_reporting(E_ALL | E_STRICT);
require_once('Exception.classes.php');
require_once('TLO.classes.php');
require_once('SSql.classes.php');
require_once('simpletest/autorun.php');

class Foo extends TLO
{
        public $one;
}

class Blog extends TLO
{
        public $title;
        public $content;
}

class Attached extends TLORelationship
{
        /** Tell TLO which side of the relationship contains only one object **/
        public static function relationOne()  { return 'Blog'; }
        /** Tell TLO which side of the relationship contains many objects **/
        public static function relationMany() { return 'Link'; }
        /** You could read this as "There may be many Links per Blog" **/
}

class Link extends TLO
{
        public $url;
}

class TestReadMe extends UnitTestCase
{
        public function testAbstract()
        {
                $this->assertEqual(TLO::sqlCreate('Foo'), "CREATE TABLE foo (id CHAR(40), one VARCHAR(25), PRIMARY KEY (id));\n");
        }

        public function testExample1()
        {
                ob_start();

                $this->assertEqual(TLO::sqlCreate('Blog'), "CREATE TABLE blog (id CHAR(40), title VARCHAR(25), content VARCHAR(25), PRIMARY KEY (id));\n");
                $this->assertEqual(TLO::sqlCreate('Link'), "CREATE TABLE link (id CHAR(40), url VARCHAR(25), attached__key__id CHAR(40), PRIMARY KEY (id));\n");

                $this->assertIsA($db = new PDO('sqlite::memory:'), 'PDO');
                $db->exec(TLO::sqlCreateAll());
                TLO::init();

                $this->assertIsA($blog1 = TLO::newObject($db, 'Blog'), 'Blog');
                $blog1->title = "News sites";
                $blog1->content = "I like reading news websites, attached to
                                   this blog are some of my favourites";
                $blog1->write();

                foreach (array('http://news.bbc.co.uk',
                               'http://news.yahoo.com') as $url) {
                        /*
                         * Create the link
                         */
                        $this->assertIsA($link = TLO::newObject($db, 'Link'), 'Link');
                        $link->url = $url;
                        $link->write();

                        /*
                         * Now create a relationship between the blog
                         * and the link
                         */
                        $this->assertIsA($blog1->newRelMany('Attached', $link), 'Attached');
                }

                $this->assertIsA($blog2 = TLO::newObject($db, 'Blog'), 'Blog');
                $blog2->title = "Social media";
                $blog2->content = "I recently discovered lots of 'social media'
                                   websites where people link lots of
                                   interesting articles. See attached for some
                                   examples of this.";
                $blog2->write();

                foreach (array('http://reddit.com',
                               'http://news.ycombinator.com/') as $url) {
                        /*
                         * Create the link
                         */
                        $this->assertIsA($link = TLO::newObject($db, 'Link'), 'Link');
                        $link->url = $url;
                        $link->write();

                        /*
                         * Now create a relationship between the blog
                         * and the link
                         */
                        $this->assertIsA($blog2->newRelMany('Attached', $link), 'Attached');
                }


                foreach (TLO::getObjects($db, 'Blog') as $blog) {
                        $this->assertIsA($blog, 'Blog');
                        printf("Title: %s<br/>\n", $blog->title);
                        printf("Content: %s<br/>\n", $blog->content);
                        printf("<ol>Attachments:\n");
                        foreach ($blog->getRelsMany('Attached') as $attached) {
                                $this->assertIsA($attached, 'Attached');
                                $this->assertIsA($link = $attached->getRelation(), 'Link');
                                printf("<li>%s</li>\n", $link->url);
                        }
                        printf("</ol>\n");
                }
                printf("<br/>\n\n");

                $output = ob_get_contents();
                ob_end_clean();

                $this->assertEqual($output, "Title: News sites<br/>
Content: I like reading news websites, attached to
                                   this blog are some of my favourites<br/>
<ol>Attachments:
<li>http://news.bbc.co.uk</li>
<li>http://news.yahoo.com</li>
</ol>
Title: Social media<br/>
Content: I recently discovered lots of 'social media'
                                   websites where people link lots of
                                   interesting articles. See attached for some
                                   examples of this.<br/>
<ol>Attachments:
<li>http://reddit.com</li>
<li>http://news.ycombinator.com/</li>
</ol>
<br/>

");
        }
}

?>
