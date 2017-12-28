<?php

namespace Drupdate;

abstract class Repository {

  protected $url = '';

  protected $clone_directory = '';

  public function clone() {
    $cmd = 'git clone '. $this->url . ' ' . $this->clone_directory;
    exec($cmd, $output, $return);
    return [$output, $return];
  }
}
