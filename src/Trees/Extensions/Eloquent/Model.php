<?php
namespace Trees\Extensions\Eloquent;

use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Trees\Extensions\Query\Builder as QueryBuilder;

abstract class Model extends BaseModel {

  public function reload() {
    if ( $this->exists || ($this->areSoftDeletesEnabled() && $this->trashed()) ) {
      $fresh = $this->getFreshInstance();

      if ( is_null($fresh) )
        throw with(new ModelNotFoundException)->setModel(get_called_class());

      $this->setRawAttributes($fresh->getAttributes(), true);

      $this->setRelations($fresh->getRelations());

      $this->exists = $fresh->exists;
    } else {
      $this->attributes = $this->original;
    }

    return $this;
  }

  public function getObservableEvents() {
    return array_merge(array('moving', 'moved'), parent::getObservableEvents());
  }

  public static function moving($callback, $priority = 0) {
    static::registerModelEvent('moving', $callback, $priority);
  }

  public static function moved($callback, $priority = 0) {
    static::registerModelEvent('moved', $callback, $priority);
  }

  protected function newBaseQueryBuilder() {
    $conn = $this->getConnection();

    $grammar = $conn->getQueryGrammar();

    return new QueryBuilder($conn, $grammar, $conn->getPostProcessor());
  }

  protected function getFreshInstance() {
    if ( $this->areSoftDeletesEnabled() )
      return static::withTrashed()->find($this->getKey());

    return static::find($this->getKey());
  }

  public function areSoftDeletesEnabled() {
    $globalScopes = $this->getGlobalScopes();

    if ( count($globalScopes) === 0 ) return false;

    return static::hasGlobalScope(new SoftDeletingScope);
  }

  public static function softDeletesEnabled() {
    return with(new static)->areSoftDeletesEnabled();
  }

}
