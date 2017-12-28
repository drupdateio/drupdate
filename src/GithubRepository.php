<?php

namespace Drupdate;

final class GithubRepository extends Repository {

  public function __construct($full_name, $user, $password, $clone_directory = '') {
    $this->url = 'https://' . $user . ':' . $password . '@github.com/' . $full_name;
    if (empty($clone_directory)) {
      $this->clone_directory = sys_get_temp_dir() . '/' . uniqid();
    }
    else {
      $this->clone_directory = $clone_directory;
    }
  }
}
