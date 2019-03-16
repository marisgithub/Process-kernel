<?php

namespace Process;

use ProcessKernel\ProcessMain;

// Used only for adding virtual process - with not exising php class on disk.
class emptyClass extends ProcessMain {          
  function process() {
    // DO something.
  }
}
