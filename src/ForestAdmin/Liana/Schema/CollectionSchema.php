<?php
/**
 * Created by PhpStorm.
 * User: jean-marc
 * Date: 20/06/16
 * Time: 16:40
 */

namespace ForestAdmin\Liana\Schema;


use ForestAdmin\Liana\Raw\Collection;
use Neomerx\JsonApi\Schema\SchemaProvider;

class CollectionSchema extends SchemaProvider
{
    protected $resourceType = 'collections';

    /**
     * @param Collection $collection
     * @return mixed
     */
    public function getId($collection)
    {
        return $collection->name;
    }

    /**
     * @param Collection $collection
     * @return array
     */
    public function getAttributes($collection)
    {
        $ret = array();
        
        $ret['name'] = $collection->name;
        $ret['fields'] = $collection->fields;
        
        if($collection->actions) {
            $ret['actions'] = $collection->actions;
        }
        
        $ret['only-for-relationships'] = null;
        $ret['is-virtual'] = null;
        $ret['is-read-only'] = false;
        $ret['is-searchable'] = true;
    
        return $ret;
    }
}

/*
    public function testApimap()
    {
        $collections = $this->map;
        $encoder = \Neomerx\JsonApi\Encoder\Encoder::instance(array(
            \ForestAdmin\Liana\Raw\Collection::class => \ForestAdmin\Liana\Schema\CollectionSchema::class,
        ), new \Neomerx\JsonApi\Encoder\EncoderOptions(JSON_PRETTY_PRINT, 'http://forestadmin.com/api/v1'));

        echo $encoder->encodeData($collections) . PHP_EOL;
        exit;
    }


 */