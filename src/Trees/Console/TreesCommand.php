<?php
namespace Trees\Console;

use Illuminate\Console\Command;
use Trees\TreesServiceProvider as Trees;

class TreesCommand extends Command {

  protected $name = 'Trees';

  protected $description = 'Get Trees version notice.';

  public function fire() {
      $this->line('<info>Trees</info> version <comment>' . Trees::VERSION . '</comment>');
      $this->line('A Nested Set pattern implementation for the Eloquent ORM.');
      $this->line('<comment>Copyright (c) 2013 Estanislau Trepat</comment>');
  }

}
