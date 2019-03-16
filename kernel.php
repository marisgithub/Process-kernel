
#!/usr/local/bin/php
<?php
//
// This script is made only for one purpose - to get rocket to the Mars!
//
// TIP: execute script in background "nohup php bitkernel.php --root > /dev/null &"
// TIP 2: path to php executable for linux: /usr/bin/php
// 
//

use ProcessKernel\System;
use ProcessKernel\Kernel;

require 'vendor/autoload.php';
require 'platform_config.php';

set_time_limit(0);

if (!empty($argv)) {
  $args = System::structureArguments($argv);
  
  if (!isset($args['class_name'])) {
    // Create kernel object.
    $kernel = new Kernel();
    $kernel->start();
    // Destroy kernel object.
    unset($kernel);    
  } else {
    $class_name = isset($args['class_name']) ? $args['class_name'] : '';
    if (!empty($class_name)) {
     $class_name = "\\bitkernel\\processes\\" . $class_name;
      
     $new_process = new $class_name();
     $new_process->process();
    }
  }
}
