<?php

/*
 * Mustache PHP Compiler
 *
 * This is a straight port of the ruby mustache compiler.
 *
 * (c) July 2011 - Manuel Odendahl - wesen@ruinwesen.com
 */

require_once(dirname(__FILE__).'/Context.php');
require_once(dirname(__FILE__).'/Parser.php');
require_once(dirname(__FILE__).'/Generator.php');
require_once(dirname(__FILE__).'/helpers.php');

class Mustache implements \ArrayAccess {
  public $raiseOnContextMiss = false;
  public $templatePath = ".";
  public $templateExtension = "mustache";
  public $enableCache = true;
  public $cacheDir = "";
  public $compilerOptions = array();

  public $context = null;

  public $partials = array();
  public $codeCache = array();

  public function __construct($options = array()) {
    $defaults = array("templatePath" => ".",
                      "templateExtension" => "mustache",
                      "cacheDir" => null,
                      "raiseOnContextMiss" => false,
                      "context" => null,
                      "enableCache" => true,
                      "compilerOptions" => array());
    $options = array_merge($defaults, $options);
    object_set_options($this, $options, array_keys($defaults));
    
    if ($this->cacheDir == null) {
      $this->cacheDir = $this->templatePath."/.mustache_cache";
    }
    if ($this->enableCache) {
      $this->ensureCacheDirectoryExists();
    }
  }

  /***************************************************************************
   *
   * caching methods
   *
   ***************************************************************************/

  public function ensureCacheDirectoryExists() {
    if (!is_dir($this->cacheDir)) {
      if (!mkdir($this->cacheDir, 0777, true)) {
        throw new Exception("could not create cache directory ".$this->cacheDir);
      }
    }
  }

  /* Store ctag, otag and compiler options as part of the cache name */
  public function getTagString($context) {
    if (!is_a($context, "Mustache\Context")) {
      $context = $this->getContext();
    }
    $res = print_r($context->otag, true)."_".print_r($context->ctag, true);
    foreach ($this->compilerOptions as $k => $v) {
      $res .= "_".$k."_".$v."_";
    }
    return $res;
  }

  /* get the filename of the actual file. */
  public function getCacheFilename($filename) {
    $cachefile = preg_replace('/[\/\\\ \.]/', '_', $filename);
    $cachefile = $this->cacheDir."/$cachefile.mustache_cache";
    return $cachefile;
  }

  /* cache a code string, along with context information. Returns the evaluated function.. */
  public function getCachedCode($code, $context) {
    if (!$this->enableCache) {
      $php = $this->compile($code, $context);
      eval($php);
      return $f;
    }

    $name = "code ".sha1($code)." ".$this->getTagString($context);

    if (array_key_exists($name, $this->codeCache)) {
      return $this->codeCache[$name];
    }

    $f = null;
    $cachefile = $this->getCacheFilename($name);
    if (file_exists($cachefile)) {
      include($cachefile);
    } else {
      $php = $this->compile($code, $context);
      file_put_contents($cachefile, "<? $php ?>");
      eval($php);
    }

    $this->codeCache[$name] = $f;

    return $f;
  }

  /* Get the cache string for a file. */
  public function getFileCodeCacheKey($filename, $context) {
    $mtime = filemtime($filename);
    $size = filesize($filename);
    return "file $filename $mtime $size ".$this->getTagString($context);
  }

  /* cache a file, along with context information. Returns the evaluated function. */
  function getCachedFile($filename, $context) {
    if (!$this->enableCache) {
      $code = file_get_contents($filename);
      $php = $this->compile($code, $context);
      eval($php);
      return $f;
    }

    $name = $this->getFileCodeCacheKey($filename, $context);
    if (array_key_exists($name, $this->codeCache)) {
      return $this->codeCache[$name];
    }

    $cachefile = $this->getCacheFilename($name);
    $f = null;
    if (file_exists($cachefile)) {
      include($cachefile);
    } else {
      $code = file_get_contents($filename);
      $php = $this->compile($code, $context);
      file_put_contents($cachefile, "<? $php ?>");
      eval($php);
    }
    $this->codeCache[$name] = $f;
    return $f;
  }

  /* clears the complete cache */
  function clearCache() {
    foreach (glob($this->cacheDir."/*.mustache_cache") as $file) {
      unlink($file);
    }
  }
    
  /***************************************************************************
   *
   * Getters and Setters
   *
   ***************************************************************************/

  /** context **/
  public function getContext() {
    if ($this->context == null) {
      $this->context = new Mustache\Context($this);
    } 
    return $this->context;
  }

  public function resetContext() {
    $this->context = null;
  }
  
  /***************************************************************************
   *
   * Mustache methods
   *
   ***************************************************************************/

  /**
   * Given a string, compile and render the string.
   **/
  public function render($data = null, $context = null) {
      // when not a string, return directly, don't try to parse
    if (!is_string($data)) {
      return $data;
    }

    if (is_string($data)) {
      $f = $this->getCachedCode($data, $context);
      return $this->evalTemplate($f, $context);
    } else {
      return $data;
    }
  }

  /**
   * Given a file name and an optional context, attemps to load and
   * render the file as a template.
   **/
  public function renderTemplate($name, $context = null) {
    $filename = $this->templatePath."/".$name.".".$this->templateExtension;
    $f = $this->getCachedFile($filename, $context);
    return $this->evalTemplate($f, $context);
  }

  public function renderFile($filename, $context = null) {
    $f = $this->getCachedFile($filename, $context);
    return $this->evalTemplate($f, $context);
  }

  
  /**
   * Override this in your subclass if you want to do fun things like
   * reading templates from a database.
   **/
  public function renderPartial($name, $context = null) {
    if (array_key_exists($name, $this->partials)) {
      return $this->render($this->partials[$name], $context);
    } else {
      return $this->renderTemplate($name, $context);
    }
  }

  /* get the content of a partial. */
  public function getPartial($name) {
    if (array_key_exists($name, $this->partials)) {
      return $this->partials[$name];
    } else {
      $filename = $this->templatePath."/".$name.".".$this->templateExtension;
      if (file_exists($filename)) {
        return file_get_contents($filename);
      } else {
        return "";
      }
    }
  }

  /* evaluates a compiled template, along with the given context. */
  public function evalTemplate($f, $context = null) {
    if ($context == null) {
      return $f($this->getContext());
    } else {
      try {
        $this->getContext()->push($context);
        return $f($this->getContext());
      } catch (Exception $e) {
        $this->getContext()->pop();
        throw $e;
      }
    }
  }

  /* Get the tokenized version of $code. */
  public function getTokens($code, $context = null) {
    $parser = new Mustache\Parser();
    $tokens = $parser->parse($code, $context);
    return $tokens;
  }

  /* Compile the code, passing the options to the compiler. */
  public function compile($code, $context = null, $options = array()) {
    $compilerOptions = $this->compilerOptions;
    $compilerOptions["mustache"] = $this;

    $options = array_merge(array("type" => "variable",
                                 "name" => "f"),
                           $options);
    
    
    $generator = new Mustache\Generator($compilerOptions);
    $compiledCode = $generator->compileCode($code, $options);

    return $compiledCode;
  }

  public function compileClass($name, $methods) {
    $compilerOptions = $this->compilerOptions;
    $compilerOptions["mustache"] = $this;

    $generator = new Mustache\Generator($compilerOptions);
    return $generator->compileClass($name, $methods);
  }

  /***************************************************************************
   *
   * Array access methods are mapped to context object
   *
   ***************************************************************************/
  /**
   * Implements the array access methods.
   **/
  function offsetExists( $offset ) {
    return $this->getContext()->offsetExists($offset);
  }

  function offsetGet ( $offset ) {
    return $this->getContext()->offsetGet($offset);
  }

  function offsetSet ( $offset ,  $value ) {
    return $this->getContext()->offsetSet($offset, $value);
  }

  function offsetUnset ( $offset ) {
    return $this->getContext()->offsetUnSet($offset, $value);
  }  

}

?>