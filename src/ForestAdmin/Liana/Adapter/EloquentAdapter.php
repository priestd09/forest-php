<?php

namespace ForestAdmin\Liana\Adapter;

use Carbon\Carbon;
use DateTime;
use ForestAdmin\ForestLaravel\DatabaseStructure;
use ForestAdmin\Liana\Api\ResourceFilter;
use ForestAdmin\Liana\Exception\AssociationNotFoundException;
use ForestAdmin\Liana\Exception\CollectionNotFoundException;
use ForestAdmin\Liana\Model\Resource as ForestResource;
use ForestAdmin\Liana\Model\Relationship as ForestRelationship;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EloquentAdapter implements QueryAdapter
{

    /* @var ForestCollection[]
    */
    protected $collections;

    /**
     * @var ForestCollection
     */
    protected $thisCollection;

    /**
     * @var EntityRepository
     */
    protected $repository;

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * Eloquent constructor.
     * @param ForestCollection[] $collections
     * @param ForestCollection $entityCollection
     */
    public function __construct($collections, $entityCollection)
    {
        $this
            ->setCollections($collections)
            ->setThisCollection($entityCollection);
    }

    /**
     * @param ForestCollection[] $collections
     * @return $this
     */
    public function setCollections($collections)
    {
        $this->collections = $collections;

        return $this;
    }

    /**
     * @return ForestCollection[]
     */
    public function getCollections()
    {
        return $this->collections;
    }

    /**
     * @param ForestCollection $collection
     * @return $this
     */
    public function setThisCollection($collection)
    {
        $this->thisCollection = $collection;

        return $this;
    }

    /**
     * @return ForestCollection
     */
    public function getThisCollection()
    {
        return $this->thisCollection;
    }
    
    /**
     * Find a resource by its identifier
     *
     * @param string $recordId
     * @return array
     */
    public function getResource($recordId)
    {
        $collection = $this->getThisCollection();
        $returnedResource = null;

        list($returnedResource, $resultSet) = $this->loadResource($recordId, $collection);

        if ($returnedResource)
        {
            $relationships = $collection->getRelationships();

            if (count($relationships))
            {
                foreach ($relationships as $tableReference => $field)
                {
                    $foreignCollection = $this->findCollection($tableReference);

                    $relationship = new ForestRelationship;

                    $relationship->setType($foreignCollection->getName());
                    // TODO: check unit test with the entity class to be sure it return the right value
                    $relationship->setEntityClassName($foreignCollection->getEntityClassName());
                    $relationship->setFieldName($field->getField());
                    $relationship->setIdentifier($foreignCollection->getIdentifier());


                    if ($field->isTypeToOne()) {
                        // TODO: retrieve the foreignkey data with it so that we can return the data
                        $relationship->setId($resultSet[$field->getForeignKey()]);
                    }

                    $returnedResource->addRelationship($relationship);
                }

                foreach ($returnedResource->getRelationships() as $relationship) {
                    if ($relationship->getId()) {
                        $foreignCollection = $this->findCollection($relationship->getType());
                        list($resourceToInclude, $resultSet) = $this->loadResource(
                            $relationship->getId(),
                            $foreignCollection
                        );

                        if ($resourceToInclude)
                        {
                            $resourceToInclude->setType($relationship->getType());
                            $returnedResource->includeResource($resourceToInclude);
                        }
                    }
                }
            }
        }

        return $returnedResource->formatJsonApi();
     }

    /**
     * Find all resources by filter
     * @param ResourceFilter $filter
     * @return array
     */
    public function listResources($filter)
    {
        $collection = $this->getThisCollection();

        $queryBuilder = $this->filterQueryBuilder($filter, $collection);

        $countQueryBuilder = clone $queryBuilder;
        $totalNumberOfRows = $countQueryBuilder->count();

        // Paginate resources
        $this->paginateQueryBuilder($queryBuilder, $filter);

        $returnedResources = $this->loadResourcesFromQueryBuilder($queryBuilder, $this->getThisCollection());

        return ForestResource::formatResourcesJsonApi($returnedResources, $totalNumberOfRows);
    }

    /**
     * @param string $recordId
     * @param string $associationName
     * @param ResourceFilter $filter
     * @return array The hasMany resources with one relationships and a link to their many relationships
     * @throws AssociationNotFoundException
     */
    public function getHasMany($recordId, $associationName, $filter)
    {
        if (!$this->hasIdentifier()) {
            return null;
        }

        try
        {
            $associatedCollection = $this->findCollection($associationName);
        } catch (CollectionNotFoundException $exc)
        {
            throw new AssociationNotFoundException($associationName);
        }

        $thisIdentifier = $this->getThisCollection()->getIdentifier();
        $associationIdentifier = $associatedCollection->getIdentifier();

//        DB::table($associatedCollection->getTable())->;



        App::make($associatedCollection->getName())->with($associatedCollection->getRelationships());

    }

    /**
     * @param array $postData
     * @return array The created resource
     */
    public function createResource($postData)
    {
        $collection = $this->getThisCollection();
        $entityName = $collection->getEntityClassName();
        $model = App::make($entityName);

        $attributes = $this->getAttributesAndRelations($postData);

        foreach ($attributes as $property => $value) {
            $field = $collection->getField($property);
            $relatedCollection = $this->findRelatedCollection($field);

            if ($relatedCollection) {
                $relatedEntityClassName = $relatedCollection->getEntityClassName();
                $relatedEntityName = $relatedCollection->getName();

                // The field is actually a relation, so we need an entity. Relations are always of type Number.
                $entry = App::make($relatedEntityClassName)->findOrFail($value);

                if (!$entry) {
                    continue;
                }
                // In the case of a foreign key, there's no need to make an attach or something, just give the proper id of the foreign entry
                $model->{$property} = $value;
                // If there's a pivot table (relation n-m) then we'd need to make an attach (entry in this pivot table)
                // and you would need to find the plural table name used (Eg : library => libraries)
                // $model->{$relatedEntityName}()->attach($value);
            } else {
                if ($field->getType() == 'Date') {
                    // Date parameters can only be set as DateTime objects
                    $value = Carbon::instance(new DateTime($value));
                }
                $model->{$property} = $value;
            }

        }

        $model->save();
        $savedId = $collection->getIdentifier();

        return $model->{$savedId};
    }

    /**
     * @param string $recordId
     * @param array $postData
     * @return array The updated resource
     */
    public function updateResource($recordId, $postData)
    {
        $collection = $this->getThisCollection();
        $entityName = $collection->getEntityClassName();

        try {
            $model = App::make($entityName)->findOrFail($recordId);
        } catch(ModelNotFoundException $exc) {
            throw new Exception('Object not found for this recordId');
        }

        $attributes = $this->getAttributesAndRelations($postData);

        foreach ($attributes as $property => $value) {
            if (Schema::hasColumn($model->getTable(), $property)) {
                if ($collection->hasField($property)) {
                    $fieldType = $collection->getField($property)->getType();

                    if ($fieldType == 'Date') {
                        $value = Carbon::instance(new DateTime($value));
                    }
                }
                $model->{$property} = $value;
            }
        }

        $model->save();

        return $recordId;
    }


    protected function loadResource($recordId, $collection)
    {
        $model = App::make($collection->getEntityClassName());

        // Check if the foreign key must be included (like in doctrin)
        $resultSet = $model->where($collection->getIdentifier(), $recordId)->first();

        $returnedResource = null;

        if ($resultSet) {
            $returnedResource = new ForestResource(
                $collection,
                $this->formatResource($resultSet->getAttributes(), $collection)
            );
        }

        // Convert Collection to array and take the first element (since there's only one resource asked)
        $resultSet = $resultSet->toArray();

        return array($returnedResource, $resultSet);
    }

    protected function formatResource($resource, $collection = null)
    {

        if (is_null($collection)) {
            $collection = $this->getThisCollection();
        }

        $ret = array();

        foreach($collection->getFields() as $field) {
            $key = $field->getField();

            if (!array_key_exists($key, $resource)) {
                // *toMany Relationship => skip
                continue;
            }

            $value = $this->getResourceFieldValue($resource, $field);

            $ret[$key] = $value;
        }

        return $ret;
    }

    protected function getResourceFieldValue($resource, $field)
    {
        $f = $field->getField();

        // TODO: If error check how to retrieve data from the collection returne by the query on the model
        // Converted stdClass object to an array
        $resource = json_decode(json_encode($resource), true);
        
        $value = $resource[$f];


        // TODO: Check if the $value is a instance of \Carbon\Carbon and what did we put in the field type (Date or Carbon\Carbon)
        if (
            (is_a($value, '\Datetime') && $field->getType() == 'Date') ||
            (is_a($value, '\Carbon\Carbon') && $field->getType() == 'Date')
        ) {
            return $value->format('c');
        }

        if(is_array($value)) {
            $value = json_encode($value);
        }

        if ($field->getType() == 'Boolean')
        {
            return $value ? true : false;
        }

        // Default return where we do not alter the data
        return $value;
    }

    protected function findCollection($tableReference)
    {
        foreach ($this->getCollections() as $collection) {
            if ($collection->getName() == $tableReference)
            {
                return $collection;
            }
        }

        throw new CollectionNotFoundException($tableReference);
    }

    public function filterQueryBuilder(ResourceFilter $filter, $collection)
    {
        // TODO: Maybe find a better way to get the table name
        $tableName = App::make($collection->getEntityClassName())->getTable();

        $queryBuilder = DB::table($tableName);

        if ($filter->hasSearch())
        {

            $queryBuilder->orWhere(function ($query) use($filter, $collection) {
                foreach ($collection->getFields() as $field)
                {
                    if ($field->getField() == $collection->getIdentifier() || $field->getType() == 'String')
                    {
                        $query->orWhere($field->getField(), '=', $filter->getSearch());
                    }
                }
            });

        }

        if ($filter->hasFilters())
        {
            foreach ($filter->getFilters() as $newfilter)
            {
                $fieldName = $newfilter->getFieldName();
                $filterValue = $newfilter->getFilterString();

                if ($newfilter->isDifferent()) {
                    $queryBuilder->where($fieldName, '!=', $filterValue);
                } elseif ($newfilter->isGreaterThan()) {
                    $queryBuilder->where($fieldName, '<', $filterValue);
                } elseif ($newfilter->isLowerThan()) {
                    $queryBuilder->where($fieldName, '>', $filterValue);
                } elseif ($newfilter->isContains() || $newfilter->isStartsBy() || $newfilter->isEndsBy()) {
                    if ($newfilter->isContains() || $newfilter->isStartsBy()) {
                        $filterValue = $filterValue . '%';
                    }
                    if ($newfilter->isContains() || $newfilter->isEndsBy()) {
                        $filterValue = '%'.$filterValue;
                    }
                    $queryBuilder->where($fieldName, 'like', $filterValue);
                } elseif ($newfilter->isPresent()) {
                    $queryBuilder->whereNotNull($fieldName);
                } elseif ($newfilter->isBlank()) {
                    $queryBuilder->where(function ($query) use($fieldName) {
                        $query->whereNull($fieldName);
                        $query->orWhere($fieldName, '=', '');
                    });
                } else {
                    $queryBuilder->where($fieldName, '=', $filterValue);
                }
            }
        }


        if ($filter->hasSortBy())
        {
            $queryBuilder->orderBy($filter->getSortBy(), $filter->getSortOrder());
        }

        return $queryBuilder;
    }

    public function paginateQueryBuilder($queryBuilder, $filter)
    {
        if ($filter->hasPageSize())
        {
            $queryBuilder->take($filter->getPageSize());

            if ($filter->hasPageNumber()) {
                $offset = $filter->getPageSize() * ($filter->getPageNumber() - 1);
                $queryBuilder->skip($offset);
            }
        }
    }

    protected function loadResourcesFromQueryBuilder($resourceQueryBuilder, $collection)
    {
        // TODO: check if the foreign key are retrieved by the query
        // setHint(\Doctrine\ORM\Query::HINT_INCLUDE_META_COLUMNS, true)
//        $model = App::make($collection->getEntityClassName());
//        dd($collection->getRelationships());


//        foreach ($collection->getRelationships() as $index => $field) {
//            $currentTable = $model->getTable();
//            $foreignKeyName = $field->getPivot()->getSourceIdentifier();
//            $reference = $field->getReference();
//            $resourceQueryBuilder->join($index, $currentTable.'.'.$foreignKeyName, '=', $reference);
//        }


        $resources = $resourceQueryBuilder->get();
//        dd($resources);
        $returnedResources = [];

        if ($resources) {
            foreach ($resources as $resource) {
                $resource = json_decode(json_encode($resource), true);
                
                $returnedResource = new ForestResource(
                    $collection,
                    $this->formatResource($resource, $collection)
                );

//                dd($returnedResource);

                $resourceId = $returnedResource->getId();

                $relationships = $collection->getRelationships();

                if (count($relationships))
                {
                    foreach ($relationships as $tableReference => $field) {
                        $relatedCollection = $this->findCollection($tableReference);

                        $relationship = new ForestRelationship;
                        $relationship->setType($relatedCollection->getName());
                        $relationship->setEntityClassName($relatedCollection->getEntityClassName());
                        $relationship->setFieldName($field->getField());
                        $relationship->setIdentifier($relatedCollection->getIdentifier());

                        if ($field->isTypeToOne())
                        {
                            $relationship->setId($resource[$field->getForeignKey()]);
                        }
                    }

                    foreach ($returnedResource->getRelationships() as $relationship) {
                        if ($relationship->getId())
                        {
                            $relatedCollection = $this->findCollection($relationship->getType());
                            list($resourceToInclude, $resultSet) = $this->loadResource(
                                $relationship->getId(),
                                $relatedCollection
                            );

                            if ($resourceToInclude)
                            {
                                $resourceToInclude->setType($relationship->getType());
                                $returnedResource->includeResource($resourceToInclude);
                            }
                        }
                    }
                }

                $returnedResources[] = $returnedResource;
            }
        }
        return $returnedResources;
    }

    protected function hasIdentifier()
    {
        return count($this->getThisCollection()->getIdentifier()) ? true : false;
    }

    protected function getAttributesAndRelations($postData, $attributes = null)
    {
        if (!is_array($attributes)) {
            $attributes = $postData['data']['attributes'];
        }

        if (!empty($postData['data']['relationships'])) {
            $relationships = $postData['data']['relationships'];
            foreach ($relationships as $relationship) {
                if (!empty($relationship['data'])) {
                    $data = $relationship['data'];
                    // for some reason, underscores were replace by dashes on Forest side
                    $data['type'] = str_replace('-', '_', $data['type']);
                    $relation = $this->getThisCollection()->getRelationship($data['type'])->getField();
                    $attributes[$relation] = $data['id'];
                }
            }
        }

        return $attributes;
    }

    protected function findRelatedCollection($field)
    {
        $relationName = $field->getReferencedTable();

        if ($relationName) {
            foreach (DatabaseStructure::getCollections() as $collection) {
                if ($collection->getName() == $relationName) {
                    return $collection;
                }
            }

            throw new CollectionNotFoundException($relationName);
        }

        return false;
    }
}