<?php

namespace ProcessKernel;

use ProcessKernel\System;
use ProcessKernel\DbHandler;

define('KERNEL_VERSION', "1.2");

/**
 * This is main class to do all stuff
 */
class Kernel extends System {
  /**
   * Namespace for build in processes.
   */
  const PROCESS_CLASS_PREFIX = "Process\\";
  
  /**
   * This is for identify system info DB table.
   * @var integer
   */
  public static $system_table_id  = 1;
  public static $scripts_classes  = [];

  /**
   * Half second for usleep() - used for kernel main cycle.
   * @var integer 
   */
  public $cycle_timeout            = 500000 * 2;
  public $maximum_threads          = 10;

  /**
   * Array of proxy addresses (not used yet).
   * @var array 
   */
  public $proxies                  = [];
  public $lastCallProxies          = [];
  
  // external processes variables
  public $scripts                  = [];
  /**
   * Scripts definition.
   * @var array
   */
  public $scripts_info             = [];
  public $scripts_call_times       = [];
  public $scripts_usages           = [];
  /**
   * Process instances.
   * @var array
   */
  public $running                  = [];
  public $kernel_started           = 0;

  /**
   * @var string - define namespace where process classes are located.
   */
  public $process_namespace        = "Moon\\Process\\"; //self::PROCESS_CLASS_PREFIX;

  /**
   * Function to do all inits.
   */
  private function init() {
    $this->connectToDb();
    $this->resume();
    $this->readSystemStatus();
    $this->readScriptInfo();
    $this->connectToMqtt();
  }
  
  /**
   * 
   * @param type $need_output
   */
  function __construct($need_output = true, $process_namespace = "") {
    // Function for special cases when kernel need to use in silent mode.
    $this->switchOutput($need_output);

    // Set custom process namespace.
    if (!empty($process_namespace)) {
      $this->setProcessNamespace($process_namespace);
    }

    $this->init();
  }
  
  function __destruct() {
    $this->log("Kernel destructor called - shouldn't be at all!");
    
    if (isset($this->mqtt)) {
      $this->mqtt->disconnect();
      unset($this->mqtt);
    }
  }

  /**
   * Function to set custom namespace.
   *
   * @param $process_namespace - namespace for custom proceses.
   */
  function setProcessNamespace($process_namespace) {
    $this->process_namespace = $process_namespace;
  }

  /**
   * Function to get custom namespace.
   *
   * @return string - namespace for custom proceses.
   */
  function getProcessNamespace() {
    return $this->process_namespace;
  }

  /**
   * 
   */
  private function connectToDb() {
    $this->db = DbHandler::getKernelInstance();
  }

  /**
   * Function to define all needed registers for MQTT connection.
   */
  private function connectToMqtt() {
    if (class_exists('Mosquitto\Client')) {
      try {
        $client = new \Mosquitto\Client();
        $client->onConnect([$this, 'mqttConnect']);
        $client->onDisconnect([$this, 'mqttDisconnect']);
        //$client->onSubscribe([$this, 'mqttSubscribe']);
        $client->onMessage([$this, 'mqttMessage']);
        $client->connect(MQTT_HOST, MQTT_PORT, MQTT_KEEPALIVE);
        $client->setReconnectDelay(MQTT_RECONNECT_DELAY);
        $client->subscribe(MQTT_KERNEL_TOPIC.'#', 1);

        // Store internally.
        $this->mqtt = $client;
        
        $this->log("mqtt ready: ".MQTT_HOST.":".MQTT_KEEPALIVE);
      } catch (\Exception $e) {
        $this->log("Cannot connect to Mosquitto server: ". $e->getMessage(), "error");
      }
    } else {
      $this->log("MQTT extention is not installed.");
    }
  }
  
  /**
   * 
   */
  function mqttConnect($r) {
    $this->log("Connection started to MQTT server @".MQTT_HOST.":".MQTT_PORT);
  }

  /*function mqttSubscribe() {
    $this->log("Subscribed to a topic\n");
  }*/

  /**
   * Function to handle all requests and make response.
   * @param type $message
   */
  function mqttMessage($message) {
    // Remove topic prefix.
    $command = str_replace(MQTT_KERNEL_TOPIC, "", $message->topic);
    
    $log = sprintf("Got MQTT request with topic: %s", $command);
    $this->log($log);
    
    $this->mqttBitkernelSwitcher($message->topic, $message->payload);
  }

  /**
   * Function to process kernel MQTT protocol commands.
   * @param string $command
   *  Command name.
   * @param string $value
   *  Command value.
   */
  function mqttBitkernelSwitcher($command, $value) {
    // Remove topic prefix.
    $command = str_replace(MQTT_KERNEL_TOPIC, "", $command);
    $this->log("Kernel command: ".$command);
    
    $error_log = "";
    switch($command) {
      case 'kill_all':
        // 
        $this->killAllActiveProcesses();
        
        // Pause Kernel.
        $this->pause();
        
        break;
      case 'process_list':
        $output = $this->generateProcessStatusList();
        
        $this->mqtt->publish('/kernel/response', $output, 1, 0);
        
        break;
      case 'add_process':
        $this->registerScript($value);
        $error_log = $this->readScriptInfo( TRUE );
        
        break;
      // will remain hidden from console.
      case 'add_virtual_process':
        $error_log = $this->registerVirtualProcess($value);
        
        break;
      // will remain hidden from console.
      // used also for update existing non-virtual processes.
      case 'reload_virtual_process':
        $error_log = $this->registerVirtualProcess($value, true);
        
        break;
      case 'remove_process':
        $error_log = $this->unRegisterScript($value);
        break;
      case 'reload_process':
        $error_log = $this->unRegisterScript($value);
        if (!$error_log) {
          $this->registerScript($value);
          $error_log = $this->readScriptInfo( TRUE );
        }
        
        break;
      case 'restart_process':
        $error_log = $this->killProcessesByClassName($value);
        
        break;
      case 'enable_process':
        $error_log = $this->enableScript($value);
        
        break;
      
      case 'disable_process':
        $error_log = $this->disableScript($value);
        
        break;
      
      // Kernel actions.
      case 'pause':
        $this->pause();
        
        break;
      case 'resume':
        $this->resume();
        
        break;
      case 'halt':
        try {
          $this->mqtt->loop(0);
        } catch (Exception $e) {
            $this->log("MQTT server crashed: ". $e->getMessage());
        }

        // Kill status script.
        posix_kill(posix_getpid(), SIGHUP);
        
        break;
    }
    if (empty($error_log)) {
      if ($command != "process_list") {
        $this->mqtt->publish('/kernel/response', $command." OK!", 1, 0);
      }
    } else {
      $this->mqtt->publish('/kernel/response', self::ERROR_PREFIX . $error_log, 1, 0);
    }
  }
  
  /**
   * Function for MQTT callback, when disconnected.
   */
  function mqttDisconnect() {
    $this->log("Disconnected cleanly from MQTT server.");
  }
  
  /**
   * Function to do virtual process register/unregister (used in mqtt input).
   * 
   * @param string $mqtt_input_value
   *   Serialised array as string (process class properties)
   * @param boolean $unregister_first
   *   Flag used to reload virtual process data.
   * @return string
   *   Error message.
   */
  private function registerVirtualProcess($mqtt_input_value, $unregister_first=false) {
    $object_vars = $this->getVirtualProcessVariables($mqtt_input_value);
    $script_classname = "";
    if (!empty($object_vars)) {
      $script_classname = $this->getVirtualProcessClassname($object_vars);

      // To be able to reload new params with no problems.
      if ($unregister_first) {
        $error_log = $this->unRegisterScript($script_classname);
      }
      
      if (!$error_log) {
        if (!empty($script_classname)) {
          $this->registerScript($script_classname);

          // Init class object and set internal variables for that object.
          $this->setVirtualProcessInstance($script_classname, $object_vars);
        } else {
          $error_log = "Error adding virtual process - empty script classname.";
        }
      }
    } else {
      $error_log = "Error adding virtual process - empty object variables.";
    }
    
    return $error_log;
  }

  /**
   * Function to output process list table labels.
   * 
   * @param string $label
   * @return string
   */
  private function prepareLabelForTable($label) {
    $space_count = 35;
    
    $space = "";
    for ($x=0;$x<$space_count-strlen($label);$x++) {
      $space .= " ";
    }

    return $label.$space;
  }
  
  /**
   * Function to output process list table spacer.
   * 
   * @return string
   */
  private function prepareTableSpacer() {
    $spacer_line_length = 80;
    
    $output = "";
    for ($x=0;$x<$spacer_line_length;$x++) {
      $output .= "=";
    }
    $output .= "\n";
    
    return $output;
  }

  /**
   * Function to list all defined processes.
   * @return string
   */
  private function generateProcessStatusList() {
    $output = "\n\n";
   
    $output.= $this->prepareTableSpacer();
    
    // Process name.
    $output .= $this->prepareLabelForTable("Process name");
    
    // Call time.
    $output .= $this->prepareLabelForTable("Next call(sec)");
    
    // Status.
    $output .= $this->prepareLabelForTable("Status");
    
    $output .= "\n";
    
    $output.= $this->prepareTableSpacer();
    
    if (!empty($this->scripts_info)) {
      
      foreach($this->scripts_info as $script_classname => $script_info) {
        if (isset(self::$scripts_classes[$script_classname])) {
          $processClass = self::$scripts_classes[$script_classname];
          
          $process_interval = $processClass->_get('process_interval');
          $processEnabled = $processClass->isEnabled();
          $processEnabledString = ($processEnabled ? "Active" : "Inactive");

          // Process classname.          
          $output .= $this->prepareLabelForTable($script_classname);
          
          // Process interval.
          $output .= $this->prepareLabelForTable($process_interval);
          
          // Process status.
          $output .= $this->prepareLabelForTable($processEnabledString);
          
          $output .= "\n";
        }
      }
    }
    
    $output.= $this->prepareTableSpacer();
    
    return $output;
  }

  /**
   * Function that is doing actual shutdown of process and clean up storages.
   * 
   * @param type $processObj - process object from running array.
   * @param type $process_number - process object position in array.
   */
  private function doProcessShutdown($processObj, $process_number) {
    $script_classname = get_class($processObj);
    
    $this->log( "Killed by killall command PID: ".trim($processObj->process_pid).", ".$processObj->script_name . $processObj->paramsString, "error" );

    $processObj->killProcess();

    // Set script ending time.
    $this->setEndingRequestTime($script_classname);

    // Save Execution time for process that ended.
    $this->storeScriptCallTime($script_classname, -1);
    
    // Release script running definition.
    $this->unsetIsRunning($script_classname);

    // Release process object.
    unset($this->running[$process_number]);
  }
  
  /**
   * Function that goes through running array and kills all active processes.
   */
  private function killAllActiveProcesses() {
    // check what is done
    foreach ($this->running as $process_number => $processObj) {
      if ($processObj->isRunning()) {
        $this->doProcessShutdown($processObj, $process_number);
      }
    }
  }

  /**
   * Function to init all proxies last call array.
   * @return type
   */
  function initProxies() {
    if (empty($this->proxies)) {
      return;
    }
    
    foreach ($this->proxies as $proxy_url) {
      $this->lastCallProxies[$proxy_url] = 0;
    }
  }
  
  /* Function to get last request time. */  
  function getLastRequestTime() {
    if (empty($this->proxies)) {
      return;
    }
    
    // init time, if not set yet
    foreach ($this->proxies as $proxy_url) {
      if (!isset($this->lastCallProxies[$proxy_url])) {
        $this->lastCallProxies[$proxy_url] = 0;
      }
    }
  }
  
  /**
   * Function to register proxy urls into kernel.
   * @param type $url
   */
  function registerProxy($url) {
    $this->proxies[] = $url;
  }
  
  /**
   * Function to get last classname.
   * 
   * @param string $script_classname - fullpath classname.
   * @return type - lastclassname.
   */
  public function getLastClassname($script_classname) {
    // Getting only short name of class, without namespace.
    $script_classname = explode("\\", $script_classname);
    $script_classname = trim(end($script_classname));
    
    return $script_classname;
  }

  /**
   * Function to set process ending time.
   * @param string $script_classname - process classname.
   */
  private function setEndingRequestTime($script_classname) {
    // Getting only short name of class, without namespace.
    $script_classname = $this->getLastClassname($script_classname);
    
    if (!$script_classname) {
      return;
    }

    // Set also last_call for process definition.
    if (isset($this->scripts_info[$script_classname])) {
      $this->scripts_info[$script_classname]['call_ended'] = time();
    }
    
    // Set Last call time.
    if (isset(self::$scripts_classes[$script_classname])) {
      $processClass = self::$scripts_classes[$script_classname];
      $processClass->_set('call_ended', time());
    }
  }

  /**
   * Function to set process last request time.
   * @param string $script_classname - process classname.
   */
  private function setLastRequestTime($script_classname) {
    // Getting only short name of class, without namespace.
    $script_classname = $this->getLastClassname($script_classname);
    
    if (!$script_classname) {
      return;
    }
    
    // Set also last_call for process definition.
    if (isset($this->scripts_info[$script_classname])) {
      $this->scripts_info[$script_classname]['last_call'] = time();
    }
    
    // Set Last call time.
    if (isset(self::$scripts_classes[$script_classname])) {
      $processClass = self::$scripts_classes[$script_classname];
      $processClass->_set('last_call', time());
    }
  }
  
  /**
   * Function to store locally execution time of script.
   * 
   * @param type $script_classname
   * @param type $time
   */
  private function storeScriptCallTime($script_classname, $execution_time) {
    $current_time = time();
    
    // Getting only short name of class, without namespace.    
    $script_classname = $this->getLastClassname($script_classname);
    
    $this->scripts_call_times[$script_classname][$current_time] = $execution_time;
    
    // Sort by time it was added.
    ksort($this->scripts_call_times[$script_classname], SORT_NUMERIC);
    
    $output = array_slice($this->scripts_call_times[$script_classname], -PROC_SCRIPT_TIME_MAX_COUNT, PROC_SCRIPT_TIME_MAX_COUNT, true);
    
    unset($this->scripts_call_times[$script_classname]);
    $this->scripts_call_times[$script_classname] = $output;
  }
  
  /**
   * Function to store locally mem and cpu usage of script.
   * @param type $script_classname
   * @param type $cpu_usage
   * @param type $mem_usage
   * @return type
   */
  private function storeScriptUsage($script_classname, $cpu_usage, $mem_usage) {
    $current_time = time();
    
    if (empty($cpu_usage) && empty($mem_usage)) {
      return;
    }
    
    // Getting only short name of class, without namespace.
    $script_classname = $this->getLastClassname($script_classname);
    
    $this->scripts_usages[$script_classname]['cpu'][$current_time] = $cpu_usage;
    $this->scripts_usages[$script_classname]['mem'][$current_time] = $mem_usage;
    
    // Sort by time it was added.
    ksort($this->scripts_usages[$script_classname]['cpu'], SORT_NUMERIC);
    ksort($this->scripts_usages[$script_classname]['mem'], SORT_NUMERIC);
    
    // slice.
    $output_cpu = array_slice($this->scripts_usages[$script_classname]['cpu'], -PROC_SCRIPT_TIME_MAX_COUNT, PROC_SCRIPT_TIME_MAX_COUNT, true);
    $output_mem = array_slice($this->scripts_usages[$script_classname]['mem'], -PROC_SCRIPT_TIME_MAX_COUNT, PROC_SCRIPT_TIME_MAX_COUNT, true);
    
    unset($this->scripts_usages[$script_classname]['cpu']);
    unset($this->scripts_usages[$script_classname]['mem']);
    
    $this->scripts_usages[$script_classname]['cpu'] = $output_cpu;
    $this->scripts_usages[$script_classname]['mem'] = $output_mem;
  }


  /**
   * Function to find and kill all processes by classname.
   * @param type $script_classname
   */
  private function killProcessesByClassName($script_classname) {
    if (!isset(self::$scripts_classes[$script_classname])) {
      return "Cannot restart! Class name ".$script_classname." not found.";
    }
    
    $running_processes = $this->findRunningProcessByName($script_classname);
    if (!empty($running_processes)) {
      foreach($running_processes as $processObj) {
        $processObj->killProcess();
        
        return false;
      }
    }
  }

    /**
   * Function to find running process in process list by classname.
   * @param type $script_classname
   * @return boolean
   */
  private function findRunningProcessByName($script_classname) {
    $running_processes = [];
    
    if (!empty($this->running)) {      
      foreach ($this->running as $running_script_object) {
        if (!empty($running_script_object) && is_object($running_script_object)) {
          $running_script_class_name = get_class($running_script_object);
          
          // Getting only short name of class, without namespace.
          $running_script_class_name_basename = $this->getLastClassname($running_script_class_name);
          
          $running_script_script_classname = $running_script_object->script_classname;
          
          // Say yes to the dress! :D
          if ($script_classname == $running_script_class_name_basename ||
              $script_classname == $running_script_script_classname) {
            $running_processes[] = $running_script_object;
          }
        }
      }
    }
    
    if (!empty($running_processes)) {  
      return $running_processes;
    }
    
    return false;
  }

  /**
   * Function to get next timestamp when cron will be called.
   * @param type $processClass
   * @param type $last_call
   */
  private function recalculateCronInterval($processClass, $last_call, $script_classname) {
    // If process is cron type, calculate next time interval.
    $next_process_interval = $processClass->findNextCronTimeInterval($last_call);

    // Set also last_call for process definition.
    if (isset($this->scripts_info[$script_classname])) {
      $this->scripts_info[$script_classname]['process_interval'] = $next_process_interval;
    }
    
    $processClass->_set('process_interval', $next_process_interval);
  }

  /**
   * Function to check if process is available to be called.
   * 
   * @param type $prevTime
   * @return boolean - available or no.
   */
  private function checkAvailableScripts($script_info, $script_classname) {
    if (!isset(self::$scripts_classes[$script_classname])) {
      return false;
    }
    
    $processClass = self::$scripts_classes[$script_classname];
    
    // If process not enabled, do not check anything at all.
    if (!$processClass->isEnabled()) {
      return false;
    }
    
    if (!$processClass->isCheckByCron()) {
      // If script is marked as needed for forever run and it is stopped.
      if ($processClass->isForeverRunning() && !$this->findRunningProcessByName($script_classname)) {
        return true;
      }

      // Check if process available for parallel runs.
      if ($processClass->isRunOnlyOnce() && $this->findRunningProcessByName($script_classname)) {
        return false;
      }
    }
    
    if ($processClass->isCheckByCron()) {
      $last_call = $processClass->_get('call_ended');
      
      // Calculate next cron call if process is not called before at all.
      if (empty($last_call)) {
        $this->recalculateCronInterval($processClass, $last_call, $script_classname);
        
        // Set script time already processed..
        //$process_class_instance = $this->scripts_info[$script_classname];
        //$process_class_instance->_set('last_call', time());
        //$processClass->_set('last_call', time());
        $this->setLastRequestTime($script_classname);
      }
    }
    
    $valid_call = $processClass->checkIfIntervalExceeded();
    
    // If process is valid for call, recalculate next call.
    if ($processClass->isCheckByCron()) {
      if ($valid_call) {
        $last_call = $processClass->_get('call_ended');

        $this->recalculateCronInterval($processClass, $last_call, $script_classname);
      }
    }
    
    return $valid_call;
  }
  
  /**
   * Function to check available scripts.
   * @return type
   */
  private function checkIfRequestPossible() {
    $available_scripts = [];
    
    if (!empty($this->scripts_info)) {
      foreach($this->scripts_info as $script_classname => $script_info) {
        if ($this->checkAvailableScripts($script_info, $script_classname)) {
          $available_scripts[$script_classname] = $script_info;
        }
      }
    } else {
      $this->log("No scripts defined.");
    }
    
    return $available_scripts;
  }

  /**
   * Function to disable script/process.
   * @param type $script_classname
   */
  private function disableScript($script_classname) {
    if (isset(self::$scripts_classes[$script_classname])) {
      $process_class_instance = self::$scripts_classes[$script_classname];
      
      $process_class_instance->_set('enabled', 0);
      
      $this->scripts_info[$script_classname] = get_object_vars($process_class_instance);
    } else {
      return "Cannot disable! Class name ".$script_classname." not found.";
    }
    
    return false;
  }
  
  /**
   * Function to enable script/process.
   * @param type $script_classname
   */
  private function enableScript($script_classname) {
    if (isset(self::$scripts_classes[$script_classname])) {
      $process_class_instance = self::$scripts_classes[$script_classname];
      
      $process_class_instance->_set('enabled', 1);
      
      $this->scripts_info[$script_classname] = get_object_vars($process_class_instance);
    } else {
      return "Cannot enable! Class name ".$script_classname." not found.";
    }
    
    return false;
  }
  
  /**
   * Function to add script class to kernel internal array to be processes.
   * @param string $scriptClassName
   */
  private function registerScript($scriptClassName) {
    $this->scripts[] = $scriptClassName;
  }
  
  /**
   * @param type $scriptClassName
   */
  private function unRegisterScript($scriptClassName) {
    $scriptPosition = $this->isScriptExists($scriptClassName);
    
    if (isset(self::$scripts_classes[$scriptClassName]) || !is_null($scriptPosition)) {
      // Remove script also from script register.
      if (!is_null($scriptPosition)) {
        array_splice($this->scripts, $scriptPosition, 1);
      }

      // Need to remove also from scripts_info array.
      unset($this->scripts_info[$scriptClassName]);

      // Unregister also class instance and array elem with that instance.
      if (isset(self::$scripts_classes[$scriptClassName])) {
        $process_class = self::$scripts_classes[$scriptClassName];
        unset($process_class);
        unset(self::$scripts_classes[$scriptClassName]);
      }
      
      // Unset also mem/cpu and process time array elements for this process.
      unset($this->scripts_usages[$scriptClassName]);
      unset($this->scripts_call_times[$scriptClassName]);
    } else {
      return "Cannot unregister! Class name ".$scriptClassName." not found.";
    }
    
    return false;
  }
  
  /**
   * Function to determine if script is added to kernel internal array.
   * @param type $scriptClassName
   */
  private function isScriptExists($scriptClassName) {
    if (!empty($this->scripts)) {
      if (in_array($scriptClassName, $this->scripts)) {
        return array_search($scriptClassName, $this->scripts);
      }
    }
    
    return null;
  }
    
  /**
   * Function to update date into db, when kernel is started.
   */
  private function updateKernelStartDate($stop=false) {
    $this->kernel_started = !$stop ? time() : 0;
  }
  
  /**
   * Function to kill process.
   * @param type $pid
   * @return type
   */
  private function kill($pid){ 
    return stripos(php_uname('s'), 'win')>-1  ? exec("taskkill /F /T /PID $pid") : exec("kill -9 $pid");
  }
  

  /**
   * Function to unset objects, that is not needed to store in db.
   * @param type $object
   */
  private function unsetNotNeeded(&$object) {
    // quick fix - to remove recursive dependencies.
    // @TODO - need some proper way to determine it.
    foreach ($object->scripts_info as $script_name => &$scripts_info) {
      if (isset($scripts_info["callback_bots"]) && !empty($scripts_info["callback_bots"])) {
        foreach ($scripts_info["callback_bots"] as $bot_name) {
          if (isset($scripts_info["temp_".$bot_name])) {
            unset($scripts_info["temp_".$bot_name]);
            
            unset($object->{"temp_".$bot_name});
          }
        }
      }
    }

    // erase also from object.
    if (isset($object->callback_bots) && !empty($object->callback_bots)) {
      foreach ($object->callback_bots as $bot_name) {
        if (isset($object->{"temp_".$bot_name})) {          
          unset($object->{"temp_".$bot_name});
        }
      }
    }
  }

  /**
   * Function to write kernel class internal variables into db.
   */
  private function updateSystemStatus() {
    $object_vars = get_object_vars($this);
    
    // Special objects not to be writed.
    unset($object_vars["mqtt"]);
    unset($object_vars["db"]);
    
    $object_vars_serialized = serialize($object_vars);
    
    $this->db->update('bit_system', [
      'id' => self::$system_table_id, //primary key
      'data' => $object_vars_serialized
    ]);
  }
  
  /**
   * Function to read kernel internal variables from DB.
   * 
   * @return boolean - false if no results.
   */
  public function loadSystemStatus() {
    $results = $this->db->select('bit_system', [
      'data'
    ],[
      'id' => self::$system_table_id
    ]);
    
    if (!empty($results)) {
      $results = array_pop($results);

      if (!empty($results) && !empty($results['data'])) {
        return unserialize($results['data']);
      }
    }
    
    return false;
  }


  /**
   * Function to read kernel internal variables from DB.
   */
  private function readSystemStatus() {
    $data = $this->loadSystemStatus();
    
    // Set back variables from DB to class variables.
    if (!empty($data)) {
      $this->setObjectVars($this, $data);
    } else {
      $this->db->insert('bit_system', [
        'data' => ''
      ]);
    }
  }
  
  /**
   * Function to set object vars from array.
   * @param type $object
   * @param array $vars
   */
  private function setObjectVars($object, $vars) {
    foreach ($vars as $name => $value) {
      $object->{$name} = $value;
    }
  }

  /**
   * Function to help out with path to process class definition.
   * @param type $script_classname
   * @return type
   */
  private function getClassRootPath($script_classname) {
    return PROC_ROOT . "processes/" . $script_classname . '.php';
  }

  /**
   * Function to ready class definition and re-define all variables.
   */
  private function reloadClassDefinition($script_classname) {
    $filename = $this->getClassRootPath($script_classname);

    $contents = "";
    $handle = fopen($filename, "r");
    if ($handle) {
      $contents = fread($handle, filesize($filename));
      $contents = str_replace("<?php", "", $contents);

      $class_suffix = rand(0, 10000);
      $new_class_name = $script_classname."_TEMP".$class_suffix;
      $contents = str_replace("class ".$script_classname, "class ".$new_class_name, $contents);

      fclose($handle);
    }

    if (!empty($contents)) {
      if (isset(self::$scripts_classes[$script_classname])) {
        eval($contents);
        
        $process_class_instance = self::$scripts_classes[$script_classname];
        
        $new_class_name_full = $this->getProcessNamespace() . $new_class_name;
        $process_class_instance_temp = new $new_class_name_full();
        $new_object_vars = get_object_vars($process_class_instance_temp);
        
        if (!empty($new_object_vars)) {
          $this->setObjectVars($process_class_instance, $new_object_vars);
        }
        
        $this->scripts_info[$script_classname] = $new_object_vars;
        
        unset($process_class_instance_temp);
      }
    }
  }
  
  /**
   * Function to init class object and set internal variables for that object.
   * 
   * @param string $script_classname
   *   Process classname.
   * @param array $object_vars
   *   Process variables array.
   * @return object
   *   Process object.
   */
  private function setVirtualProcessInstance($script_classname, $object_vars) {
    $new_class_name_full = self::PROCESS_CLASS_PREFIX . "emptyClass";
    $process_class_instance = new $new_class_name_full;

    //$object_vars = get_object_vars($process_class_instance);
    $this->scripts_info[$script_classname] = $object_vars;

    // Set class instance to static array.
    self::$scripts_classes[$script_classname] = $process_class_instance;

    // Load class file and re-define all class variables.
    //$this->reloadClassDefinition($script_classname);

    // Set object vars after load from db.
    $this->setObjectVars($process_class_instance, $this->scripts_info[$script_classname]);
    
    return $process_class_instance;
  }

  /**
   * Function to unserialise object variables
   * 
   * @param string $script_definition
   *   Serialised array passed as string.
   * @return boolean
   *   False if not valid input value, array if correct.
   */
  private function getVirtualProcessVariables($script_definition) {
    if (empty($script_definition)) {
      return false;
    }
    
    $object_vars = unserialize($script_definition);
    if (empty($object_vars) || !is_array($object_vars)) {
      return false;
    }
    
    return $object_vars;
  }
  
  /**
   * 
   * @param type $object_vars
   * @return boolean/string
   *   False if error, classname if 
   */
  private function getVirtualProcessClassname($object_vars) {
    if (!isset($object_vars['script_classname'])) {
      return false;
    }
    
    return $object_vars['script_classname'];
  }

  /**
   * Function to set process internal variables from process definition.
   * @param object $newProcessInstance
   * @param string $script_classname
   */
  private function readClassDefinition($newProcessInstance, $script_classname) {
    if (isset(self::$scripts_classes[$script_classname])) {
      $process_class_instance = self::$scripts_classes[$script_classname];
      
      $new_object_vars = get_object_vars($process_class_instance);
      
      //echo "\n".serialize($new_object_vars)."\n\n";
      
      if (!empty($new_object_vars)) {
        $this->setObjectVars($newProcessInstance, $new_object_vars);
      }
    }
  }

  /**
   * Function to load script objects and save locally their params.
   * 
   * @param boolean $break_if_syntax_error
   *   Function will return error if one of processes have syntax error.
   * @return string
   *   Error message.
   */
  private function readScriptInfo($break_if_syntax_error = false) {
    if (!empty($this->scripts)) {
      foreach ($this->scripts as $script_classname) {
        if (!isset(self::$scripts_classes[$script_classname])) {
          $filename = $this->getClassRootPath($script_classname);
                  
          // Check if class file exists.
          if (file_exists($filename)) {
            // Check if php file have valid syntax.
            $valid_syntax = $this->checkPhpSyntax($filename);

            if ($valid_syntax) {
              $error_message = "Syntax error in ".$script_classname;

              $this->log($error_message);

              // Need to give response to MQTT in special cases.
              if ($break_if_syntax_error) {
                return $error_message;
              }

              continue;
            }
            
            $script_classname_full = $this->getProcessNamespace() . $script_classname;
            $process_class_instance = new $script_classname_full();

            // Load only initial process class info.
            if (!isset($this->scripts_info[$script_classname])) {
              $object_vars = get_object_vars($process_class_instance);
              $this->scripts_info[$script_classname] = $object_vars;
              
              //echo serialize($object_vars);
              
              // Set class instance to static array.
              self::$scripts_classes[$script_classname] = $process_class_instance;

              // Load class file and re-define all class variables.
              $this->reloadClassDefinition($script_classname);
            } else {
              // Set object vars after load from db.
              $this->setObjectVars($process_class_instance, $this->scripts_info[$script_classname]);
              
              // Set class instance to static array.
              self::$scripts_classes[$script_classname] = $process_class_instance;
            }
          } else {
            // Check if process is virtual.
            if (isset($this->scripts_info[$script_classname])) {
              // Init class object and set internal variables for that object.
              $this->setVirtualProcessInstance($script_classname, $this->scripts_info[$script_classname]);
            } else {
              $error_log = "Process ".$script_classname."(".$filename.") class file not found in ".PROC_ROOT . "processes/";

              $this->log($error_log);
              $this->unRegisterScript($script_classname);

              return $error_log;
            }
          }
        }
      }
    } else {
      $this->log("No scripts defined.");
    }
  }
  
  /**
   * Function to release process to be called again. If $only_once is set.
   * @param string $script_classname
   */
  private function unsetIsRunning($script_classname) {
    if (isset(self::$scripts_classes[$script_classname])) {
      $processClass = self::$scripts_classes[$script_classname];
      
      // Set that process is running.
      $processClass->_set('running', false);
    }
  }

  /**
   * Function to save active process info to script definition.
   * @param type $script_classname
   * @return boolean
   */
  private function refreshScriptInfo($script_classname) {
    if (empty($script_classname)) {
      return false;
    }
    
    if (isset(self::$scripts_classes[$script_classname])) {
      $process_class_instance = self::$scripts_classes[$script_classname];
      
      $object_vars = get_object_vars($process_class_instance);
      $this->scripts_info[$script_classname] = $object_vars;
    }
  }


  /**
   * Function to return timeout time for usleep() function.
   * @return type
   */
  private function getCycleMicrosendons() {
    return $this->cycle_timeout;
  }
  
  /**
   * Function that will be looped in neverending loop.
   * 
   * Will call subprocess to handle import and check time interval.
   */
  public function start() {
    // update started date
    $this->updateKernelStartDate();
    
    do {
      // If defined MQTT, trying to read messages.
      if (class_exists('Mosquitto\Client')) {
        try {
          @$this->mqtt->loop(0);
        } catch (\Exception $e) {
          $this->log("MQTT server crashed: ". $e->getMessage(), "error");
          
          // Try to reconnect.
          $this->connectToMqtt();
        }
      }
      
      $this->log("HIT...");
      
      // Tick.
      usleep($this->getCycleMicrosendons());
      
      // write status info for kernel.
      $this->updateSystemStatus();
      
      // Check if kernel is active.
      if (!$this->isActive()) {
        continue;
      }
      
      // check if proxy call is possible
      foreach ($this->checkIfRequestPossible() as $script_classname => $script_info) {
        $processesRunning = count($this->running);
        
        if ($processesRunning < PROC_MAX_PROCESSES) {
          $params = [];
          
          $script_classname_full = $this->getProcessNamespace() . $script_classname;
          if (class_exists($script_classname_full)) {
            $newProcess = new $script_classname_full();

            // Function to set process internal variables from process definition.
            $this->readClassDefinition($newProcess, $script_classname);
          } else {
            // If class is not found, that could mean that it is virtual process 
            // without php file on disk.
            
            $newProcess = $this->setVirtualProcessInstance($script_classname, $script_info); 
            
            print_r($script_info);
          }
          
          if (!is_object($newProcess)) {
            $this->log("No valid process object for ".$script_classname);
            continue;
          }
          
          // Gather process params only for internal scripts.
          if (!$newProcess->isExternal()) {
            $params = [
              '--class_name' => $script_classname,
            ];
          }
          
          $newProcess->doExecute($params);
          
          //$this->unsetNotNeeded($newProcess);
          $this->running[] = $newProcess;

          $this->log("Action fired to ".$script_classname." (Processes running: ".$processesRunning."): ");
          
          // Set starting time of script.
          $this->setLastRequestTime($script_classname);
          
          // ---
          $this->refreshScriptInfo($script_classname);
        }
      }
      
      // check what is done
      foreach ($this->running as $process_number => $processObj) {
        if (!$processObj->isRunning() || (!$processObj->isForeverRunning() && $processObj->isOverExecuted())) {
          $script_classname = $processObj->script_classname;
          if (empty($script_classname)) {
            $script_classname = get_class($processObj);
          }

          if (!$processObj->isRunning()) {
            $this->log( "Done: PID:".trim($processObj->process_pid).", ".$processObj->script_name . $processObj->paramsString );

            // Save Execution time for process that ended.
            $execution_time = $processObj->getProcessExecutionTime();

            // Set script ending time.
            $this->setEndingRequestTime($script_classname);

            $this->storeScriptCallTime($script_classname, $execution_time);
          } else {
            $this->log( "Killed: PID: ".trim($processObj->process_pid).", ".$processObj->script_name . $processObj->paramsString, "error" );

            $processObj->killProcess();

            // Set script ending time.
            $this->setEndingRequestTime($script_classname);

            // Save Execution time for process that ended.
            $this->storeScriptCallTime($script_classname, -1);
          }

          // Release script running definition.
          $this->unsetIsRunning($script_classname);

          // Release process object.
          unset($this->running[$process_number]);
        } else {
          list($cpu_usage, $mem_usage) = $processObj->getUsageData();
          
          //$this->log( "CPU: ".$cpu_usage.", MEM: ".$mem_usage );
          
          // Check if mem/cpu usage exceeded allowed limits, if so kill process.
          if ($processObj->checkIfCpuExceeded($cpu_usage) || 
              $processObj->checkIfMemExceeded($mem_usage)) {
            $this->log( "Killed by cpu/mem check: PID: ".trim($processObj->process_pid).", ".$processObj->script_name . $processObj->paramsString, "error" );
            
            $processObj->killProcess();
          }
          
          // Save Execution time for process that ended.
          $script_classname = get_class($processObj);
         
          $this->storeScriptUsage($script_classname, $cpu_usage, $mem_usage);
        }
      }
      
    } while(true); 
  }
}
