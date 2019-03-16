<?php

namespace ProcessKernel;

use ProcessKernel\DbHandler;

abstract class ProcessMain extends System {
  protected $start_time;
  
  public $max_cpu_usage      = PROC_MAX_CPU_USAGE;
  public $max_mem_usage      = PROC_MAX_MEM_USAGE;
  public $max_execution_time = PROC_MAX_EXECUTION_TIME;
  public $executable         = PROC_EXECUTABLE;
  public $script_name        = PROC_SCRIPT_NAME;
  public $root_directory     = PROC_ROOT_CALLABLE;
  public $callScheduler      = "* * * * *"; // same way as CRON.
  public $enabled            = 1;
  public $last_call          = 0;
  public $call_ended         = 0;
  /**
   * Interval between calls in seconds.
   * @var integer
   */
  public $process_interval   = 0;
  /**
   * Flag to start process by cron definition variable $callScheduler.
   * @var boolean
   */
  public $checkByCron        = false;
  /**
   * If process have defined external path to run another script.
   * @var boolean 
   */
  public $is_external        = false;
  /**
   * If need to re-run script if ended/killed.
   * @var boolean
   */
  public $forever_running    = false;
  /**
   * Process not allowed to be run in parralel.
   * @var boolean
   */
  public $only_once          = false;
  
  public $rawParamsString    = [];
  public $script_classname   = "";

  // Force Extending class to define this method.
  abstract function process();

  // store class name locally.
  function __construct() {
    $script_classname = get_class($this);
    
    // Getting only short name of class, without namespace.
    $script_classname = explode("\\", $script_classname);
    $script_classname_basename = end($script_classname);
    
    // remove temp part that was loaded for basename.
    if (strpos($script_classname_basename, "_TEMP")!==FALSE) {
      list($script_classname_basename, $class_suffix) = explode("_TEMP", $script_classname_basename);
    }
    
    $this->script_classname = $script_classname_basename;
  }
  
  /**
   * Function to check if script is available for run in parralel.
   * @return boolean
   */
  public function isRunOnlyOnce() {
    return $this->only_once;
  }
  
  /**
   * Function to check if process "cron type".
   * @return boolean
   */
  public function isCheckByCron() {
    return $this->checkByCron;
  }

  /**
   * Function to get if process is external.
   * @return boolean
   */
  public function isExternal() {
    return $this->is_external;
  }

  /**
   * Function to get cron call scheduler.
   * @return boolean
   */
  public function getCallScheduler() {
    return $this->callScheduler;
  }
  
  /**
   * Function to check if process is enabled.
   * @return boolean
   */
  public function isEnabled() {
    return $this->enabled;
  }
  
  /**
   * 
   */
  public function connectToDb() {
    $this->db = DbHandler::getBotInstance();
  }
  
  /**
   * Function to get time interval till next cron call.
   * @return type
   */
  public function findNextCronTimeInterval($last_call="") {
    if (empty($last_call)) {
      $last_call = time();
    }
    $theTimeIsNow = time();

    $startTheTimeIsNow = $theTimeIsNow + 60;
    while (1) {
      $startTheTimeIsNow++;

      // Find next valid date.
      if ($this->isTimeCron($startTheTimeIsNow, $this->getCallScheduler())) {
        break;
      }
    }

    return $startTheTimeIsNow-$last_call;
  }
  
  /**
    Test if a timestamp matches a cron format or not
    //$cron = '5 0 * * *';
  */
  private function isTimeCron($time, $cron)  {
    $cron_parts = explode(' ' , $cron);
    if (count($cron_parts) != 5) {
      return false;
    }
     
    list($min , $hour , $day , $mon , $week) = explode(' ' , $cron);
     
    $to_check = array('min' => 'i' , 'hour' => 'G' , 'day' => 'j' , 'mon' => 'n' , 'week' => 'w');
     
    $ranges = array(
      'min' => '0-59',
      'hour' => '0-23',
      'day' => '1-31',
      'mon' => '1-12',
      'week' => '0-6',
    );
     
    foreach ($to_check as $part => $c) {
      $val = $$part;
      $values = array();

      /* For patters like 0-23/2 */
      if (strpos($val , '/') !== false) {
        //Get the range and step
        list($range , $steps) = explode('/' , $val);

        // Now get the start and stop
        if ($range == '*') {
          $range = $ranges[$part];
        }

        list($start , $stop) = explode('-' , $range);

        for ($i = $start ; $i <= $stop ; $i = $i + $steps) {
          $values[] = $i;
        }
      }
      /*
          For patters like :
          2
          2,5,8
          2-23
      */
      else {
        $k = explode(',' , $val);

        foreach ($k as $v) {
          if (strpos($v , '-') !== false) {
            list($start , $stop) = explode('-' , $v);

            for ($i = $start ; $i <= $stop ; $i++) {
              $values[] = $i;
            }
          } else {
            $values[] = $v;
          }
        }
      }

      if ( !in_array( date($c , $time) , $values ) and (strval($val) != '*') )  {
        return false;
      }
    }
     
    return true;
  }
  
  /**
   * Function to get class internal variable.
   * @param string $name
   * @return various.
   */
  public function _get($name) {
    return $this->{$name};
  }

  /**
   * Function to set class internal variable.
   * @param string $name
   * @param string $value
   */
  public function _set($name, $value) {
    $this->{$name} = $value;
  }
  
  /**
   * Function to check if process is callable by cron syntax.
   * @return boolean
   */
  public function checkIfCronCallNeeded() {
    // Only if check by cron flag enabled.
    if ($this->isCheckByCron()) {
      return $this->isTimeCron(time(), $this->getCallScheduler());
    }
    
    return false;
  }

  /**
   * Function to check if process is available for call by time.
   * @return boolean
   */
  public function checkIfIntervalExceeded() {
    $process_interval = $this->_get('process_interval');
    $call_ended = $this->_get('call_ended');
    
    /*
    echo "call_ended: ".$call_ended." - ".date('Y-m-d H:i:s', $call_ended)."\n";
    echo "process_interval: ".$process_interval." - ".date('Y-m-d H:i:s', $process_interval)."\n";
    echo "call_ended + process_interval: ".($call_ended + $process_interval)." - ".date('Y-m-d H:i:s', $call_ended + $process_interval)."\n";
    echo "\n\n";
    */
    
    // Check if available
    if (time() >= $call_ended + $process_interval) {
      return true;
    }
    
    return false;
  }
  
  /**
   * Function to check if current process exceeded cpu usage limit.
   * @param type $cpu
   */
  public function checkIfCpuExceeded($cpu) {
    if ($this->max_cpu_usage > 0 && (double)$cpu >= $this->max_cpu_usage) {
      return true;
    }
    
    return false;
  }

  /**
   * Function to check if current process exceeded mem usage limit.
   * @param type $mem
   */
  public function checkIfMemExceeded($mem) {
    if ($this->max_mem_usage > 0 && (double)$mem >= $this->max_mem_usage) {
      return true;
    }
    
    return false;
  }
  
  /**
   * Function to return total time of process execution.
   * @return type
   */
  public function getProcessExecutionTime() {
     $last_call = $this->start_time;
     $now = time();
     
     return $now - $last_call;
  }

  /**
   * 
   * @return type
   */
  public function getRawParamsClassName() {
    if (!isset($this->rawParamsString['--class_name'])) {
      return;
    }
    
    return $this->rawParamsString['--class_name'];
  }

  /**
   * Function to check if process need to run forever.
   * @return type
   */
  public function isForeverRunning() {
    return $this->forever_running;
  }

  /**
   * Function to do execute external process.
   * 
   * @param array $params
   *   Various params to execute process.
   * @return null
   *   
   */
  public function doExecute($params=array()) {
    // need executable params
    if (!$this->executable /*|| !$this->root_directory || !$this->script_name*/) {
      return;
    }
    
    // concat params.
    $paramsString = '';
    if (!empty($params)) {
      foreach ($params as $key => $val) {
        if (strpos($key, "--")!==FALSE) {
          $paramsString .= " " . $key . "=" . $val;
        } else {
          $paramsString .= " " . $key;
        }
      }
    }
    
    $this->rawParamsString = $params;
    $this->paramsString = $paramsString;
    $command = $this->executable . " " . $this->root_directory . $this->script_name . $this->paramsString;
    
    $this->process_pid = shell_exec("nohup $command > /dev/null 2>&1 & echo $!");
    
    $this->start_time = time();
  }

  /**
   * Function to check if process still running.
   * @return boolean
   */
  public function isRunning() {
    exec("ps $this->process_pid", $pState);     
    $running = (count($pState) >= 2);
    return $running;
  }
  
  /**
   * 
   */
  public function killProcess() {
    // first kill all subprocess.
    exec("pkill -TERM -P ".$this->process_pid);
    
    // then kill also main process.
    exec("kill -9 ".$this->process_pid);
  }
  
  /**
   * Function to get cpu and mem usage for process.
   * @return type
   */
  public function getUsageData() {    
    $pid = str_replace(array("\n", "\r"), '', $this->process_pid);
    
    $process_status = exec("pstree -p $pid | grep -o '([0-9]\+)' | grep -o '[0-9]\+' |  xargs ps -o %mem,%cpu,cmd -p | awk '{memory+=$1;cpu+=$2} END {print memory,cpu}'");

    if (strlen($process_status) > 0) {
      list($mem_usage, $cpu_usage) = explode(" ", $process_status);
      
      //if (!empty($cpu_usage) && !empty($mem_usage)) {
        return [$cpu_usage, $mem_usage];
      //}
    }
  }

  // long execution time, proccess is going to be killer
  public function isOverExecuted() {
      if ($this->start_time+$this->max_execution_time<time()) return true;
      else return false;
  }
}
