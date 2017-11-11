<?php
namespace Trees;

use Illuminate\Database\Capsule\Manager as Capsule;

class SetValidator {

  protected $node = NULL;

  public function __construct($node) {
    $this->node = $node;
  }

  public function passes() {
    return $this->validateBounds() && $this->validateDuplicates() &&
      $this->validateRoots();
  }

  public function fails() {
    return !$this->passes();
  }

  protected function validateBounds() {
    $connection = $this->node->getConnection();
    $grammar    = $connection->getQueryGrammar();

    $tableName      = $this->node->getTable();
    $primaryKeyName = $this->node->getKeyName();
    $parentColumn   = $this->node->getQualifiedParentColumnName();

    $lftCol = $grammar->wrap($this->node->getLeftColumnName());
    $rgtCol = $grammar->wrap($this->node->getRightColumnName());

    $qualifiedLftCol    = $grammar->wrap($this->node->getQualifiedLeftColumnName());
    $qualifiedRgtCol    = $grammar->wrap($this->node->getQualifiedRightColumnName());
    $qualifiedParentCol = $grammar->wrap($this->node->getQualifiedParentColumnName());

    $whereStm = "($qualifiedLftCol IS NULL OR
      $qualifiedRgtCol IS NULL OR
      $qualifiedLftCol >= $qualifiedRgtCol OR
      ($qualifiedParentCol IS NOT NULL AND
        ($qualifiedLftCol <= parent.$lftCol OR
          $qualifiedRgtCol >= parent.$rgtCol)))";

    $query = $this->node->newQuery()
      ->join($connection->raw($grammar->wrapTable($tableName).' AS parent'),
             $parentColumn, '=', $connection->raw('parent.'.$grammar->wrap($primaryKeyName)),
             'left outer')
      ->whereRaw($whereStm);

    return ($query->count() == 0);
  }

  protected function validateDuplicates() {
    return (
      !$this->duplicatesExistForColumn($this->node->getQualifiedLeftColumnName()) &&
      !$this->duplicatesExistForColumn($this->node->getQualifiedRightColumnName())
    );
  }

  protected function validateRoots() {
    $roots = forward_static_call(array(get_class($this->node), 'roots'))->get();

    if ( $this->node->isScoped() )
      return $this->validateRootsByScope($roots);

    return $this->isEachRootValid($roots);
  }

  protected function duplicatesExistForColumn($column) {
    $connection = $this->node->getConnection();
    $grammar    = $connection->getQueryGrammar();

    $columns = array_merge($this->node->getQualifiedScopedColumns(), array($column));

    $columnsForSelect = implode(', ', array_map(function($col) use ($grammar) {
      return $grammar->wrap($col); }, $columns));

    $wrappedColumn = $grammar->wrap($column);

    $query = $this->node->newQuery()
      ->select($connection->raw("$columnsForSelect, COUNT($wrappedColumn)"))
      ->havingRaw("COUNT($wrappedColumn) > 1");

    foreach($columns as $col)
      $query->groupBy($col);

    $result = $query->first();

    return !is_null($result);
  }

  protected function isEachRootValid($roots) {
    $left = $right = 0;

    foreach($roots as $root) {
      $rootLeft   = $root->getLeft();
      $rootRight  = $root->getRight();

      if ( !($rootLeft > $left && $rootRight > $right) )
        return false;

      $left   = $rootLeft;
      $right  = $rootRight;
    }

    return true;
  }

  protected function validateRootsByScope($roots) {
    foreach($this->groupRootsByScope($roots) as $scope => $groupedRoots) {
      $valid = $this->isEachRootValid($groupedRoots);

      if ( !$valid )
        return false;
    }

    return true;
  }

  protected function groupRootsByScope($roots) {
    $rootsGroupedByScope = array();

    foreach($roots as $root) {
      $key = $this->keyForScope($root);

      if ( !isset($rootsGroupedByScope[$key]) )
        $rootsGroupedByScope[$key] = array();

      $rootsGroupedByScope[$key][] = $root;
    }

    return $rootsGroupedByScope;
  }

  protected function keyForScope($node) {
    return implode('-', array_map(function($column) use ($node) {
      $value = $node->getAttribute($column);

      if ( is_null($value) )
        return 'NULL';

      return $value;
    }, $node->getScopedColumns()));
  }

}
