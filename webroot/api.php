<?php
/* Things you may want to tweak in here:
 *  - xhprof_enable() uses a few constants.
 *  - The values passed to rand() determine the the odds of any particular run being profiled.
 *  - The MongoDB collection and such.
 *
 * I use unsafe writes by default, let's not slow down requests any more than I need to. As a result you will
 * indubidubly want to ensure that writes are actually working.
 *
 * The easiest way to get going is to either include this file in your index.php script, or use php.ini's
 * auto_prepend_file directive http://php.net/manual/en/ini.core.php#ini.auto-prepend-file
 */


// Use the callbacks defined in the configuration file
// to determine whether or not XHgui should enable profiling.
//
// Only load the config class so we don't pollute the host application's
// autoloaders.
$dir = dirname(__DIR__);
require_once $dir . '/src/Xhgui/Config.php';
Xhgui_Config::load($dir . '/config/config.default.php');
if (file_exists($dir . '/config/config.php')) {
    Xhgui_Config::load($dir . '/config/config.php');
}
unset($dir);

if ((!extension_loaded('mongo') && !extension_loaded('mongodb')) && Xhgui_Config::read('save.handler') === 'mongodb') {
    error_log('xhgui - extension mongo not loaded');
    return;
}

$post_data = $_POST['data'];
$auth_value = $_POST['auth_value'];
$auth_key = Xhgui_Config::read('auth_key');
$data_auth_value = md5($post_data.$auth_key);
if($data_auth_value !== $auth_value){
    echo 'Unauth Access';
    die();
}

$send_data = json_decode($post_data,true);

if (!defined('XHGUI_ROOT_DIR')) {
    require dirname(dirname(__FILE__)) . '/src/bootstrap.php';
}

try {
    $config = Xhgui_Config::all();
    $config += array('db.options' => array());
    $saver = Xhgui_Saver::factory($config);
    foreach($send_data['meta']['SERVER'] as $key => $value) {
        if(strpos($key, '.') !== false){
            unset($send_data['meta']['SERVER'][$key]);
            $send_data['meta']['SERVER'][strtr($key, ['.' => '_'])] = $value;
        }
    }
    foreach($send_data['meta']['env'] as $key => $value) {
        if(strpos($key, '.') !== false){
            unset($send_data['meta']['env'][$key]);
            $send_data['meta']['env'][strtr($key, ['.' => '_'])] = $value;
        }
    }

    $saver->save($send_data);
} catch (Exception $e) {
    error_log('xhgui - ' . $e->getMessage());
}

