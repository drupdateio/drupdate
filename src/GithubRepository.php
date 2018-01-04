<?php

namespace Drupdate;

final class GithubRepository extends Repository {

  protected $full_name = '';
  protected $user = '';
  protected $password = '';

  public function __construct($full_name, $branch = NULL, $user, $password, $clone_directory = '') {
    $this->full_name = $full_name;
    $this->user = $user;
    $this->password = $password;
    if ($branch == NULL) {
      $this->branch = $this->getDefaultBranch();
    }
    else {
      $this->branch = $branch;
    }
    $this->url = 'https://' . $user . ':' . $password . '@github.com/' . $full_name;
    if (empty($clone_directory)) {
      $this->clone_directory = sys_get_temp_dir() . '/' . uniqid();
    }
    else {
      $this->clone_directory = $clone_directory;
    }
  }

  protected function pullRequest($modules) {
    $pr_data = new \stdClass();
    $pr_data->title = 'Drupal module updates ' . $this->getDateString();
    $pr_data->head = 'drupdate-' . $this->getDateString();
    $pr_data->base = $this->branch;
    $pr_data->body = "Updated the following: \n\n";
    $arr_modules = explode(' ', $modules);
    foreach ($arr_modules as $module) {
      $pos = strpos($module, '-');
      $module_name = substr($module, 0, $pos);
      $module_version = substr($module, $pos + 1);
      $pr_data->body .= " * [" . $module_name . "](https://www.drupal.org/project/" . $module_name . "/releases/" . $module_version . ")\n";
    }

    $headers = array(
      'Authorization: token ' . $this->password,
      'Accept: application/vnd.github.machine-man-preview+json'
    );

    $ch = curl_init();

    // set URL and other appropriate options
    curl_setopt($ch, CURLOPT_URL, "https://api.github.com/repos/" . $this->full_name . "/pulls");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($pr_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Drupdate');

    // grab URL and pass it to the browser
    $response = curl_exec($ch);

    // close cURL resource, and free up system resources
    curl_close($ch);
  }

  protected function getDefaultBranch() {
    $headers = array(
      'Authorization: token ' . $this->password,
      'Accept: application/vnd.github.machine-man-preview+json'
    );

    $ch = curl_init();

    // set URL and other appropriate options
    curl_setopt($ch, CURLOPT_URL, "https://api.github.com/repos/" . $this->full_name);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Drupdate');

    // grab URL and pass it to the browser
    $response = curl_exec($ch);

    // close cURL resource, and free up system resources
    curl_close($ch);

    $json = json_decode($response);
    if ($json->default_branch) {
      return $json->default_branch;
    }
    else {
      return 'master';
    }
  }
}
