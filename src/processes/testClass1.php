<?php

namespace Process;

use ProcessKernel\ProcessMain;

class testClass1 extends ProcessMain {
  public $process_interval   = 500; // Interval between calls in seconds.
  public $checkByCron        = false; // Flag to start process by cron definition variable $callScheduler.
  public $only_once          = true;
  public $forever_running    = false;
  public $max_execution_time = 301;
          
  function process() {
    // DO something.
    
    sleep(300);
  }
}
