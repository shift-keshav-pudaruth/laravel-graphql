<?php
/**
 * Created by PhpStorm.
 * User: Shift
 * Date: 5/15/2018
 * Time: 11:34 AM
 */

namespace Folklore\GraphQL\Eloquent\Type;

use Folklore\GraphQL\Support\Type as FolkloreType;
use GraphQL\Type\Definition\Type as GraphQLType;
class PaginationSimple extends FolkloreType
{
    protected $inputObject = true;

    protected $attributes = [
        'name' => 'PaginationEloquentSimple',
        'description' => 'Pagination simple parameters used by eloquent queries'
    ];

    public function fields()
    {
        return [
            'page' => [
                'type' => GraphQLType::int(),
                'description' => 'Page number'
            ],
            'perPage' => [
                'type' => GraphQLType::int(),
                'description' => 'Number of items per page'
            ]
        ];
    }
}