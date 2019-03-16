<?php

namespace Process;

use ProcessKernel\ProcessMain;

class testClass4 extends ProcessMain {
  public $process_interval   = 1; // Interval between calls in seconds.
  public $executable         = 'java -cp "/vagrant/public/bit_kernel/scripts/*"';
  public $script_name        = "com.disney.SleepingBeauty 10";
  public $root_directory     = "";
  public $is_external        = true;
  public $only_once          = true;
  public $forever_running    = true;
          
  function process() {
    // DO nothing for external scripts.
  }
}