<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 5                                                  |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
 */


// escape early if called directly
defined('_JEXEC') or die('No direct access allowed');

global $civicrmUpgrade;
$civicrmUpgrade = FALSE;
function civicrm_setup() {
  // Check for php version and ensure its greater than minPhpVersion
  $minPhpVersion = '7.3.0';
  if (version_compare(PHP_VERSION, $minPhpVersion) < 0) {
    echo "CiviCRM requires PHP version $minPhpVersion or greater. You are running PHP version " . PHP_VERSION . "<p>";
    exit();
  }

  global $adminPath, $compileDir;

  $adminPath = JPATH_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'com_civicrm';

  $jConfig = new JConfig();
  set_time_limit(4000);

  // Path to the archive
  $archivename = $adminPath . DIRECTORY_SEPARATOR . 'civicrm.zip';

  // a bit of support for the non-alternaive joomla install
  if (file_exists($archivename)) {
    // ensure that the site has native zip, else abort
    if (
      !function_exists('zip_open') ||
      !function_exists('zip_read')
    ) {
      echo "Your PHP version is missing  zip functionality. Please ask your system administrator / hosting provider to recompile PHP with zip support.<p>";
      echo "If this is a new install, you will need to uninstall CiviCRM from the Joomla Extension Manager.<p>";
      exit();
    }

    $extractdir = $adminPath;
    $archive = new Joomla\Archive\Archive;
    $archive->extract($archivename, $extractdir);
  }

  $scratchDir = JPATH_SITE . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'civicrm';
  if (!is_dir($scratchDir)) {
    JFolder::create($scratchDir, 0777);
  }

  $compileDir = $scratchDir . DIRECTORY_SEPARATOR . 'templates_c';
  if (!is_dir($compileDir)) {
    JFolder::create($compileDir, 0777);
  }

  $db = JFactory::getDBO();
  $db->setQuery(' SELECT count( * )
FROM information_schema.tables
WHERE table_name LIKE "civicrm_domain"
AND table_schema = "' . $jConfig->db . '" ');

  global $civicrmUpgrade;
  $civicrmUpgrade = ($db->loadResult() == 0) ? FALSE : TRUE;
}

function civicrm_write_file($name, &$buffer) {
  JFile::write($name, $buffer);
}

function civicrm_main() {
  global $civicrmUpgrade, $adminPath;

  civicrm_setup();

  // setup vars
  $configFile = $adminPath . DIRECTORY_SEPARATOR . 'civicrm.settings.php';

  // generate backend config file
  $string = "
<?php
define('CIVICRM_SETTINGS_PATH', '$configFile');
\$error = @include_once( '$configFile' );
if ( \$error == false ) {
    echo \"Could not load the settings file at: {$configFile}\n\";
    exit( );
}

// Load class loader
require_once \$civicrm_root . '/CRM/Core/ClassLoader.php';
CRM_Core_ClassLoader::singleton()->register();
";

  $string = trim($string);
  civicrm_write_file($adminPath . DIRECTORY_SEPARATOR .
    'civicrm' . DIRECTORY_SEPARATOR .
    'civicrm.config.php',
    $string
  );

  $liveSite = substr_replace(JURI::root(), '', -1, 1);
  if ($civicrmUpgrade) {
    require_once $configFile;
    if (defined('CIVICRM_SITE_KEY')) {
      $siteKey = CIVICRM_SITE_KEY;
    }
    if (defined('CIVICRM_CRED_KEYS')) {
      $credKeys = CIVICRM_CRED_KEYS;
    }
    if (defined('CIVICRM_SIGN_KEYS')) {
      $signKeys = CIVICRM_SIGN_KEYS;
    }
  }

  if (empty($siteKey)) {
    $siteKey = preg_replace(';[^a-zA-Z0-9];', '', base64_encode(random_bytes(37)));
  }
  if (empty($credKeys)) {
    $credKeys = 'aes-cbc:hkdf-sha256:' . preg_replace(';[^a-zA-Z0-9];', '', base64_encode(random_bytes(37)));
  }
  if (empty($signKeys)) {
    $signKeys = 'jwt-hs256:hkdf-sha256:' . preg_replace(';[^a-zA-Z0-9];', '', base64_encode(random_bytes(37)));
  }

  // generate backend settings file
  $string = civicrm_config(FALSE, $siteKey, $credKeys, $signKeys);
  civicrm_write_file($configFile, $string);

  // generate frontend settings file
  $string = civicrm_config(TRUE, $siteKey, $credKeys, $signKeys);
  civicrm_write_file(JPATH_SITE . DIRECTORY_SEPARATOR .
    'components' . DIRECTORY_SEPARATOR .
    'com_civicrm' . DIRECTORY_SEPARATOR .
    'civicrm.settings.php',
    $string
  );

  define('CIVICRM_SETTINGS_PATH', $configFile);
  include_once CIVICRM_SETTINGS_PATH;

  // for install case only
  if (!$civicrmUpgrade) {
    $sqlPath = $adminPath . DIRECTORY_SEPARATOR . 'civicrm' . DIRECTORY_SEPARATOR . 'sql';

    civicrm_source($sqlPath . DIRECTORY_SEPARATOR . 'civicrm.mysql');
    civicrm_source($sqlPath . DIRECTORY_SEPARATOR . 'civicrm_data.mysql');

    require_once 'CRM/Core/ClassLoader.php';
    CRM_Core_ClassLoader::singleton()->register();

    require_once 'CRM/Core/Config.php';
    $config = CRM_Core_Config::singleton();

    // now also build the menu
    require_once 'CRM/Core/Menu.php';
    CRM_Core_Menu::store();
  }
}

function civicrm_source($fileName, $lineMode = FALSE) {

  if (!defined('DB_DSN_MODE')) {
    define('DB_DSN_MODE', 'auto');
  }

  $dsn = CIVICRM_DSN;

  require_once 'DB.php';

  $db = DB::connect($dsn);
  if (PEAR::isError($db)) {
    die("Cannot open $dsn: " . $db->getMessage());
  }

  if (!$lineMode) {
    $string = file_get_contents($fileName);

    //get rid of comments starting with # and --
    $string = preg_replace("/^#[^\n]*$/m", "\n", $string);
    $string = preg_replace("/^\-\-[^\n]*$/m", "\n", $string);

    $queries = preg_split('/;\s*$/m', $string);

    foreach ($queries as $query) {
      $query = trim($query);
      if (!empty($query)) {
        $res =& $db->query($query);
        if (PEAR::isError($res)) {
          die("Cannot execute $query: " . $res->getMessage());
        }
      }
    }
  }
  else {
    $fd = fopen($fileName, "r");
    while ($string = fgets($fd)) {
      $string = ereg_replace("\n#[^\n]*\n", "\n", $string);
      $string = ereg_replace("\n\-\-[^\n]*\n", "\n", $string);
      $string = trim($string);
      if (!empty($string)) {
        $res =& $db->query($string);
        if (PEAR::isError($res)) {
          die("Cannot execute $string: " . $res->getMessage());
        }
      }
    }
  }
}

function civicrm_config($frontend = FALSE, $siteKey, $credKeys, $signKeys) {
  global $adminPath, $compileDir;

  $jConfig = new JConfig();

  $liveSite = substr_replace(JURI::root(), '', -1, 1);
  $params = array(
    'cms' => 'Joomla',
    'crmRoot' => $adminPath . DIRECTORY_SEPARATOR . 'civicrm',
    'templateCompileDir' => $compileDir,
    'baseURL' => $liveSite . '/administrator/',
    'dbUser' => $jConfig->user,
    'dbPass' => $jConfig->password,
    'dbHost' => $jConfig->host,
    'dbName' => $jConfig->db,
    'CMSdbUser' => $jConfig->user,
    'CMSdbPass' => $jConfig->password,
    'CMSdbHost' => $jConfig->host,
    'CMSdbName' => $jConfig->db,
    'siteKey' => $siteKey,
    'credKeys' => $credKeys,
    'signKeys' => $signKeys,
    'dbSSL' => '',
    'CMSdbSSL' => '',
  );

  if ($frontend) {
    $params['baseURL'] = $liveSite . '/';
  }

  $str = file_get_contents($adminPath . DIRECTORY_SEPARATOR .
    'civicrm' . DIRECTORY_SEPARATOR .
    'templates' . DIRECTORY_SEPARATOR .
    'CRM' . DIRECTORY_SEPARATOR .
    'common' . DIRECTORY_SEPARATOR .
    'civicrm.settings.php.template'
  );
  foreach ($params as $key => $value) {
    $str = str_replace('%%' . $key . '%%', $value, $str);
  }
  return trim($str);
}

civicrm_main();
