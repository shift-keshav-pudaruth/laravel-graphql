<?php
/**
 * Created by PhpStorm.
 * User: Shift
 * Date: 5/10/2018
 * Time: 9:11 AM
 */

namespace Folklore\GraphQL\Eloquent;

use Folklore\GraphQL\Eloquent\Interfaces\EloquentParser;
use Folklore\GraphQL\Support\Type as FolkloreType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type as GraphQLType;
use Illuminate\Database\Eloquent\Model;

class Type extends FolkloreType
{
    use Helper;

    public function __construct() {
        parent::__construct();

        //Setup default attribute names from config
        $this->setupAttributeNames();
    }

    /**
     * Inject eloquent fields where necessary
     *
     * @param array $fields
     * @return array
     */
    public function processFields(array $fields)
    {
        $newFields = [];

        foreach($fields as $fieldKey => $fieldMeta)
        {
            //Resolve type
            $type = $this->getBaseType($fieldMeta['type']);

            //if type is eloquent, hence is a model
            if($this->isEloquentModel($type)) {
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
                    //Filter
                    $filterAttributeName => [
                        'name' => $filterAttributeName,
                        'type' => GraphQLType::listOf(\GraphQL::type('eloquentFilter')),
                    ],
                    //Order By
                    $orderByAttributeName => [
                        'name' => $orderByAttributeName,
                        'type' => GraphQLType::listOf(\GraphQL::type('eloquentOrder')),
                    ],
                    //Limit Argument
                    $this->limitAttributeName => [
                        'name' => $this->limitAttributeName,
                        'type' => GraphQLType::int()
                    ],
                    //Offset Argument
                    $this->offsetAttributeName => [
                        'name' => $this->offsetAttributeName,
                        'type' => GraphQLType::int()
                    ]
                ];

                //Check if model has soft delete trait
                if(method_exists($this->config['model'],'trashed')) {
                    //With Trashed
                    $injectedArgs[$this->withTrashedAttributeName] = [
                        'name' => $this->withTrashedAttributeName,
                        'type' => GraphQLType::boolean()
                    ];
                    //onlyTrashed
                    $injectedArgs[$this->onlyTrashedAttributeName] = [
                        'name' => $this->onlyTrashedAttributeName,
                        'type' => GraphQLType::boolean()
                    ];
                }

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