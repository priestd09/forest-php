<?php

namespace ForestAdmin\Liana\Schema;

use ForestAdmin\Liana\Model\Resource as ForestResource;
use Neomerx\JsonApi\Schema\SchemaProvider;

/**
 * Class ResourceSchema
 * @package ForestAdmin\Liana\Schema
 */
class ResourceSchema extends SchemaProvider
{
    protected $resourceType = 'plok';

    /**
     * @param ForestResource $forestResource
     * @return mixed
     */
    public function getId($forestResource)
    {
        return $forestResource->getId();
    }

    /**
     * @param ForestResource $forestResource
     * @return array
     */
    public function getAttributes($forestResource)
    {
        return $forestResource->getAttributes();
    }
}