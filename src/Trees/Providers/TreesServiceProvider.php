<?php
namespace Trees\Providers;

use Trees\Generators\MigrationGenerator;
use Trees\Generators\ModelGenerator;
use Trees\Console\TreesCommand;
use Trees\Console\InstallCommand;
use Illuminate\Support\ServiceProvider;

class TreesServiceProvider extends ServiceProvider {

  const VERSION = '1.1.1';

  public function register() {
    $this->registerCommands();
  }

  public function registerCommands() {
    $this->registerTreesCommand();
    $this->registerInstallCommand();

    $this->commands('command.Trees', 'command.Trees.install');
  }

  protected function registerTreesCommand() {
    $this->app->singleton('command.Trees', function($app) {
      return new TreesCommand;
    });
  }

  protected function registerInstallCommand() {
    $this->app->singleton('command.Trees.install', function($app) {
      $migrator = new MigrationGenerator($app['files']);
      $modeler  = new ModelGenerator($app['files']);

      return new InstallCommand($migrator, $modeler);
    });
  }

  public function provides() {
    return array('command.Trees', 'command.Trees.install');
  }

}
