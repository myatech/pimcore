<?php
/**
 * Pimcore
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.pimcore.org/license
 *
 * @category   Pimcore
 * @package    Object
 * @copyright  Copyright (c) 2009-2013 pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     New BSD License
 */

class Object_Service extends Element_Service {

    /**
     * @var array
     */
    protected $_copyRecursiveIds;

    /**
     * @var User
     */
    protected $_user;

    /**
     * @param  User $user
     * @return void
     */
    public function __construct($user = null) {
        $this->_user = $user;
    }


    /**
     * finds all objects which hold a reference to a specific user
     *
     * @static
     * @param  integer $userId
     * @return Object_Concrete[]
     */
    public static function getObjectsReferencingUser($userId) {
        $userObjects = array();
        $classesList = new Object_Class_List();
        $classesList->setOrderKey("name");
        $classesList->setOrder("asc");
        $classes = $classesList->load();

        $classesToCheck = array();
        if (is_array($classes)) {
            foreach ($classes as $class) {
                $fieldDefinitions = $class->getFieldDefinitions();
                $dataKeys = array();
                if (is_array($fieldDefinitions)) {
                    foreach ($fieldDefinitions as $tag) {
                        if ($tag instanceof Object_Class_Data_User) {
                            $dataKeys[] = $tag->getName();
                        }
                    }
                }
                if (is_array($dataKeys) and count($dataKeys) > 0) {
                    $classesToCheck[$class->getName()] = $dataKeys;
                }
            }
        }

        foreach ($classesToCheck as $classname => $fields) {
            $listName = "Object_" . ucfirst($classname) . "_List";
            $list = new $listName();
            $conditionParts = array();
            foreach ($fields as $field) {
                $conditionParts[] = $field . "='" . $userId . "'";
            }
            $list->setCondition(implode(" AND ", $conditionParts));
            $objects = $list->load();
            $userObjects = array_merge($userObjects, $objects);
        }
        return $userObjects;
    }

    /**
     * @param  Object_Abstract $target
     * @param  Object_Abstract $source
     * @return
     */
    public function copyRecursive($target, $source) {

        // avoid recursion
        if (!$this->_copyRecursiveIds) {
            $this->_copyRecursiveIds = array();
        }
        if (in_array($source->getId(), $this->_copyRecursiveIds)) {
            return;
        }

        $source->getProperties();
        //load all in case of lazy loading fields
        self::loadAllObjectFields($source);

        $new = clone $source;
        $new->o_id = null;
        $new->setChilds(null);
        $new->setKey(Element_Service::getSaveCopyName("object", $new->getKey(), $target));
        $new->setParentId($target->getId());
        $new->setUserOwner($this->_user->getId());
        $new->setUserModification($this->_user->getId());
        $new->setResource(null);
        $new->setLocked(false);
        $new->setCreationDate(time());
        $new->save();

        // add to store
        $this->_copyRecursiveIds[] = $new->getId();

        foreach ($source->getChilds() as $child) {
            $this->copyRecursive($new, $child);
        }

        $this->updateChilds($target, $new);

        return $new;
    }


    /**
     * @param  Object_Abstract $target
     * @param  Object_Abstract $source
     * @return Object_Abstract copied object
     */
    public function copyAsChild($target, $source) {

        //load properties
        $source->getProperties();

        //load all in case of lazy loading fields
        self::loadAllObjectFields($source);

        $new = clone $source;
        $new->o_id = null;

        $new->setChilds(null);
        $new->setKey(Element_Service::getSaveCopyName("object", $new->getKey(), $target));
        $new->setParentId($target->getId());
        $new->setUserOwner($this->_user->getId());
        $new->setUserModification($this->_user->getId());
        $new->setResource(null);
        $new->setLocked(false);
        $new->setCreationDate(time());
        $new->save();

        $this->updateChilds($target, $new);

        return $new;
    }

    /**
     * @param  Object_Abstract $target
     * @param  Object_Abstract $source
     * @return return $target
     */
    public function copyContents($target, $source) {

        // check if the type is the same
        if (get_class($source) != get_class($target)) {
            throw new Exception("Source and target have to be the same type");
        }

        //load all in case of lazy loading fields
        self::loadAllObjectFields($source);

        $new = clone $source;
        $new->setChilds($target->getChilds());
        $new->setId($target->getId());
        $new->setPath($target->getPath());
        $new->setKey($target->getKey());
        $new->setParentId($target->getParentId());
        $new->setScheduledTasks($source->getScheduledTasks());
        $new->setProperties($source->getProperties());
        $new->setUserModification($this->_user->getId());

        $new->save();

        $target = Object_Abstract::getById($new->getId());
        return $target;
    }


    /**
     * @param  Object_Abstract $object
     * @return array
     */
    public static function gridObjectData($object, $fields = null) {

        $localizedPermissionsResolved = false;
        $data = Element_Service::gridElementData($object);

        if ($object instanceof Object_Concrete) {
            $data["classname"] = $object->getClassName();
            $data["idPath"] = Element_Service::getIdPath($object);
            $data['inheritedFields'] = array();

            $user = Pimcore_Tool_Admin::getCurrentUser();

//TODO keep this for later!
//            if (!$user->isAdmin()) {
//                $permissionSet = $object->getPermissions(null, $user);
//                $fieldPermissions = self::getFieldPermissions($object, $permissionSet);
//            }

            if(empty($fields)) {
                $fields = array_keys($object->getclass()->getFieldDefinitions());
            }
            foreach($fields as $key) {
                $brickType = null;
                $brickGetter = null;
                $dataKey = $key;
                $keyParts = explode("~", $key);

                $def = $object->getClass()->getFieldDefinition($key);

                if (substr($key, 0, 1) == "~") {
                    $type = $keyParts[1];
                    if ($type == "keyvalue") {
                        $field = $keyParts[2];
                        $keyid = $keyParts[3];

                        $getter = "get" . ucfirst($field);
                        if(method_exists($object,$getter)) {
                            $keyValuePairs = $object->$getter();
                            if ($keyValuePairs) {
                                // get with inheritance
                                $props = $keyValuePairs->getProperties();

                                foreach ($props as $pair) {
                                    if ($pair["key"] == $keyid) {

                                        if (isset($pair["translated"])) {
                                            if (isset($data['#kv-tr'][$dataKey])) {
                                                if (!is_array($data['#kv-tr'][$dataKey])) {
                                                    $arr = array($data['#kv-tr'][$dataKey]);
                                                    $data['#kv-tr'][$dataKey] = $arr;
                                                }
                                                $data['#kv-tr'][$dataKey][] = $pair["translated"];
                                            }
                                            else {
                                                $data['#kv-tr'][$dataKey] = $pair["translated"];
                                            }
                                        }

                                        if (isset($data[$dataKey])) {
                                            if (!is_array($data[$dataKey])) {
                                                $arr = array($data[$dataKey]);
                                                $data[$dataKey] = $arr;
                                            }
                                            $data[$dataKey][] = $pair["value"];
                                        } else {
                                            $data[$dataKey] = $pair["value"];
                                        }

                                        if ($pair["inherited"]) {
                                            $data['inheritedFields'][$dataKey] = array("inherited" => $pair["inherited"], "objectid" => $pair["source"]);
                                        }


//                                   break;
                                    }
                                }
                            }
                        }

                    }
                } else if(count($keyParts) > 1) {
                    // brick
                    $brickType = $keyParts[0];
                    $brickKey = $keyParts[1];
                    $key = self::getFieldForBrickType($object->getclass(), $brickType);

                    $brickClass = Object_Objectbrick_Definition::getByKey($brickType);
                    $def = $brickClass->getFieldDefinition($brickKey);
                }

                if(!empty($key)) {

                    // some of the not editable field require a special response

                    $getter = "get".ucfirst($key);
                    $brickGetter = null;
                    if(!empty($brickKey)) {
                        $brickGetter = "get".ucfirst($brickKey);
                    }

                    $needLocalizedPermissions = false;

                    // if the definition is not set try to get the definition from localized fields
                    if(!$def) {
                        if($locFields = $object->getClass()->getFieldDefinition("localizedfields")) {
                            $def = $locFields->getFieldDefinition($key);
                            if ($def) {
                                $needLocalizedPermissions = true;
                            }
                        }
                    }

                    //relation type fields with remote owner do not have a getter
                    if(method_exists($object,$getter)) {

                        //system columns must not be inherited
                        if(in_array($key, Object_Concrete::$systemColumnNames)) {
                            $data[$dataKey] = $object->$getter();
                        } else {

                            $valueObject = self::getValueForObject($object, $getter, $brickType, $brickGetter);
                            $data['inheritedFields'][$dataKey] = array("inherited" => $valueObject->objectid != $object->getId(), "objectid" => $valueObject->objectid);

                            if(method_exists($def, "getDataForGrid")) {
                                $tempData = $def->getDataForGrid($valueObject->value, $object);

                                if($def instanceof Object_Class_Data_Localizedfields) {
                                    $needLocalizedPermissions = true;
                                    foreach($tempData as $tempKey => $tempValue) {
                                        $data[$tempKey] = $tempValue;
                                    }
                                } else {
                                    $data[$dataKey] = $tempData;
                                }
                            } else {
                                $data[$dataKey] = $valueObject->value;
                            }
                        }
                    }

                    if ($needLocalizedPermissions) {
                        if (!$user->isAdmin()) {
                            /** @var  $locale Zend_Locale */
                            $locale = (string) Zend_Registry::get("Zend_Locale");

                            $permissionTypes = array("View", "Edit");
                            foreach ($permissionTypes as $permissionType) {
                                //TODO, this needs refactoring! Ideally, call it only once!
                                $languagesAllowed = self::getLanguagePermissions($object, $user, "l" . $permissionType);

                                if ($languagesAllowed) {
                                    $languagesAllowed = array_keys($languagesAllowed);

                                    if (!in_array($locale, $languagesAllowed)) {
                                        $data["metadata"]["permission"][$key]["no" . $permissionType] = 1;
                                        if ($permissionType == "View") {
                                            $data[$key] = null;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

            }
        }
        return $data;
    }

    public static function getLanguagePermissions($object, $user, $type) {
        $languageAllowed = null;

        $permission = $object->getPermissions($type, $user);

        if (!is_null($permission)) {
            // backwards compatibility. If all entries are null, then the workspace rule was set up with
            // an older pimcore

            $permission = $permission[$type];
            if ($permission) {
                $permission = explode(",", $permission);
                if (is_null($languageAllowed)) {
                    $languageAllowed = array();
                }

                foreach($permission as $language) {
                    $languageAllowed[$language] = 1;
                }
            }
        }

        return $languageAllowed;
    }


    public static function getLayoutPermissions($classId, $permissionSet) {
        $layoutPermissions = null;


        if (!is_null($permissionSet)) {
            // backwards compatibility. If all entries are null, then the workspace rule was set up with
            // an older pimcore

            $permission = $permissionSet["layouts"];
            if ($permission) {
                $permission = explode(",", $permission);
                if (is_null($layoutPermissions)) {
                    $layoutPermissions = array();
                }

                foreach($permission as $p) {
                    $setting = explode("_", $p);
                    $c = $setting[0];

                    if ($c == $classId) {
                        $l = $setting[1];

                        if (is_null($layoutPermissions)) {
                            $layoutPermissions = array();
                        }
                        $layoutPermissions[$l] = $l;
                    }
                }
            }
        }

        return $layoutPermissions;
    }



    public static function getFieldForBrickType(Object_Class $class, $bricktype) {
        $fieldDefinitions = $class->getFieldDefinitions();
        foreach($fieldDefinitions as $key => $fd) {
            if($fd instanceof Object_Class_Data_Objectbricks) {
                if(in_array($bricktype, $fd->getAllowedTypes())) {
                    return $key;
                }
            }
        }
        return null;
    }

    /**
     * gets value for given object and getter, including inherited values
     *
     * @static
     * @return stdclass, value and objectid where the value comes from
     */
    private static function getValueForObject($object, $getter, $brickType = null, $brickGetter = null) {
        $value = $object->$getter();
        if(!empty($value) && !empty($brickType)) {
            $getBrickType = "get" . ucfirst($brickType);
            $value = $value->$getBrickType();
            if(!empty($value) && !empty($brickGetter)) {
                $value = $value->$brickGetter();
            }
        }


        if(empty($value) || (is_object($value) && method_exists($value, "isEmpty") && $value->isEmpty())) {
            $parent = self::hasInheritableParentObject($object);
            if(!empty($parent)) {
                return self::getValueForObject($parent, $getter, $brickType, $brickGetter);
            }
        }

        $result = new stdClass();
        $result->value = $value;
        $result->objectid = $object->getId();
        return $result;
    }

    public static function hasInheritableParentObject(Object_Concrete $object) {
        if($object->getClass()->getAllowInherit()) {
            if ($object->getParent() instanceof Object_Abstract) {
                $parent = $object->getParent();
                while($parent && $parent->getType() == "folder") {
                    $parent = $parent->getParent();
                }

                if ($parent && ($parent->getType() == "object" || $parent->getType() == "variant")) {
                    if ($parent->getClassId() == $object->getClassId()) {
                        return $parent;
                    }
                }
            }
        }
    }

    /**
     * call the getters of each object field, in case some of the are lazy loading and we need the data to be loaded
     *
     * @static
     * @param  Object_Concrete $object
     * @return void
     */
    public static function loadAllObjectFields($object) {

        $object->getProperties();

        if ($object instanceof Object_Concrete) {
            //load all in case of lazy loading fields
            $fd = $object->getClass()->getFieldDefinitions();
            foreach ($fd as $def) {
                $getter = "get" . ucfirst($def->getName());
                if (method_exists($object, $getter)) {
                    $object->$getter();
                }
            }
        }
    }

    /**
     *
     * @param string $filterJson
     * @param Object_Class $class
     * @return string
     */
    public static function getFilterCondition($filterJson, $class) {

        $systemFields = array("o_path", "o_key", "o_id", "o_published","o_creationDate","o_modificationDate");

        // create filter condition
        $conditionPartsFilters = array();

        if ($filterJson) {
            $filters = Zend_Json::decode($filterJson);
            foreach ($filters as $filter) {

                $operator = "=";

                if($filter["type"] == "string") {
                    $operator = "LIKE";
                } else if ($filter["type"] == "numeric") {
                    if($filter["comparison"] == "lt") {
                        $operator = "<";
                    } else if($filter["comparison"] == "gt") {
                        $operator = ">";
                    } else if($filter["comparison"] == "eq") {
                        $operator = "=";
                    }
                } else if ($filter["type"] == "date") {
                    if($filter["comparison"] == "lt") {
                        $operator = "<";
                    } else if($filter["comparison"] == "gt") {
                        $operator = ">";
                    } else if($filter["comparison"] == "eq") {
                        $operator = "=";
                    }
                    $filter["value"] = strtotime($filter["value"]);
                } else if ($filter["type"] == "list") {
                    $operator = "=";
                } else if ($filter["type"] == "boolean") {
                    $operator = "=";
                    $filter["value"] = (int) $filter["value"];
                }

                $field = $class->getFieldDefinition($filter["field"]);
                $brickField = null;
                $brickType = null;
                if(!$field) {

                    // if the definition doesn't exist check for a localized field
                    $localized = $class->getFieldDefinition("localizedfields");
                    if($localized instanceof Object_Class_Data_Localizedfields) {
                        $field = $localized->getFieldDefinition($filter["field"]);
                    }


                    //if the definition doesn't exist check for object brick
                    $keyParts = explode("~", $filter["field"]);

                    if (substr($filter["field"], 0, 1) == "~") {
                        // not needed for now
//                            $type = $keyParts[1];
//                            $field = $keyParts[2];
//                            $keyid = $keyParts[3];
                    } else if(count($keyParts) > 1) {
                        $brickType = $keyParts[0];
                        $brickKey = $keyParts[1];

                        $key = self::getFieldForBrickType($class, $brickType);
                        $field = $class->getFieldDefinition($key);

                        $brickClass = Object_Objectbrick_Definition::getByKey($brickType);
                        $brickField = $brickClass->getFieldDefinition($brickKey);

                    }
                }
                if($field instanceof Object_Class_Data_Objectbricks) {
                    // custom field
                    $db = Pimcore_Resource::get();
                    if(is_array($filter["value"])) {
                        $fieldConditions = array();
                        foreach ($filter["value"] as $filterValue) {
                            $fieldConditions[] = $db->getQuoteIdentifierSymbol() . $brickType . $db->getQuoteIdentifierSymbol() . "." . $brickField->getFilterCondition($filterValue, $operator);
                        }
                        $conditionPartsFilters[] = "(" . implode(" OR ", $fieldConditions) . ")";
                    } else {
                        $conditionPartsFilters[] = $db->getQuoteIdentifierSymbol() . $brickType . $db->getQuoteIdentifierSymbol() . "." . $brickField->getFilterCondition($filter["value"], $operator);
                    }
                } else if($field instanceof Object_Class_Data) {
                    // custom field
                    if(is_array($filter["value"])) {
                        $fieldConditions = array();
                        foreach ($filter["value"] as $filterValue) {
                            $fieldConditions[] = $field->getFilterCondition($filterValue, $operator);
                        }
                        $conditionPartsFilters[] = "(" . implode(" OR ", $fieldConditions) . ")";
                    } else {
                        $conditionPartsFilters[] = $field->getFilterCondition($filter["value"], $operator);
                    }

                } else if (in_array("o_".$filter["field"], $systemFields)) {
                    // system field
                    $conditionPartsFilters[] = "`o_" . $filter["field"] . "` " . $operator . " '" . $filter["value"] . "' ";
                }
            }
        }

        $conditionFilters = "1 = 1";
        if (count($conditionPartsFilters) > 0) {
            $conditionFilters = "(" . implode(" AND ", $conditionPartsFilters) . ")";
        }
        Logger::log("ObjectController filter condition:" . $conditionFilters);
        return $conditionFilters;
    }

    /**
     * @static
     * @param $object
     * @param $fieldname
     * @return array
     */
    public static function getOptionsForSelectField($object, $fieldname) {
        $class = null;
        $options = array();

        if(is_object($object) && method_exists($object, "getClass")) {
            $class = $object->getClass();
        } else if(is_string($object)) {
            $object = new $object();
            $class = $object->getClass();
        }

        if($class) {
            /**
             * @var Object_Class_Data_Select $definition
             */
            $definition = $class->getFielddefinition($fieldname);
            if($definition instanceof Object_Class_Data_Select) {
                $_options = $definition->getOptions();

                foreach($_options as $option) {
                    $options[$option["value"]] = $option["key"];
                }
            }
        }

        return $options;
    }

    /**
     * @static
     * @param $path
     * @return bool
     */
    public static function pathExists ($path, $type = null) {

        $path = Element_Service::correctPath($path);

        try {
            $object = new Object_Abstract();

            if (Pimcore_Tool::isValidPath($path)) {
                $object->getResource()->getByPath($path);
                return true;
            }
        }
        catch (Exception $e) {

        }

        return false;
    }


    /**
     * Rewrites id from source to target, $rewriteConfig contains
     * array(
     *  "document" => array(
     *      SOURCE_ID => TARGET_ID,
     *      SOURCE_ID => TARGET_ID
     *  ),
     *  "object" => array(...),
     *  "asset" => array(...)
     * )
     * @param $object
     * @param $rewriteConfig
     * @return Object_Abstract
     */
    public static function rewriteIds($object, $rewriteConfig) {
        // rewriting elements only for snippets and pages
        if($object instanceof Object_Concrete) {
            $fields = $object->getClass()->getFieldDefinitions();

            foreach($fields as $field) {
                if(method_exists($field, "rewriteIds")) {
                    $setter = "set" . ucfirst($field->getName());
                    if(method_exists($object, $setter)) { // check for non-owner-objects
                        $object->$setter($field->rewriteIds($object, $rewriteConfig));
                    }
                }
            }
        }

        // rewriting properties
        $properties = $object->getProperties();
        foreach ($properties as &$property) {
            $property->rewriteIds($rewriteConfig);
        }
        $object->setProperties($properties);

        return $object;
    }

    public static function getValidLayouts(Object_Concrete $object) {
        $user = Pimcore_Tool_Admin::getCurrentUser();

        $resultList = array();
        $isMasterAllowed = $user->getAdmin();

        $permissionSet = $object->getPermissions("layouts", $user);
        $layoutPermissions = self::getLayoutPermissions($object->getClassId(), $permissionSet);
        if (!$layoutPermissions || isset($layoutPermissions[0])) {
            $isMasterAllowed = true;
        }

        if ($user->getAdmin()) {
            $superLayout = new Object_Class_CustomLayout();
            $superLayout->setId(-1);
            $superLayout->setName("Master (Admin Mode)");
            $resultList[-1] = $superLayout;
        }

        if ($isMasterAllowed) {
            $master = new Object_Class_CustomLayout();
            $master->setId(0);
            $master->setName("Master");
            $resultList[0] = $master;
        }

        $classId = $object->getClassId();
        $list = new Object_Class_CustomLayout_List();
        $list->setOrderKey("name");
        $condition = "classId = " . $list->quote($classId);
        if (count($layoutPermissions) && !$isMasterAllowed) {
            $layoutIds = array_values($layoutPermissions);
            $condition .= " AND id IN (" . implode(",", $layoutIds) . ")";
        }
        $list->setCondition($condition);
        $list = $list->load();

        if ((!count($resultList) && !count($list)) || (count($resultList) == 1 && !count($list)))  {
            return array();
        }

        foreach ($list as $customLayout) {
            $resultList[$customLayout->getId()] = $customLayout;
        }

        return $resultList;
    }



    public static function extractLocalizedFieldDefinitions($layout, $targetList, $insideLocalizedField) {
        if ($insideLocalizedField && $layout instanceof Object_Class_Data and !$layout instanceof Object_Class_Data_Localizedfields) {
            $targetList[$layout->getName()] = $layout;
        }

        if (method_exists($layout, "getChilds")) {
            $children = $layout->getChilds();
            $insideLocalizedField |= ($layout instanceof Object_Class_Data_Localizedfields);
            if (is_array($children)) {
                foreach ($children as $child) {
                    $targetList = self::extractLocalizedFieldDefinitions($child, $targetList, $insideLocalizedField);
                }
            }
        }
        return $targetList;
    }


    /** Calculates the super layout definition for the given object.
     * @param Object_Concrete $object
     * @return mixed
     */
    public static function getSuperLayoutDefinition(Object_Concrete $object) {
        $masterLayout = $object->getClass()->getLayoutDefinitions() ;
        $superLayout = unserialize(serialize($masterLayout));

        self::createSuperLayout($superLayout);
        return $superLayout;

    }


    private static function createSuperLayout(&$layout) {
        if ($layout instanceof Object_Class_Data) {
            $layout->setInvisible(false);
            $layout->setNoteditable(false);
        }

        if (method_exists($layout, "getChilds")) {
            $children = $layout->getChilds();
            if (is_array($children)) {
                foreach ($children as $child) {
                    self::createSuperLayout($child);
                }
            }
        }
    }


    private static function synchronizeCustomLayoutFieldWithMaster($masterDefinition, &$layout) {

        if ($layout instanceof Object_Class_Data) {
            $fieldname = $layout->name;
            if (!$masterDefinition[$fieldname]) {
                return false;
            } else {
                if ($layout->getFieldtype() != $masterDefinition[$fieldname]->getFieldType()) {
                    $layout->adoptMasterDefinition($masterDefinition[$fieldname]);
                } else {
                    $layout->synchronizeWithMasterDefinition($masterDefinition[$fieldname]);
                }
            }
        }

        if (method_exists($layout, "getChilds")) {
            $children = $layout->getChilds();
            if (is_array($children)) {
                $count = count($children);
                for ($i = $count  -1; $i >= 0; $i--) {
                    $child = $children[$i];
                    if (!self::synchronizeCustomLayoutFieldWithMaster($masterDefinition, $child)) {
                        unset($children[$i]);
                    }
                    $layout->setChilds($children);
                }
            }
        }
        return true;
    }

    /** Synchronizes a custom layout with its master layout
     * @param Object_Class_CustomLayout $customLayout
     */
    public static function synchronizeCustomLayout(Object_Class_CustomLayout $customLayout) {
        $classId = $customLayout->getClassId();
        $class = Object_Class::getById($classId);
        if ($class->getModificationDate() > $customLayout->getModificationDate()) {
            $masterDefinition = $class->getFieldDefinitions();
            $customLayoutDefinition = $customLayout->getLayoutDefinitions();
            $targetList = self::extractLocalizedFieldDefinitions($class->getLayoutDefinitions(), array(), false);
            $masterDefinition = array_merge($masterDefinition, $targetList);

            self::synchronizeCustomLayoutFieldWithMaster($masterDefinition, $customLayoutDefinition);
            $customLayout->save();
        }
    }

    public static function getCustomGridFieldDefinitions($classId, $objectId) {
        $object = Object_Abstract::getById($objectId);

        $class = Object_Class::getById($classId);
        $masterFieldDefinition = $class->getFieldDefinitions();

        if (!$object) {
            return null;
        }

        $user = Pimcore_Tool_Admin::getCurrentUser();
        if ($user->isAdmin()) {
            return null;
        }

        $permissionList = array();

        $parentPermissionSet = $object->getPermissions(null, $user, true);
        if ($parentPermissionSet) {
            $permissionList[] = $parentPermissionSet;
        }

        $childPermissions = $object->getChildPermissions(null, $user);
        $permissionList = array_merge($permissionList, $childPermissions);


        $layoutDefinitions = array();

        foreach ($permissionList as $permissionSet) {
            $allowedLayoutIds = self::getLayoutPermissions($classId, $permissionSet);
            if (is_array($allowedLayoutIds)) {
                foreach ($allowedLayoutIds as $allowedLayoutId) {
                    if ($allowedLayoutId) {
                        if (!$layoutDefinitions[$allowedLayoutId]) {
                            $customLayout = Object_Class_CustomLayout::getById($allowedLayoutId);
                            if (!$customLayout) {
                                continue;
                            }
                            $layoutDefinitions[$allowedLayoutId] = $customLayout;
                        }
                    }
                }
            }
        }

        $mergedFieldDefinition = unserialize(serialize($masterFieldDefinition));

        if (count($layoutDefinitions)) {
            foreach ($mergedFieldDefinition as $key => $def) {
                if ($def instanceof Object_Class_Data_Localizedfields) {
                    $mergedLocalizedFieldDefinitions = $mergedFieldDefinition[$key]->getFieldDefinitions();

                    foreach ($mergedLocalizedFieldDefinitions as $locKey => $locValue) {
                        $mergedLocalizedFieldDefinitions[$locKey]->setInvisible(false);
                        $mergedLocalizedFieldDefinitions[$locKey]->setNotEditable(false);
                    }
                    $mergedFieldDefinition[$key]->setChilds($mergedLocalizedFieldDefinitions);


                } else {
                    $mergedFieldDefinition[$key]->setInvisible(false);
                    $mergedFieldDefinition[$key]->setNotEditable(false);
                }
            }
        }

        foreach ($layoutDefinitions as $customLayoutDefinition) {
            $layoutName = $customLayoutDefinition->getName();

            $layoutDefinitions = $customLayoutDefinition->getLayoutDefinitions();
            $dummyClass = new Object_Class();
            $dummyClass->setLayoutDefinitions($layoutDefinitions);
            $customFieldDefinitions = $dummyClass->getFieldDefinitions();

            foreach ($mergedFieldDefinition as $key => $value) {
                if (!$customFieldDefinitions[$key]) {
                    unset($mergedFieldDefinition[$key]);
                }
            }

            foreach ($customFieldDefinitions as $key => $def) {
                if ($def instanceof Object_Class_Data_Localizedfields) {
                    if (!$mergedFieldDefinition[$key]) {
                        continue;
                    }
                    $customLocalizedFieldDefinitions = $def->getFieldDefinitions();
                    $mergedLocalizedFieldDefinitions = $mergedFieldDefinition[$key]->getFieldDefinitions();

                    foreach ($mergedLocalizedFieldDefinitions as $locKey => $locValue) {
                        self::mergeFieldDefinition($mergedLocalizedFieldDefinitions, $customLocalizedFieldDefinitions, $locKey);
                    }
                    $mergedFieldDefinition[$key]->setChilds($mergedLocalizedFieldDefinitions);

                } else {
                    self::mergeFieldDefinition($mergedFieldDefinition, $customFieldDefinitions, $key);
                }
            }
        }

        return $mergedFieldDefinition;
    }

    private static function mergeFieldDefinition(&$mergedFieldDefinition, &$customFieldDefinitions, $key) {
        if (!$customFieldDefinitions[$key]) {
            unset($mergedFieldDefinition[$key]);
        } else if (isset($mergedFieldDefinition[$key])) {
            $def = $customFieldDefinitions[$key];
            if ($def->getNotEditable()) {
                $mergedFieldDefinition[$key]->setNotEditable(true);
            }
            if ($def->getInvisible()) {
                if ($mergedFieldDefinition[$key] instanceof Object_Class_Data_Objectbricks) {
                    unset($mergedFieldDefinition[$key]);
                    return;
                } else {
                    $mergedFieldDefinition[$key]->setInvisible(true);
                }
            }

            if ($def->title) {
                $mergedFieldDefinition[$key]->setTitle($def->title);
            }
        }
    }

    private static function doFilterCustomGridFieldDefinitions(&$layout, $fieldDefinitions) {
        if ($layout instanceof Object_Class_Data) {
            $name = $layout->getName();
            if (!$fieldDefinitions[$name] || $fieldDefinitions[$name]->getInvisible()) {
                return false;
            } else {
                $layout->setNoteditable($layout->getNoteditable() | $fieldDefinitions[$name]->getNoteditable());
            }
        }

        if (method_exists($layout, "getChilds")) {

            $children = $layout->getChilds();
            if (is_array($children)) {
                $count = count($children);
                for ($i = $count  -1; $i >= 0; $i--) {
                    $child = $children[$i];
                    if (!self::doFilterCustomGridFieldDefinitions($child, $fieldDefinitions)) {
                        unset($children[$i]);
                    }

                }
                $layout->setChilds(array_values($children));
            }
        }
        return true;
    }


    /**  Determines the custom layout definition (if necessary) for the given class
     * @param Object_Class $class
     * @param int $objectId
     * @return array layout
     */
    public static function getCustomLayoutDefinitionForGridColumnConfig(Object_Class $class, $objectId) {

        $layoutDefinitions = $class->getLayoutDefinitions();

        $result = array(
            "layoutDefinition" => $layoutDefinitions
        );

        if (!$objectId) {
            return $result;
        }

        $user = Pimcore_Tool_Admin::getCurrentUser();

        if ($user->isAdmin()) {
            return $result;
        }


        $mergedFieldDefinition = self::getCustomGridFieldDefinitions($class->getId(), $objectId);
        if (is_array($mergedFieldDefinition)) {
            if ($mergedFieldDefinition["localizedfields"]) {
                $childs = $mergedFieldDefinition["localizedfields"]->getFieldDefinitions();
                if (is_array($childs)) {
                    foreach($childs as $locKey => $locValue) {
                        $mergedFieldDefinition[$locKey] = $locValue;
                    }
                }
            }


            self::doFilterCustomGridFieldDefinitions($layoutDefinitions, $mergedFieldDefinition);
            $result["layoutDefinition"] = $layoutDefinitions;
            $result["fieldDefinition"] = $mergedFieldDefinition;
        }

        return $result;

    }

}
