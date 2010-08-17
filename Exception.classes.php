<?php

/** Wrap Exception so we have sprintf() capabilities by default **/
class SPFException extends Exception
{
        public function __construct()
        {
                $args = func_get_args();
                $format = array_shift($args);
                parent::__construct(vsprintf($format, $args));
        }
}

?>
