<?php
namespace Trees\Console;

use Trees\Generators\MigrationGenerator;
use Trees\Generators\ModelGenerator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;

class InstallCommand extends Command {

  protected $name = 'Trees:install';

  protected $description = 'Scaffolds a new migration and model suitable for Trees.';

  protected $migrator;

  protected $modeler;

  public function __construct(MigrationGenerator $migrator, ModelGenerator $modeler) {
    parent::__construct();

    $this->migrator = $migrator;
    $this->modeler  = $modeler;
  }

  public function fire() {
    $name = $this->input->getArgument('name');

    $this->writeMigration($name);

    $this->writeModel($name);

  }

  protected function getArguments() {
    return array(
      array('name', InputArgument::REQUIRED, 'Name to use for the scaffolding of the migration and model.')
    );
  }

  protected function writeMigration($name) {
    $output = pathinfo($this->migrator->create($name, $this->getMigrationsPath()), PATHINFO_FILENAME);

    $this->line("      <fg=green;options=bold>create</fg=green;options=bold>  $output");
  }

  protected function writeModel($name) {
    $output = pathinfo($this->modeler->create($name, $this->getModelsPath()), PATHINFO_FILENAME);

    $this->line("      <fg=green;options=bold>create</fg=green;options=bold>  $output");
  }

  protected function getMigrationsPath() {
    return $this->laravel['path.database'].'/migrations';
  }

  protected function getModelsPath() {
    return $this->laravel['path.base'];
  }

}
