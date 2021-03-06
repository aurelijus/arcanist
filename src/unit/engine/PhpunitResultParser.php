<?php

/**
 * PHPUnit Result Parsing utility
 *
 * Intended to enable custom unit engines derived
 * from phpunit to reuse common business logic related
 * to parsing phpunit test results and reports
 *
 * For an example on how to integrate with your test
 * engine, see PhpunitTestEngine.
 *
 */
final class PhpunitResultParser {

  private $enableCoverage;
  private $projectRoot;

  public function setEnableCoverage($enable_coverage) {
    $this->enableCoverage = $enable_coverage;

    return $this;
  }

  public function setProjectRoot($project_root) {
    $this->projectRoot = $project_root;

    return $this;
  }

  /**
   * Parse test results from phpunit json report
   *
   * @param string $path Path to test
   * @param string $json_path Path to phpunit json report
   * @param string $clover_tmp Path to phpunit clover report
   * @param array $affected_tests Array of the tests affected by this run
   * @param bool $enable_coverage Option to enable coverage in results
   *
   * @return array
   */
  public function parseTestResults(
    $path,
    $json_tmp,
    $clover_tmp,
    $affected_tests) {

      $test_results = Filesystem::readFile($json_tmp);

      $report = $this->getJsonReport($json_tmp);

      // coverage is for all testcases in the executed $path
      $coverage = array();
      if ($this->enableCoverage !== false) {
        $coverage = $this->readCoverage($clover_tmp, $affected_tests);
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
          if (strpos($event->message, 'Skipped Test') !== false) {
            $status = ArcanistUnitTestResult::RESULT_SKIP;
            $user_data .= $event->message;
          } else if (strpos($event->message, 'Incomplete Test') !== false) {
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

        $name = preg_replace('/ \(.*\)/', '', $event->test);

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
   * Read the coverage from phpunit generated clover report
   *
   * @param string $path Path to report
   *
   * @return array
   */
  private function readCoverage($path, $affected_tests) {
    $test_results = Filesystem::readFile($path);
    if (empty($test_results)) {
      throw new Exception('Clover coverage XML report file is empty, '
        . 'it probably means that phpunit failed to run tests. '
        . 'Try running arc unit with --trace option and then run '
        . 'generated phpunit command yourself, you might get the '
        . 'answer.'
      );
    }

    $coverage_dom = new DOMDocument();
    $coverage_dom->loadXML($test_results);

    $reports = array();
    $files = $coverage_dom->getElementsByTagName('file');

    foreach ($files as $file) {
      $class_path = $file->getAttribute('name');
      if (empty($affected_tests[$class_path])) {
        continue;
      }
      $test_path = $affected_tests[$file->getAttribute('name')];
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
          } else if ((int) $line->getAttribute('count') > 0) {
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

    $json = preg_replace('/}{\s*"/', '},{"', $json);
    $json = '[' . $json . ']';
    $json = json_decode($json);
    if (!is_array($json)) {
      throw new Exception('JSON could not be decoded');
    }

    return $json;
  }
}
