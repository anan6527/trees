<?php
namespace Trees;

use \Closure;
use Illuminate\Support\Contracts\ArrayableInterface;
use Trees\Node;

class SetMapper {

  protected $node = NULL;

  protected $childrenKeyName = 'children';

  public function __construct($node, $childrenKeyName = 'children') {
    $this->node = $node;

    $this->childrenKeyName = $childrenKeyName;
  }

  public function map($nodeList) {
    $self = $this;

    return $this->wrapInTransaction(function() use ($self, $nodeList) {
      forward_static_call(array(get_class($self->node), 'unguard'));

      $result = $self->mapTree($nodeList);

      forward_static_call(array(get_class($self->node), 'reguard'));

      return $result;
    });
  }

  public function mapTree($nodeList) {
    $tree = $nodeList instanceof ArrayableInterface ? $nodeList->toArray() : $nodeList;

    $affectedKeys = array();

    $result = $this->mapTreeRecursive($tree, $this->node->getKey(), $affectedKeys);

    if ( $result && count($affectedKeys) > 0 )
      $this->deleteUnaffected($affectedKeys);

    return $result;
  }

  public function getChildrenKeyName() {
    return $this->childrenKeyName;
  }

  protected function mapTreeRecursive(array $tree, $parentKey = null, &$affectedKeys = array()) {
    foreach($tree as $attributes) {
      $node = $this->firstOrNew($this->getSearchAttributes($attributes));

      $data = $this->getDataAttributes($attributes);
      if ( !is_null($parentKey) )
        $data[$node->getParentColumnName()] = $parentKey;

      $node->fill($data);

      $result = $node->save();

      if ( ! $result ) return false;

      $affectedKeys[] = $node->getKey();

      if ( array_key_exists($this->getChildrenKeyName(), $attributes) ) {
        $children = $attributes[$this->getChildrenKeyName()];

        if ( count($children) > 0 ) {
          $result = $this->mapTreeRecursive($children, $node->getKey(), $affectedKeys);

          if ( ! $result ) return false;
        }
      }
    }

    return true;
  }

  protected function getSearchAttributes($attributes) {
    $searchable = array($this->node->getKeyName());

    return array_only($attributes, $searchable);
  }

  protected function getDataAttributes($attributes) {
    $exceptions = array($this->node->getKeyName(), $this->getChildrenKeyName());

    return array_except($attributes, $exceptions);
  }

  protected function firstOrNew($attributes) {
    $className = get_class($this->node);

    if ( count($attributes) === 0 )
      return new $className;

    return forward_static_call(array($className, 'firstOrNew'), $attributes);
  }

  protected function pruneScope() {
    if ( $this->node->exists )
      return $this->node->descendants();

    return $this->node->newNestedSetQuery();
  }

  protected function deleteUnaffected($keys = array()) {
    return $this->pruneScope()->whereNotIn($this->node->getKeyName(), $keys)->delete();
  }

  protected function wrapInTransaction(Closure $callback) {
    return $this->node->getConnection()->transaction($callback);
  }

}
