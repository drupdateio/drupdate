<?php

namespace Drupdate;

final class GithubRepository extends Repository {

  public function __construct($full_name, $user, $password, $clone_directory) {
    $this->url = 'https://' . $user . ':' . $password . '@github.com/' . $full_name;
    $this->clone_directory = $clone_directory;
  }
}
