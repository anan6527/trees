<?php
namespace Trees;

use Trees\Helpers\DatabaseHelper as DB;
use Trees\Node;

class SetBuilder {

  protected $node = NULL;

  protected $bounds = array();

  public function __construct($node) {
    $this->node = $node;
  }

  public function rebuild($force = false) {
    $alreadyValid = forward_static_call(array(get_class($this->node), 'isValidNestedSet'));

    if ( !$force && $alreadyValid ) return true;

    $self = $this;

    $this->node->getConnection()->transaction(function() use ($self) {
      foreach($self->roots() as $root)
        $self->rebuildBounds($root, 0);
    });
  }

  public function roots() {
    return $this->node->newQuery()
      ->whereNull($this->node->getQualifiedParentColumnName())
      ->orderBy($this->node->getQualifiedLeftColumnName())
      ->orderBy($this->node->getQualifiedRightColumnName())
      ->orderBy($this->node->getQualifiedKeyName())
      ->get();
  }

  public function rebuildBounds($node, $depth = 0) {
    $k = $this->scopedKey($node);

    $node->setAttribute($node->getLeftColumnName(), $this->getNextBound($k));
    $node->setAttribute($node->getDepthColumnName(), $depth);

    foreach($this->children($node) as $child)
      $this->rebuildBounds($child, $depth + 1);

    $node->setAttribute($node->getRightColumnName(), $this->getNextBound($k));

    $node->save();
  }

  public function children($node) {
    $query = $this->node->newQuery();

    $query->where($this->node->getQualifiedParentColumnName(), '=', $node->getKey());

    foreach($this->scopedAttributes($node) as $fld => $value)
      $query->where($this->qualify($fld), '=', $value);

    $query->orderBy($this->node->getQualifiedLeftColumnName());
    $query->orderBy($this->node->getQualifiedRightColumnName());
    $query->orderBy($this->node->getQualifiedKeyName());

    return $query->get();
  }

  protected function scopedAttributes($node) {
    $keys = $this->node->getScopedColumns();

    if ( count($keys) == 0 )
      return array();

    $values = array_map(function($column) use ($node) {
      return $node->getAttribute($column); }, $keys);

    return array_combine($keys, $values);
  }

  protected function scopedKey($node) {
    $attributes = $this->scopedAttributes($node);

    $output = array();

    foreach($attributes as $fld => $value)
      $output[] = $this->qualify($fld).'='.(is_null($value) ? 'NULL' : $value);

    return implode(',', $output);
  }

  protected function getNextBound($key) {
    if ( false === array_key_exists($key, $this->bounds) )
      $this->bounds[$key] = 0;

    $this->bounds[$key] = $this->bounds[$key] + 1;

    return $this->bounds[$key];
  }

  protected function qualify($column) {
    return $this->node->getTable() . '.' . $column;
  }

}
