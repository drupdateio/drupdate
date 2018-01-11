<?php

namespace Drupdate;

use Symfony\Component\Yaml\Yaml;

abstract class Repository {

  // options
  protected $options = array();

  // Repository URL
  protected $url = '';

  // Clone directory
  protected $clone_directory = '';

  // List of installed modules
  protected $projects = array();

  // List of modules that need to be updated
  protected $updates = array();

  // Recommended versions for the modules that need to be updated
  protected $recommended_versions = array();

  // Temporary directory for drupal core updates
  protected $core_directory = '';

  // Base branch to clone
  protected $branch = '';

  protected $date_string = '';

  public function clone() {
    $cmd = 'git clone -b '. $this->branch . ' ' . $this->url . ' ' . $this->clone_directory;
    exec($cmd, $output, $return);
    return [$output, $return];
  }

  /**
   * Read options from yaml file if it exists
   */
  protected function readOptions() {
    if (empty($this->options) && is_file($this->clone_directory . '/.drupdate.yml')) {
      $options = Yaml::parseFile($this->clone_directory . '/.drupdate.yml');
      if (!empty($options)) {
        $this->options = $options;
      }
    }
  }

  protected function getModules() {
    if (empty($this->projects)) {
      // Step 2: get list of modules with their version
      $dir_iterator = new \RecursiveDirectoryIterator($this->clone_directory);
      $iterator = new \RecursiveIteratorIterator($dir_iterator, \RecursiveIteratorIterator::SELF_FIRST);
      foreach ($iterator as $file) {
        if ($file->getExtension() == 'info') {
          $data = file_get_contents($file->getRealPath());
          $info = drupal_parse_info_format($data);
          if (isset($info['project']) && ($info['project'] == $file->getBasename('.info') || $info['project'] == 'drupal') && isset($info['version']) && strpos($info['version'], 'dev') === FALSE) {
            $this->projects[$info['project']]['info'] = $info;
          }
        }
      }
    }
    return $this->projects;
  }

  protected function getUpdates() {
    if (empty($this->updates)) {
      $to_update = array();
      $recommended_versions = array();
      // Step 3: get list of modules that need to be updated
      update_process_project_info($this->projects);

      foreach ($this->projects as $name => $project) {
        // See if there is an update available
        $update_fetch_url = isset($project['info']['project status url']) ? $project['info']['project status url'] : UPDATE_DEFAULT_URL;
        $update_fetch_url .= '/'.$name.'/7.x';
        $xml = file_get_contents($update_fetch_url);
        if ($xml) {
          $available = update_parse_xml($xml);
          update_calculate_project_update_status(NULL, $project, $available);
          $recommended = isset($project['recommended']) ? $project['recommended'] : '';
          if (isset($this->options['security_only']) && !empty($this->options['security_only'])) {
            if (count($project['security updates'])) {
              $shifted = array_shift($project['security updates']);
              $recommended = $shifted['version'];
            }
          }
          if (isset($project['existing_version']) &&
            !empty($recommended) &&
            $project['existing_version'] != $recommended &&
            (!isset($this->options['ignore']) || (isset($this->options['ignore']) && !in_array($name, $this->options['ignore'])))) {
              $to_update[] = $name;
              $recommended_versions[$name] = $recommended;
          }
        }
      }
      $this->updates = $to_update;
      $this->recommended_versions = $recommended_versions;
    }
    return $this->updates;
  }

  protected function downloadModuleAndCommit($module) {
    $core_directory = '';
    // Create a new temporary directory
    $destination = sys_get_temp_dir() . '/' . uniqid();
    mkdir($destination);

    // Set the source directory
    $drupal_directory = $this->clone_directory;
    if (isset($this->options['directory'])) {
      $drupal_directory = $this->clone_directory . "/" . $this->options['directory'];
    }

    // Copy source into destination
    $cmd = 'cp -R ' . $drupal_directory . ' ' . $destination;
    exec($cmd, $output, $return);

    $full_module = $module . '-' . $this->recommended_versions[$module];
    if ($module == 'drupal') {
      $core_directory = sys_get_temp_dir() . '/' . uniqid();
      $cmd = 'drush dl ' . $full_module . ' -y --destination=' . $core_directory . ' --drupal-project-rename';
      exec($cmd, $output, $return);
      if ($return == 0) {
        $cmd = 'cp -R ' . $core_directory . '/drupal/* ' . $destination;
        exec($cmd, $output, $return);
      }
    }
    else {
      // Download module
      $cmd = 'cd ' . $destination . '; drush -y dl ' . $full_module;
      exec($cmd, $output, $return);
    }

    $this->commit($full_module, $destination);
    $this->pullRequest($full_module);
    $this->rrmdir($destination);
    if (is_dir($core_directory)) {
      $this->rrmdir($core_directory);
    }
  }

  protected function downloadUpdatesAndCommit() {
    // Step 4: download updated modules with drush
    $to_update = $this->updates;
    if (!empty($to_update)) {
      foreach ($to_updates as $module) {
        $this->downloadModuleAndCommit($module);
      }
    }
  }

  protected function commit($module, $directory) {
    // Step 5: commit the changes in an update branch
    $date = $this->getDateString();
    $cmd = 'cd '. $directory . '; git checkout -b drupdate-' . $module . '-' . $date .'; git add --all .; git commit -am "Updated ' . $module.'"';
    exec($cmd, $output, $return);
    if ($return == 0) {
      // push the updated modules to the branch
      $cmd = 'cd ' . $directory . '; git push origin drupdate-' . $module . '-' . $date;
      exec($cmd, $output, $return);
    }
  }

  abstract protected function pullRequest($module);

  public function cleanUp() {
    if (is_dir($this->clone_directory)) {
      $this->rrmdir($this->clone_directory);
    }
    if (!empty($this->core_directory) && is_dir($this->core_directory)) {
      $this->rrmdir($this->core_directory);
    }
  }

  protected function getDateString() {
    if (empty($this->date_string)) {
      date_default_timezone_set('UTC');
      $this->date_string = date('Y-m-d-His');
    }
    return $this->date_string;
  }

  /**
   * Recursively deletes a directory
   * Taken from http://php.net/manual/en/function.rmdir.php
   */
  private function rrmdir($dir) {
    if (is_dir($dir)) {
      $objects = scandir($dir);
      foreach ($objects as $object) {
        if ($object != "." && $object != "..") {
          if (filetype($dir."/".$object) == "dir") {
            $this->rrmdir($dir."/".$object);
          }
          else {
            unlink($dir."/".$object);
          }
        }
      }
      reset($objects);
      rmdir($dir);
    }
  }

  public function handle($options = array()) {
    $this->options = $options;
    $this->clone();
    $this->readOptions();
    $this->getModules();
    $this->getUpdates();
    $this->downloadUpdatesAndCommit();
    $this->cleanUp();
  }
}
