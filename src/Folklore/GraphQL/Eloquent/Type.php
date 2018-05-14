<?php
/**
 * Created by PhpStorm.
 * User: Shift
 * Date: 5/10/2018
 * Time: 9:11 AM
 */

namespace Folklore\GraphQL\Eloquent;

use Folklore\GraphQL\Support\Type as FolkloreType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type as GraphQLType;
class Type extends FolkloreType
{
    use Helper;

    protected $baseEloquentOrderByAttributeName;
    protected $baseEloquentFilterAttributeName;
    protected $eloquentFilterAttributeName;
    protected $eloquentOrderByAttributeName;

    public function __construct() {
        parent::__construct();

        //Setup Eloquent Attribute Names
        $this->baseEloquentOrderByAttributeName = config('graphql.eloquent.query.orderByAttributeName',
            'EloquentOrderBy');
        $this->baseEloquentFilterAttributeName = config('graphql.eloquent.query.filterAttributeName',
            'EloquentFilter');

        $this->eloquentOrderByAttributeName = $this->formatVariableName($this->baseEloquentOrderByAttributeName);
        $this->eloquentFilterAttributeName= $this->formatVariableName($this->baseEloquentFilterAttributeName);
    }

    public function processFields($fields)
    {
        $newFields = [];

        foreach($fields as $fieldKey => $fieldMeta)
        {
            //Resolve type
            $type = $this->getBaseType($fieldMeta['type']);

            //if type is eloquent
            if($type instanceof ObjectType) {
                if(!isset($type->config['model'])) {
                    throw new \Exception("Wrong configuration detected. Please set model in the {$type->name} 'attributes' attribute.");
                }

                $filterAttributeName = $this->formatVariableName(
                                            $this->baseEloquentFilterAttributeName,
                                            $this->attributes['name'],
                                            $fieldKey
                                        );
                $orderByAttributeName = $this->formatVariableName(
                                            $this->baseEloquentOrderByAttributeName,
                                            $this->attributes['name'],
                                            $fieldKey
                                        );

                $injectedArgs = [
                    $filterAttributeName => [
                        'name' => $filterAttributeName,
                        'type' => GraphQLType::listOf(\GraphQL::type('eloquentFilter')),
                    ],
                    $orderByAttributeName => [
                        'name' => $orderByAttributeName,
                        'type' => GraphQLType::listOf(\GraphQL::type('eloquentOrder')),
                    ]
                ];

                //Inject Eloquent args
                if(isset($fieldMeta['args'])) {
                    $fieldMeta['args'] = array_merge($fieldMeta['args'],$injectedArgs);
                } else {
                    $fieldMeta['args'] = $injectedArgs;
                }
            }

            $newFields[$fieldKey] = $fieldMeta;
        }

        return $newFields;
    }
}