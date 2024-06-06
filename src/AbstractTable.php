<?php

/**
 * The table handler is an object for interfacing with a table rather than a row.
 * This can be returned by using getInstance() or getTableHandler() from the objects this
 * table returns.
 * By using instantiating this object rather than using static methods, we can pass it around
 * and refer to it through the tableInterface.
 */

namespace iRAP\MysqlObjects;

use Exception;
use iRAP\CoreLibs\ArrayLib;
use iRAP\CoreLibs\MysqliLib;
use mysqli;
use mysqli_result;

abstract class AbstractTable implements TableInterface
{
    # Array of all the child instances that get created.
    protected static array $s_instances = [];

    protected int $m_defaultSearchLimit = 999999999999999999;

    # Cache of loaded objects, so we don't need to go and re-fetch them.
    # This object needs to ensure we clear these when we update rows.
    protected array $m_objectCache = [];

    /**
     * Loads all of these objects from the database.
     * This also clears and fully loads the cache.
     * @return AbstractTableRowObject[]
     * @throws Exception
     */
    public function loadAll(): array
    {
        $this->emptyCache();

        $query = "SELECT * FROM `" . $this->getTableName() . "`";
        $result = $this->getDb()->query($query);

        if ($result === FALSE) {
            throw new Exception('Error selecting all objects for loading.');
        }

        return $this->convertMysqliResultToObjects($result);
    }

    /**
     * Loads a single object of this class's type from the database using the unique row_id
     * @param int $id - the id of the row in the database table.
     * @param bool $useCache - optionally set as false to force a database lookup even if we have a
     *                    cached value from a previous lookup.
     * @return AbstractTableRowObject - the loaded object.
     * @throws NoSuchIdException
     * @throws Exception
     */
    public function load($id, bool $useCache = true): AbstractTableRowObject
    {
        $objects = $this->loadIds([$id], $useCache);

        if (count($objects) == 0) {
            $msg = 'There is no ' . $this->getTableName() . ' object with id: ' . $id;
            throw new NoSuchIdException($msg);
        }

        return ArrayLib::getFirstElement($objects);
    }

    /**
     * Loads a number of objects of this class's type from the database using the provided array
     * list of IDs. If any of the objects are already in the cache, they are fetched from there.
     * NOTE: The returned array of objects is indexed by the IDs of the objects.
     * @param array $ids - the list of IDs of the objects we wish to load.
     * @param bool $useCache - optionally set as false to force a database lookup even if we have a
     *                        cached value from a previous lookup.
     * @return AbstractTableRowObject[] - list of the objects with the specified IDs indexed
     *                                         by the objects ID.
     * @throws Exception
     */
    public function loadIds(array $ids, bool $useCache = true): array
    {
        $loadedObjects = [];
        $constructor = $this->getRowObjectConstructorWrapper();
        $idsToFetch = [];

        foreach ($ids as $id) {
            if (!isset($this->m_objectCache[$id]) || !$useCache) {
                $idsToFetch[] = $id;
            } else {
                $loadedObjects[$id] = $this->m_objectCache[$id];
            }
        }

        if (count($idsToFetch) > 0) {
            $db = $this->getDb();
            $escapedIdsToFetch = MysqliLib::escapeValues($idsToFetch, $db);
            $idsToFetchWrapped = ArrayLib::wrapElements($escapedIdsToFetch, "'");

            $query = "SELECT * FROM `" . $this->getTableName() . "` " .
                "WHERE `id` IN(" . implode(", ", $idsToFetchWrapped) . ")";

            /* @var $result mysqli_result|bool */
            $result = $db->query($query);

            if ($result === FALSE) {
                throw new Exception("Failed to select from table. " . $db->error);
            }

            $fieldInfoMap = [];

            for ($i = 0; $i < $result->field_count; $i++) {
                $fieldInfo = $result->fetch_field_direct($i);
                $fieldInfoMap[$fieldInfo->name] = $fieldInfo->type;
            }

            while (($row = $result->fetch_assoc()) != null) {
                $object = $constructor($row, $fieldInfoMap);
                $objectId = $row['id'];
                $this->m_objectCache[$objectId] = $object;
                $loadedObjects[$objectId] = $this->m_objectCache[$objectId];
            }
        }

        return $loadedObjects;
    }

    /**
     * Loads a range of data from the table.
     * It is important to note that offset is not tied to ID in any way.
     * @param int $offset
     * @param int $numElements
     * @return AbstractTableRowObject[]
     * @throws Exception
     */
    public function loadRange(int $offset, int $numElements): array
    {
        $query = "SELECT * FROM `" . $this->getTableName() . "` " .
            "LIMIT " . $offset . "," . $numElements;

        $db = $this->getDb();
        $result = $db->query($query);

        if ($result === FALSE) {
            throw new Exception('Error selecting all objects for loading. ' . $db->error);
        }

        return $this->convertMysqliResultToObjects($result);
    }

    /**
     * Load objects from the table that meet have all the attributes specified
     * in the provided wherePairs parameter.
     * @param array $wherePairs - column-name/value pairs that the object must have in order
     *                            to be fetched. the value in the pair may be an array to load
     *                            any objects that have any one of those values.
     *                            For example:
     *                              id => [1,2,3] would load objects that have ID 1,2, or 3.
     * @return AbstractTableRowObject[]
     * @throws Exception
     */
    public function loadWhereAnd(array $wherePairs): array
    {
        $db = $this->getDb();
        $query = $this->generateSelectWhereQuery($wherePairs, 'AND');
        $result = $db->query($query);

        if ($result === FALSE) {
            throw new Exception("Failed to load objects, check your where parameters.");
        }

        return $this->convertMysqliResultToObjects($result);
    }

    /**
     * Load objects from the table that meet the specified WHERE statement.
     *
     * WARNING: Unlike other load methods, this one takes the whole WHERE statement, so does not
     * do any escaping of the values
     *
     * @param string $where - the complete WHERE statement, minus the WHERE keyword and subsequent
     *                          space.
     *                            For example:
     *                              "name = 'John Smith'" would result in "WHERE name = 'John Smith'"
     * @return array<AbstractTableRowObject>
     * @throws Exception
     */
    public function loadWhereExplicit(string $where): array
    {
        $db = $this->getDb();
        $query = "SELECT * FROM `" . $this->getTableName() . "` WHERE " . $where;
        $result = $db->query($query);

        if ($result === FALSE) {
            throw new Exception("Failed to load objects, check your where parameters.");
        }

        return $this->convertMysqliResultToObjects($result);
    }

    /**
     * Load objects from the table that meet ANY of the attributes specified
     * in the provided wherePairs parameter.
     * @param array $wherePairs - column-name/value pairs that the object must have at least one of
     *                            in order to be fetched. the value in the pair may be an array to
     *                            load any objects that have any one of those values.
     *                            For example:
     *                              id => [1,2,3] would load objects that have ID 1,2, or 3.
     * @return AbstractTableRowObject[]
     * @throws Exception
     */
    public function loadWhereOr(array $wherePairs): array
    {
        $db = $this->getDb();
        $query = $this->generateSelectWhereQuery($wherePairs, 'OR');
        $result = $db->query($query);

        if ($result === FALSE) {
            throw new Exception("Failed to load objects, check your where parameters.");
        }

        return $this->convertMysqliResultToObjects($result);
    }

    /**
     * Create a new object that represents a new row in the database.
     * @param array $row - name value pairs to create the object from.
     * @return AbstractTableRowObject
     * @throws Exception
     */
    public function create(array $row): AbstractTableRowObject
    {
        $db = $this->getDb();

        $query = "INSERT INTO " . $this->getTableName() . " SET " .
            MysqliLib::generateQueryPairs($row, $db);

        $result = $db->query($query);

        if ($result === FALSE) {
            throw new Exception("Insert query failed: " . $db->error);
        }

        $insertId = $db->insert_id;
        return $this->load($insertId);
    }

    /**
     * Replace rows in a table.
     * WARNING - If they don't exist, then they will be inserted rather than throwing an error
     * or exception. If you just want to replace a single object, try using the update() method
     * instead.
     * This only makes sense if the primary or unique key is set in the input parameter.
     * @param array $row - row of data to replace with.
     * @return mysqli_result
     * @throws Exception
     */
    public function replace(array $row): mysqli_result
    {
        $db = $this->getDb();

        $query = "REPLACE INTO " . $this->getTableName() . " SET " .
            MysqliLib::generateQueryPairs($row, $db);

        $result = $db->query($query);

        if ($result === FALSE) {
            throw new Exception("replace query failed: " . $db->error);
        }

        return $result;
    }

    /**
     * Update a row specified by the ID with the provided data.
     * @param int $id - the ID of the object being updated
     * @param array $row - the data to update the object with
     * @return AbstractTableRowObject
     * @throws Exception if query failed.
     */
    public function update($id, array $row): AbstractTableRowObject
    {
        # This logic must not ever be changed to load the row object and then call update on that
        # because it's update method will call this method, and you will end up with a loop.
        $query =
            "UPDATE `" . $this->getTableName() . "` " .
            "SET " . MysqliLib::generateQueryPairs($row, $this->getDb()) . " " .
            "WHERE `id`='" . $id . "'";

        $result = $this->getDb()->query($query);

        if ($result === FALSE) {
            throw new Exception("Failed to update row in " . $this->getTableName());
        }

        if (isset($this->m_objectCache[$id])) {
            $existingObject = $this->getCachedObject($id);
            $existingArrayForm = $existingObject->getArrayForm();
            $newArrayForm = $existingArrayForm;

            # overwrite the existing data with the new.
            foreach ($row as $column_name => $value) {
                $newArrayForm[$column_name] = $value;
            }

            $objectConstructor = $this->getRowObjectConstructorWrapper();
            $updatedObject = $objectConstructor($newArrayForm);
            $this->updateCache($updatedObject);
        } else {
            # We don't have the object loaded into cache, so we need to fetch it from the
            # database in order to be able to return an object. This updates cache as well.
            # We also need to handle the event of the update being to change the ID.
            if (isset($row['id'])) {
                $updatedObject = $this->load($row['id']);
            } else {
                $updatedObject = $this->load($id);
            }
        }

        # If we changed the object's ID, then we need to remove the old cached object.
        if (isset($row['id']) && $row['id'] != $id) {
            $this->unsetCache($id);
        }

        return $updatedObject;
    }

    /**
     * Removes the object from the mysql database.
     * @param int $id - the ID of the object we wish to delete.
     * @return bool
     * @throws Exception - if query failed, returning FALSE.
     */
    public function delete($id): bool
    {
        $result = $this->deleteIds([$id]);
        return $result === 1;
    }

    /**
     * Deletes objects that have any of the specified IDs. This will not throw an error or
     * exception if an object with one of the IDs specified does not exist.
     * This is a fast and cache-friendly operation.
     * @param array $ids - the list of IDs of the objects we wish to delete.
     * @return int - the number of objects deleted.
     * @throws Exception
     */
    public function deleteIds(array $ids): int
    {
        $db = $this->getDb();
        $idsToDelete = MysqliLib::escapeValues($ids, $db);
        $wherePairs = ["id" => $idsToDelete];
        $query = $this->generateDeleteWhereQuery($wherePairs, "AND");
        $result = $db->query($query);

        if ($result === FALSE) {
            throw new Exception("Failed to delete objects by ID.");
        }

        # Remove these objects from our cache.
        foreach ($ids as $objectId) {
            $this->unsetCache($objectId);
        }

        return $db->affected_rows;
    }

    /**
     *  Deletes all rows from the table by running TRUNCATE.
     * @param bool $inTransaction - set to true to run a slower query that won't implicitly commit
     * @return bool
     * @throws Exception
     */
    public function deleteAll(bool $inTransaction = false): bool
    {
        if ($inTransaction) {
            # This is much slower but can be run without inside a transaction
            $query = "DELETE FROM `" . $this->getTableName() . "`";

        } else {
            # This is much faster, but will cause an implicit commit.
            $query = "TRUNCATE `" . $this->getTableName() . "`";

        }

        $result = $this->getDb()->query($query);

        if ($result === FALSE) {
            throw new Exception('Failed to drop table: ' . $this->getTableName());
        }

        $this->emptyCache();
        return $result;
    }

    /**
     * Delete rows from the table that meet have all the attributes specified
     * in the provided wherePairs parameter.
     * WARNING - by default this will clear your cache. You can manually set clearCache to false
     *           if you know what you are doing, but you may wish to delete by ID instead which
     *           will be cache-optimised. We clear the cache to prevent loading cached objects
     *           from memory when they were previously deleted using one of these methods.
     * @param array $wherePairs - column-name/value pairs that the object must have in order
     *                            to be deleted. the value in the pair may be an array to delete
     *                            any objects that have any one of those values.
     *                            For example:
     *                              id => [1,2,3] would delete objects that have ID 1,2, or 3.
     * @param bool $clearCache - optionally set as false to not have this operation clear the
     *                           cache afterwards.
     * @return int - the number of rows/objects that were deleted.
     * @throws Exception
     */
    public function deleteWhereAnd(array $wherePairs, bool $clearCache = true): int
    {
        $db = $this->getDb();
        $query = $this->generateDeleteWhereQuery($wherePairs, "AND");
        /* @var $result mysqli_result|bool */
        $result = $db->query($query);

        if ($result === FALSE) {
            throw new Exception("Failed to delete objects, check your where parameters.");
        }

        if ($clearCache) {
            $this->emptyCache();
        }

        return $db->affected_rows;
    }

    /**
     * Delete rows from the table that meet ANY of the attributes specified
     * in the provided wherePairs parameter.
     * WARNING - by default this will clear your cache. You can manually set clearCache to false
     *           if you know what you are doing, but you may wish to delete by ID instead which
     *           will be cache-optimised. We clear the cache to prevent loading cached objects
     *           from memory when they were previously deleted using one of these methods.
     * @param array $wherePairs - column-name/value pairs that the object must have at least one of
     *                            in order to be fetched. the value in the pair may be an array to
     *                            delete any objects that have any one of those values.
     *                            For example:
     *                              id => [1,2,3] would delete objects that have ID 1,2, or 3.
     * @param bool $clearCache - optionally set as false to not have this operation clear the
     *                           cache afterwards.
     * @return int|string
     * @throws Exception
     */
    public function deleteWhereOr(array $wherePairs, bool $clearCache = true)
    {
        $db = $this->getDb();
        $query = $this->generateDeleteWhereQuery($wherePairs, "OR");
        $result = $db->query($query);

        if ($result === FALSE) {
            throw new Exception("Failed to delete objects, check your where parameters.");
        }

        if ($clearCache) {
            $this->emptyCache();
        }

        return $db->affected_rows;
    }

    /**
     * Search the table for items and return any matches as objects. This method is
     * required by the TableHandlerInterface
     * @param array $parameters
     * @return array
     * @throws Exception
     */
    public function search(array $parameters): array
    {
        return $this->advancedSearch($parameters);
    }

    /**
     * Search the table for items and return any matches as objects. This method is
     * required by the TableHandlerInterface
     * @param array $parameters - these may not be sanitized already.
     * @param array $whereClauses
     * @return array
     * @throws Exception
     */
    public function advancedSearch(array $parameters, array $whereClauses = []): array
    {
        $objects = [];

        if (isset($parameters['start_id'])) {
            $whereClauses[] = "`id` >= '" . intval($parameters['start_id']) . "'";
        }

        if (isset($parameters['end_id'])) {
            $whereClauses[] = "`id` <= '" . intval($parameters['end_id']) . "'";
        }

        if (isset($parameters['in_id'])) {
            if (is_array($parameters['in_id'])) {
                $possibleIds = [];
                $idArray = $parameters['in_id'];

                foreach ($idArray as $idInput) {
                    $possibleIds[] = intval($idInput);
                }

                $whereClauses[] = "`id` IN (" . implode(",", $possibleIds) . ")";
            } else {
                throw new Exception('"in_id" needs to be an array of IDs ');
            }
        }

        $offset = isset($parameters['offset']) ? intval($parameters['offset']) : 0;
        $limit = (isset($parameters['limit'])) ? intval($parameters['limit']) : $this->m_defaultSearchLimit;

        if (count($whereClauses) > 0) {
            $whereClause = " WHERE " . implode(" AND ", $whereClauses);
        } else {
            $whereClause = "";
        }

        $query =
            "SELECT * " .
            "FROM `" . $this->getTableName() . "` " . $whereClause . " ";

        // Add the order by clause if required
        if (isset($parameters['order_column']) && isset($parameters['order_direction'])) {
            $query .= "ORDER BY {$parameters['order_column']} {$parameters['order_direction']} ";
        }

        //Finally the limit/offset
        $query .= "LIMIT " . $offset . "," . $limit;

        $result = $this->getDb()->query($query);

        if ($result === FALSE) {
            throw new Exception('Error selecting all objects.');
        }

        $constructor = $this->getRowObjectConstructorWrapper();

        if ($result->num_rows > 0) {
            while (($row = $result->fetch_assoc()) != null) {
                $objects[] = $constructor($row);
            }
        }

        return $objects;
    }

    /**
     * Fetch the single instance of this object.
     * @return AbstractTable
     */
    public static function getInstance(): AbstractTable
    {
        $className = get_called_class();

        if (!isset(self::$s_instances[$className])) {
            self::$s_instances[$className] = new $className();
        }

        return self::$s_instances[$className];
    }

    /**
     * Get the user to specify fields that may be null in the database and thus don't have
     * to be set when creating this object.
     * @return string[] - array of column names that may be null.
     */
    abstract public function getFieldsThatAllowNull(): array;

    /**
     * Get the user to specify fields that have default values and thus don't have
     * to be set when creating this object.
     * @return string[] - array of column names that may be null.
     */
    abstract public function getFieldsThatHaveDefaults(): array;

    /**
     * Return an inline function that takes the $row array and will call the relevant row object's
     * constructor with it.
     * @return callable - the callable must take the data row as its only parameter and return
     *                     the created object
     *                     e.g. $returnObj = function($row){ return new rowObject($row); }
     */
    public function getRowObjectConstructorWrapper(): callable
    {
        $objectClassName = $this->getObjectClassName();

        return function ($row, $row_field_types = null) use ($objectClassName) {
            return new $objectClassName($row, $row_field_types);
        };
    }

    public abstract function getObjectClassName();

    /**
     * Return the database connection to the database that has this table.
     * @return mysqli
     */
    public abstract function getDb(): mysqli;

    /**
     * Remove the cache entry for an object.
     * This should only happen when objects are destroyed.
     * This will not throw exception/error if id doesn't exist.
     * @param int $objectId - the ID of the object we wish to clear the cache of.
     */
    public function unsetCache(int $objectId)
    {
        unset($this->m_objectCache[$objectId]);
    }

    /**
     * Completely empty the cache. Do this if a table is emptied etc.
     */
    public function emptyCache()
    {
        $this->m_objectCache = [];
    }

    /**
     * Fetch an object from our cache.
     * @param int $id - the id of the row the object represents.
     * @return AbstractTableRowObject
     * @throws Exception
     */
    protected function getCachedObject(int $id): AbstractTableRowObject
    {
        if (!isset($this->m_objectCache[$id])) {
            throw new Exception("There is no cached object");
        }

        return $this->m_objectCache[$id];
    }

    /**
     * Update our cache with the provided object.
     * Note that if you simply changed the object's ID, you will need to call unsetCache() on
     * the original ID.
     * @param AbstractTableRowObject $object
     */
    protected function updateCache(AbstractTableRowObject $object)
    {
        $this->m_objectCache[$object->get_id()] = $object;
    }

    /**
     * Helper function that converts a query result into a collection of the row objects.
     * @param mysqli_result $result
     * @return AbstractTableRowObject[]
     */
    protected function convertMysqliResultToObjects(mysqli_result $result): array
    {
        $objects = [];

        if ($result->num_rows > 0) {
            $constructor = $this->getRowObjectConstructorWrapper();

            $fieldInfoMap = [];

            for ($i = 0; $i < $result->field_count; $i++) {
                $fieldInfo = $result->fetch_field_direct($i);
                $fieldInfoMap[$fieldInfo->name] = $fieldInfo->type;
            }

            while (($row = $result->fetch_assoc()) != null) {
                $loadedObject = $constructor($row, $fieldInfoMap);
                $this->updateCache($loadedObject);
                $objects[] = $loadedObject;
            }
        }

        return $objects;
    }

    /**
     * Helper function that generates the raw SQL string to send to the database in order to
     * load objects that have any/all (depending on $conjunction) of the specified attributes.
     * @param array $wherePairs - column-name/value pairs of attributes the objects must have to
     *                           be loaded.
     * @param string $conjunction - 'AND' or 'OR' which changes whether the object needs all or
     *                              any of the specified attributes in order to be loaded.
     * @return string - the raw sql string to send to the database.
     * @throws Exception - invalid $conjunction specified that was not 'OR' or 'AND'
     */
    protected function generateSelectWhereQuery(array $wherePairs, string $conjunction): string
    {
        return "SELECT * FROM `" . $this->getTableName() . "` " .
            $this->generateWhereClause($wherePairs, $conjunction);
    }

    /**
     * Helper function that generates the raw SQL string to send to the database in order to
     * delete objects that have any/all (depending on $conjunction) of the specified attributes.
     * @param array $wherePairs - column-name/value pairs of attributes the objects must have to
     *                           be deleted.
     * @param string $conjunction - 'AND' or 'OR' which changes whether the object needs all or
     *                              any of the specified attributes in order to be loaded.
     * @return string - the raw sql string to send to the database.
     * @throws Exception - invalid $conjunction specified that was not 'OR' or 'AND'
     */
    protected function generateDeleteWhereQuery(array $wherePairs, string $conjunction): string
    {
        return "DELETE FROM `" . $this->getTableName() . "` " .
            $this->generateWhereClause($wherePairs, $conjunction);
    }

    /**
     * Generate the "where" part of a query based on name/value pairs and the provided conjunction
     * @param array $wherePairs - column/value pairs for where clause. Value may or may not be an
     *                            array list of values for WHERE IN().
     * @param string $conjunction - one of "AND" or "OR" for if all/any of criteria need to be met
     * @return string - the where clause of a query such as "WHERE `id`='3'"
     * @throws Exception
     */
    protected function generateWhereClause(array $wherePairs, string $conjunction): string
    {
        $whereClause = "";
        $upperConjunction = strtoupper($conjunction);
        $possibleConjunctions = ["AND", "OR"];

        if (!in_array($upperConjunction, $possibleConjunctions)) {
            throw new Exception("Invalid conjunction: " . $upperConjunction);
        }

        $whereStrings = [];

        foreach ($wherePairs as $attribute => $searchValue) {
            $whereString = "`" . $attribute . "` ";

            if (is_array($searchValue)) {
                if (count($searchValue) === 0) {
                    $whereString = "FALSE";
                } else {
                    $escapedValues = MysqliLib::escapeValues($searchValue, $this->getDb());
                    $searchValueWrapped = ArrayLib::wrapElements($escapedValues, "'");
                    $whereString .= " IN(" . implode(",", $searchValueWrapped) . ")";
                }
            } else {
                $whereString .= " = '" . $this->getDb()->escape_string($searchValue) . "'";
            }

            $whereStrings[] = $whereString;
        }

        if (count($whereStrings) > 0) {
            $whereClause = "WHERE " . implode(" " . $upperConjunction . " ", $whereStrings);
        }

        return $whereClause;
    }
}
