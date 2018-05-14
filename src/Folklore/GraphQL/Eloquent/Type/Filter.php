<?php
/**
 * Created by PhpStorm.
 * User: Shift
 * Date: 5/11/2018
 * Time: 12:07 PM
 */

namespace Folklore\GraphQL\Eloquent\Type;

use Folklore\GraphQL\Support\Type as FolkloreType;
use GraphQL\Type\Definition\Type as GraphQLType;
use GraphQL\Type\Definition\UnionType;
class Filter extends FolkloreType
{
    protected $inputObject = true;

    protected $attributes = [
        'name' => 'eloquentFilter',
        'description' => 'Filter used by eloquent queries'
    ];

    public function fields()
    {
        return [
            'name' => [
                'type' => GraphQLType::nonNull(GraphQLType::string()),
                'description' => 'Name of filter'
            ],
            'value' => [
                'type' => GraphQLType::string(),
                'description' => 'Value of filter'
            ],
            'cast' => [
                'type' => GraphQLType::string(),
                'description' => 'Cast value to specific type'
            ],
            'operator' => [
                'type' => GraphQLType::string(),
                'description' => 'Operator of filter'
            ],
            'join' => [
                'type' => GraphQLType::string(),
                'description' => 'Join of filter: AND/OR'
            ],
        ];
    }
}