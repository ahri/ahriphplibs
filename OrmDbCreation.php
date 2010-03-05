<?php

# the following file must pull in all the dependences; Orm, SSql, Node, and also set up the Orm Schemas
require_once('Config.inc.php');

############## datatype stuff
function getDatatypes()
{
        return array('Short Text (e.g. email title)', 'Long Text (e.g. email body)', 'Date', 'Date & Time', 'Integer', 'Float');
}


function getDbType($orm_name)
{
        return SSql::getType(Orm::getSSqlName($orm_name));
}

function getActualDatatypes($orm_name)
{
        # valid "cast"s are "string", "int" and "NULL"
        switch (getDbType($orm_name)) {
                case 'Dummy':
                case 'MySQL':
                case 'MySQLi':
                        $actual_datatypes = array((object) array('type'    => 'VARCHAR',
                                                                 'cast'    => 'string',
                                                                 'length'  => 30,
                                                                 'default' => NULL),
                                                  (object) array('type'    => 'TEXT',
                                                                 'cast'    => 'string',
                                                                 'length'  => NULL,
                                                                 'default' => NULL),
                                                  (object) array('type'    => 'DATE',
                                                                 'cast'    => 'string',
                                                                 'length'  => NULL,
                                                                 'default' => NULL),
                                                  (object) array('type'    => 'DATETIME',
                                                                 'cast'    => 'string',
                                                                 'length'  => NULL,
                                                                 'default' => NULL),
                                                  (object) array('type'    => 'INT',
                                                                 'cast'    => 'int',
                                                                 'length'  => NULL,
                                                                 'default' => NULL),
                                                  (object) array('type'    => 'FLOAT',
                                                                 'cast'    => 'float',
                                                                 'length'  => NULL,
                                                                 'default' => NULL));
                        break;
                default:
                        throw new OrmException(sprintf('Sorry, schema generation not yet supported for database type %s', $db_type));
        }

        return $actual_datatypes;
}

function getIdDatatype()
{
        $id_datatype = array_flip(getDatatypes());
        return $id_datatype['Integer']; # default datatype for ids (Integer)
}


function rowMatch($orm_name, $class, $property)
{
        $actual_datatypes = getActualDatatypes($orm_name);
        $db_type          = getDbType($orm_name);

        if (!isset($_POST[$idx = sprintf('type_%s_%s', $class, $property)]))
                throw new OrmInputException(sprintf('Must specify a type for %s->%s', $class, $property));

        $datatype = $actual_datatypes[$_POST[$idx]];

        $type      = $datatype->type;
        $length    = (isset($_POST[$idx = sprintf('length_%s_%s',  $class, $property)]) && !empty($_POST[$idx]))? $_POST[$idx] : $datatype->length;
        $default   =  isset($_POST[$idx = sprintf('default_%s_%s', $class, $property)])?                          $_POST[$idx] : $datatype->default;
        $autoinc   = (isset($_POST[$idx = sprintf('autoinc_%s_%s', $class, $property)])   && $_POST[$idx] == 'on');
        $allownull = (isset($_POST[$idx = sprintf('allownull_%s_%s', $class, $property)]) && $_POST[$idx] == 'on');

        $length  = empty($length)?  '' : sprintf(' (%s)', $length);
        $default = empty($default)? '' : sprintf(' DEFAULT %s', Orm::sqlVar($orm_name, $default, $datatype->cast));

        if ($autoinc) {
                switch ($db_type) {
                        case 'Dummy':
                        case 'MySQL':
                        case 'MySQLi':
                                $autoinc = ' AUTO_INCREMENT';
                                break;
                        case 'SQLite':
                                # this page left blank ;) -- SQLite automatically treats integer primary keys as auto_inc
                                break;
                }
        } else {
                $autoinc = '';
        }

        if (!$allownull) {
                $allownull = ' NOT NULL';
        } else {
                $allownull = '';
        }

        return (object) array('name'      => Orm::propertyToDbName($property),
                              'type'      => $type,
                              'length'    => $length,
                              'default'   => $default,
                              'allownull' => $allownull,
                              'autoinc'   => $autoinc);
}

############ html generation

$html = new Node('html');
$head = $html->head();
$head->title('Orm DB Creation', true);

$style = $head->style(<<<EOF
body {
        font-family: verdana;
        font-size:   10pt;
}
EOF
, Node::UNSTRIPPED);
$style = 'text/css';
$body = $html->body();

if       (sizeof($_POST) == 0) {
        # select orm name
        $form = $body->form('Please select an Orm schema for which to generate database tables: ');
        $form->method = 'post';

        $select = $form->select();
        $select->name = 'orm_name';
        foreach (Orm::getNames() as $name) {
                $option = $select->option($name);
                $option->value = $name;
        }

        $submit = $form->input();
        $submit->type = 'submit';
        $submit->value = 'Refine';

} elseif (sizeof($_POST) == 1 && !empty($_POST['orm_name'])) {
        $orm_name = $_POST['orm_name'] == '-'? NULL : $_POST['orm_name'];
        $id_datatype = getIdDatatype();

        $form = $body->form();
        $form->method = 'post';

        $on = $form->input();
        $on->type  = 'hidden';
        $on->name  = 'orm_name';
        $on->value = $_POST['orm_name'];

        $table = $form->table();
        $table->border = 1;
        $table->cellspacing = '0px';
        $table->cellpadding = '5px';

        $tr = $table->tr();
        $tr->th('Property',       true);
        $tr->th('Type',           true);
        $tr->th('Length',         true);
        $tr->th('Default Value',  true);
        $tr->th('Allow NULLs',    true);
        $tr->th('Auto Increment', true);
        $cols = 0;
        foreach ($tr as $count)
                $cols++;

        foreach (OrmDbCreation::getDbObjects($orm_name) as $class => $o) {
                if (!$o->is_class && !$o->is_irelationship)
                        continue;

                $tr = $table->tr();

                $object_name = $class;
                if ($o->is_irelationship)
                        $object_name .= ' (Relationship)';

                $tr->th($object_name, true)->colspan = $cols;
                foreach ($o->properties as $p) {
                        $tr = $table->tr();
                        if (in_array($p, $o->keys))
                                $tr->th($p, true);
                        else
                                $tr->td($p, true);

                        $types = $tr->td()->select();
                        $types->name = sprintf('type_%s.%s',   $class, $p);

                        foreach (getDatatypes() as $key => $type) {
                                $option = $types->option($type, true);
                                $option->value = $key;
                                if ($p == Orm::AUTO_PROPERTY_ID && $key == $id_datatype)
                                        $option->selected = 'selected';
                        }

                        $length = $tr->td()->input();
                        $length->name = sprintf('length_%s.%s', $class, $p);
                        $length->type = 'text';
                        $length->size = 5;
                        $length->maxlen = 4;

                        $default = $tr->td()->input();
                        $default->type = 'text';
                        $default->name = sprintf('default_%s.%s', $class, $p);

                        $allownull = $tr->td()->input();
                        $allownull->type = 'checkbox';
                        $allownull->name = sprintf('allownull_%s.%s', $class, $p);
                        if ($o->is_irelationship)
                                $allownull->checked = 'checked';

                        $autoinc = $tr->td()->input();
                        $autoinc->type = 'checkbox';
                        $autoinc->name = sprintf('autoinc_%s.%s', $class, $p);
                        if ($p == Orm::AUTO_PROPERTY_ID)
                                $autoinc->checked = 'checked';
                }
        }

        $tr = $table->tr()->th();
        $tr->colspan = $cols;
        $submit = $tr->input();
        $submit->type = 'submit';
        $submit->value = 'Generate Schema';

} elseif (sizeof($_POST) > 2) {
        $orm_name = $_POST['orm_name'] == '-'? NULL : $_POST['orm_name'];

        $schema = '';
        # generate the schema and stick into a textarea
        # iterate over db objects adding appropriate types to $o->sql_properties[]
        foreach (($objs = OrmDbCreation::getDbObjects($orm_name)) as $class => $o) {
                # the two objects we need to generate tables for; classes and hinges
                if (!$o->is_class && !(!$o->is_irelationship && sizeof($o->relationships) > 0))
                        continue;

                $schema .= sprintf("CREATE TABLE %s (\n", Orm::classToDbName($class));
                $properties = array();
                foreach ($o->properties as $p) {
                        $rowMatch = rowMatch($orm_name, $class, $p);

                        $key = '';
                        if (in_array($p, $o->keys))
                                $key = ' PRIMARY KEY';

                        $properties[] = sprintf('    %s %s%s%s%s%s%s',
                                                $rowMatch->name,
                                                $rowMatch->type,
                                                $rowMatch->length,
                                                $rowMatch->allownull,
                                                $rowMatch->default,
                                                $rowMatch->autoinc,
                                                $key);
                }

                if (!is_null($o->parent)) {
                        foreach ($objs[$o->parent]->keys as $key) {
                                $rowMatch = rowMatch($orm_name, $o->parent, $key);

                                $properties[] = sprintf("    %s__key__%s %s%s%s",
                                                        Orm::classToDbName(Orm::RELATIONSHIP_INHERITS),
                                                        $rowMatch->name,
                                                        $rowMatch->type,
                                                        $rowMatch->length,
                                                        $rowMatch->default);
                        }
                }

                $relationships = array();
                foreach ($o->relationships as $rship => $class) {
                        foreach ($objs[$class]->keys as $key) {
                                $rowMatch = rowMatch($orm_name, $class, $key);

                                $properties[] = sprintf("    %s__key__%s %s%s%s",
                                                        Orm::classToDbName($rship),
                                                        $rowMatch->name,
                                                        $rowMatch->type,
                                                        $rowMatch->length,
                                                        $rowMatch->default);
                        }

                        foreach ($objs[$rship]->properties as $p) {
                                $rowMatch = rowMatch($orm_name, $rship, $p);

                                $properties[] = sprintf("    %s__var__%s %s%s%s",
                                                        Orm::classToDbName($rship),
                                                        $rowMatch->name,
                                                        $rowMatch->type,
                                                        $rowMatch->length,
                                                        $rowMatch->default);
                        }
                }

                $schema .= sprintf("%s\n);\n\n", implode(",\n", array_merge($properties, $relationships)));
        }


        $form = $body->form();
        $form->method = 'post';

        $on = $form->input();
        $on->type  = 'hidden';
        $on->name  = 'orm_name';
        $on->value = $_POST['orm_name'];

        $textarea = $form->textarea($schema, Node::UNMANGLED);
        $textarea->name = 'schema';
        $textarea->cols = 100;
        $textarea->rows = 40;
        $form->br();
        $submit = $form->input();
        $submit->type = 'submit';
        $submit->value = 'Execute against database';

} elseif (sizeof($_POST) == 2 && !empty($_POST['schema'])) {
        $orm_name = $_POST['orm_name'] == '-'? NULL : $_POST['orm_name'];
        # execute against db
        #SSql::query($_POST['schema'], Orm::getSSqlName($orm_name));
        foreach (explode(';', $_POST['schema']) as $table) {
                $table = trim($table);
                if (!empty($table))
                        SSql::query($table, Orm::getSSqlName($orm_name));
        }

        $body->addText('Done!');
}

echo $html;

?>
