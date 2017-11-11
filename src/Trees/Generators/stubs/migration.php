<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class {{class}} extends Migration {

  public function up() {
    Schema::create('{{table}}', function(Blueprint $table) {
      $table->increments('id');
      $table->integer('parent_id')->nullable()->index();
      $table->integer('lft')->nullable()->index();
      $table->integer('rgt')->nullable()->index();
      $table->integer('depth')->nullable();


      $table->timestamps();
    });
  }

  public function down() {
    Schema::drop('{{table}}');
  }

}
