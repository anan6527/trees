<?php
namespace Trees;

use \Illuminate\Events\Dispatcher;

class Move {

  protected $node = NULL;

  protected $target = NULL;

  protected $position = NULL;

  protected $_bound1 = NULL;

  protected $_bound2 = NULL;

  protected $_boundaries = NULL;

  protected static $dispatcher;

  public function __construct($node, $target, $position) {
    $this->node     = $node;
    $this->target   = $this->resolveNode($target);
    $this->position = $position;

    $this->setEventDispatcher($node->getEventDispatcher());
  }

  public static function to($node, $target, $position) {
    $instance = new static($node, $target, $position);

    return $instance->perform();
  }

  public function perform() {
    $this->guardAgainstImpossibleMove();

    if ( $this->fireMoveEvent('moving') === false )
      return $this->node;

    if ( $this->hasChange() ) {
      $self = $this;

      $this->node->getConnection()->transaction(function() use ($self) {
        $self->updateStructure();
      });

      $this->target->reload();

      $this->node->setDepthWithSubtree();

      $this->node->reload();
    }

    $this->fireMoveEvent('moved', false);

    return $this->node;
  }

  public function updateStructure() {
    list($a, $b, $c, $d) = $this->boundaries();

    $this->applyLockBetween($a, $d);

    $connection = $this->node->getConnection();
    $grammar    = $connection->getQueryGrammar();

    $currentId      = $this->quoteIdentifier($this->node->getKey());
    $parentId       = $this->quoteIdentifier($this->parentId());
    $leftColumn     = $this->node->getLeftColumnName();
    $rightColumn    = $this->node->getRightColumnName();
    $parentColumn   = $this->node->getParentColumnName();
    $wrappedLeft    = $grammar->wrap($leftColumn);
    $wrappedRight   = $grammar->wrap($rightColumn);
    $wrappedParent  = $grammar->wrap($parentColumn);
    $wrappedId      = $grammar->wrap($this->node->getKeyName());

    $lftSql = "CASE
      WHEN $wrappedLeft BETWEEN $a AND $b THEN $wrappedLeft + $d - $b
      WHEN $wrappedLeft BETWEEN $c AND $d THEN $wrappedLeft + $a - $c
      ELSE $wrappedLeft END";

    $rgtSql = "CASE
      WHEN $wrappedRight BETWEEN $a AND $b THEN $wrappedRight + $d - $b
      WHEN $wrappedRight BETWEEN $c AND $d THEN $wrappedRight + $a - $c
      ELSE $wrappedRight END";

    $parentSql = "CASE
      WHEN $wrappedId = $currentId THEN $parentId
      ELSE $wrappedParent END";

    $updateConditions = array(
      $leftColumn   => $connection->raw($lftSql),
      $rightColumn  => $connection->raw($rgtSql),
      $parentColumn => $connection->raw($parentSql)
    );

    if ( $this->node->timestamps )
      $updateConditions[$this->node->getUpdatedAtColumn()] = $this->node->freshTimestamp();

    return $this->node
                ->newNestedSetQuery()
                ->where(function($query) use ($leftColumn, $rightColumn, $a, $d) {
                  $query->whereBetween($leftColumn, array($a, $d))
                        ->orWhereBetween($rightColumn, array($a, $d));
                })
                ->update($updateConditions);
  }

  protected function resolveNode($node) {
    if ( $node instanceof \Trees\Node ) return $node->reload();

    return $this->node->newNestedSetQuery()->find($node);
  }

  protected function guardAgainstImpossibleMove() {
    if ( !$this->node->exists )
      throw new MoveNotPossibleException('A new node cannot be moved.');

    if ( array_search($this->position, array('child', 'left', 'right', 'root')) === FALSE )
      throw new MoveNotPossibleException("Position should be one of ['child', 'left', 'right'] but is {$this->position}.");

    if ( !$this->promotingToRoot() ) {
      if ( is_null($this->target) ) {
        if ( $this->position === 'left' || $this->position === 'right' )
          throw new MoveNotPossibleException("Could not resolve target node. This node cannot move any further to the {$this->position}.");
        else
          throw new MoveNotPossibleException('Could not resolve target node.');
      }

      if ( $this->node->equals($this->target) )
        throw new MoveNotPossibleException('A node cannot be moved to itself.');

      if ( $this->target->insideSubtree($this->node) )
        throw new MoveNotPossibleException('A node cannot be moved to a descendant of itself (inside moved tree).');

      if ( !$this->node->inSameScope($this->target) )
        throw new MoveNotPossibleException('A node cannot be moved to a different scope.');
    }
  }

  protected function bound1() {
    if ( !is_null($this->_bound1) ) return $this->_bound1;

    switch ( $this->position ) {
      case 'child':
        $this->_bound1 = $this->target->getRight();
        break;

      case 'left':
        $this->_bound1 = $this->target->getLeft();
        break;

      case 'right':
        $this->_bound1 = $this->target->getRight() + 1;
        break;

      case 'root':
        $this->_bound1 = $this->node->newNestedSetQuery()->max($this->node->getRightColumnName()) + 1;
        break;
    }

    $this->_bound1 = (($this->_bound1 > $this->node->getRight()) ? $this->_bound1 - 1 : $this->_bound1);
    return $this->_bound1;
  }

  protected function bound2() {
    if ( !is_null($this->_bound2) ) return $this->_bound2;

    $this->_bound2 = (($this->bound1() > $this->node->getRight()) ? $this->node->getRight() + 1 : $this->node->getLeft() - 1);
    return $this->_bound2;
  }

  protected function boundaries() {
    if ( !is_null($this->_boundaries) ) return $this->_boundaries;

    $this->_boundaries = array(
      $this->node->getLeft()  ,
      $this->node->getRight() ,
      $this->bound1()         ,
      $this->bound2()
    );
    sort($this->_boundaries);

    return $this->_boundaries;
  }

  protected function parentId() {
    switch( $this->position ) {
      case 'root':
        return NULL;

      case 'child':
        return $this->target->getKey();

      default:
        return $this->target->getParentId();
    }
  }

  protected function hasChange() {
    return !( $this->bound1() == $this->node->getRight() || $this->bound1() == $this->node->getLeft() );
  }

  protected function promotingToRoot() {
    return ($this->position == 'root');
  }

  public static function getEventDispatcher() {
    return static::$dispatcher;
  }

  public static function setEventDispatcher(Dispatcher $dispatcher) {
    static::$dispatcher = $dispatcher;
  }

  protected function fireMoveEvent($event, $halt = true) {
    if ( !isset(static::$dispatcher) ) return true;

    $event = "eloquent.{$event}: ".get_class($this->node);

    $method = $halt ? 'until' : 'fire';

    return static::$dispatcher->$method($event, $this->node);
  }

  protected function quoteIdentifier($value) {
    if ( is_null($value) )
      return 'NULL';

    $connection = $this->node->getConnection();

    $pdo = $connection->getPdo();

    return $pdo->quote($value);
  }

  protected function applyLockBetween($lft, $rgt) {
    $this->node->newQuery()
      ->where($this->node->getLeftColumnName(), '>=', $lft)
      ->where($this->node->getRightColumnName(), '<=', $rgt)
      ->select($this->node->getKeyName())
      ->lockForUpdate()
      ->get();
  }
}
