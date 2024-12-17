<?php

/**
 * The table handler is an object for interfacing with a table rather than a row.
 * This can be returned from a ModelObject from a static method. Thus, if the programmer wants
 * to fetch a resource using the ModelObject definition, they would do:
 * MyModelName::getTableHandler()->load($id);
 * This allows the developer to treat the model as an object that represents a row in the table
 */

namespace iRAP\MysqlObjects;


use Exception;
use mysqli;

interface TableInterface
{
    /**
     * Return the singleton instance of this object.
     */
    public static function getInstance();

    /**
     * Return the name of this table.
     */
    public function getTableName();

    /**
     * Get a connection to the database.
     * @return mysqli
     */
    public function getDb(): mysqli;

    /**
     * Removes the object from the mysql database.
     * @return void
     */
    public function delete($id);

    /**
     * Deletes all rows from the table by running TRUNCATE.
     */
    public function deleteAll();

    /**
     * Loads all of these objects from the database.
     * @return AbstractTableRowObject[]
     */
    public function loadAll(): array;

    /**
     * Loads a single object of this class's type from the database using the unique ID of the row.
     * @param $id - the id of the row in the database table.
     * @param bool $useCache - optionally set as false to force a database lookup even if we have a
     *                    cached value from a previous lookup.
     * @return AbstractTableRowObject - the loaded object.
     */
    public function load($id, bool $useCache = true): AbstractTableRowObject;

    /**
     * Loads a range of data from the table.
     * It is important to note that offset is not tied to ID in any way.
     * @param int $offset
     * @param int $numElements
     * @return AbstractTableRowObject[]
     */
    public function loadRange(int $offset, int $numElements): array;

    /**
     * Create a new row with unfiltered data.
     * @param array $row
     * @return AbstractTableRowObject
     */
    public function create(array $row): AbstractTableRowObject;

    /**
     * Replace a row by id.
     */
    public function replace(array $row);

    /**
     * Update a specified row with inputs
     * @param $id
     * @param array $row
     * @return AbstractTableRowObject
     */
    public function update($id, array $row): AbstractTableRowObject;

    /**
     * Search the table for items and return any matches as objects.
     * @param array $parameters
     * @return AbstractTableRowObject[]
     * @throws Exception
     */
    public function search(array $parameters): array;

    /**
     * Take a given array of USER PROVIDED data and validate it.
     * This is where you would check that the provided date is the correct type such as an int
     * instead of a string, and possibly run more advanced logic to ensure a date was in UK format
     * instead of american format
     * WARNING - Do NOT perform mysqli escaping here as that is performed at the last possible
     * moment in the save method.
     * This is a good point to throw exceptions if someone has provided  a string when expecting a
     * boolean etc.
     * @return array - the validated inputs
     */
    public function validateInputs(array $data): array;

    /**
     * List the fields that allow null values
     * @return string[] - array of column names.
     */
    public function getFieldsThatAllowNull(): array;

    /**
     * List the fields that have default values.
     * @return string[] - array of column names.
     */
    public function getFieldsThatHaveDefaults(): array;
}
