<?php

namespace Process;

use ProcessKernel\ProcessMain;

class testClass3 extends ProcessMain {
  public $process_interval   = 1; // Interval between calls in seconds.
  
  function process() {
    // DO something.
    sleep(10);
  }
}