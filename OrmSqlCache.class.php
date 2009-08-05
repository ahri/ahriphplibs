<?php

class OrmSqlCache extends Orm
{
        const marker_regex = '/^        ### NEXT FUNCTION MARKER ###$/m';
        const default_name = '_';
        # TODO Write OrmSqlCache class in own file
        #      It should be extremely basic; just a bunch of variables as lookups for SQL
        #      It should have a protected static method OrmSqlCache::add($hash, $func_args, $func_body, $name)
        #      It should have a protected static method OrmSqlCache::getRecentlyCachedBody($hash, $name) -- returns NULL on failure
        #      It should have a protected static method OrmSqlCache::getFuncName($hash, $name)
        #      It should have a public static method OrmSqlCache::save() that adds all the current and added SQL together and rewrites its own file
        private static $additions = array();

        protected static function add($hash, $func_args, $func_body, $name = NULL)
        {
                $o = (object) array('hash'      => $hash,
                                    'func_args' => $func_args,
                                    'func_body' => $func_body,
                                    'name'      => $name);

                $func_name = self::getFuncName($hash, $name);

                if (method_exists(__CLASS__, $func_name) || in_array($o, self::$additions))
                        throw new OrmException('Duplicate function added');

                self::$additions[] = $o;
        }

        protected static function getRecentlyCachedBody($hash, $name = NULL)
        {
                foreach (self::$additions as $addition)
                        if ($hash == $addition->hash && $name == $addition->name)
                                return $addition->func_body;
        }

        protected static function getFuncName($hash, $name = NULL)
        {
                if (Orm::validName(self::default_name))
                        throw new OrmException(sprintf('The Orm name "%s" is valid and must absolutely NOT be!', self::default_name));
                if (is_null($name))
                        $name = self::default_name;

                return sprintf('%s_%s', $name, $hash);
        }

        public static function save()
        {
                if (($self = file_get_contents(__FILE__)) === FALSE)
                        throw new OrmException('Could not read file %s, which is weird, because I\'m executing out of it...', __FILE__);

                if(!preg_match(self::marker_regex, $self, $m))
                        throw new OrmException(sprintf('Cannot find Next Function Marker in %s', __FILE__));

                $functions = '';
                while ($addition = array_pop(self::$additions)) {
                        $functions .= sprintf("        public static function %s(%s)\n        {\n%s\n        }\n\n",
                                              self::getFuncName($addition->hash, $addition->name),
                                              $addition->func_args,
                                              preg_replace('#^#m', '                ', $addition->func_body));
                }

                $functions .= $m[0]; # add the function marker

                #echo(preg_replace(self::marker_regex, $functions, $self, 1));die();

                if (file_put_contents(__FILE__, preg_replace(self::marker_regex, $functions, $self, 1), LOCK_EX) === FALSE) # get a lock on the file and write it
                        throw new OrmException(sprintf('Could not write file %s', __FILE__));
        }

        ############ Below this point are generated functions ###########

        ### NEXT FUNCTION MARKER ###
}

?>
