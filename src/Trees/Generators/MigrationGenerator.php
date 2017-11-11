<?php
namespace Trees\Generators;

class MigrationGenerator extends Generator {

  public function create($name, $path) {
    $path = $this->getPath($name, $path);

    $stub = $this->getStub('migration');

    $this->files->put($path, $this->parseStub($stub, array(
      'table' => $this->tableize($name),
      'class' => $this->getMigrationClassName($name)
    )));

    return $path;
  }

  protected function getMigrationName($name) {
    return 'create_' . $this->tableize($name) . '_table';
  }

  protected function getMigrationClassName($name) {
    return $this->classify($this->getMigrationName($name));
  }

  protected function getPath($name, $path) {
    return $path . '/' . $this->getDatePrefix() . '_' . $this->getMigrationName($name) . '.php';
  }

  protected function getDatePrefix() {
    return date('Y_m_d_His');
  }

}
