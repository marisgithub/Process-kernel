<?php

// Handles Database connection
// Requires platform_config.php file to be accessible in the same direction

namespace ProcessKernel;

use Medoo\Medoo;

/**
 * @package exchange\db
 * Handles database connection. Singleton - only one object of this class is always created.
 */
class DbHandler {
  /** @var DbHandler */
  private static $kernel_instance;

  /** @var Medoo */
  private $db_conn;

  /**
   * Get instance of Medoo object handling connection to Kernel DB.
   * @return Medoo|null Medoo DB handling object
   */
  public static function getKernelInstance() {
    if (!self::$kernel_instance) {
      // Lazy init of the handler
      if (!defined("KERNEL_DB_USER")) {
        throw new Exception("Platform config must define KERNEL_DB_USER and other constants!");
        //SystemService::logError("Platform config must define KERNEL_DB_USER and other constants!");
        //SystemService::logError("Could not initialize DbHandler");
        //return null;
      }
      self::$kernel_instance = self::createDbHandler(KERNEL_DB_DBNAME, KERNEL_DB_USER,
        KERNEL_DB_PASSWORD, KERNEL_DB_HOST, KERNEL_DB_PORT);
    }
    return self::$kernel_instance ? self::$kernel_instance->db_conn : null;
  }

  /**
   * Make constructor private to prohibit calling new DbHandler() directly
   * @param string $db_name Database name
   * @param string $db_user Database user
   * @param string $db_passw Database password
   * @param string $db_host Database host
   * @param int $db_port Database TCP port
   */
  private function __construct($db_name, $db_user, $db_passw, $db_host, $db_port) {
    $this->db_conn = new Medoo([
      'database_type' => 'mysql',
      'database_name' => $db_name,
      'server' => $db_host,
      'username' => $db_user,
      'password' => $db_passw,
      // [optional]
      'charset' => 'utf8',
      'port' => $db_port]);
  }

  /**
   * @param string $db_name Database name
   * @param string $db_user Database user
   * @param string $db_passw Database password
   * @param string $db_host Database host
   * @param int $db_port Database TCP port
   * @return bool|DbHandler false on error
   */
  private static function createDbHandler($db_name, $db_user, $db_passw, $db_host, $db_port) {
    try {
      $h = new DbHandler($db_name, $db_user, $db_passw, $db_host, $db_port);
      return $h;
    } catch (\PDOException $ex) {
      throw new Exception("Could not create DB connection: " . $ex->getMessage());

      //SystemService::logError("Could not create DB connection: " . $ex->getMessage());
      //return false;
    }
  }

}