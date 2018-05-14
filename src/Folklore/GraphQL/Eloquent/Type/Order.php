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
class Order extends FolkloreType
{
    protected $inputObject = true;

    protected $attributes = [
        'name' => 'eloquentOrder',
        'description' => 'Order by used by eloquent queries'
    ];

    public function fields()
    {
        return [
            'name' => [
                'type' => GraphQLType::nonNull(GraphQLType::string()),
                'description' => 'Name of order column'
            ],
            //TODO: convert into Enum
            'value' => [
                'type' => GraphQLType::string(),
                'description' => 'Value of order'
            ]
        ];
    }
}