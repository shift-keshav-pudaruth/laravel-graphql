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
    protected $baseEloquentOrderByAttributeName;
    protected $baseEloquentFilterAttributeName;
    protected $eloquentFilterAttributeName;
    protected $eloquentOrderByAttributeName;
    protected $withTrashedAttributeName='EloquentWithTrashed';
    protected $onlyTrashedAttributeName='EloquentOnlyTrashed';
    protected $limitAttributeName='EloquentLimit';
    protected $offsetAttributeName='EloquentOffset';

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