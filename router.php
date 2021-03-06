<?php
// @link https://github.com/sup-ham/php-server-router

// env vars
// ALT_SCRIPT=index.php,__app.html,etc...   comma separated

class Config
{
  const debug = true;
  const protected_paths = '~/\.git(\/.*)?$|/nbproject~';
  const show_files = true;
}


class Router
{
  private static $docRoot;
  private static $pathInfo;
  private static $prevPathInfo;
  private static $requestURI;
  private static $rewriteURI;
  private static $parsedHtaccess = [];

  private static $types = [
    'css' => 'text/css',
    'svg' => 'image/svg+xml',
    'js' => 'text/javascript',
  ];

  // default = index.php,index.html
  // @see [getScripts()]
  public static $scripts;

  public static function setup()
  {
    $port = ($_SERVER['SERVER_PORT'] != '80') ? ":$_SERVER[SERVER_PORT]" : "";
    $_SERVER['SERVER_ADDR'] = "$_SERVER[SERVER_NAME]$port";
    self::setRequestURI();
  }

  public static function run()
  {
    self::setup();
    self::maybeFileRequest();
    return self::serveURI();
  }

  protected static function serveURI()
  {
    $currentUri = self::$requestURI;
    error_log("REQUEST_URI = $currentUri");

    if (self::isProtected($currentUri)) {
      http_response_code(403);
      self::showError('HTTP/1.1 403 Forbidden');
    }

    self::$docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
    $path = self::$docRoot . $currentUri;
    constant('Config::debug') && error_log('DOCUMENT_ROOT = ' . self::$docRoot);

    if (is_dir($path)) {
      return self::serveDir($path, $currentUri);
    }
    if (is_file($path)) {
      if (self::isDot('php', $path)) {
        return self::serveScript($path, dirname($path), '');
      }
      if (self::$pathInfo !== null) {
        self::readFile($path);
      }
      return false;
    }

    $i = 0;
    do {
      self::$pathInfo = basename($currentUri) . (self::$pathInfo !== null ? '/' . self::$pathInfo : '');
      $currentUri = rtrim(str_replace('\\', '/', dirname($currentUri)), '/');
      $dir = self::$docRoot . $currentUri;

      if (false === self::serveIndex($dir, $currentUri)) {
        return;
      }
    } while (($i++ < 20) && ($currentUri && $currentUri !== '/'));
  }

  protected static function getScripts()
  {
    if (!self::$scripts) {
      self::$scripts = array('index.php', 'index.html');

      if ($altScripts = getenv('ALT_SCRIPT')) {
        self::$scripts = array_merge(explode(',', $altScripts), self::$scripts);
      }

      array_unshift(self::$scripts, '.htaccess');
    }
    return self::$scripts;
  }

  protected static function serveIndex($dir, $currentUri)
  {
    foreach (self::getScripts() as $script) {
      $script = $dir . '/' . $script;
      constant('Config::debug') && error_log("trying script: $script");
      if (is_file($script)) {
        if (self::serveHtaccess($script, $dir, $currentUri) === null) {
          continue;
        }
        return self::serveScript($script, $dir, $currentUri);
      }
    }
  }

  protected static function serveHtaccess($file, $dir, $currentUri)
  {
    if (!self::isDot('htaccess', $file)) {
      return false;
    }
    if (in_array($file, self::$parsedHtaccess)) {
      return;
    }
    self::$parsedHtaccess[] = $file;
    constant('Config::debug') && error_log(__METHOD__);
    $stopParsing = false;

    foreach (file($file) as $line) {
      if ($stopParsing) {
        return;
      }
      @list($command, $args) = explode(' ', trim($line), 2);
      constant('Config::debug') && error_log(__METHOD__ . ' ' . print_r([$command, $args], 1));

      if (!$command or strpos($command, 'Rewrite') === false) {
        continue;
      }
      $args = preg_split('/ +/', trim($args));
      if ($command === 'RewriteEngine' && strtolower($args[0]) === 'on') {
        self::$rewriteURI = true;
        continue;
      }
      if (!self::$rewriteURI) {
        throw new \Exception('Rewrite engine is off');
      }
      if ($command === 'RewriteCond') {
        if ($args[0] === '%{REQUEST_FILENAME}') {
          if ($args[1] === '!-d' && is_dir(self::$docRoot . self::$requestURI)) {
            return;
          }
          if ($args[1] === '!-f' && is_file(self::$docRoot . self::$requestURI)) {
            return;
          }
        }
      }
      if ($command === 'RewriteRule') {
        $newURI = preg_replace('/' . $args[0] . '/', explode('?', $args[1])[0], ltrim(self::$pathInfo, '/'));
        self::$pathInfo = substr(self::$requestURI, strlen($currentUri));
        self::$prevPathInfo = $newURI;
        error_log(__METHOD__ . ' ' . __LINE__ . ' ' . print_r([$newURI, $dir, $currentUri], 1));
        self::$requestURI = $currentUri . '/' . $newURI;

        return self::serveURI();
      }
    }
    return true;
  }

  protected static function serveDir($dir, $currentUri)
  {
    constant('Config::debug') && error_log(__METHOD__ . ' ' . print_r(func_get_args(), 1));
    $dir = rtrim($dir, '/');
    if (false === self::serveIndex($dir, $currentUri)) {
      return;
    }
    http_response_code(404);
    if (Config::show_files) {
      exit(self::showFiles($dir));
    }
  }

  protected static function serveScript($script, $dir, $currentUri)
  {
    constant('Config::debug') && error_log(__METHOD__ . ' ' . print_r(func_get_args(), 1));
    constant('Config::debug') && error_log(print_r($_SERVER, 1));
    constant('Config::debug') && error_log(print_r(['pathInfo' => self::$pathInfo], 1));
    error_log(__METHOD__ . " SCRIPT_FILENAME = $script");

    // PHP Built-in server fails to serve path that contains dot
    $hasDotInDir = strpos($dir, '.') !== false;
    if (!$hasDotInDir && self::isDot('php', $script) && !self::$prevPathInfo) {
      return false;
    }

    if (self::$pathInfo !== null) {
      $_SERVER['SCRIPT_NAME'] = substr($script, strlen(self::$docRoot));
      $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] . self::$pathInfo;
    } else {
      $_SERVER['SCRIPT_NAME'] .= $script;
      $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    }

    self::includeScript($script);
  }

  protected static function includeScript($script)
  {
    chdir(dirname($script));
    include $_SERVER['SCRIPT_FILENAME'] = $script;
    constant('Config::debug') && error_log("Script included: $script");
    exit();
  }

  protected static function setRequestURI()
  {
    $exploded = explode('?', $_SERVER['REQUEST_URI'], 2);
    return self::$requestURI = rtrim($exploded[0], '/');
  }

  protected static function showFiles($dir)
  {
    header('Content-Type: text/html');
    $files = array_merge((array) @scandir($dir), []);
    sort($files);
    echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">
              <style>body{font: normal 1.4em/1.4em monospace}
              a{text-decoration:none} a:hover{background:#B8C7FF}</style></head><body>';

    $reqUri = self::$requestURI;

    echo "<table>";
    foreach ($files as $file) {
      if ($file === '.') {
        continue;
      }
      $link = "$reqUri/$file/";
      if (is_dir("$dir/$file")) {
        echo "<tr><td>[&plus;] <a href='$link'>$file/</a></td><td></td><td></td></tr>\n";
      } else {
        @$_files[] = $file;
      }
    }

    foreach ((array) @$_files as $file) {
      $link = "$reqUri/$file";
      $bytes = filesize($dir . '/' . $file);
      echo "<tr><td>[&bull;] <a href='$link'>$file</a></td><td><span class=filesize>$bytes</span></td>";
      echo "<td><a href='?view=$link'>view</a></td></tr>\n";
    }
    echo "</table>";

    $time = filemtime(__FILE__);
    echo "<script src='/?$time.js'></script></body></html>";
  }

  protected static function showError($message)
  {
    $template = "<html><meta name='viewport' content='width=device-width, initial-scale=1'>
                <title>$message</title><body>
                <p><code>>> $_SERVER[REQUEST_METHOD] " . htmlspecialchars(urldecode($_SERVER['REQUEST_URI'])) . " $_SERVER[SERVER_PROTOCOL]</code></p>
                <p><code><< $message</code></p></body>";
    exit($template);
  }

  protected static function isProtected($path)
  {
    $regex = Config::protected_paths;
    if (preg_match($regex, $path)) {
      return true;
    }
  }

  protected static function readFile($file, $ext = null)
  {
    $ext = $ext ?: pathinfo($file, PATHINFO_EXTENSION);
    if (isset(self::$types[$ext])) {
      header('content-type: ' . self::$types[$ext]);
    }
    readfile($file);
    exit();
  }

  protected static function maybeFileRequest()
  {
    if ($file = $_GET['view']  ?? null) {
      $ext = pathinfo($file, PATHINFO_EXTENSION);

      $players['mp4'] = fn () => include 'plyr.php';

      $players['js'] = fn() => self::readFile(__DIR__ . '/' . $file, $ext);
      $players['css'] = $players['js'];

      if ($player = $players[$ext] ?? null) {
        $player();
        die;
      }

      die('no viewer for ' . $file);
    }

    if ($_SERVER['REQUEST_URI'] !== '/?' . filemtime(__FILE__) . '.js') {
      return;
    }

    header('Content-Type: application/javascript');
    header('Cache-Control: public, max-age=' . strtotime('6 month'));
    echo "// link: http://stackoverflow.com/a/20463021"
      . "\nfileSizeIEC = (a,b,c,d,e) => (b=Math,c=b.log,d=1024,e=c(a)/c(d)|0,a/b.pow(d,e)).toFixed(2) +' '+(e?'KMGTPEZY'[--e]+'iB':'Bytes')"
      . "\ndocument.querySelectorAll('.filesize').forEach((e) => e.innerHTML = fileSizeIEC(e.innerHTML))";
    die;
  }

  public static function isDot($ext, $file)
  {
    return pathinfo($file, PATHINFO_EXTENSION) === $ext;
  }
}

return !!Router::run();
