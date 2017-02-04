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

/* xhprof_enable()
 * See: http://php.net/manual/en/xhprof.constants.php
 *
 *
 * XHPROF_FLAGS_NO_BUILTINS
 *  Omit built in functions from return
 *  This can be useful to simplify the output, but there's some value in seeing that you've called strpos() 2000 times
 *  (disabled on PHP 5.5+ as it causes a segfault)
 *
 * XHPROF_FLAGS_CPU
 *  Include CPU profiling information in output
 *
 * XHPROF_FLAGS_MEMORY (integer)
 *  Include Memory profiling information in output
 *
 *
 * Use bitwise operators to combine, so XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY to profile CPU and Memory
 *
 */

/* uprofiler support
 * The uprofiler extension is a fork of xhprof.  See: https://github.com/FriendsOfPHP/uprofiler
 *
 * The two extensions are very similar, and this script will use the uprofiler extension if it is loaded,
 * or the xhprof extension if not.  At least one of these extensions must be present.
 *
 * The UPROFILER_* constants mirror the XHPROF_* ones exactly, with one additional constant available:
 *
 * UPROFILER_FLAGS_FUNCTION_INFO (integer)
 *  Adds more information about function calls (this information is not currently used by XHGui)
 */

/* Tideways support
 * The tideways extension is a fork of xhprof. See https://github.com/tideways/php-profiler-extension
 *
 * It works on PHP 5.5+ and PHP 7 and improves on the ancient timing algorithms used by XHProf using
 * more modern Linux APIs to collect high performance timing data.
 *
 * The TIDEWAYS_* constants are similar to the ones by XHProf, however you need to disable timeline
 * mode when using XHGui, because it only supports callgraphs and we can save the overhead. Use
 * TIDEWAYS_FLAGS_NO_SPANS to disable timeline mode.
 */

// this file should not - under no circumstances - interfere with any other application
if (!extension_loaded('xhprof') && !extension_loaded('uprofiler') && !extension_loaded('tideways')) {
    error_log('xhgui - either extension xhprof, uprofiler or tideways must be loaded');
    return;
}

if (rand(1, 10) !== 1) {
    return ;
}

if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
}

if (extension_loaded('uprofiler')) {
    uprofiler_enable(UPROFILER_FLAGS_CPU | UPROFILER_FLAGS_MEMORY);
} else if (extension_loaded('tideways')) {
    tideways_enable(TIDEWAYS_FLAGS_CPU | TIDEWAYS_FLAGS_MEMORY | TIDEWAYS_FLAGS_NO_SPANS);
} else {
    if (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION > 4) {
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_NO_BUILTINS);
    } else {
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
    }
}

function http_post($url,$post){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    //curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);

    curl_close($ch);
}

register_shutdown_function(
    function () {
        if (extension_loaded('uprofiler')) {
            $data['profile'] = uprofiler_disable();
        } else if (extension_loaded('tideways')) {
            $data['profile'] = tideways_disable();
        } else {
            $data['profile'] = xhprof_disable();
        }

        // ignore_user_abort(true) allows your PHP script to continue executing, even if the user has terminated their request.
        // Further Reading: http://blog.preinheimer.com/index.php?/archives/248-When-does-a-user-abort.html
        // flush() asks PHP to send any data remaining in the output buffers. This is normally done when the script completes, but
        // since we're delaying that a bit by dealing with the xhprof stuff, we'll do it now to avoid making the user wait.
        ignore_user_abort(true);
        flush();

        $uri = array_key_exists('REQUEST_URI', $_SERVER)
            ? $_SERVER['REQUEST_URI']
            : null;
        if (empty($uri) && isset($_SERVER['argv'])) {
            $cmd = basename($_SERVER['argv'][0]);
            $uri = $cmd . ' ' . implode(' ', array_slice($_SERVER['argv'], 1));
        }

        $time = array_key_exists('REQUEST_TIME', $_SERVER)
            ? $_SERVER['REQUEST_TIME']
            : time();
        $requestTimeFloat = explode('.', $_SERVER['REQUEST_TIME_FLOAT']);
        if (!isset($requestTimeFloat[1])) {
            $requestTimeFloat[1] = 0;
        }

//        if (Xhgui_Config::read('save.handler') === 'file') {
            $requestTs = array('sec' => $time, 'usec' => 0);
            $requestTsMicro = array('sec' => $requestTimeFloat[0], 'usec' => $requestTimeFloat[1]);
//        } else {
//            $requestTs = new MongoDate($time);
//            $requestTsMicro = new MongoDate($requestTimeFloat[0], $requestTimeFloat[1]);
//        }

        $data['meta'] = array(
            'url' => $uri,
            'SERVER' => $_SERVER,
            'get' => $_GET,
            'env' => $_ENV,
            'simple_url' => preg_replace('/\=\d+/', '', $uri),
            'request_ts' => $requestTs,
            'request_ts_micro' => $requestTsMicro,
            'request_date' => date('Y-m-d', $time),
        );

        try {
            $api_url = 'http://xhgui.com/api.php';
            $auth_key = 'TGo9A7CVJsWIKTro';
            $post_data['data'] = json_encode($data);
            $post_data['auth_value'] = md5($post_data['data'].$auth_key);
            http_post($api_url,$post_data);
        } catch (Exception $e) {
            error_log('xhgui - ' . $e->getMessage());
        }
    }
);
