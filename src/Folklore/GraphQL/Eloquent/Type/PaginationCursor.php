<?php
/**
 * Created by PhpStorm.
 * User: Shift
 * Date: 5/15/2018
 * Time: 11:43 AM
 * WIP
 */

namespace Folklore\GraphQL\Eloquent\Type;

use Folklore\GraphQL\Support\Type as FolkloreType;
use GraphQL\Type\Definition\Type as GraphQLType;
class PaginationCursor extends FolkloreType
{
    protected $inputObject = true;

    protected $attributes = [
        'name' => 'PaginationEloquentCursor',
        'description' => 'Pagination with cursor parameters used by eloquent queries'
    ];

    public function fields()
    {
        /**
         * WIP
         * Reference: https://facebook.github.io/relay/graphql/connections.htm
         */
        return [
            'after' => [
                'type' => GraphQLType::string(),
                'description' => 'Fetch items after this cursor'
            ],
            'before' => [
                'type' => GraphQLType::string(),
                'description' => 'Fetch items before this cursor'
            ],
            'first' => [
                'type' => GraphQLType::int(),
                'description' => 'Number of items to fetch after cursor'
            ],
            'last' => [
                'type' => GraphQLType::int(),
                'description' => 'Number of items to fetch before cursor'
            ],
        ];
    }
}