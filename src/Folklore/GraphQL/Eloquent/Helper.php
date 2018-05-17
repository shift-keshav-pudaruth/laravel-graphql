<?php
/**
 * Created by PhpStorm.
 * User: Shift
 * Date: 5/11/2018
 * Time: 9:19 AM
 */

namespace Folklore\GraphQL\Eloquent;

use Folklore\GraphQL\Support\PaginationType;
use GraphQL\Type\Definition\ListOfType;
use Illuminate\Database\Eloquent\Model;
trait Helper
{
    /**
     * Pagination attribute name - Base
     *
     * @var string
     */
    protected $basePaginationAttributeName;

    /**
     * Pagination attribute name combined with type in use with query variables
     *
     * @var string
     */
    protected $eloquentPaginationAttributeName;

    /**
     * Pagination - Items per page
     *
     * @var integer
     */
    protected $defaultPaginationPerPage;

    /**
     * Pagination - Type of data
     *
     * @var string
     */
    protected $paginationType;

    /**
     * Order by attribute name - Base
     *
     * @var string
     */
    protected $baseEloquentOrderByAttributeName;

    /**
     * Order by attribute name in use with query variables
     *
     * @var
     */
    protected $eloquentOrderByAttributeName;

    /**
     * Filter attribute name - Base
     *
     * @var string
     */
    protected $baseEloquentFilterAttributeName;

    /**
     * Filter Attribute name in use with query variables
     *
     * @var string
     */
    protected $eloquentFilterAttributeName;

    /**
     * With trashed attribute name in use with query argument
     *
     * @var string
     */
    protected $withTrashedAttributeName;

    /**
     * Only trashed attribute name in use with query argument
     *
     * @var string
     */
    protected $onlyTrashedAttributeName;

    /**
     * Limit attribute name in use with query argument
     *
     * @var string
     */
    protected $limitAttributeName;

    /**
     * Offset attribute name in use with query argument
     *
     * @var string
     */
    protected $offsetAttributeName;

    /**
     * Setup attribute names
     *
     * Call it in the construct function
     */
    protected function setupAttributeNames()
    {
        //Eloquent: Order By
        $this->baseEloquentOrderByAttributeName = config('graphql.eloquent.query.attributeName.orderBy','EloquentOrderBy');
        $this->eloquentOrderByAttributeName = $this->formatVariableName($this->baseEloquentOrderByAttributeName);

        //Eloquent: Filter
        $this->baseEloquentFilterAttributeName = config('graphql.eloquent.query.attributeName.filter', 'EloquentFilter');
        $this->eloquentFilterAttributeName= $this->formatVariableName($this->baseEloquentFilterAttributeName);

        //Eloquent: Pagination
        $this->basePaginationAttributeName = config('graphql.eloquent.query.attributeName.pagination', 'EloquentPagination');
        $this->eloquentPaginationAttributeName = $this->formatVariableName($this->basePaginationAttributeName);
        $this->defaultPaginationPerPage = config('graphql.eloquent.pagination.per_page',10);

        $this->paginationType = config('graphql.eloquent.pagination.type','simple');

        //Trashed
        $this->withTrashedAttributeName = config('graphql.eloquent.query.attributeName.withTrashed','EloquentWithTrashed');
        $this->onlyTrashedAttributeName = config('graphql.eloquent.query.attributeName.onlyTrashed','EloquentOnlyTrashed');

        //Offset/Limit
        $this->limitAttributeName = config('graphql.eloquent.query.attributeName.limit','EloquentLimit');
        $this->offsetAttributeName = config('graphql.eloquent.query.attributeName.offset','EloquentOffset');
    }

    /**
     * Get Base Eloquent Type
     *
     * @param $type
     * @return mixed
     */
    protected function getBaseType($type=null) {
        $type = $type ?? $this->type();

        //Pagination type
        if($type instanceof PaginationType) {
            $type = $type->getField('items')->getType();
        }

        //List type
        if ($type instanceof ListOfType) {
            $type = $type->getWrappedType();
        }

        return $type;
    }

    /**
     * Format variable names to avoid clashes
     *
     * @param null $variableName
     * @param null $prefix
     * @return string
     */
    protected function formatVariableName($variableName=null,$baseTypeName=null,$relationshipName=null)
    {
        if($baseTypeName !== null) {
            if($relationshipName !== null) {
                return $baseTypeName.ucfirst($relationshipName).ucfirst($variableName);
            } else {
                return $baseTypeName.ucfirst($variableName);
            }
        }

        return $this->getBaseType()->name.ucfirst($variableName);
    }

    /**
     * Check if graphql type is tied to an eloquent model
     *
     * @param $type
     * @return bool
     */
    protected function isEloquentModel($type)
    {
        return isset($type->config['model']) && $type->config['model'] instanceof Model;
    }
}