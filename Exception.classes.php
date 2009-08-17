<?php

# Wrap Exception so we have sprintf() capabilities by default
class SPFException extends Exception
{
        public function __construct()
        {
                $args = func_get_args();
                parent::__construct(call_user_func_array('sprintf', $args));
        }
}

?>
