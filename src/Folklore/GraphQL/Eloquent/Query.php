<?php
/**
 * Created by PhpStorm.
 * User: Shift
 * Date: 5/10/2018
 * Time: 8:17 AM
 */

namespace Folklore\GraphQL\Eloquent;

use Folklore\GraphQL\Support\PaginationType;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\NodeList;
use Folklore\GraphQL\Error\ValidationError;
use Folklore\GraphQL\Support\Traits\ShouldValidate;
use GraphQL\Type\Definition\Type;
use Folklore\GraphQL\Support\Query as FolkloreQuery;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Validation\Rule;
use GraphQL\Type\Definition\ListOfType;
use Illuminate\Database\Eloquent\Builder;
class Query extends FolkloreQuery
{
    use Helper, ShouldValidate;

    protected $allowedOperators;
    protected $whereOperators=['=', '<>', '!=', '>', '<','>=','<=','BETWEEN','LIKE','IN'];
    protected $defaultOperator = '=';
    protected $defaultJoin = 'AND';

    /**
     * Query constructor.
     */
    public function __construct() {
        parent::__construct();

        //Setup default attribute names from config
        $this->setupAttributeNames();

        //List of allowed operators in a filter condition
        $this->allowedOperators = config('graphql.eloquent.query.filter.operator.list');
    }

    protected function getValidator($args, $rules, $messages = [])
    {
        $validator =  app('validator')->make($args, $rules, $messages);
        if (method_exists($this, 'withValidator')) {
            $this->withValidator($validator, $args);
        }

        return $validator;
    }

    private function validateConditions($args)
    {
        //Todo, validate value based on operator value
        $rules = [
            'filter' => 'array',
            'filter.*.name' => 'required',
            'filter.*.value' => 'required',
            'filter.*.operator.value' => Rule::in($this->whereOperators),
            //Todo: Nested conditions
            'filter.condition' => Rule::in('AND','OR')
        ];

        $validator = $this->getValidator($args, $rules);
        if ($validator->fails()) {
            throw with(new ValidationError('validation'))->setValidator($validator);
        }

        return true;
    }

    /**
     * Inject eloquent arguments into query's arguments
     *
     * @param array $args
     * @return array
     * @throws \Exception
     */
    public function processArgs(array $args)
    {
        $newArgs = [];

        //Loop through each argument and build a new array of arguments
        foreach($args as $argumentKey => $argumentMeta)
        {
            $type = $argumentMeta['type'];

            //Check if there are conflict of name with reserved names
            if(in_array($argumentKey,[
                    $this->eloquentFilterAttributeName,
                    $this->eloquentOrderByAttributeName,
                    $this->eloquentPaginationAttributeName,
                    $this->limitAttributeName,
                    $this->offsetAttributeName
                ]) ||
                in_array($argumentMeta['name'],[
                    $this->eloquentFilterAttributeName,
                    $this->eloquentOrderByAttributeName,
                    $this->eloquentPaginationAttributeName,
                    $this->limitAttributeName,
                    $this->offsetAttributeName
                ]))
            {
                throw new \Exception("{$argumentKey} is a reserved argument name by the GraphQL Eloquent Query. Please rename this argument.");
            }

            $newArgs[$argumentKey] = $argumentMeta;
        }

        /*
         * Inject arguments
         */

        //Filter argument
        $newArgs[$this->eloquentFilterAttributeName] = [
            'name' => $this->eloquentFilterAttributeName,
            'type' => Type::listOf(\GraphQL::type('eloquentFilter'))
        ];

        //Order By Argument
        $newArgs[$this->eloquentOrderByAttributeName] = [
            'name' => $this->eloquentOrderByAttributeName,
            'type' => Type::listOf(\GraphQL::type('eloquentOrder'))
        ];

        //Limit Argument
        $newArgs[$this->limitAttributeName] = [
            'name' => $this->limitAttributeName,
            'type' => Type::int()
        ];

        //Offset Argument
        $newArgs[$this->offsetAttributeName] = [
            'name' => $this->offsetAttributeName,
            'type' => Type::int()
        ];

        //If parent type supports soft deletes
        if(method_exists($this->getBaseType()->config['model'],'trashed')) {
            //Trashed
            $newArgs[$this->withTrashedAttributeName] = [
                'name' => $this->withTrashedAttributeName,
                'type' => Type::boolean()
            ];

            //Only Trashed
            $newArgs[$this->onlyTrashedAttributeName] = [
                'name' => $this->onlyTrashedAttributeName,
                'type' => Type::boolean()
            ];
        }

        //Eloquent pagination
        if($this->type() instanceof PaginationType) {
            switch($this->paginationType) {
                case 'cursor':
                    $newArgs[$this->eloquentPaginationAttributeName] = [
                        'name' => $this->eloquentPaginationAttributeName,
                        'type' => \GraphQL::type('PaginationEloquentCursor')
                    ];
                    break;
                case 'simple':
                default:
                    $newArgs[$this->eloquentPaginationAttributeName] = [
                        'name' => $this->eloquentPaginationAttributeName,
                        'type' => \GraphQL::type('PaginationEloquentSimple')
                    ];
                    break;
            }
        }

        return $newArgs;
    }

    /**
     * Cast value to type
     *
     * @param $value
     * @param null $cast
     * @return bool|float|int|string
     */
    protected function castValue($value,$cast=null)
    {
        switch($cast) {
            case 'float':
                return (float)$value;
            case 'integer':
                return (int)$value;
            case 'boolean':
                return (bool)$value;
            case 'timestamp':
                return new \Carbon\Carbon($value);
            case 'string':
            default:
                return (string)$value;
        }
    }

    /**
     * Extract the value of an argument by its name
     *
     * @param FieldNode $fieldNode
     * @param array $variables
     * @param string $name
     * @param mixed $defaultValue
     * @return mixed
     */
    private function extractArgumentByName(FieldNode $fieldNode, array $variables, $name, $defaultValue=null)
    {
        $argumentsList = $fieldNode->arguments;
        if ($argumentsList->count()) {
            foreach ($argumentsList as $argumentField) {
                //When extracting arguments by name, we bypass the eloquent attributes
                if($argumentField->name->value === $name) {
                    return $argumentField->value->value;
                }
            }
        }

        return $defaultValue;
    }

    /**
     * Extract filter arguments by their names
     *
     * @param $fieldNode
     * @param array $variables
     * @return array
     */
    protected function extractFilterArgumentsByName(FieldNode $fieldNode, array $variables)
    {
        //Build argument array from graphql query
        $arguments = [];
        $argumentsList = $fieldNode->arguments;
        if ($argumentsList->count()) {
            foreach ($argumentsList as $argumentField) {
                //When extracting arguments by name, we bypass the eloquent attributes
                if(!in_array($argumentField->name->value,
                            [
                                $this->withTrashedAttributeName,
                                $this->onlyTrashedAttributeName,
                                $this->limitAttributeName,
                                $this->offsetAttributeName
                            ])
                    &&

                    !str_contains($argumentField->name->value,
                    [
                        $this->baseEloquentOrderByAttributeName,
                        $this->baseEloquentFilterAttributeName,
                        $this->basePaginationAttributeName
                    ]))
                {
                    //Is a variable
                    if(!isset($argumentField->value->value)) {
                        //Variable is set, we borrow its value
                        if(isset($variables[$argumentField->name->value])) {
                            $arguments[$argumentField->name->value] = $variables[$argumentField->name->value];
                        }
                    } else {
                        //value is passed directly through argument
                        $arguments[$argumentField->name->value] = $argumentField->value->value;
                    }
                }
            }
        } elseif (isset($variables[$fieldNode->name->value])) {
            //When arguments are set in the same name in the variables array as the field (relationship) name
            $arguments = $variables[$fieldNode->name->value];
        }

        //Add field and its arguments to list when not empty
        if (!empty($arguments)) {
            $finalArguments = [];
            foreach ($arguments as $argumentName => $argumentValue) {
                $finalArguments[] = [
                    'name'     => $argumentName,
                    'value'    => $argumentValue,
                    'operator' => $this->defaultOperator,
                    'join' => $this->defaultJoin
                ];
            }

            return $finalArguments;
        }

        return [];
    }

    /**
     * Extract Arguments from one variable
     *
     * @param NodeList $argumentsList
     * @param $variableName
     * @return array
     * @throws \Exception
     */
    protected function extractArgumentsFromVariable(array $variables, $variableName)
    {
        //if argument list is not empty
        if (count($variables) && isset($variables[$variableName])) {
            $argumentList = $variables[$variableName];
            //Argument list should be in array format (json)
            if(!is_array($argumentList)) {
                throw new \Exception("Argument list expected to be in array format.");
            }

            $finalArguments = [];

            //Loop through json object and extract the arguments
            foreach($argumentList as $key => $argumentRow) {
                if(!isset($argumentRow['name'])) {
                    throw new \Exception("Name field is compulsory on the argument '{$variableName}' number {$key}");
                }

                //Name & value is compulsory on an argument
                $formattedArgumentRow = [
                    'name' => $argumentRow['name']
                ];

                /*
                 * Filters
                 */
                if(str_contains($variableName,$this->baseEloquentFilterAttributeName)) {

                    //Value - Required
                    if(!isset($argumentRow['value'])) {
                        throw new \Exception("Value field is compulsory on the argument '{$variableName}' number {$key}");
                    }

                    $formattedArgumentRow['value'] = $this->castValue($argumentRow['value'], $argumentRow['cast'] ?? null);

                    //Operator - Optional
                    $formattedArgumentRow['operator'] = $argumentRow['operator'] ?? $this->defaultOperator;
                    //Join - Optional
                    $formattedArgumentRow['join'] = $argumentRow['join'] ?? $this->defaultJoin;
                }

                /*
                 * Order By
                 */
                if(str_contains($variableName,$this->baseEloquentOrderByAttributeName)) {
                    //Value - Optional
                    $formattedArgumentRow['value'] = $argumentRow['value'] ?? 'asc';
                }

                //Add formatted argument to list
                array_push($finalArguments,$formattedArgumentRow);
                $finalArguments[] = $formattedArgumentRow;
            }

            return $finalArguments;
        }

        return [];
    }

    /**
     * Map Relations with Arguments
     * It assumes that each argument for each type is a filter that will be applied to the query
     *
     * @param NodeList $selectionSet - List of nodes from GraphQL library
     * @param $nodeType - Parent Type
     * @param array $variables - Variables extracted from graphql query
     * @param array $outputArgumentList - Used recursively whilst mapping child relationship
     * @param string $prefix - Used for annotating child relationship names with dot notation
     * @return array
     */
    protected function mapRelationArguments(NodeList $selectionSet, $nodeType, $variables = [], $outputArgumentList = [], $prefix = '')
    {
        foreach ($selectionSet as $selectionNode) {
            if (!empty($selectionNode->selectionSet)) {
                $selectionNodeName = $selectionNode->name->value;
                $fieldName         = empty($prefix) ? $selectionNodeName : "{$prefix}.{$selectionNodeName}";
                $field             = $nodeType->getField($selectionNodeName);
                $argumentType      = empty($field->args) ? null : $field->args[0]->getType();

                if($argumentType) {
                    $argumentsByName = $this->extractFilterArgumentsByName($selectionNode,$variables);
                    //Filter Arguments
                    $argumentsByEloquentFilter = $this->extractArgumentsFromVariable(
                                                    $variables,
                                                    $this->formatVariableName(
                                                        $this->baseEloquentFilterAttributeName,
                                                        $this->getBaseType()->name,
                                                        $fieldName
                                                    )
                                                );

                    //Order By
                    $argumentsByEloquentOrder = $this->extractArgumentsFromVariable(
                                                    $variables,
                                                    $this->formatVariableName(
                                                        $this->baseEloquentOrderByAttributeName,
                                                        $this->getBaseType()->name,
                                                        $fieldName
                                                    )
                                                );
                    //Build argument list
                    $outputArgumentList[$fieldName] = [ 'filter'=>array_merge($argumentsByName,$argumentsByEloquentFilter),
                                                        'orderBy'=>$argumentsByEloquentOrder,
                                                        'withTrashed' => $this->extractArgumentByName($selectionNode,$variables,$this->withTrashedAttributeName,false),
                                                        'onlyTrashed' => $this->extractArgumentByName($selectionNode,$variables,$this->onlyTrashedAttributeName,false),
                                                        'limit' => $this->extractArgumentByName($selectionNode,$variables,$this->limitAttributeName,false),
                                                        'offset' => $this->extractArgumentByName($selectionNode,$variables,$this->offsetAttributeName,false),
                                                      ];
                }

                //Recursively walk through child relationships
                $childSelections = $selectionNode->selectionSet->selections;
                if (!empty($childSelections)) {
                    $newNodeType = $this->getBaseType($field->getType());

                    if($this->isEloquentModel($newNodeType)) {
                        $outputArgumentList = $this->mapRelationArguments(
                            $childSelections,
                            $newNodeType,
                            $variables,
                            $outputArgumentList,
                            $fieldName
                        );
                    }
                }
            }
        }
        return $outputArgumentList;
    }

    /**
     * Get relationship names from query
     *
     * @param NodeList $selectionSet
     * @param $nodeType
     * @param array $relationsList
     * @param string $prefix
     */
    protected function getRelations(NodeList $selectionSet, $nodeType, $relationsList=[], $prefix = '')
    {
        //Loop through each field
        foreach($selectionSet as $selectionNode) {
            //Field has attributes
            if (!empty($selectionNode->selectionSet)) {
                $selectionNodeName = $selectionNode->name->value;
                $fieldName = empty($prefix) ? $selectionNodeName : "{$prefix}.{$selectionNodeName}";
                $field = $nodeType->getField($selectionNodeName);
                $fieldType = $this->getBaseType($field->getType());

                //If it is an eloquent model, we assume that its name is the relationship's name
                if($this->isEloquentModel($fieldType)) {
                    $relationsList[] = $fieldName;

                    //Recursively walk through child relationships
                    $childSelections = $selectionNode->selectionSet->selections;
                    if (!empty($childSelections)) {
                        $relationsList = $this->getRelations(
                            $childSelections,
                            $fieldType,
                            $relationsList,
                            $fieldName
                        );
                    }
                }
            }
        }

        return $relationsList;
    }

    /**
     * Map relations with their arguments
     *
     * @param ResolveInfo $info
     * @param array $variables
     * @return array
     */
    protected function mapRelations(ResolveInfo $info, $variables)
    {
        $nodeType = $this->getBaseType($info->returnType);
        $argumentSelections = $info->fieldNodes[0]->selectionSet->selections;
        if($this->type() instanceof PaginationType) {
            $arguments = $this->mapRelationArguments(
                            $argumentSelections[0]->selectionSet->selections,
                            $nodeType,
                            $variables
                        );
            $relations   = $this->getRelations(
                            $argumentSelections[0]->selectionSet->selections,
                            $nodeType
                        );
        } else {
            $arguments = $this->mapRelationArguments($argumentSelections, $nodeType, $variables);
            $relations   = $this->getRelations(
                $argumentSelections,
                $nodeType
            );
        }

        return collect($relations)
                ->unique()->flip()
                ->map(function ($_, $relation) use ($arguments) {
                    return $arguments[$relation] ?? [];
                })->toArray();
    }

    /**
     * Apply Filter to Eloquent Query
     *
     * @param mixed $query
     * @param array $conditions
     * @return mixed
     */
    protected function applyFilterToQuery($query, array $conditions)
    {
        $allowedOperators = array_map(function($val){
                                return strtolower($val);
                            },$this->allowedOperators);

        //Loop through each condition & apply it if supported, to the query
        foreach($conditions as $condition) {
            if(in_array(strtolower($condition['operator']), $allowedOperators)) {
                //Where operator
                if(in_array($condition['operator'],$this->whereOperators)) {
                    $query->where($condition['name'],$condition['operator'],$condition['value'],$condition['join']);
                    continue;
                }

                //Eloquent relationship constraints
                switch(strtolower($condition['operator'])) {
                    case 'has':
                        $query->has(
                            $condition['value']['relation'],
                            $condition['value']['operator'] ?? '>=',
                            $condition['value']['count'] ?? 1,
                            $condition['join']
                        );
                        break;
                    case 'doesnthave':
                        $query->doesntHave($condition['value']['relation'],$condition['join']);
                        break;
                    //TODO: Figure formatting of nested where has
                    case 'wheredoesnthave':
                        $query->whereDoesntHave($condition['name'],function($query){
                            return $this->applyFilterToQuery($query,$condition['filter']);
                        });
                        break;
                    case 'orWhereDoesntHave':
                        $query->orWhereDoesntHave($condition['name'],function($query){
                            return $this->applyFilterToQuery($query,$condition['filter']);
                        });
                        break;
                    case 'wherehas':
                        $query->whereHas($condition['name'], function($query){
                            return $this->applyFilterToQuery($query,$condition['filter']);
                        },$condition['value']['operator'] ?? '>=', $condition['value']['count'] ?? 1);
                        break;
                    case 'orWhereHas':
                        $query->orWhereHas($condition['name'], function($query){
                            return $this->applyFilterToQuery($query,$condition['filter']);
                        },$condition['value']['operator'] ?? '>=', $condition['value']['count'] ?? 1);
                        break;
                    default:
                        throw new \Exception("The operator '{$condition['operator']}' is allowed but wasn't processed.");
                        break;
                }
            } else {
                throw new \Exception("The operator '{$condition['operator']}' is forbidden");
            }
        }

        return $query;
    }

    /**
     * Apply order by params to query
     *
     * @param $query
     * @param array $orderList
     * @return mixed
     */
    private function applyOrderToQuery($query, array $orderList)
    {
        foreach($orderList as $orderRow) {
            $query->orderBy($orderRow['name'],$orderRow['value']);
        }

        return $query;
    }

    /**
     * Apply with trashed filter to query
     *
     * @param $query
     * @return mixed
     */
    private function applyWithTrashedToQuery($query)
    {
        return $query->withTrashed();
    }

    /**
     * Apply only trashed filter to query
     *
     * @param $query
     * @return mixed
     */
    private function applyOnlyTrashedToQuery($query)
    {
        return $query->onlyTrashed();
    }

    /**
     * Apply conditions & order to relationships
     *
     * @param ResolveInfo $info
     * @param Builder $query
     * @return Builder
     */
    public function requestedRelations(ResolveInfo $info, Builder $query)
    {
        $relations = $this->mapRelations($info, $info->variableValues);
        $relationArray = [];

        //Loop through each relation and apply its conditions
        foreach ($relations as $relation => $conditions) {
            if(count($conditions)) {
                $relationArray[$relation] =  function ($query) use ($conditions,$relation,$info) {
                    $continueProcessing = true;
                    /*
                     * Before query is applied to relationship
                     * Run this method
                     * If query returns false, we stop processing the remaining conditions
                     * When relationship name is 'user.roles', method name will be 'beforeUserRolesQuery'
                    */
                    $beforeMethodName = 'before'.implode('',array_map(function($val){return ucfirst($val);},explode('.',$relation))).'Query';
                    if(method_exists($this,$beforeMethodName)) {
                        $continueProcessing = $this->$beforeMethodName($conditions,$info,$query) === null ?? true;
                    }

                    //Continue processing
                    if($continueProcessing) {
                        //Apply filter & order
                        $query = $this->applyOrderToQuery(
                            $this->applyFilterToQuery(
                                $query,
                                $conditions['filter']
                            ),
                            $conditions['orderBy']);

                        //Apply with trashed filter
                        if($conditions['withTrashed']) {
                            $this->applyWithTrashedToQuery($query);
                        }

                        //Apply only trashed filter
                        if($conditions['onlyTrashed']) {
                            $this->applyOnlyTrashedToQuery($query);
                        }

                        //Offset
                        if($conditions['offset']) {
                            $query->offset($conditions['offset']);
                        }

                        //Limit
                        if($conditions['limit']) {
                            $query->limit($conditions['limit']);
                        }
                    }

                    return $query;
                };
            }
            else {
                array_push($relationArray,$relation);
            }
        }

        $query->with($relationArray);

        return $query;
    }

    /**
     * Apply requested filters to base type
     *
     * @param ResolveInfo $info
     * @param $eloquentQuery
     * @throws \Exception
     */
    public function requestedFilters(ResolveInfo $info, $eloquentQuery)
    {
        $arguments = [];
        if($this->getBaseType()) {
            $argumentsByName = [];
            if(isset($info->fieldNodes[0])) {
                $argumentsByName = $this->extractFilterArgumentsByName($info->fieldNodes[0],$info->variableValues);
            }

            $argumentsByEloquentFilter = $this->extractArgumentsFromVariable(
                                            $info->variableValues,
                                            $this->formatVariableName(
                                                $this->baseEloquentFilterAttributeName,
                                                $this->config['name']
                                            )
                                        );

            $arguments = array_merge($argumentsByName,$argumentsByEloquentFilter);
        }

        if (!empty($arguments)) {
            $this->applyFilterToQuery($eloquentQuery,$arguments);
        }
    }

    /**
     * Apply requested order by to base type
     *
     * @param ResolveInfo $info
     * @param $eloquentQuery
     * @throws \Exception
     */
    public function requestedOrders(ResolveInfo $info, $eloquentQuery)
    {
        $argumentsByEloquentOrder = [];
        $argumentsByEloquentOrder = $this->extractArgumentsFromVariable(
            $info->variableValues,
            $this->formatVariableName(
                $this->baseEloquentOrderByAttributeName,
                $this->name
            )
        );

        if (!empty($argumentsByEloquentOrder)) {
            $this->applyOrderToQuery($eloquentQuery,$argumentsByEloquentOrder);
        }
    }

    /**
     * Apply pagination to query
     *
     * @param ResolveInfo $info
     * @param $eloquentQuery
     * @return mixed
     */
    public function applyPagination(ResolveInfo $info, $eloquentQuery)
    {
        $variables = $info->variableValues;
        $paginationVariableName = $this->formatVariableName($this->basePaginationAttributeName);

        switch($this->paginationType) {
            case 'cursor':
                //TODO: Parse cursor pagination data & apply it
                break;
            case 'simple':
            default:
                if(isset($variables[$paginationVariableName])) {
                    $pagination = $variables[$paginationVariableName];
                    return $eloquentQuery->paginate(
                        $pagination['perPage'] ?? $this->defaultPaginationPerPage,
                        ['*'],
                        'page',
                        $pagination['page'] ?? 0
                    );
                }
        }


        return $eloquentQuery->paginate($this->defaultPaginationPerPage, ['*'],'page',0);
    }

    /**
     * Apply filters to query
     *
     * @param ResolveInfo $info
     * @param $eloquentQuery
     * @throws \Exception
     */
    public function applyFilters(ResolveInfo $info, $eloquentQuery)
    {
        //Apply filters & order to relations
        $this->requestedRelations($info, $eloquentQuery);

        //Apply filters to base type
        $this->requestedFilters($info, $eloquentQuery);

        //Apply order to base type
        $this->requestedOrders($info, $eloquentQuery);
    }

    /**
     * Resolve query
     *
     * @param $root
     * @param $args
     * @param $context
     * @param ResolveInfo $info
     * @return mixed
     * @throws \Exception
     */
    public function resolve($root, $args, $context, ResolveInfo $info)
    {
        $type = $this->getBaseType($info->returnType);

        if(!isset($type->config['model'])) {
            throw new \Exception("Wrong configuration detected. Please set model in the {$type->name} 'attributes' array.");
        }

        //Query builder
        $eloquentQuery = $type->config['model']->query();

        //Before main query executes, check for method and execute it
        //If it returns false, we skip applying filters
        $continueApplyingFilters = true;
        $beforeMethodName = 'before'.ucfirst($type->name).'Query';
        if(method_exists($this,$beforeMethodName)) {
            $continueApplyingFilters = $this->$beforeMethodName($root, $args, $context, $info,$eloquentQuery) ?? true;
        }

        //Processing is a go
        if($continueApplyingFilters) {
            //Apply filters to base query
            $this->applyFilters($info,$eloquentQuery);
        }


        //If query returns paginated results
        if($this->type() instanceof PaginationType) {
            return $this->applyPagination($info,$eloquentQuery);
        } else {
            //Return result
            return $eloquentQuery->first();
        }

    }
}