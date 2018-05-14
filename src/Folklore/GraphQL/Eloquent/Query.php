<?php
/**
 * Created by PhpStorm.
 * User: Shift
 * Date: 5/10/2018
 * Time: 8:17 AM
 */

namespace Folklore\GraphQL\Eloquent;

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
    use Helper;

    protected $variablePrefix;
    protected $baseEloquentOrderByAttributeName;
    protected $baseEloquentFilterAttributeName;
    protected $eloquentFilterAttributeName;
    protected $eloquentOrderByAttributeName;
    protected $withTrashedAttributeName='withTrashed';
    protected $onlyTrashedAttributeName='onlyTrashed';
    protected $eloquentFilters;
    protected $eloquentOrder;
    protected $whereOperators=['=', '<>', '!=', '>', '<','>=','<=','BETWEEN','LIKE','IN'];
    protected $defaultOperator = '=';
    protected $defaultJoin = 'AND';

    use ShouldValidate;

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

    public function processArgs($args)
    {
        $newArgs = [];

        foreach($args as $argumentKey => $argumentMeta)
        {
            $type = $argumentMeta['type'];
            if($type instanceof self) {
                if(!isset($type->config['model'])) {
                    throw new \Exception("Wrong configuration detected. Please set model in the {$type->name} 'attributes' attribute.");
                }
            }

            //Check if there are conflict of name with reserved names
            if(in_array($argumentKey,[$this->eloquentFilterAttributeName,$this->eloquentOrderByAttributeName]) ||
                in_array($argumentMeta['name'],[$this->eloquentFilterAttributeName,$this->eloquentOrderByAttributeName])) {
                throw new \Exception("{$argumentKey} is a reserved argument name by the GraphQL Eloquent Query. Please rename this argument.");
            }

            $newArgs[$argumentKey] = $argumentMeta;
        }

        //Inject arguments

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

        return $newArgs;
    }

    protected function resolve($root, $args, $context, ResolveInfo $info)
    {
        $type = $info->returnType;
        if ($type instanceof ListOfType) {
            $type = $type->ofType;
        }

        if(!isset($type->config['model'])) {
            throw new \Exception("Wrong configuration detected. Please set model in the {$type->name} 'attributes' attribute.");
        }

        $eloquentQuery = $type->config['model']->query();
        $this->applyFilters($info,$eloquentQuery);

        return $eloquentQuery->get();
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
                if($argumentField->name->value !== $this->withTrashedAttributeName &&
                    $argumentField->name->value !== $this->onlyTrashedAttributeName &&
                    !str_contains($argumentField->name->value,
                    [
                        $this->baseEloquentOrderByAttributeName,
                        $this->baseEloquentFilterAttributeName
                    ])) {
                    $arguments[$argumentField->name->value] = $argumentField->value->value;
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
    protected function extractArgumentsFromVariable($variables, $variableName)
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
                    $outputArgumentList[$fieldName] = [ 'filter'=>array_merge($argumentsByName,$argumentsByEloquentFilter),
                                                        'orderBy'=>$argumentsByEloquentOrder,
                                                        'withTrashed' => $this->extractArgumentByName($selectionNode,$variables,$this->withTrashedAttributeName,false),
                                                        'onlyTrashed' => $this->extractArgumentByName($selectionNode,$variables,$this->onlyTrashedAttributeName,false)
                                                      ];
                }

                //Recursively walk through child relationships
                $childSelections = $selectionNode->selectionSet->selections;
                if (!empty($childSelections)) {
                    $newNodeType = $field->getType();

                    if ($newNodeType instanceof ListOfType) {
                        $newNodeType = $newNodeType->ofType;
                    }

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
        return $outputArgumentList;
    }


    protected function mapRelations(ResolveInfo $info, $variables)
    {
        $fields   = $info->getFieldSelection(3);
        $nodeType = $info->returnType;
        if ($nodeType instanceof ListOfType) {
            $nodeType = $nodeType->ofType;
        }
        $arguments = $this->mapRelationArguments($info->fieldNodes[0]->selectionSet->selections, $nodeType, $variables);
        return collect(array_keys(array_dot($fields)))
            //Formatting relationship names in eloquent notation
            ->map(function (string $value): string {
                return substr($value, 0, strrpos($value, '.') ?: 0);
            })->unique()->filter()->flip()
            //Add
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
    private function applyFilterToQuery($query, array $conditions)
    {
        foreach($conditions as $condition) {
            //Where operator
            if(in_array($condition['operator'],$this->whereOperators)) {
                $query->where($condition['name'],$condition['operator'],$condition['value'],$condition['join']);
                continue;
            }

            //Has
            switch(strtolower($condition['operator'])) {
                case 'has':
                    $query->has($condition['value']);
                    break;
                case 'doesnthave':
                    $query->doesntHave($condition['value']);
                    break;
                //TODO: Figure formatting of nested where has
                case 'wheredoesnthave':
                    $query->whereDoesntHave($condition['name'],function($query){
                        return $this->applyFilterToQuery($query,$condition['filter']);
                    });
                    break;
                case 'wherehas':
                    $query->whereHas($condition['name'], function($query){
                        return $this->applyFilterToQuery($query,$condition['filter']);
                    });
                    break;
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
        foreach ($relations as $relation => $conditions) {
            if(count($conditions)) {
                $relationArray[$relation] =  function ($query) use ($conditions) {
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
            $argumentsByName = $this->extractFilterArgumentsByName($info->fieldNodes[0],$info->variableValues);
            $argumentsByEloquentFilter = $this->extractArgumentsFromVariable(
                                            $info->variableValues,
                                            $this->formatVariableName(
                                                $this->baseEloquentFilterAttributeName,
                                                $this->getBaseType()->name
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
        if($this->getBaseType()) {
            $argumentsByEloquentOrder = $this->extractArgumentsFromVariable(
                $info->variableValues,
                $this->formatVariableName(
                    $this->baseEloquentOrderByAttributeName,
                    $this->getBaseType()->name
                )
            );
        }

        if (!empty($argumentsByEloquentOrder)) {
            $this->applyOrderToQuery($eloquentQuery,$argumentsByEloquentOrder);
        }
    }

    public function applyFilters($info, $eloquentQuery)
    {
        //Apply filters & order to relations
        $this->requestedRelations($info, $eloquentQuery);
        //Apply filters to base type
        $this->requestedFilters($info, $eloquentQuery);
        //Apply order to base type
        $this->requestedOrders($info, $eloquentQuery);
    }
}