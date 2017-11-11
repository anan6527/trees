<?php
namespace Trees;

use Trees\Extensions\Eloquent\Collection;
use Trees\Extensions\Eloquent\Model;


abstract class Node extends Model {

  protected $parentColumn = 'parent_id';

  protected $leftColumn = 'lft';

  protected $rightColumn = 'rgt';

  protected $depthColumn = 'depth';

  protected $orderColumn = null;

  protected $guarded = array('id', 'parent_id', 'lft', 'rgt', 'depth');

  protected static $moveToNewParentId = NULL;

  protected $scoped = array();

  protected static function boot() {
    parent::boot();

    static::creating(function($node) {
      $node->setDefaultLeftAndRight();
    });

    static::saving(function($node) {
      $node->storeNewParent();
    });

    static::saved(function($node) {
      $node->moveToNewParent();
      $node->setDepth();
    });

    static::deleting(function($node) {
      $node->destroyDescendants();
    });

    if ( static::softDeletesEnabled() ) {
      static::restoring(function($node) {
        $node->shiftSiblingsForRestore();
      });

      static::restored(function($node) {
        $node->restoreDescendants();
      });
    }
  }

  public function getParentColumnName() {
    return $this->parentColumn;
  }

  public function getQualifiedParentColumnName() {
    return $this->getTable(). '.' .$this->getParentColumnName();
  }

  public function getParentId() {
    return $this->getAttribute($this->getparentColumnName());
  }

  public function getLeftColumnName() {
    return $this->leftColumn;
  }

  public function getQualifiedLeftColumnName() {
    return $this->getTable() . '.' . $this->getLeftColumnName();
  }

  public function getLeft() {
    return $this->getAttribute($this->getLeftColumnName());
  }

  public function getRightColumnName() {
    return $this->rightColumn;
  }

  public function getQualifiedRightColumnName() {
    return $this->getTable() . '.' . $this->getRightColumnName();
  }

  public function getRight() {
    return $this->getAttribute($this->getRightColumnName());
  }

  public function getDepthColumnName() {
    return $this->depthColumn;
  }

  public function getQualifiedDepthColumnName() {
    return $this->getTable() . '.' . $this->getDepthColumnName();
  }

  public function getDepth() {
    return $this->getAttribute($this->getDepthColumnName());
  }

  public function getOrderColumnName() {
    return is_null($this->orderColumn) ? $this->getLeftColumnName() : $this->orderColumn;
  }

  public function getQualifiedOrderColumnName() {
    return $this->getTable() . '.' . $this->getOrderColumnName();
  }

  public function getOrder() {
    return $this->getAttribute($this->getOrderColumnName());
  }

  public function getScopedColumns() {
    return (array) $this->scoped;
  }

  public function getQualifiedScopedColumns() {
    if ( !$this->isScoped() )
      return $this->getScopedColumns();

    $prefix = $this->getTable() . '.';

    return array_map(function($c) use ($prefix) {
      return $prefix . $c; }, $this->getScopedColumns());
  }

  public function isScoped() {
    return !!(count($this->getScopedColumns()) > 0);
  }

  public function parent() {
    return $this->belongsTo(get_class($this), $this->getParentColumnName());
  }

  public function children() {
    return $this->hasMany(get_class($this), $this->getParentColumnName())
                ->orderBy($this->getOrderColumnName());
  }

  public function newNestedSetQuery($excludeDeleted = true) {
    $builder = $this->newQuery($excludeDeleted)->orderBy($this->getQualifiedOrderColumnName());

    if ( $this->isScoped() ) {
      foreach($this->scoped as $scopeFld)
        $builder->where($scopeFld, '=', $this->$scopeFld);
    }

    return $builder;
  }

  public function newCollection(array $models = array()) {
    return new Collection($models);
  }

  public static function all($columns = array('*')) {
    $instance = new static;

    return $instance->newQuery()
                    ->orderBy($instance->getQualifiedOrderColumnName())
                    ->get();
  }

  public static function root() {
    return static::roots()->first();
  }

  public static function roots() {
    $instance = new static;

    return $instance->newQuery()
                    ->whereNull($instance->getParentColumnName())
                    ->orderBy($instance->getQualifiedOrderColumnName());
  }

  public static function allLeaves() {
    $instance = new static;

    $grammar = $instance->getConnection()->getQueryGrammar();

    $rgtCol = $grammar->wrap($instance->getQualifiedRightColumnName());
    $lftCol = $grammar->wrap($instance->getQualifiedLeftColumnName());

    return $instance->newQuery()
                    ->whereRaw($rgtCol . ' - ' . $lftCol . ' = 1')
                    ->orderBy($instance->getQualifiedOrderColumnName());
  }

  public static function allTrunks() {
    $instance = new static;

    $grammar = $instance->getConnection()->getQueryGrammar();

    $rgtCol = $grammar->wrap($instance->getQualifiedRightColumnName());
    $lftCol = $grammar->wrap($instance->getQualifiedLeftColumnName());

    return $instance->newQuery()
                    ->whereNotNull($instance->getParentColumnName())
                    ->whereRaw($rgtCol . ' - ' . $lftCol . ' != 1')
                    ->orderBy($instance->getQualifiedOrderColumnName());
  }

  public static function isValidNestedSet() {
    $validator = new SetValidator(new static);

    return $validator->passes();
  }

  public static function rebuild($force = false) {
    $builder = new SetBuilder(new static);

    $builder->rebuild($force);
  }

  public static function buildTree($nodeList) {
    return with(new static)->makeTree($nodeList);
  }

  public function scopeWithoutNode($query, $node) {
    return $query->where($node->getKeyName(), '!=', $node->getKey());
  }

  public function scopeWithoutSelf($query) {
    return $this->scopeWithoutNode($query, $this);
  }

  public function scopeWithoutRoot($query) {
    return $this->scopeWithoutNode($query, $this->getRoot());
  }

  public function scopeLimitDepth($query, $limit) {
    $depth  = $this->exists ? $this->getDepth() : $this->getLevel();
    $max    = $depth + $limit;
    $scopes = array($depth, $max);

    return $query->whereBetween($this->getDepthColumnName(), array(min($scopes), max($scopes)));
  }

  public function isRoot() {
    return is_null($this->getParentId());
  }

  public function isLeaf() {
    return $this->exists && ($this->getRight() - $this->getLeft() == 1);
  }

  public function isTrunk() {
    return !$this->isRoot() && !$this->isLeaf();
  }

  public function isChild() {
    return !$this->isRoot();
  }

  public function getRoot() {
    if ( $this->exists ) {
      return $this->ancestorsAndSelf()->whereNull($this->getParentColumnName())->first();
    } else {
      $parentId = $this->getParentId();

      if ( !is_null($parentId) && $currentParent = static::find($parentId) ) {
        return $currentParent->getRoot();
      } else {
        return $this;
      }
    }
  }

  public function ancestorsAndSelf() {
    return $this->newNestedSetQuery()
                ->where($this->getLeftColumnName(), '<=', $this->getLeft())
                ->where($this->getRightColumnName(), '>=', $this->getRight());
  }

  public function getAncestorsAndSelf($columns = array('*')) {
    return $this->ancestorsAndSelf()->get($columns);
  }

  public function getAncestorsAndSelfWithoutRoot($columns = array('*')) {
    return $this->ancestorsAndSelf()->withoutRoot()->get($columns);
  }

  public function ancestors() {
    return $this->ancestorsAndSelf()->withoutSelf();
  }

  public function getAncestors($columns = array('*')) {
    return $this->ancestors()->get($columns);
  }

  public function getAncestorsWithoutRoot($columns = array('*')) {
    return $this->ancestors()->withoutRoot()->get($columns);
  }

  public function siblingsAndSelf() {
    return $this->newNestedSetQuery()
                ->where($this->getParentColumnName(), $this->getParentId());
  }

  public function getSiblingsAndSelf($columns = array('*')) {
    return $this->siblingsAndSelf()->get($columns);
  }

  public function siblings() {
    return $this->siblingsAndSelf()->withoutSelf();
  }

  public function getSiblings($columns = array('*')) {
    return $this->siblings()->get($columns);
  }

  public function leaves() {
    $grammar = $this->getConnection()->getQueryGrammar();

    $rgtCol = $grammar->wrap($this->getQualifiedRightColumnName());
    $lftCol = $grammar->wrap($this->getQualifiedLeftColumnName());

    return $this->descendants()
                ->whereRaw($rgtCol . ' - ' . $lftCol . ' = 1');
  }

  public function getLeaves($columns = array('*')) {
    return $this->leaves()->get($columns);
  }

  public function trunks() {
    $grammar = $this->getConnection()->getQueryGrammar();

    $rgtCol = $grammar->wrap($this->getQualifiedRightColumnName());
    $lftCol = $grammar->wrap($this->getQualifiedLeftColumnName());

    return $this->descendants()
                ->whereNotNull($this->getQualifiedParentColumnName())
                ->whereRaw($rgtCol . ' - ' . $lftCol . ' != 1');
  }

  public function getTrunks($columns = array('*')) {
    return $this->trunks()->get($columns);
  }

  public function descendantsAndSelf() {
    return $this->newNestedSetQuery()
                ->where($this->getLeftColumnName(), '>=', $this->getLeft())
                ->where($this->getLeftColumnName(), '<', $this->getRight());
  }

  public function getDescendantsAndSelf($columns = array('*')) {
    if ( is_array($columns) )
      return $this->descendantsAndSelf()->get($columns);

    $arguments = func_get_args();

    $limit    = intval(array_shift($arguments));
    $columns  = array_shift($arguments) ?: array('*');

    return $this->descendantsAndSelf()->limitDepth($limit)->get($columns);
  }

  public function descendants() {
    return $this->descendantsAndSelf()->withoutSelf();
  }

  public function getDescendants($columns = array('*')) {
    if ( is_array($columns) )
      return $this->descendants()->get($columns);

    $arguments = func_get_args();

    $limit    = intval(array_shift($arguments));
    $columns  = array_shift($arguments) ?: array('*');

    return $this->descendants()->limitDepth($limit)->get($columns);
  }

  public function immediateDescendants() {
    return $this->children();
  }

  public function getImmediateDescendants($columns = array('*')) {
    return $this->children()->get($columns);
  }

  public function getLevel() {
    if ( is_null($this->getParentId()) )
      return 0;

    return $this->computeLevel();
  }

  public function isDescendantOf($other) {
    return (
      $this->getLeft() > $other->getLeft()  &&
      $this->getLeft() < $other->getRight() &&
      $this->inSameScope($other)
    );
  }

  public function isSelfOrDescendantOf($other) {
   return (
      $this->getLeft() >= $other->getLeft() &&
      $this->getLeft() < $other->getRight() &&
      $this->inSameScope($other)
    );
  }

  public function isAncestorOf($other) {
    return (
      $this->getLeft() < $other->getLeft()  &&
      $this->getRight() > $other->getLeft() &&
      $this->inSameScope($other)
    );
  }

  public function isSelfOrAncestorOf($other) {
   return (
      $this->getLeft() <= $other->getLeft() &&
      $this->getRight() > $other->getLeft() &&
      $this->inSameScope($other)
    );
  }

  public function getLeftSibling() {
    return $this->siblings()
                ->where($this->getLeftColumnName(), '<', $this->getLeft())
                ->orderBy($this->getOrderColumnName(), 'desc')
                ->get()
                ->last();
  }

  public function getRightSibling() {
    return $this->siblings()
                ->where($this->getLeftColumnName(), '>', $this->getLeft())
                ->first();
  }

  public function moveLeft() {
    return $this->moveToLeftOf($this->getLeftSibling());
  }

  public function moveRight() {
    return $this->moveToRightOf($this->getRightSibling());
  }

  public function moveToLeftOf($node) {
    return $this->moveTo($node, 'left');
  }

  public function moveToRightOf($node) {
    return $this->moveTo($node, 'right');
  }

  public function makeNextSiblingOf($node) {
    return $this->moveToRightOf($node);
  }

  public function makeSiblingOf($node) {
    return $this->moveToRightOf($node);
  }

  public function makePreviousSiblingOf($node) {
    return $this->moveToLeftOf($node);
  }

  public function makeChildOf($node) {
    return $this->moveTo($node, 'child');
  }

  public function makeFirstChildOf($node) {
    if ( $node->children()->count() == 0 )
      return $this->makeChildOf($node);

    return $this->moveToLeftOf($node->children()->first());
  }

  public function makeLastChildOf($node) {
    return $this->makeChildOf($node);
  }

  public function makeRoot() {
    return $this->moveTo($this, 'root');
  }

  public function equals($node) {
    return ($this == $node);
  }

  public function inSameScope($other) {
    foreach($this->getScopedColumns() as $fld) {
      if ( $this->$fld != $other->$fld ) return false;
    }

    return true;
  }

  public function insideSubtree($node) {
    return (
      $this->getLeft()  >= $node->getLeft()   &&
      $this->getLeft()  <= $node->getRight()  &&
      $this->getRight() >= $node->getLeft()   &&
      $this->getRight() <= $node->getRight()
    );
  }

  public function setDefaultLeftAndRight() {
    $withHighestRight = $this->newNestedSetQuery()->reOrderBy($this->getRightColumnName(), 'desc')->take(1)->sharedLock()->first();

    $maxRgt = 0;
    if ( !is_null($withHighestRight) ) $maxRgt = $withHighestRight->getRight();

    $this->setAttribute($this->getLeftColumnName()  , $maxRgt + 1);
    $this->setAttribute($this->getRightColumnName() , $maxRgt + 2);
  }

  public function storeNewParent() {
    if ( $this->isDirty($this->getParentColumnName()) && ($this->exists || !$this->isRoot()) )
      static::$moveToNewParentId = $this->getParentId();
    else
      static::$moveToNewParentId = FALSE;
  }

  public function moveToNewParent() {
    $pid = static::$moveToNewParentId;

    if ( is_null($pid) )
      $this->makeRoot();
    else if ( $pid !== FALSE )
      $this->makeChildOf($pid);
  }

  public function setDepth() {
    $self = $this;

    $this->getConnection()->transaction(function() use ($self) {
      $self->reload();

      $level = $self->getLevel();

      $self->newNestedSetQuery()->where($self->getKeyName(), '=', $self->getKey())->update(array($self->getDepthColumnName() => $level));
      $self->setAttribute($self->getDepthColumnName(), $level);
    });

    return $this;
  }

  public function setDepthWithSubtree() {
    $self = $this;

    $this->getConnection()->transaction(function() use ($self) {
      $self->reload();

      $self->descendantsAndSelf()->select($self->getKeyName())->lockForUpdate()->get();

      $oldDepth = !is_null($self->getDepth()) ? $self->getDepth() : 0;

      $newDepth = $self->getLevel();

      $self->newNestedSetQuery()->where($self->getKeyName(), '=', $self->getKey())->update(array($self->getDepthColumnName() => $newDepth));
      $self->setAttribute($self->getDepthColumnName(), $newDepth);

      $diff = $newDepth - $oldDepth;
      if ( !$self->isLeaf() && $diff != 0 )
        $self->descendants()->increment($self->getDepthColumnName(), $diff);
    });

    return $this;
  }

  public function destroyDescendants() {
    if ( is_null($this->getRight()) || is_null($this->getLeft()) ) return;

    $self = $this;

    $this->getConnection()->transaction(function() use ($self) {
      $self->reload();

      $lftCol = $self->getLeftColumnName();
      $rgtCol = $self->getRightColumnName();
      $lft    = $self->getLeft();
      $rgt    = $self->getRight();

      $self->newNestedSetQuery()->where($lftCol, '>=', $lft)->select($self->getKeyName())->lockForUpdate()->get();

      $self->newNestedSetQuery()->where($lftCol, '>', $lft)->where($rgtCol, '<', $rgt)->delete();

      $diff = $rgt - $lft + 1;

      $self->newNestedSetQuery()->where($lftCol, '>', $rgt)->decrement($lftCol, $diff);
      $self->newNestedSetQuery()->where($rgtCol, '>', $rgt)->decrement($rgtCol, $diff);
    });
  }

  public function shiftSiblingsForRestore() {
    if ( is_null($this->getRight()) || is_null($this->getLeft()) ) return;

    $self = $this;

    $this->getConnection()->transaction(function() use ($self) {
      $lftCol = $self->getLeftColumnName();
      $rgtCol = $self->getRightColumnName();
      $lft    = $self->getLeft();
      $rgt    = $self->getRight();

      $diff = $rgt - $lft + 1;

      $self->newNestedSetQuery()->where($lftCol, '>=', $lft)->increment($lftCol, $diff);
      $self->newNestedSetQuery()->where($rgtCol, '>=', $lft)->increment($rgtCol, $diff);
    });
  }

  public function restoreDescendants() {
    if ( is_null($this->getRight()) || is_null($this->getLeft()) ) return;

    $self = $this;

    $this->getConnection()->transaction(function() use ($self) {
      $self->newNestedSetQuery()
        ->withTrashed()
        ->where($self->getLeftColumnName(), '>', $self->getLeft())
        ->where($self->getRightColumnName(), '<', $self->getRight())
        ->update(array(
          $self->getDeletedAtColumn() => null,
          $self->getUpdatedAtColumn() => $self->{$self->getUpdatedAtColumn()}
        ));
    });
  }

  public static function getNestedList($column, $key = null, $seperator = ' ') {
    $instance = new static;

    $key = $key ?: $instance->getKeyName();
    $depthColumn = $instance->getDepthColumnName();

    $nodes = $instance->newNestedSetQuery()->get()->toArray();

    return array_combine(array_map(function($node) use($key) {
      return $node[$key];
    }, $nodes), array_map(function($node) use($seperator, $depthColumn, $column) {
      return str_repeat($seperator, $node[$depthColumn]) . $node[$column];
    }, $nodes));
  }

  public function makeTree($nodeList) {
    $mapper = new SetMapper($this);

    return $mapper->map($nodeList);
  }

  protected function moveTo($target, $position) {
    return Move::to($this, $target, $position);
  }

  protected function computeLevel() {
    list($node, $nesting) = $this->determineDepth($this);

    if ( $node->equals($this) )
      return $this->ancestors()->count();

    return $node->getLevel() + $nesting;
  }

  protected function determineDepth($node, $nesting = 0) {
    while( $parent = $node->parent()->first() ) {
      $nesting = $nesting + 1;

      $node = $parent;
    }

    return array($node, $nesting);
  }

}
