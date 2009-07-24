<?php
 /******************************************************************************
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

# Due to technology constraints we can't implement variable overriding (as redeclaration is undetectable)
#
# loading:
#         care only about property names
#         names higher up the heirarchy (where the top is Orm) take absolute precidence over those lower down
#         this enables efficient retrieval of the values from the db
#
# saving:
#         care about all properties and their values
#         names higher up the heirarchy (where the top is Orm) take absolute precidence over those lower down
#         this enables us to insert/update to multiple tables with all values set
#
# properties in the immediate decendant should be global and supreme??
# better idea; just don't include the immediate descendant in our collection
#
#
# examples of tree heirarchies for overriding variables:
#
# 1) Orm -> OrmClass -> A -> B
# 2)                      -> C -> D
# 3)                           -> E
# 4)                      -> F -> G
#
# What if?
# A=2, B=NULL, C=NULL, D=5, E=NULL, F=7, G=2
# 1) load:    select A table
#    save:    update A table, insert B table
# 2) load:    INVALID
#    save:    INVALID
# 3) load:    INVALID
#    save:    update A table, insert C table, insert E table
# 4) load:    select A table, select F table, select G table
#    save:    update A table, udpate F table, update G table


##################
# notes for docs
# Inherits is a special relationship
# Inherits is a one-way rule
# anchor alias (0)
# user defined keys; CLASS::CLASS_keys
# relationships don't have to be instanciable unless you want to store variables in them. classes do.
#
# db structure:
#     class as table
#     fk rels as alias__key__key
#     fk rel vars as alias__var__var
#
##################

# TODO: find all instances of OrmClass and refer them to Orm::class_name

# TODO: move from array_shift() to array_pop() for performance; array_reverse() once is acceptable to facilitate this

# TODO: implement Orm::load() and OrmClass->save()
#                            possibly with getHeirarchy() or maybe just with getProperties() ? getHeirarchy is cheaper. put in OrmClass.


# TODO: make sure that thrown exceptions in private methods of Orm and direct descendants are of type OrmException, not of OrmInputException, cos it's us doing things wrong.

# TODO: create Orm::getClasses('Blog') style thingie to load up all blogs
# TODO: OrmClass->getRelations('AuthoredBy') -- work out whether we have classes to represent the relations for that rule (blog -> author or author->blog -- prefer OTM i guess??) and grab that shit too
# TODO: OrmClass and OrmRelationship need to know what Orm Name to use

# get rid of OrmClass->getId() -- superceded by Orm->getKeyValues('Blog') (should probably move this to OrmClass) ------- it's not superceded because "id" is special; can have many ids for a heirarchy of objects
# TODO: need a variable in OrmClass and OrmRelationship tracking whether it's been loaded from the DB. suggest private Orm->fromDb = false, and accompanying accessors (might have to stick the vars in OrmClass/OrmRelationship though to make em private...)    ----- ALREADY THERE AS OrmClass->synced (needs re-implementing now that SQL has all changed)

# TODO: consider Author->newRelation('AuthoredBy') returning object: Class => Book, Relationship => AuthoredBy (if AuthoredBy is class)
# TODO: write ->addRelation() passing in an object: Class => Book, Relationship => AuthoredBy (if AuthoredBy is a class)

# TODO: neaten up the classes, yo.

# TODO: abstract class support in the class heirarchy (abstract class stuff ends up being part of the next concrete class)

# 

abstract class Orm
{
        const default_name           = '-';
        const relationship_inherits  = 'Inherits';
        const anchor_alias           = '0';
        const auto_property_id       = 'id';
        const orm_class_sql_cache    = 'OrmSqlCache';
        const orm_class_class        = 'OrmClass';
        const orm_class_relationship = 'OrmRelationship';

        private static $sql_resource_cache = array();

        private static $schemas = array();

        public final static function setup($schema, $ssql_name = NULL, $name = NULL)
        {
                if (self::validName(self::default_name))
                        throw new OrmException('The default_name constant must NOT be a valid name!');

                self::name($name);
                if (!SSql::validName($ssql_name))
                        throw new OrmInputException('Invalid SSql Name specified, please ensure you create an SSql connection prior to setting up Orm');

                self::$schemas[$name] = (object) array(
                        'rules'     => self::parseRules($schema),
                        'ssql_name' => $ssql_name
                );

                self::$schemas[$name]->classes        = array();
                self::$schemas[$name]->relationships  = array();
                self::$schemas[$name]->irelationships = array();

                foreach (self::$schemas[$name]->rules as $rule) {
                        self::validClassName($rule->input);
                        self::validClassName($rule->output);
                        self::validClassName($rule->alias);


                        if ($rule->alias == self::relationship_inherits)
                                throw new OrmInputException(sprintf('The relationship "%s" is reserved', self::relationship_inherits));

                        if (class_exists($rule->input)) {
                                if (!is_subclass_of($rule->input, self::orm_class_class))
                                        throw new OrmInputException(sprintf('The specified class %s exists but is not a subclass of OrmClass',    $rule->input ));

                                self::$schemas[$name]->classes[] = $rule->input;
                        }

                        if (class_exists($rule->output)) {
                                if (!is_subclass_of($rule->output, self::orm_class_class))
                                        throw new OrmInputException(sprintf('The specified class %s exists but is not a subclass of OrmClass',    $rule->output));

                                self::$schemas[$name]->classes[] = $rule->output;
                        }

                        if (in_array($rule->alias, self::$schemas[$name]->relationships))
                                throw new OrmInputException(sprintf('Relationships must be unique; "%s" is already registered', $rule->alias));

                        self::$schemas[$name]->relationships[] = $rule->alias;
                        if (class_exists($rule->alias)) {
                                if (!is_subclass_of($rule->alias, self::orm_class_relationship))
                                        throw new OrmInputException(sprintf('The specified class %s exists but is not a subclass of OrmRelationship', $rule->alias));

                                self::$schemas[$name]->irelationships[] = $rule->alias;
                        }
                }

                self::$schemas[$name]->classes       = array_unique(self::$schemas[$name]->classes);
                self::$schemas[$name]->relationships = self::$schemas[$name]->relationships;

        }

        # returns true only if registered and instanciable
        protected static function isClass($name, $class)
        {
                self::name($name);
                return in_array($class, self::$schemas[$name]->classes);
        }

        # returns true only if registered and instanciable
        protected static function isIRelationship($name, $relationship)
        {
                self::name($name);
                return in_array($relationship, self::$schemas[$name]->irelationships);
        }

        # returns true only if registered
        protected static function isRelationship($name, $relationship)
        {
                self::name($name);
                return in_array($relationship, self::$schemas[$name]->relationships);
        }

        protected static function validName($name)
        {
                if(!is_null($name) && (!is_string($name) || preg_match('#[^a-zA-Z0-9_]#', $name) || preg_match('#^[^a-zA-Z]#', $name)))
                        return false;

                return true;
        }

        # for use when the arrays are going to be accessed directly, both checks the name and substitues the default value in where required
        private static function name(&$name)
        {
                if(!self::validName($name))
                        throw new OrmInputException('$name must be a string of alphanumerics and underscores, starting with a letter. You passed '.$name);

                if (is_null($name))
                        $name = self::default_name;
        }

        public static final function getRules($name = NULL)
        {
                self::name($name);
                if (!isset(self::$schemas[$name]))
                        throw new OrmInputException('Invalid Orm name specified');

                return self::$schemas[$name]->rules;
        }


        public static final function getSSqlName($name = NULL)
        {
                self::name($name);
                if (!isset(self::$schemas[$name]))
                        throw new OrmInputException('Invalid SSql name specified');

                return self::$schemas[$name]->ssql_name;
        }


        # Important note:
        # It's crucial to the sytem as a whole that these regexps do not allow underscores in class names, and double underscores in property names

        private final static function validClassName($name)
        {
                # a-z, 0-9. Must start with a capitalised letter
                return !preg_match('#[^A-Za-z0-9]|^[^A-Z]$#', $name);
        }

        private final static function validPropertyName($name)
        {
                # a-z, 0-9 and underscore. Must start with a letter. Cannot contain two underscores in a row
                return !preg_match('#[^a-z0-9_]|^[^a-z]|[^a-z]|__$#', $name);
        }

        # not sure of the point of this one......
        private final static function validDbName($name)
        {
                return !preg_match('#[^a-z_]|^[^a-z]|[^a-z]|__$#', $name);
        }

        private final static function testClassName($name) {
                if (!self::validClassName($name))
                        throw new OrmInputException('Class '.$name.' does not adhere to class name rules');
        }

        private final static function testPropertyName($name) {
                if (!self::validPropertyName($name))
                        throw new OrmInputException('Property '.$name.' does not adhere to property name rules');
        }

        # TODO: does not belong in Orm* classes -- MOVE!
        protected static function debugDump($var)
        {
                ob_start();
                var_dump($var);
                $output = ob_get_contents();
                ob_end_clean();
                return $output;
        }

        /* DEPRECATED
        # returns an associative array of the form class => properties, where properties is an array of strings
        private static final function getHeirarchy($class) ####### DEPRECATED
        {
                ### DEPRECATED #################################################
                throw new OrmException('The method getHeirarchy is DEPRECATED');
                ### DEPRECATED #################################################

                if (!is_subclass_of($class, __CLASS__))
                        throw new OrmInputException('Given class is not a subclass of '.__CLASS__);

                # first create the array
                $hclass = $class;
                do
                        $heirarchy[$hclass] = array();
                while(get_parent_class($hclass = get_parent_class($hclass)) != __CLASS__);

                $class_reflection = new ReflectionClass($class);
                foreach($class_reflection->getProperties() as $pr) {
                        if ($pr->isStatic() || $pr->isPrivate())
                                continue;

                        $source_class  = $pr->getDeclaringClass()->getName();
                        $property_name = $pr->getName();

                        if ($property_name == 'id') throw new OrmInputException(sprintf('Class "%s" may not contain the reserved property "%s"', $source_class, $property_name));

                        self::testClassName($source_class);
                        self::testPropertyName($property_name);

                        if (!isset($heirarchy[$source_class]))
                                $heirarchy[$source_class] = array();
                        
                        $heirarchy[$source_class][] = $property_name;
                }
                return $heirarchy;
        }
        */

        # returns an array of property names for a given class. If the second argument is TRUE then the class heirarchy is flattened
        protected static final function getProperties($class, $flat = false)
        {
                if (!is_subclass_of($class, __CLASS__))
                        throw new OrmInputException('Given class is not a subclass of '.__CLASS__);

                # first create the array
                $properties = array();

                $class_reflection = new ReflectionClass($class);
                /*foreach ($class_reflection->getProperties() as $pr) {
                        if ($pr->isStatic() || $pr->isPrivate())
                                continue;

                        $source_class  = $pr->getDeclaringClass()->getName();
                        if (!$flat && $source_class != $class)
                                continue;

                        $property_name = $pr->getName();

                        if ($property_name == self::auto_property_id)
                                throw new OrmInputException(sprintf('Class "%s" may not contain the reserved property "%s"', $source_class, $property_name));

                        self::testClassName($source_class);
                        self::testPropertyName($property_name);

                        $properties[] = $property_name;
                }

                # examine its lineage; any immediate abstract classes get their properties merged in
                # TODO: refactor?
                while (($class_reflection = $class_reflection->getParentClass())    && # get the parent
                       $class_reflection->getName() != self::orm_class_class        && # check that we're not hitting the top
                       $class_reflection->getName() != self::orm_class_relationship && # check that we're not hitting the top
                       !$class_reflection->isInstantiable()) {                         # all abstract classes get their properties merged
                        foreach ($class_reflection->getProperties() as $pr) {
                                if ($pr->isStatic() || $pr->isPrivate())
                                        continue;

                                $source_class  = $pr->getDeclaringClass()->getName();
                                if ($source_class != $class_reflection->getName())
                                        continue;

                                $property_name = $pr->getName();

                                if ($property_name == self::auto_property_id)
                                        throw new OrmInputException(sprintf('Class "%s" may not contain the reserved property "%s"', $source_class, $property_name));

                                self::testClassName($source_class);
                                self::testPropertyName($property_name);

                                $properties[] = $property_name;
                        }
                }*/

                do {
                        foreach ($class_reflection->getProperties() as $pr) {
                                if ($pr->isStatic() || $pr->isPrivate())
                                        continue;

                                $source_class  = $pr->getDeclaringClass()->getName();
                                if ($class_reflection->getName() == $class && !$flat && $source_class != $class)
                                        continue;
                                elseif ($source_class != $class_reflection->getName())
                                        continue;

                                $property_name = $pr->getName();

                                if ($property_name == self::auto_property_id)
                                        throw new OrmInputException(sprintf('Class "%s" may not contain the reserved property "%s"', $source_class, $property_name));

                                self::testClassName($source_class);
                                self::testPropertyName($property_name);

                                $properties[] = $property_name;
                        }
                } while (($class_reflection = $class_reflection->getParentClass())    && # get the parent
                          $class_reflection->getName() != self::orm_class_class        && # check that we're not hitting the top
                          $class_reflection->getName() != self::orm_class_relationship && # check that we're not hitting the top
                          !$class_reflection->isInstantiable());                          # all abstract classes get their properties merged

                return $properties;
        }

        protected static final function classToDbName($class_name)
        {
                if (!is_string($class_name))
                        throw new OrmInputException('Must pass a string');

                self::testClassName($class_name);
                $sql_name = '';
                for($i = 0; $i < strlen($class_name); $i++) {
                        $c = substr($class_name, $i, 1);
                        if (preg_match('#[A-Z]#', $c)) { # uppercase => new word
                                if ($i != 0) $sql_name .= '_'; # don't want a leading underscore
                                $sql_name .= strtolower($c);
                        } else {        # lowercase
                                $sql_name .= $c;
                        }
                }
                return $sql_name; # 'BaseReport' -> 'base_report'
        }

        protected static final function propertyToDbName($property_name)
        {
                if (!is_string($property_name))
                        throw new OrmInputException('Must pass a string');

                self::testPropertyName($property_name);

                # return the input
                return $property_name;
        }

        protected function dbToClassName($db_name)
        {
                return str_replace(' ', '', ucwords(str_replace('_', ' ', $db_name)));
        }

        protected function dbToPropertyName($db_name) {
                return $db_name;
        }

        # function returns custom keys specified on a per-class basis by CLASSNAME::CLASSNAME_keys
        # or defaults to array('id')
        public static final function getKeys($class)
        {
                if (!is_subclass_of($class, __CLASS__))
                        throw new OrmInputException('Supply a subclass of '.__CLASS__);

                $const = sprintf('%s::%s_keys', $class, $class);
                if (!defined($const))
                        return array(self::auto_property_id);

                $keys = array_unique(explode(',', constant($const)));

                foreach ($keys as $key)
                        if (!in_array($key, self::getProperties($class)))
                                throw new OrmInputException("$key is not a member of class $class");

                return $keys;
        }

        #################################### SQL Construction ###################

        private static final function sqlVar($name, $var)
        {
                if (is_string ($var))               return (string) sprintf("'%s'", SSql::escape($var, self::getSSqlName($name)));
                if (is_int($var) || is_float($var)) return (string) SSql::escape($var, self::getSSqlName($name));
                if (is_null   ($var))               return (string) 'NULL';

                throw new OrmInputException('Cannot insert variables of type '.$type.' into the database');
        }

        private static final function parseRules($code)
        {
                # parse mappings I to O as A
                $rules = array();
                foreach (explode("\n", $code) as $line) {
                        if (!preg_match('#^(?<input>\w+)\s+to\s+(?<output>\w+)\s+as\s+(?<alias>\w+)(\s+(?<options>.+))?$#', $line, $match))
                                continue;

                        $rule = new stdClass();

                        $rule->input   = $match['input' ];
                        $rule->output  = $match['output'];
                        $rule->alias   = $match['alias' ];
                        if (!isset($match['options']))
                                $rule->options = array();
                        else
                                $rule->options = preg_split('#\s+#', $match['options']);

                        $rules[] = $rule;
                }

                return $rules;
        }

        private static final function isMatch($rule, $spec)
        {
                if (!is_object($rule) || !isset($rule->input) || !isset($rule->output) || !isset($rule->alias))
                        throw new OrmException('Rule must be an object with properties input, output, alias all set');

                if (is_string($spec))
                        return $spec == $rule->input || $spec == $rule->output;

                # essentially an "or"
                if (is_array($spec))
                        foreach ($spec as $s)
                                if (self::isMatch($s))
                                        return true;

                if (!is_object($spec))
                        throw new OrmException('Specs must be either strings, objects or arrays of objects');

                if (isset($spec->input) && $spec->input != $rule->input)
                        return false;

                if (isset($spec->output) && $spec->output != $rule->output)
                        return false;

                if (isset($spec->alias) && $spec->alias != $rule->alias)
                        return false;

                return true;
        }

        # find a route from class A to class B
        # note that this function has some advanced functionality in the rule_matches variable; it is possible to set "waypoints"
        #         either as a string (in which case they match to either input or output rules) or as an object where any or all
        #         of input, output, alias may be stipulated
        private static final function routeRecurse($input_spec, $output_spec, $name, $waypoint_specs = array(), &$paths = array(), $path = array())
        {
                # if the path is empty, we should begin with rules that specify our input class in their ->input or ->output
                if (sizeof($path) == 0) {
                        foreach (self::getRules($name) as $rule) {
                                if (self::isMatch($rule, $input_spec))
                                        self::routeRecurse($input_spec, $output_spec, $name, &$waypoint_specs, $paths, array($rule));
                        }

                        # we have our valid paths by this point

                        # if no valid paths are found return NULL
                        if (sizeof($paths) == 0)
                                return NULL;

                        # sort paths by size and return the shortest
                        usort($paths, array('self', 'sizeCompare'));
                        return array_pop($paths);
                }

                # else we're on course; examine the final entry on our path as test
                $test = $path[sizeof($path)-1];

                # if $test matches our $output_spec, iterate over the waypoint_specs to make sure we matched everything
                if (self::isMatch($test, $output_spec)) {
                        foreach ($waypoint_specs as $spec) {
                                $match = true; # empty rules must match
                                if (is_array($spec))
                                        $spec = (object) $spec;

                                foreach ($path as $rule) {
                                        $match = self::isMatch($rule, $spec);

                                        if ($match)
                                                break;
                                }

                                if (!$match) # a required match was not found
                                        return NULL;
                        }

                        # by this point we have a valid path that matches all requirements
                        $paths[] = $path;

                        return NULL;
                }

                # else find another rule whose ->input or ->output matches test->input or ->output and does not already exist in our path, and recurse
                foreach (self::getRules($name) as $rule) {
                        if (($test->input  == $rule->input   ||
                             $test->input  == $rule->output  ||
                             $test->output == $rule->input   ||
                             $test->output == $rule->output) &&
                             !in_array($rule, $path))
                                self::routeRecurse($input_spec, $output_spec, $name, &$waypoint_specs, $paths, array_merge($path, array($rule)));
                }

                return NULL;
        }
        /*
        # find a route from class A to class B
        # note that this function has some advanced functionality in the rule_matches variable; it is possible to set "waypoints"
        #         either as a string (in which case they match to either input or output rules) or as an object where any or all
        #         of input, output, alias may be stipulated
        private static final function routeRecurse($input_spec, $output_spec, $name = NULL, $waypoint_specs = array(), &$paths = array(), $path = array())
        {
                # if the path is empty, we should begin with rules that specify our input class in their ->input or ->output
                if (sizeof($path) == 0) {
                        foreach (self::getRules($name) as $rule) {
                                $enter_inherits = (object) array('alias' => self::relationship_inherits,
                                                                 'input' => $rule->input);
                                if (self::isMatch($rule, $input_spec) && ($rule->alias != self::relationship_inherits || self::isMatch($rule, $enter_inherits)))
                                        self::routeRecurse($input_spec, $output_spec, $name, &$waypoint_specs, $paths, array($rule));
                        }

                        # we have our valid paths by this point

                        # if no valid paths are found return NULL
                        if (sizeof($paths) == 0)
                                return NULL;

                        # sort paths by size and return the shortest
                        usort($paths, array('self', 'sizeCompare'));
                        return array_pop($paths);
                }

                # else we're on course; examine the final entry on our path as test
                $test = $path[sizeof($path)-1];

                $enter_inherits = (object) array('alias'  => self::relationship_inherits,
                                                 'input'  => $test->input);
                $leave_inherits = (object) array('alias'  => self::relationship_inherits,
                                                 'output' => $test->input);

                # if $test matches our $output_spec, iterate over the waypoint_specs to make sure we matched everything
                if (self::isMatch($test, $output_spec) &&
                    ($test->alias != self::relationship_inherits || self::isMatch($test, $leave_inherits))) { # if we want to finish on an "inherits" rule it must be the correct direction
                        foreach ($waypoint_specs as $spec) {
                                $match = true; # empty rules must match
                                if (is_array($spec))
                                        $spec = (object) $spec;

                                foreach ($path as $rule) {
                                        $match = self::isMatch($rule, $spec);

                                        if ($match)
                                                break;
                                }

                                if (!$match) # a required match was not found
                                        return NULL;
                        }

                        # by this point we have a valid path that matches all requirements
                        $paths[] = $path;

                        return NULL;
                }

                # else find another rule whose ->input or ->output matches test->input or ->output and does not already exist in our path, and recurse
                foreach (self::getRules($name) as $rule) {
                        if (($test->input  == $rule->input   ||
                             $test->input  == $rule->output  ||
                             $test->output == $rule->input   ||
                             $test->output == $rule->output) &&
                             !in_array($rule, $path)         &&
                             ($rule->alias != self::relationship_inherits || self::isMatch($rule, $enter_inherits)) && # in order to enter an inherits rule we must be travelling in the correct direction
                             ($test->alias != self::relationship_inherits || self::isMatch($test, $leave_inherits)))   # similarly in order to leave an inherits rule, a directional check must be made
                                self::routeRecurse($input_spec, $output_spec, $name, &$waypoint_specs, $paths, array_merge($path, array($rule)));
                }

                return NULL;
        }*/

        private static final function sizeCompare(&$a, &$b)
        {
                $diff = sizeof($b) - sizeof($a);

                if ($diff != 0)
                        return $diff;

                # sizes are the same, so let's compare number of unique classes used instead
                # should we just compare unique classes from the outset? -- NO; this gives complex routes that loop over the same class an unfair advantage

                $ca = array();
                foreach ($a as $rule) {
                        $ca[] = $rule->input;
                        $ca[] = $rule->output;
                }

                $cb = array();
                foreach ($b as $rule) {
                        $cb[] = $rule->input;
                        $cb[] = $rule->output;
                }

                return sizeof(array_unique($cb)) - sizeof(array_unique($ca));
        }

        private static final function comboRecurse($items, &$combos, $used = array())
        {
                if (sizeof($items) == sizeof($used)) {
                        if (!is_array($combos))
                                $combos = array();

                        #if (!in_array(array_reverse($used), $combos))
                        $combos[] = $used;
                        return NULL;
                }

                foreach ($items as $item)
                        if (!in_array($item, $used))
                                self::comboRecurse($items, $combos, array_merge($used, array($item)));
        }

        /*
        # mixes up the different input/output destinations and tries to find the best route across the database
        # does not concern itself with which final input/output are decided upon....
        private static final function getRoute(array $destinations, array $stipulations, $name = NULL)
        {
                if (sizeof($destinations = array_unique($destinations, SORT_REGULAR)) < 2)
                        throw new OrmException('Cannot calculate routes where we don\'t have at least 2 "destinations"');

                # get all possible combinations of routes around destinations
                self::comboRecurse($destinations,  $combos);

                # calculate routes, select the shortest full route
                $routes = array();
                foreach ($combos as $i => $combo) {
                        # we're going from point A to point B
                        $a = array_shift($combo); # first item is "A"
                        $b = array_pop($combo); # last item is "B"

                        if ($route = self::routeRecurse($a, $b, $name, array_merge($combo, $stipulations)))
                                $routes[$i] = $route;
                }

                usort($routes, array('self', 'sizeCompare'));

                return array_pop($routes);
        }*/

        private static final function anchorInput(array &$combo, $key, array $input) {
                $combo = array_merge($input, $combo);
        }

        private static final function getRoute($name, array $combos, array $stipulations)
        {
                # calculate routes, select the shortest full route
                $routes = array();
                foreach ($combos as $i => $combo) {
                        # we're going from point A to point B
                        $a = array_shift($combo); # first item is "A"
                        $b = array_pop($combo);   # last item is "B"

                        if ($route = self::routeRecurse($a, $b, $name, array_merge($combo, $stipulations)))
                                $routes[$i] = $route;
                }

                usort($routes, array('self', 'sizeCompare'));
                return array_pop($routes);
        }

        private static final function objectify(&$var)
        {
                if (!is_object($var))
                        $var = (object) $var;
        }

        private static final function rshipRespecify(&$class, $dummy, $name) # TODO: figure out why I need the dummy variable here
        {
                if (self::isIRelationship($name, $class))
                        $class = (object) array('alias' => $class);
        }

        private static final function rshipRespecifyArray(&$array, $dummy, $name) # TODO: figure out why I need the dummy variable here
        {
                array_walk($array, array('self', 'rshipRespecify'), $name);
        }

        # Constructs SQL from Class-based specifications of input/output
        #
        #         destinations: object->class  = "classname"
        #                             ->input  = (spec)
        #                             ->output = true
        #
        #         stipulations: add route stipulations with respect to the schema rules; strings are matched against
        #                       input/output classes, objects/arrays are matched against an or all of input/output/alias
        #
        protected static final function getSqlComponents($name, array $destinations, $waypoints = array())
        {
                # now we need to cater for the situation where only one table is being selected from
                $dest_count = sizeof($destinations);
                if ($dest_count == 0)
                        throw new OrmInputException('Must supply one or more destinations');

                # ensure variable formatting
                array_walk($destinations, array('self', 'objectify'));

                # turn the destinations into specifications
                foreach ($destinations as $dest) {
                        if (!isset($dest->class))
                                throw new OrmInputException('Must set "class" variable for destinations');



                        $o = (object) array('output' => $dest->class,
                                            'lookup' => isset($dest->input)? $dest->input : false,
                                            'get_vars' => isset($dest->output)? $dest->output : false);

                        if (self::isClass($name, $dest->class) && isset($dest->alias) && $dest->alias != self::anchor_alias)
                                $waypoints[] = (object) array('alias' => $dest->alias); # these are infered waypoints
                }

                $tail = $destinations;
                $head = array_shift($tail);


                if ($dest_count == 1) {
                        # create a fake route for SQL generation consisting of a single rule (with a hackish invalid alias name)
                        $route = array((object) array('input'  => $head->class,
                                                      'output' => $head->class,
                                                      'alias'  => self::anchor_alias));

                } else {
                        # generate list of destination classes
                        #       a) first item is input but not ouput
                        #               anchor first, scramble rest
                        #       b) first item is both input and output
                        #               anchor first, scramble rest
                        #       c) first item is neither input nor output
                        #               scramble all

                        if ($head->input) {
                                $list = array();
                                foreach ($tail as $dest)
                                        $list[] = $dest->class;

                                self::comboRecurse($list, $combos);

                                array_walk($combos, array('self', 'anchorInput'), array($head->class));
                        } else {
                                $list = array();
                                foreach ($destinations as $dest)
                                        $list[] = $dest->class;

                                self::comboRecurse($list, $combos);
                        }

                        # make sure that relationships are properly specified as waypoints
                        array_walk($combos, array('self', 'rshipRespecifyArray'), $name);

                        if(!$route = self::getRoute($name, $combos, $waypoints))
                                throw new OrmInputException(sprintf("Could not calculate a valid route for this combination of destinations"));
                }

                # by this point we have a valid route, started off with a dummy rule that has an alias of "0"

                $last_rule = (object) array('input'  => NULL,
                                            'output' => $head->class,
                                            'alias'  => self::anchor_alias);

                # prepare the Hinges and Nodes arrays
                $inputs = $outputs = array($last_rule->output);
                foreach ($route as $rule) {
                        $inputs [] = $rule->input;
                        $outputs[] = $rule->output;
                }
                # just in case the final rule is backwards; we don't want it turning up in nodes
                $inputs[]  = $rule->input;
                $outputs[] = $rule->input;

                $inputs  = array_unique($inputs);
                $outputs = array_unique($outputs);
                $nodes  = array_diff($outputs, $inputs);
                unset($inputs, $outputs);

                # Classify dests for optimisations
                $used = $non_key_inputs = $outputs = array();
                foreach ($destinations as $dest) {
                        if (!isset($dest->alias))
                                $alias = NULL;

                        $o = (object) array('class' => $dest->class, 'alias' => $alias);

                        $used[] = $o;

                        if (isset($dest->input))
                                foreach ($dest->input as $key => $val)
                                        if (self::isClass($name, $dest->class) && !in_array($key, self::getKeys($dest->class)))
                                                $non_key_inputs[] = $o;

                        if (isset($dest->output))
                                $outputs[] = $o;
                }

                if (sizeof($route) > 1)
                $route[] = NULL; # add an extra element so that the chain continues long enough to evaluate the final table


                $select = $from = $where = array();
                foreach ($route as $rule) {
                        ### reset vars to avoid confusion
                        $current_table    =
                        $linked_class     =
                        $linked_table     =
                        $result_of        =
                        $link             =
                        $link_stored_here =
                                NULL;

                        if        (is_null($rule)) { # special rule for final link in chain
                                if ($current_class == $last_rule->input) {
                                        $current_class = $last_rule->output;
                                        $linked_class  = $last_rule->input;
                                        $link_stored_here = false;
                                } else {
                                        $current_class = $last_rule->input;
                                        $linked_class  = $last_rule->output;
                                        $link_stored_here = true;
                                }
                        } else if ($rule->input  == $last_rule->input || $rule->input  == $last_rule->output) {
                                $current_class = $rule->input;
                                $linked_class = $rule->output;
                                $link_stored_here = true;
                        } else if ($rule->output == $last_rule->input || $rule->output == $last_rule->output) {
                                $current_class = $rule->output;
                                $linked_class = $rule->input;
                                $link_stored_here = false;
                        }
                        $result_of = $last_rule->alias;
                        if (!is_null($rule))
                                $link = $rule->alias;

                        # variables for use in loop:
                        if ($link)
                                $link_sql        = self::classToDbName($link);

                        $curr_unused_node = (in_array($current_class, $nodes) && !(in_array((object) array('class' => $current_class, 'alias' => NULL),       $used) ||
                                                                                   in_array((object) array('class' => $current_class, 'alias' => $result_of), $used)));
                        $link_unused_node = (in_array($linked_class, $nodes)  && !(in_array((object) array('class' => $linked_class,  'alias' => NULL),       $used) ||
                                                                                   in_array((object) array('class' => $linked_class,  'alias' => $link),      $used)));

                        # this portion of the code tries to avoid pulling in more tables by designating inputs that can be specified over a relationship
                        # i.e. by adding contraints to tables we already pull in for joins
                        $input_as_rel_keys = false;
                        if (!$curr_unused_node && in_array($current_class, $nodes) &&
                            !(in_array((object) array('class' => $current_class, 'alias' => NULL),       $outputs)        ||
                              in_array((object) array('class' => $current_class, 'alias' => $result_of), $outputs))       &&
                            !(in_array((object) array('class' => $current_class, 'alias' => NULL),       $non_key_inputs) ||
                              in_array((object) array('class' => $current_class, 'alias' => $result_of), $non_key_inputs))) {
                                $curr_unused_node = true;
                                $input_as_rel_keys = true;
                        }
                        if (!$link_unused_node && in_array($linked_class, $nodes) &&
                            !(in_array((object) array('class' => $linked_class, 'alias' => NULL),       $outputs)   ||
                              in_array((object) array('class' => $linked_class, 'alias' => $result_of), $outputs))  &&
                            !(in_array((object) array('class' => $linked_class, 'alias' => NULL),  $non_key_inputs) ||
                              in_array((object) array('class' => $linked_class, 'alias' => $link), $non_key_inputs))) {
                                $link_unused_node = true;
                        }

                        $result_of_sql   = $result_of == self::anchor_alias? $result_of : self::classToDbName($result_of);
                        $current_table   = self::classToDbName($current_class);
                        $current_alias   = self::isClass($name, $current_class)? sprintf("%s__%s", $current_table, $result_of_sql) : $current_table;
                        $linked_table    = self::classToDbName($linked_class);
                        if ($link)
                                $linked_alias    = self::isClass($name, $linked_class)? sprintf("%s__%s",  $linked_table,  $link_sql) : $linked_table;
                        # also: $current_class
                        #       $linked_class
                        #       $link_stored_here
                        #       $nodes   = array of Node classes
                        #       $used    = array of classes requested for input or output (might be relationships)
                        #       $inputs  = array of classes requested for inputting (might be relationships)
                        #       $outputs = array of classes requested for outputting (might be relationships)
                        #       $non_key_inputs = array of classes that have non-keys specified as inputs
                        #
                        # from this point on it is strictly prohibited to use the $rule variable

                        # next line useful for debugging. do not remove.
                        #printf("last alias:       %s,\ncurr alias:       %s,\nlinked alias:     %s,\nlink stored here: %d\ncurr unused node: %d\nlink unused node: %d\ni/pt as rel keys: %d\n\n", isset($last_alias)? $last_alias : '', $current_alias, isset($linked_alias)? $linked_alias : '', $link_stored_here, $curr_unused_node, $link_unused_node, $input_as_rel_keys);

                        # SELECT
                        foreach ($destinations as $dest) {
                                if (isset($dest->output)) {
                                        if ($dest->class == $current_class) {
                                                $class = $current_class;
                                                $alias = $current_alias;
                                                # automagically grab parents of $current_class
                                                $class_reflection = new ReflectionClass($class);
                                                do {
                                                        # use reflection to SKIP abstract classes
                                                        if (!$class_reflection->isInstantiable())
                                                                continue;

                                                        if ($class != $current_class) {
                                                                $last_alias_inherits = $alias;
                                                                $table = self::classToDbName($class);
                                                                $alias_name = self::classToDbName(self::relationship_inherits);
                                                                $alias = sprintf('%s__%s__%s', $table, $alias_name, $current_alias);
                                                                # TODO: need to add to FROM and join WHERE.... perhaps I should do it there?
                                                                $from[] = sprintf("%s %s", $table, $alias);
                                                                foreach (self::getKeys($class) as $key)
                                                                        $where[] = sprintf("%s.%s__key__%s = %s.%s", $last_alias_inherits, $alias_name, $key, $alias, $key);
                                                        }
                                                        $properties = self::getProperties($class);
                                                        if (self::getKeys($class) == array(self::auto_property_id))
                                                                array_unshift($properties, self::auto_property_id);
                                                        foreach ($properties as $var)
                                                                $select[] = sprintf("%s.%s AS %s__%s", $alias, $var, $alias, $var);

                                                } while (($class_reflection = $class_reflection->getParentClass()) &&
                                                         ($class = $class_reflection->getName()) != self::orm_class_class);

                                        } else if ($dest->class == $result_of) {
                                                $table = $link_stored_here? $current_alias : $last_alias;
                                                foreach (self::getProperties($result_of, true) as $var)
                                                        $select[] = sprintf("%s.%s__var__%s AS %s__%s__var__%s", $table, $result_of_sql, $var, $table, $result_of_sql, $var);
                                        }
                                }

                                if (isset($dest->input)) {
                                        $properties = self::getProperties($dest->class);
                                        if ($dest->class == $current_class) {
                                                if (self::getKeys($dest->class) == array(self::auto_property_id))
                                                        array_unshift($properties, self::auto_property_id);

                                                foreach ($dest->input as $key => $var) {
                                                        if (!in_array($key, $properties))
                                                                throw new OrmInputException(sprintf('Property "%s" is not a member of Class "%s"', $key, $dest->class));

                                                        $key = self::propertyToDbName($key);
                                                        /*$var = self::sqlVar($name, $var);*/
                                                        if ($input_as_rel_keys)
                                                                $table_key = sprintf("%s.%s__key__%s", $last_alias, $result_of, $key);
                                                        else
                                                                $table_key = sprintf("%s.%s", $current_alias, $key);

                                                        $where[] = sprintf("%s = %s", $table_key, $var);
                                                }

                                        } else if ($dest->class == $result_of) {
                                                foreach ($dest->input as $key => $var) {
                                                        if (!in_array($key, $properties))
                                                                throw new OrmInputException(sprintf('Property "%s" is not a member of Class "%s"', $key, $dest->class));

                                                        $key = self::propertyToDbName($key);
                                                        /*$var = self::sqlVar($name, $var);*/

                                                        if ($link_stored_here) {
                                                                $where[] = sprintf("%s.%s__var__%s = %s", $current_alias, $result_of_sql, $key, $var);
                                                        } else {
                                                                $where[] = sprintf("%s.%s__var__%s = %s", $linked_alias,  $result_of_sql, $key, $var);
                                                        }
                                                }
                                        }
                                }
                        }

                        if (!$curr_unused_node) {
                                # FROM
                                if (self::isClass($name, $current_class))
                                        $from[] = sprintf("%s %s", $current_table, $current_alias);
                                else
                                        $from[] = $current_alias;
                        }
                        

                        # JOIN
                        if ($link) {
                                if ($link_stored_here) {
                                        if (!$link_unused_node) {
                                                foreach (self::getKeys($linked_class) as $key)
                                                        $where[] = sprintf("%s.%s__key__%s = %s.%s", $current_alias, $link_sql, $key, $linked_alias, $key);
                                        }

                                } else {
                                        $var_prefix = $curr_unused_node? sprintf("%s.%s__", $last_alias, $last_link_sql) : sprintf("%s.", $current_alias);# TODO: refactor so we only have one loop below
                                        foreach (self::getKeys($current_class) as $key)
                                                $where[] = sprintf("%s%s = %s.%s__key__%s", $var_prefix, $key, $linked_alias, $link_sql, $key);
                                }
                        }

                        # above here do not use $rule variable
                        $last_alias = $current_alias;
                        if (isset($link_sql))
                                $last_link_sql = $link_sql;
                        $last_rule  = $rule;
                }


                $o = (object) array(
                        'select' => implode(', ',    $select            ),
                        'from'   => implode(', ',    array_unique($from)),
                );
                if (sizeof($where) > 0)
                        $o->where = implode(' AND ', $where );

                return $o;
        }




        /*
        # Constructs SQL from Class-based specifications of input/output
        #
        #         class_inputs: associative (keyed) array of the form class_name => key_value_object
        #
        #         outputs:      classes required for output
        #
        #         stipulations: add route stipulations with respect to the schema rules; strings are matched against
        #                       input/output classes, objects/arrays are matched against an or all of input/output/alias
        #
        static final function getSqlComponents(array $class_inputs, array $outputs, $stipulations = array(), $name = NULL)
        {
                $select = array();
                $from   = array();
                $where  = array();

                if (sizeof($outputs) < 1)
                        throw new OrmInputException('Must provide at least one output class');

                $inputs = array_keys($class_inputs);
                $destinations = array_unique(array_merge($inputs,  $outputs), SORT_REGULAR);
                # check that inputs/outputs are genuine Orm classes ($destinations) and that we have more than 0 outputs
                foreach ($destinations as $class)
                        if (!self::isClass($class, $name) && !self::isIRelationship($class, $name))
                                throw new OrmInputException(sprintf('%s is not a registered class or relationship', $class));

                if (sizeof($destinations) > 1) {
                        # need to translate OrmRelationships into their corresponding input Classes
                        $route_across = array();
                        foreach ($destinations as $class) {
                                $alias = '';
                                if (self::isClass($class, $name)) {
                                        $properties = self::getProperties($class);
                                        $table = self::classToDbName($class);
                                        $route_across[] = $class;
                                } else if (self::isIRelationship($class, $name)) {
                                        $properties = self::getProperties($class, true);
                                        $alias = self::classToDbName($class);
                                        # find the storage (input) table from the rules
                                        foreach (self::getRules($name) as $rule)
                                                if ($rule->alias == $class) {
                                                        $table = self::classtoDbName($rule->input);
                                                        $route_across[] = $rule->input;
                                                }

                                        if (strlen($table) == 0)
                                                throw new OrmInputException(sprintf('No rule contains alias %s', $class));
                                } else {
                                        throw new OrmInputException(sprintf('The class %s is not a registered class or relationship. Please consult your schema.'));
                                }

                                if (strlen($alias) > 0)
                                        $alias = sprintf("%s__", $alias);

                                if (in_array($class, $outputs)) {
                                        $from[] = $table;

                                        foreach ($properties as $property) {
                                                $var = self::propertyToDbName($property);
                                                $select[] = sprintf('%s.%s%s AS %s__%s%s', $table, $alias, $var, $table, $alias, $var);
                                        }
                                }
                                
                                if (in_array($class, $inputs)) {
                                        # $class_inputs[$class] gives us the inputs we want as an object or array
                                        foreach ($class_inputs[$class] as $property => $value) {
                                                $var = self::propertyToDbName($property);
                                                $where[] = sprintf('%s.%s%s = %s', $table, $alias, $var, self::sqlVar($value, $name));
                                        }
                                }
                        }
                        if(!$route = self::getRoute($route_across, $stipulations, $name))
                                throw new OrmInputException(sprintf('Could not find a valid route across %s', implode(', ', $route_across)));

                        # when constructing the SQL look for classes that are referenced in two or more OUTPUT rules,
                        # if these classes are not mentioned in the inputs or outputs then we can "hop" over them by matching the input keys on those rules
                        $outs = array();
                        $hops = array();
                        foreach ($route as $rule) {
                                if (in_array($rule->output, $outs) && !in_array($rule->output, array_merge($inputs, $outputs)))
                                        $hops[$rule->output] = $rule;
                                else
                                        $outs[] = $rule->output;
                        }
                        unset($outs);
                        $hop_outs = array_keys($hops);

                        # need to work out from $route what other joins to make and from $hops what can be avoided
                        # add joins to FROM and WHERE, if neccessary
                        foreach ($route as $rule) {
                                $a = self::classToDbName($rule->input);
                                $b = self::classToDbName($rule->output);
                                $x = self::classToDbName($rule->alias);

                                # if the alias is Inherits, and the input is in our inputs, and the output is in our outputs... dummy!
                                if ($rule->alias == self::relationship_inherits && (in_array($rule->input, $inputs) || in_array($rule->input, $outputs)) && in_array($rule->output, $outputs))
                                        $select[] = sprintf("'dummy' AS %s__%s__%s", self::classToDbName($rule->input), self::classToDbName($rule->alias), self::classToDbName($rule->output));

                                if (!in_array($table = self::classtoDbName($rule->input), $from)) {
                                        $from[] = $table;

                                        # now join the input to the output
                                        foreach (self::getKeys($rule->output) as $key) {
                                                $key = self::propertyToDbName($key);
                                                array_unshift($where, sprintf("%s.%s__%s = %s.%s", $a, $x, $key, $b, $key));
                                        }
                                }
                                if (!in_array($table = self::classtoDbName($rule->output), $from)) {
                                        # see about hopping over it
                                        if (in_array($rule->output, $hop_outs)) {
                                                if ($rule->input != $hops[$rule->output]->input) {
                                                        $c = self::classToDbName($hops[$rule->output]->input);
                                                        $y = self::classToDbName($hops[$rule->output]->alias);

                                                        # now join the inputs
                                                        foreach (self::getKeys($rule->output) as $key) {
                                                                $key = self::propertyToDbName($key);
                                                                array_unshift($where, sprintf("%s.%s__%s = %s.%s__%s", $a, $x, $key, $c, $y, $key));
                                                        }
                                                }
                                        } else {
                                                $from[] = $table;

                                                # now join the input to the output
                                                foreach (self::getKeys($rule->output) as $key) {
                                                        $key = self::propertyToDbName($key);
                                                        $where[] = sprintf("%s.%s__%s = %s.%s", $a, $x, $key, $b, $key);
                                                }
                                        }
                                }
                        }

                } else {
                        # at this point we're only selecting out a single class
                        $table = self::classToDbName($outputs[0]);
                        foreach (self::getProperties($outputs[0]) as $property) {
                                $var = self::propertyToDbName($property);
                                $select[] = sprintf('%s.%s AS %s__%s', $table, $var, $table, $var);
                        }
                        $from[] = $table;
                        
                        foreach ($class_inputs[$outputs[0]] as $key => $value) {
                                $var = self::propertyToDbName($key);
                                $where[] = sprintf('%s.%s = %s', $table, $var, self::sqlVar($value, $name));
                        }
                }

                $o = (object) array(
                        'select' => implode(', ',    $select),
                        'from'   => implode(', ',    $from  ),
                );
                if (sizeof($where) > 0)
                        $o->where = implode(' AND ', $where );

                return $o;
        }*/

        # Takes input of various classes in/out and checks for a cached version of the SQL to avoid the auto routing process
        public static final function getSql($destinations, $waypoints = array(), $name = NULL) {
                $dests = $destinations;
                $vals = array();
                $func_args = array();
                $hash = serialize($waypoints);
                foreach ($dests as $i => &$d) {
                        self::objectify($d);
                        if (isset($d->input))
                                foreach ($d->input as $key => &$val) {
                                        $varname = sprintf('$i%d_%s%s_%s', $i, self::classToDbName($d->class), isset($d->alias)? '_'.self::classToDbName($d->alias) : '', self::propertyToDbName($key));
                                        $func_args[] = $varname;
                                        $vals[] = self::sqlVar($name, $val); # TODO: think about moving the escapes into the $func_body -- would require shenanigans with $name
                                        $val = sprintf('{%s}', $varname);
                                }

                        if (isset($d->output))
                                $hash .= sprintf('%s_%s', $d->class, isset($d->alias)? $d->alias : '');
                }

                $hash = 'h'.sha1($hash.($func_args = implode(', ', $func_args)));

                $func_name = class_exists(self::orm_class_sql_cache)? call_user_func(array(self::orm_class_sql_cache, 'getFuncName'), $hash, $name) : NULL;

                if ($func_name && method_exists(self::orm_class_sql_cache, $func_name)) { # found in cache
                        $sql = call_user_func_array(array(self::orm_class_sql_cache, $func_name), $vals);

                } else if ($func_name && $func_body = call_user_func(array(self::orm_class_sql_cache, 'getRecentlyCachedBody'), $hash, $name)) {
                        $sql = call_user_func_array(create_function($func_args, $func_body), $vals);

                } else {
                        $func_body = sprintf("return \"\n%s\";", SSql::format(self::getSqlFromComponents(self::getSqlComponents($name, $dests, $waypoints))));

                        # add to cache
                        if (class_exists(self::orm_class_sql_cache))
                                call_user_func(array(self::orm_class_sql_cache, 'add'), $hash, $func_args, $func_body, $name);

                        $sql = call_user_func_array(create_function($func_args, $func_body), $vals);
                }

                return $sql;
        }

        # Simple function to convert SQL components into an SQL statement
        protected static final function getSqlFromComponents($components)
        {
                $sql = array();

                foreach ($components as $command => $args)
                        $sql[] = sprintf("%s %s", strtoupper(str_replace('_', ' ', $command)), $args);

                return implode(' ', $sql);
        }

        # Takes SQL and checked for a cached resource to avoid re-running SQL
        protected static final function getSqlResource($name, $sql) {
                self::name($name);

                $resource = NULL;
                $hash = sha1($sql);

                # search for a cached resource
                foreach (self::$sql_resource_cache as $c) {
                        if ($o->name == $name && $o->hash == $hash) {
                                $resource = $o->resource;
                                break;
                        }
                }

                # if we didn't find the cached resource, execute the SQL
                if (is_null($resource))
                        self::$sql_resource_cache[] = (object) array('name'     => $name,
                                                                     'hash'     => $hash,
                                                                     'resource' => ($resource = SSql::query($sql, self::getSSqlName($name))));

                return $resource;
        }

        protected static final function classesFromResource($name, $resource, $class, $alias = NULL, $count = 0)
        {
                # person__0__firstname
                # mtm_wishes__yearned_by__var__times,
                # person__inherits__user__likes__surname

                # if we explode the key on '__' we can inspect the sizeof each key to determine the data we have;
                #      3 = class, result_of, property
                #      4 = relationship, result_of, "var", property
                #      5 = class, "inherits", parent, parent_result_of, property


                # TODO: need to check that classes or relationships are instanciable
                # TODO: how am i going to populate the objects? could use __set_state($assoc_arr) ?
                # TODO: abstract away the cacheing



                $objects = array();

                foreach(SSql::getResultsFor($resource) as $result) {
                        $class_vars = array('setup_name' => $name);
                        foreach ($result as $key => $val) {
                                switch (sizeof($parts = explode('__', $key))) {
                                        case 3: # class, result_of, property
                                                list($r_class, $r_alias, $r_property) = $parts;

                                                if ($class == $r_class && (is_null($alias) || $alias == $r_alias))
                                                        $class_vars[$r_property] = $val;
                                                break;

                                        case 4: # relationship, result_of, "var", property
                                                list($r_relationship, $r_alias, $var_e_var, $r_property) = $parts;
                                                if ($var_e_var != 'var')
                                                        throw new OrmException(sprintf('Not expecting key of form %s', $key));
                                                break;

                                        case 5: # class, "inherits", parent, parent_result_of, property
                                                list($r_class, $inherits_e_inherits, $r_parent, $r_parent_alias, $r_property) = $parts;
                                                if ($inherits_e_inherits != self::relationship_inherits)
                                                        throw new OrmException(sprintf('Not expecting key of form %s', $key));

                                                if ($class == $r_parent && (is_null($alias) || $alias == $r_parent_alias)) {
                                                        if ($_property == self::auto_property_id)
                                                                $class_vars[sprintf('_%s%s', $r_class, self::auto_property_id)] = $val;
                                                        else
                                                                $class_vars[$r_property] = $val;
                                                }
                                                break;
                                }
                        }

                        $objects[] = new $class($class_vars, $name);

                        if ($count > 0)
                                if (sizeof($objects) == $count)
                                        break;
                }

                return $objects;
        }
}

abstract class OrmClass extends Orm
{
        private $setup_name = NULL;
        private $ids        = array();
        private $synced     = array();

        /* ### DEPRECATED
        ### helper functions for array callbacks ###
        private final function sqlKey($key)
        {
                return SSql::escape(self::propertyToDbName($key), $this->ssql_name);
        }

        private final function sqlValue($key)
        {
                return $this->sqlVar($this->$key, $name);
        }

        private final function sqlKeyValue($key)
        {
                return sprintf('%s = %s', $this->sqlKey($key), $this->sqlValue($key));
        }
        ### helper functions for array callbacks ###
        */

        # Can be constructed with the following syntaxes:
        #       new OrmClass()
        #       new OrmClass($array)
        #       new OrmClass($array, $name)
        #       new OrmClass($key1,  $key2)
        #       new OrmClass($key1,  $key2, $name)
        public function __construct()
        {
                # no args = no further setup
                if (func_num_args() == 0) return;

                # use this to work out whether an arg refers to an Orm name
                $name_specified = 0;

                if (is_array(func_get_arg(1))) { # array = new object with initial values
                        $name_specified = 2;
                        foreach($input as $key => $val)
                                $this->$key = $val;

                } else {                         # keys passed
                        $keys = self::getKeys(get_class($this));
                        $name_specified = sizeof($keys);

                        if (func_num_args() < (sizeof($keys) - 1))
                                throw new OrmInputException('Not enough keys were passed in');

                        foreach($keys as $key)
                                $this->$key = func_get_arg($i);
                }

                if ($name_specified > 0) {
                        $this->setup_name = func_get_arg($name_specified);
                }
        }

        ### Accessors ###
        public final function getId($class = NULL)
        {
                if ($class && !isset($this->ids[$class]))
                        throw new OrmInputException('Supply a valid class');

                return $class? $this->ids[$class] : $this->ids[get_class($this)];
        }

        public final function getKeyValues($class)
        {
                if (!($this instanceof $class))
                        throw new OrmInputException('Must supply a class that makes up an object of this type -- '.get_class($this));

                $array = array();
                foreach (self::getKeys($class) as $key)
                        $array[$key] = $this->$key;

                return $array;
        }
        ### Accessors ###

        public function save()
        {
                # TODO
        }

        public function delete()
        {
                # TODO
        }

        public final function getRelations($class) {
                #getSql(getSqlComponents())
        }
}

abstract class OrmRelationship extends Orm {}

class OrmException            extends Exception {}
class OrmInputException       extends OrmException {}


class OrmDbCreation extends Orm
{
        public static function getDbObjects($name)
        {
                $objs = array();
                foreach (self::getRules($name) as $rule) {
                        $o1 = (object) array('is_class'      => false,
                                             'keys'          => array(),
                                             'properties'    => array(),
                                             'parent'        => NULL,
                                             'relationships' => array());
                        $o2 = clone $o1;

                        if (!isset($objs[$rule->input]))
                                $objs[$rule->input] = $o1;

                        $objs[$rule->input]->relationships[$rule->relationship] = $rule->output;
                        $objs[$rule->output] = $o2;
                }

                foreach ($objs as $class => $o) {
                        $o->is_class = self::isClass($name, $class);
                        $o->keys = self::getKeys($class);
                        $o->properties = self::getProperties($class, !$o->is_class);
                        $o->parent = (($parent = get_parent_class($class)) == Orm::orm_class_class || $parent == Orm::orm_class_relationship)? NULL : $parent;

                        if ($keys == array(Orm::auto_property_id))
                                array_unshift($o->properties, Orm::auto_property_id);
                }

                return $objs;
        }
}

?>
