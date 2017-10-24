<?php

namespace Hamba\QueryGet;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;

trait Selectable
{
    public static function getSelects($requestSelects, $opt)
    {
        //get specification
        $classObj = new static;
        $className = get_class($classObj);

        //check if class is selectable
        if (!property_exists($className, 'selectable')) {
            throw new \Exception($className.' is not selectable');
        }

        $selectSpecs = $classObj->selectable;

        //validate selectables
        if (!is_array($selectSpecs)) {
            throw new \Exception('Mallformed selectable for '.$className);
        }

        //make spesifications uniform
        $selectSpecs = collect($selectSpecs)->mapWithKeys(function ($select, $key) {
            if (is_numeric($key)) {
                return [$select=>$select];
            } else {
                return [$key=>$select];
            }
        });

        //filter specs from option
        if (array_has($opt, 'only')) {
            $selectSpecs = $selectSpecs->only($opt['only']);
        } elseif (array_has($opt, 'except')) {
            $selectSpecs = $selectSpecs->except($opt['except']);
        }
        
        $finalSelects = ['id'];
        $withs = [];

        $requestSelects = array_wrap($requestSelects);
        $selectAll = (in_array('*', $requestSelects));
        
        //groupped properties
        $groups = [];
        if ($selectAll) {
            //add all available properties
            foreach ($selectSpecs as $key => $select) {
                //check if relation
                if (isset($classObj) && (method_exists($classObj, $select))){
                    
                } else {
                    //it is properti
                    $finalSelects[$key] = $select;
                }
            }
        }

        //clean request
        $requestSelects = array_filter(array_unique($requestSelects), function ($val) {
            return $val != '*';
        });

        //ensure array
        foreach ($requestSelects as $selectString) {
            $key = str_before($selectString, '.');
            $afterKey = substr($selectString, strlen($key)+1);
            
            if (!array_has($selectSpecs, $key)) {
                //if property not selectable -> skip
                continue;
            }

            //it is model property
            $mappedName = $selectSpecs[$key];
            
            //check if relation
            if (isset($classObj)) {
                //if class exists, determined whether it is relation or attribute
                if(method_exists($classObj, $mappedName)){
                    //it is relation
                    if (!array_has($groups, $key)) {
                        $groups[$key] = [];
                    }
                    if ($afterKey === false) {
                        //if relation but has no property specified, use key as relation name
                    } else {
                        //further select specified
                        $groups[$key][] = $afterKey;
                    }
                    continue;
                }
            }

            //if not relation, add as renamed attribute
            $finalSelects[] = $mappedName.' as '.$key;
        }

        //reduce depth
        if (!isset($opt['depth'])) {
            $opt['depth'] = 5;
        }

        //select all must have depth
        if (--$opt['depth'] <= 0) {
            if ($selectAll || $opt['depth'] < -10) {
//                goto result;
            }
        }

        //process groups/relations
        foreach ($groups as $key => $groupSelects) {
            $relationName = $selectSpecs[$key];
            
            //find in relation definition
            $relationClass = null;
            
            //if has select all property
            if (($groupSelects == '*') || (array_search('*', $groupSelects) !== false)) {
                $groupSelects = '*';
            }

            //find relationClass and include foreign keys
            if (isset($classObj)) {
                $relation = $classObj->$relationName();
                //get class name
                $relationClass = $relation->getRelated();

                //include foreign keys
                if ($relation instanceof BelongsTo) {
                    //belongsTo need foreign
                    $foreignKey = $relation->getForeignKey();
                    $finalSelects[] = $foreignKey;
                } elseif ($relation instanceof MorphOneOrMany) {
                    if (is_array($groupSelects)) {
                        $groupSelects[] = 'id';
                        $groupSelects[] = $relation->getForeignKeyName();
                        $groupSelects[] = $relation->getMorphType();
                    }
                } elseif ($relation instanceof HasOneOrMany) {
                    if (is_array($groupSelects)) {
                        $groupSelects[] = $relation->getForeignKeyName();
                    } else {
                        //its select all
                    }
                }
            }

            //check if relation class is selectable
            if (!method_exists($className, 'getSelects')) {
                throw new \Exception($className.' is not selectable');
            }
            
            //process recursive select for relation
            $select = $relationClass::getSelects($groupSelects, $opt);
            //add relation name
            $select['name'] = $relationName;
            //push
            $withs[$key] = $select;
        }

        result:
        //wrap result
        return [
            'selects' => $finalSelects,
            'withs' => $withs
        ];
    }
}