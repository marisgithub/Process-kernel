#!/usr/local/bin/php
<?php

use ProcessKernel\System;

chdir(__DIR__); // Make sure we are in the script dir
require '../../vendor/autoload.php';
require_once("../../platform_config.php");
//
// Script to communitate with Bit Kernel.
//

if (class_exists('Mosquitto\Client')) {
  set_time_limit(0);
  
  $system = new System();
  
  if (!empty($argv)) {
    $args = $system->structureArguments($argv);
    
    if (count($args) == 1) {
      // No options added.
      $system->displayPossibleArguments();
    } elseif (count($args) > 2) {
      echo "Cannot pass more than one argument.\n";
      
      $system->displayPossibleArguments();
    } else {
      $arg_name = "";
      
      foreach($args as $arg_name => $arg_value) {
        if ($arg_name != "input") {
          break;
        }
      }
      
      // check if argument is valid.
      if ($system->isArgumentValid($arg_name)) {
        echo "Invalid argument ".$arg_name."\n";
        
        $system->displayPossibleArguments();
      } else {
        // Finally we have valid argument.
        $system->doBitkernelCommand($arg_name, $arg_value);
      }
    }
  }
} else {
  echo "MQTT php extention is not installed.";
}