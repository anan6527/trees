<?php
namespace Trees\Generators;

use Illuminate\Filesystem\Filesystem;

abstract class Generator {

  protected $files = NULL;

  function __construct(Filesystem $files) {
    $this->files = $files;
  }

  public function getStubPath() {
    return __DIR__.'/stubs';
  }

  public function getFilesystem() {
    return $this->files;
  }

  protected function getStub($name) {
    if ( stripos($name, '.php') === FALSE )
      $name = $name . '.php';

    return $this->files->get($this->getStubPath() . '/' . $name);
  }

  protected function parseStub($stub, $replacements=array()) {
    $output = $stub;

    foreach ($replacements as $key => $replacement) {
      $search = '{{'.$key.'}}';
      $output = str_replace($search, $replacement, $output);
    }

    return $output;
  }

  protected function classify($input) {
    return studly_case(str_singular($input));
  }

  protected function tableize($input) {
    return snake_case(str_plural($input));
  }
}
