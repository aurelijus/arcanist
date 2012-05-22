<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * PHPUnit wrapper
 *
 * To use, set unit_engine in .arcconfig, or use --engine flag
 * with arc unit. Currently supports only class & test files
 * (no directory support).
 * To use custom phpunit configuration, set phpunit_config in
 * .arcconfig (e.g. app/phpunit.xml.dist).
 *
 * @group unitrun
 */
final class PhpunitTestEngine extends ArcanistBaseUnitTestEngine {

  private $configFile;
  private $affectedTests;
  private $projectRoot;

  public function run() {

    $this->projectRoot = $this->getWorkingCopy()->getProjectRoot();

    $this->affectedTests = array();
    foreach ($this->getPaths() as $path) {

      $path = Filesystem::resolvePath($path);

      // TODO: add support for directories
      // Users can call phpunit on the directory themselves
      if (is_dir($path)) {
        continue;
      }

      // Not sure if it would make sense to go further if
      // it is not a .php file
      if (substr($path, -4) != '.php') {
        continue;
      }

      if (substr($path, -8) == 'Test.php') {
        // Looks like a valid test file name.
        $this->affectedTests[$path] = $path;
        continue;
      }

      if ($test = $this->findTestFile($path)) {
        $this->affectedTests[$path] = $test;
      }

    }

    if (empty($this->affectedTests)) {
      throw new ArcanistNoEffectException('No tests to run.');
    }

    $this->prepareConfigFile();
    $futures = array();
    $tmpfiles = array();
    foreach ($this->affectedTests as $class_path => $test_path) {
      $json_tmp = new TempFile();
      $clover_tmp = null;
      $clover = null;
      if ($this->getEnableCoverage() !== false) {
        $clover_tmp = new TempFile();
        $clover = csprintf('--coverage-clover %s', $clover_tmp);
      }

      $config = $this->configFile ? csprintf('-c %s', $this->configFile) : null;

      $futures[$test_path] = new ExecFuture('phpunit %C --log-json %s %C %s',
        $config, $json_tmp, $clover, $test_path);
      $tmpfiles[$test_path] = array(
        'json' => $json_tmp,
        'clover' => $clover_tmp,
      );


    }

    $results = array();
    foreach (Futures($futures)->limit(4) as $test => $future) {

      list($err, $stdout, $stderr) = $future->resolve();

      $results[] = $this->parseTestResults($test_path,
        $tmpfiles[$test]['json'],
        $tmpfiles[$test]['clover']);
    }

    return array_mergev($results);
  }

  /**
   * We need this non-sense to make json generated by phpunit
   * valid.
   *
   * @param string $json_tmp Path to JSON report
   *
   * @return array JSON decoded array
   */
  private function getJsonReport($json_tmp) {
    $json = Filesystem::readFile($json_tmp);

    if (empty($json)) {
      throw new Exception('JSON report file is empty, '
        . 'it probably means that phpunit failed to run tests. '
        . 'Try running arc unit with --trace option and then run '
        . 'generated phpunit command yourself, you might get the '
        . 'answer.'
      );
    }

    $json = str_replace('}{"', '},{"', $json);
    $json = '[' . $json . ']';
    $json = json_decode($json);
    if (!is_array($json)) {
      throw new Exception('JSON could not be decoded');
    }

    return $json;
  }

  /**
   * Parse test results from phpunit json report
   *
   * @param string $path Path to test
   * @param string $json_path Path to phpunit json report
   * @param string $clover_tmp Path to phpunit clover report
   *
   * @return array
   */
  private function parseTestResults($path, $json_tmp, $clover_tmp) {
    $test_results = Filesystem::readFile($json_tmp);

    $report = $this->getJsonReport($json_tmp);

    // coverage is for all testcases in the executed $path
    $coverage = array();
    if ($this->getEnableCoverage() !== false) {
      $coverage = $this->readCoverage($clover_tmp);
    }

    $results = array();
    foreach ($report as $event) {
      if ('test' != $event->event) {
        continue;
      }

      $status = ArcanistUnitTestResult::RESULT_PASS;
      $user_data = '';

      if ('fail' == $event->status) {
        $status = ArcanistUnitTestResult::RESULT_FAIL;
        $user_data  .= $event->message . "\n";
        foreach ($event->trace as $trace) {
          $user_data .= sprintf("\n%s:%s", $trace->file, $trace->line);
        }
      } else if ('error' == $event->status) {
        if ('Skipped Test' == $event->message) {
          $status = ArcanistUnitTestResult::RESULT_SKIP;
          $user_data .= $event->message;
        } else if ('Incomplete Test' == $event->message) {
          $status = ArcanistUnitTestResult::RESULT_SKIP;
          $user_data .= $event->message;
        } else {
          $status = ArcanistUnitTestResult::RESULT_BROKEN;
          $user_data  .= $event->message;
          foreach ($event->trace as $trace) {
            $user_data .= sprintf("\n%s:%s", $trace->file, $trace->line);
          }
        }
      }

      $name = substr($event->test, strlen($event->suite) + 2);
      $result = new ArcanistUnitTestResult();
      $result->setName($name);
      $result->setResult($status);
      $result->setDuration($event->time);
      $result->setCoverage($coverage);
      $result->setUserData($user_data);

      $results[] = $result;
    }

    return $results;
  }

  /**
   * Red the coverage from phpunit generated clover report
   *
   * @param string $path Path to report
   *
   * @return array
   */
  private function readCoverage($path) {
    $test_results = Filesystem::readFile($path);
    if (empty($test_results)) {
      throw new Exception('Clover coverage XML report file is empty');
    }

    $coverage_dom = new DOMDocument();
    $coverage_dom->loadXML($test_results);

    $reports = array();
    $files = $coverage_dom->getElementsByTagName('file');

    foreach ($files as $file) {
      $class_path = $file->getAttribute('name');
      if (empty($this->affectedTests[$class_path])) {
        continue;
      }
      $test_path = $this->affectedTests[$file->getAttribute('name')];
      // get total line count in file
      $line_count = count(file($class_path));

      $coverage = '';
      $start_line = 1;
      $lines = $file->getElementsByTagName('line');
      for ($ii = 0; $ii < $lines->length; $ii++) {
        $line = $lines->item($ii);
        for (; $start_line < $line->getAttribute('num'); $start_line++) {
          $coverage .= 'N';
        }

        if ($line->getAttribute('type') != 'stmt') {
          $coverage .= 'N';
        } else {
          if ((int) $line->getAttribute('count') == 0) {
            $coverage .= 'U';
          }
          else if ((int) $line->getAttribute('count') > 0) {
            $coverage .= 'C';
          }
        }

        $start_line++;
      }

      for (; $start_line <= $line_count; $start_line++) {
        $coverage .= 'N';
      }

      $len = strlen($this->projectRoot . DIRECTORY_SEPARATOR);
      $class_path = substr($class_path, $len);
      $reports[$class_path] = $coverage;
    }

    return $reports;
  }


  /**
   * Some nasty guessing here.
   *
   * Walk up to the project root trying to find
   * [Tt]ests directory and replicate the structure there.
   *
   * Assume that the class path is
   * /www/project/module/package/subpackage/FooBar.php
   * and a project root is /www/project it will look for it by these paths:
   * /www/project/module/package/subpackage/[Tt]ests/FooBarTest.php
   * /www/project/module/package/[Tt]ests/subpackage/FooBarTest.php
   * /www/project/module/[Tt]ests/package/subpackage/FooBarTest.php
   * /www/project/Tt]ests/module/package/subpackage/FooBarTest.php
   *
   * TODO: Add support for finding tests based on PSR-1 naming conventions:
   * /www/project/src/Something/Foo/Bar.php tests should be detected in
   * /www/project/tests/Something/Foo/BarTest.php
   *
   * TODO: Add support for finding tests in testsuite folders from
   * phpunit.xml configuration.
   *
   * @param string $path
   *
   * @return string|boolean
   */
  private function findTestFile($path) {
    $expected_file = substr(basename($path), 0, -4) . 'Test.php';
    $expected_dir = null;
    $dirname = dirname($path);
    foreach (Filesystem::walkToRoot($dirname) as $dir) {
      $expected_dir =  DIRECTORY_SEPARATOR
                        . substr($dirname, strlen($dir) + 1)
                        . $expected_dir;
      $look_for = $dir . DIRECTORY_SEPARATOR
                    . '%s' . $expected_dir . $expected_file;

      if (Filesystem::pathExists(sprintf($look_for, 'Tests'))) {
        return sprintf($look_for, 'Tests');
      } else if (Filesystem::pathExists(sprintf($look_for, 'Tests'))) {
        return sprintf($look_for, 'Tests');
      }

      if ($dir == $this->projectRoot) {
        break;
      }

    }

    return false;
  }

  /**
   * Tries to find and update phpunit configuration file
   * based on phpunit_config option in .arcconfig
   */
  private function prepareConfigFile() {
    $project_root = $this->projectRoot . DIRECTORY_SEPARATOR;

    if ($config = $this->getWorkingCopy()->getConfig('phpunit_config')) {
      if (Filesystem::pathExists($project_root . $config)) {
        $this->configFile = $project_root . $config;
      } else {
        throw new Exception('PHPUnit configuration file was not ' .
          'found in ' . $project_root . $config);
      }
    }
  }
}
