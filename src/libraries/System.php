<?php

namespace ProcessKernel;

class System {
  const ERROR_PREFIX = "ERROR: ";
  
  public $is_active = false;
  /**
   * If needed to run or use kernel without direct output set false.
   * @var boolean 
   */
  public static $need_output = true;
  
  // Define possible arguments.
  private $status_arguments = [
    'status' => 'To get current Bit Kernel status',
    'help' => 'Display all commands.',
    '---' => '',
    'kill_all' => 'Kill all active processes and sub-processes AND pause kernel to not fire processes again.',
    'process_list' => 'Get all currently active processes.',
    'add_process' => 'To add process class to kernel queue.',
    'remove_process' => 'To remove process class from kernel queue.',
    'reload_process' => 'Reload process variables from class file.',
    'disable_process' => 'Disable process from processing.',
    'enable_process' => 'Enable process to be processed in kernel queue.',
    'restart_process' => 'Kill one type processes. They will be started again automatically.',
    'add_virtual_process' => 'To add virtual process class to kernel queue. (by passing serialised array of process object variables)',
    'reload_virtual_process' => 'To remove and re-add virtual process class to kernel queue.',
    '----' => '',
    'pause' => "Pause BitKernel operation.",
    'resume' => "Resume BitKernel operation.",
    'halt' => "Halt BitKernel process.",
  ];

  /**
   * Function for special cases when kernel need to use in silent mode
   * @param type $state
   */
  function switchOutput($state) {
    self::$need_output = $state;
  }

  /* Function to check if allowed output. */
  function canOutput() {
    return $this->need_output;
  }

  /* Function to pause indexing. */  
  function pause() {
    $this->log("Kernel paused.");
    
    $this->is_active = false;
  }
  
  /* Function to resume indexing after pause. */
  function resume() {
    $this->log("Kernel resumed.");
    
    $this->is_active = true;
  }
  
  /* Function to determine if move process is started. */
  function isActive() {
    return $this->is_active;
  }
  
  /**
   * Function to log messages in log file, direct output or database.
   * @param type $message
   * @param type $type: info, warn, debug, error.
   * @param type $source
   * @return type
   */
  public static function log($message, $type = "info", $source = "bitkernel", $msgtype = "") {
    $backtrace = debug_backtrace();
    $caller = next($backtrace);
    $caller_function = $caller['function'];
    $caller_class = $caller['class'];
    $caller_method = $caller_function."::".$caller_class;
    
    $log_message = "[" . date('Y-m-d H:i:s') . "]" .
            " " . posix_getpid() . ":" .
            " " . strtoupper($type) . ":" .
            " " . $message .
            ($caller_method ? " (" . $caller_method . ") " : '') .
            "\n";
    
    if (defined('LOG_LEVEL')) {
      switch (LOG_LEVEL) {
        case LOG_WRITE_FILE:
          // Write to log file.
          self::writeLogFile($log_message, $type);
          
          break;
        case LOG_WRITE_DB:
          /*$fields = [
            "source" => $source,
            "type" => strtoupper($type),
            "msg" => $message,
            "msgtype" => $msgtype,
          ];
          
          DB::insert('log',
            $fields
          );*/
          break;
        default:
        case LOG_DIRECT_OUTPUT:
          if (self::$need_output) {
            echo $log_message;
          }
          break;
      }
    }
  }
  
  /**
   * Function to write message log into file.
   * @param type $message
   */
  private function writeLogFile($message, $type) {
    $log_path = self::getLogDirectory();

    // if error, write it to other file.
    $filename = LOG_FILENAME;
    if ($type == "error") {
      $filename = ERROR_LOG_FILENAME;
    }
    
    if (is_dir($log_path)) {
      $handle = fopen($log_path . "/" . $filename."_".date('H').".log", 'a+');
      if ($handle) {
        fwrite($handle, $message);
        fclose($handle);
      }
    }
  }
  
  /**
   * Function to determine everyday directory for the logs.
   * @return string
   */
  private function getLogDirectory() {
    $log_path_top = LOG_DIRECTORY . 'bitkernel';
    
    $log_path = LOG_DIRECTORY . '/bitkernel/bitlog_'.date('Y-m-d');
    
    // create dir for whole day date.
    if (!is_dir($log_path_top)) {
      mkdir($log_path_top);         
    }
    
    if (!is_dir($log_path)) {
      mkdir($log_path);         
    }
    
    return $log_path;
  }
  
  /**
   * Function to structure passed arguments from command line.
   * 
   * This function can recognise following params:
   *   - regular params like "index.php param1 param2" will be like:
   *        [0] => 'index.php', [1] => 'param1', [2] => 'param2
   *   - variable params like "index.php --param1=awesome --param2=still" will be like:
   *        [param1] => 'awesome', [param2] => 'still'
   *   - flag params like "index.php -a -b -c" will be like:
   *        [a] => true, [b] => true, [c] => true
   * 
   * 
   * @param type $argv
   * @return type
   */
  function structureArguments($argv) {
    $_ARG = array();
    foreach ($argv as $arg) {
      if (preg_match('/--([^=]+)=(.*)/', $arg, $reg)) {
        $_ARG[$reg[1]] = $reg[2];
      } elseif(preg_match('/^-([a-zA-Z0-9])/', $arg, $reg)) {
        $_ARG[$reg[1]] = 'true';
      } elseif (preg_match('/--([^=]+)/', $arg, $reg)) {
        $_ARG[$reg[1]] = 1;
      } else {
        $_ARG['input'][]=$arg; 
      }
    }
    return $_ARG; 
  }
  
  //
  // MQTT status script functions.
  //
  
  /**
   * 
   * @param type $command
   * @return type
   */
  function isArgumentValid($command) {
    return (empty($command) || !isset($this->status_arguments[$command]));
  }
  
  /**
   * Function to display all possible script arguments.
   */
  function displayPossibleArguments() {
    echo "\n";
    echo "BitKernel communication script.\n";
    echo "Usage: php bitstatus.php <command>\n\n";
    
    $space_count = 30;
    foreach ($this->status_arguments as $arg_name => $arg_description) {
      $space = "";
      $arg_name = "--".$arg_name;
      for ($x=0;$x<$space_count-strlen($arg_name);$x++) {
        $space .= " ";
      }
      
      echo $arg_name.$space.$arg_description."\n";
    }
    
    echo "\n";
  }
  
  function mqttConnect($r) {
    echo "Connected\n";
  }

  function mqttMessage($message) {
    //print_r($message);
    echo "BitKernel response: ".$message->payload."\n";

    // Kill status script.
//    posix_kill(posix_getpid(), SIGHUP);
  }

  function mqttDisconnect() {
    echo "Disconnected cleanly\n";
  }  
  
  /**
   * Function to do actual call to bitkernel.
   * @param string $command
   */
  function doBitkernelCommand($command, $value="") {
    if (class_exists('Mosquitto\Client')) {
      $client = new \Mosquitto\Client();
      $client->onConnect([$this, 'mqttConnect']);
      $client->onDisconnect([$this, 'mqttDisconnect']);
      //$client->onSubscribe('subscribe');
      $client->onMessage([$this, 'mqttMessage']);
      $client->connect("0.0.0.0", 1883, 5);
      $client->subscribe('/kernel/response', 1);
    }
    
    if (empty($value)) {
      $value = date('Y-m-d H:i:s').rand(0, 10000);
    }

    // 
    $client->publish('/kernel/command/'.$command, $value, 1, 0);

    echo "Waiting for BitKernel response\n";

    $wait_for = 5;
    ob_start();
    while (true) {
      $client->loop();  
      sleep(1);
      $wait_for --;
      
      if ($wait_for == 0) {
        break;
      }
    }

    $mqtt_content = ob_get_contents();
    ob_end_clean();
    
    if (!empty($mqtt_content) && strlen($mqtt_content) > 10) {
      echo $mqtt_content;
      
      $client->disconnect();
      unset($client);
      
      return;
    }
    
    echo "\n";
    echo self::ERROR_PREFIX . "BitKernel didn't responded.\n";
  }
  
  /**
   * Function to test if php file is alive.
   * 
   * @param type $fileName
   * @param type $checkIncludes
   * @return boolean
   * @throws Exception
   */
  function checkPhpSyntax($fileName, $checkIncludes = false) {
      // If it is not a file or we can't read it throw an exception
      if (!is_file($fileName) || !is_readable($fileName))
        throw new Exception("Cannot read file ".$fileName);

      // Sort out the formatting of the filename
      $fileName = realpath($fileName);

      // Get the shell output from the syntax check command
      $output = @shell_exec('php -l "'.$fileName.'"');

      // Try to find the parse error text and chop it off
      $syntaxError = preg_replace("/Errors parsing.*$/", "", $output, -1, $count);

      // If the error text above was matched, throw an exception containing the syntax error
      if ($count > 0) {
        return true;
      }

      // If we are going to check the files includes
      if ($checkIncludes) {
        foreach ($this->getIncludes($fileName) as $include) {
          // Check the syntax for each include
          $this->checkPhpSyntax($include);
        }
      }

      return false;
    }
    
    /**
     * Function to get all includes of php file.
     * 
     * @param type $fileName
     * @return array
     */
    function getIncludes($fileName) {
        // NOTE that any file coming into this function has already passed the syntax check, so
        // we can assume things like proper line terminations
            
        $includes = array();
        // Get the directory name of the file so we can prepend it to relative paths
        $dir = dirname($fileName);
        
        // Split the contents of $fileName about requires and includes
        // We need to slice off the first element since that is the text up to the first include/require
        $requireSplit = array_slice(preg_split('/require|include/i', file_get_contents($fileName)), 1);
        
        // For each match
        foreach($requireSplit as $string) {
          // Substring up to the end of the first line, i.e. the line that the require is on
          $string = substr($string, 0, strpos($string, ";"));

          // If the line contains a reference to a variable, then we cannot analyse it
          // so skip this iteration
          if (strpos($string, "$") !== false)
            continue;

          // Split the string about single and double quotes
          $quoteSplit = preg_split('/[\'"]/', $string);

          // The value of the include is the second element of the array
          // Putting this in an if statement enforces the presence of '' or "" somewhere in the include
          // includes with any kind of run-time variable in have been excluded earlier
          // this just leaves includes with constants in, which we can't do much about
          if ($include = $quoteSplit[1]) {
            // If the path is not absolute, add the dir and separator
            // Then call realpath to chop out extra separators
            if (strpos($include, ':') === FALSE)
              $include = realpath($dir.DIRECTORY_SEPARATOR.$include);

            array_push($includes, $include);
          }
        }
        
        return $includes;
    } 
}

