<?php

namespace Trees\Extensions\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;

class Builder extends BaseBuilder {

  public function reOrderBy($column, $direction = 'asc') {
    $this->orders = null;

    if ( !is_null($column) ) return $this->orderBy($column, $direction);

    return $this;
  }

  public function aggregate($function, $columns = array('*')) {
    if ( !isset($this->groups) )
      $this->reOrderBy(null);

    return parent::aggregate($function, $columns);
  }

}
