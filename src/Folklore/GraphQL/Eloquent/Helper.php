<?php
/**
 * Created by PhpStorm.
 * User: Shift
 * Date: 5/11/2018
 * Time: 9:19 AM
 */

namespace Folklore\GraphQL\Eloquent;

use GraphQL\Type\Definition\ListOfType;
trait Helper
{
    /**
     * Get Base Eloquent Type
     *
     * @param $type
     * @return mixed
     */
    protected function getBaseType($type=null) {
        $type = $type ?? $this->type();
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
}