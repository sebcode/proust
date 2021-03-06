<?php

/*
 * Proust - Mustache PHP Compiler
 *
 * This is a relatively straight port of the ruby mustache compiler,
 * with multiple enhancements for PHP.
 *
 * (c) July 2011 - Manuel Odendahl - wesen@ruinwesen.com
 */

define('PROUST_VERSION_ID', '0.1');

require_once('lib/StringScanner.php');
require_once('lib/Proust.php');

function usage() {
  print "Usage:\n\n";
  print " Proust.php [-o outputfile] [-p partialDir] [-i] [-e] [-t] [-h] [-j json] -- inputfiles...\n\n";
  print "   -o outputfile : store php in this file\n";
  print "   -t            : print token array\n";
  print "   -h            : this information\n";
  print "   -p path       : set template path\n";
  print "   -e            : evaluate templates\n";
  print "   -j json       : parse json file and pass as context to evaluation\n";
  print "   -c name       : compile to class name\n";
  print "   --disable-lambdas : disable lambdas for compilation\n";
  print "   --disable-indentation : disable indentation for compilation\n";
  print "   --include-partials : include partials directly as code\n";
  print "   --beautify     : beautify generated code\n";
}


if (defined('STDIN')) {
  error_reporting(E_ALL);
  ini_set('xdebug.max_nesting_level', 10000);

  require_once('Console/Getopt.php');
  $o = new Console_Getopt();
  $argv = $o->readPHPArgv();

  /* check we are not included by someone */
  if (realpath($argv[0]) != realpath(__FILE__)) {
    return;
  }
  
  function filenameToFunctionName($filename){
    $name = basename($filename);
    $name = preg_replace('/\.[^\.]*$/', '_', $name);
    $name = preg_replace('/[^a-zA-Z0-9]/', '_', $name);
    return $name;
  }
  
  function _getopt($opts, $name) {
    if (array_key_exists($name, $opts)) {
      return $opts[$name];
    } else {
      return null;
    }
  }
  
  $res = $o->getopt($argv, 'o:thp:ej:c:', array("disable-lambdas", "disable-indentation", "include-partials", "beautify"));
  $opts = array();
  if (is_a($res, "PEAR_error")) {
    usage();
    die();
  }
  foreach ($res[0] as $foo) {
    $opts[$foo[0]] = ($foo[1] === null ? true : $foo[1]);
  }

  if (_getopt($opts, "h")) {
    usage();
    die();
  }

  $context = array();

  if (_getopt($opts, "j")) {
    $context = json_decode(file_get_contents(_getopt($opts, "j")));
  }

  $files = $res[1];
  $options = array("enableCache" => false);
  $compilerOptions = array();
  if (_getopt($opts, "--include-partials")) {
    $compilerOptions["includePartialCode"] = true;
  }
  if (_getopt($opts, "--disable-lambdas")) {
    $compilerOptions["disableLambdas"] = true;
  }
  if (_getopt($opts, "--disable-indentation")) {
    $compilerOptions["disableIndentation"] = true;
  }
  if (_getopt($opts, "--beautify")) {
    $compilerOptions["beautify"] = true;
  }
  if (_getopt($opts, "p")) {
    $options["templatePath"] = _getopt($opts, "p");
  }
  $options["compilerOptions"] = $compilerOptions;
  $m = new Proust\Proust($options);

  $methods = array();
  $code = "";
  foreach ($files as $file) {
    $tpl = file_get_contents($file);

    if (_getopt($opts, "c")) {
      /* store method name and code */
      array_push($methods, array(filenameToFunctionName($file), $tpl));
    } else if (_getopt($opts, "e")) {
      var_dump($m->render($tpl, $context));
    } else if (_getopt($opts, "t")) {
      $code .= "Tokens for $file:\n".print_r($m->getTokens($tpl), true)."\n";;
    } else {
      $code .= $m->compile($tpl, null, array("type" => "function",
                                             "name" => filenameToFunctionName($file)))."\n";
    }
  }

  if (_getopt($opts, "c")) {
    $className = $opts['c'];
    $method = filenameToFunctionName($files[0]);
    $code = $m->compileClass($className, $methods);
    if (_getopt($opts, "e")) {
      eval($code);
      $obj = new $className();
      print $obj->$method($context);
      die();
    }
  }

  if (!_getopt($opts, 't') && !_getopt($opts, "e")) {
    $code = "<?php\n\n$code\n?>\n";
    if (_getopt($opts, 'o') !== null) {
      file_put_contents($opts['o'], $code);
      print "Written to ".$opts['o']."\n";
    } else {
      print $code;
    }
  } else {
    print $code;
  }
}

?>