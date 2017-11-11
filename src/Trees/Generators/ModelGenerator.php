<?php
namespace Trees\Generators;

class ModelGenerator extends Generator {

  public function create($name, $path) {
    $path = $this->getPath($name, $path);

    $stub = $this->getStub('model');

    $this->files->put($path, $this->parseStub($stub, array(
      'table' => $this->tableize($name),
      'class' => $this->classify($name)
    )));

    return $path;
  }

  protected function getPath($name, $path) {
    return $path . '/' . $this->classify($name) . '.php';
  }

}
