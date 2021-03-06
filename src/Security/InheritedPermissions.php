<?php

namespace SilverStripe\Security;

use InvalidArgumentException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use SilverStripe\Versioned\Versioned;

/**
 * Calculates batch permissions for nested objects for:
 *  - canView: Supports 'Anyone' type
 *  - canEdit
 *  - canDelete: Includes special logic for ensuring parent objects can only be deleted if their children can
 *    be deleted also.
 */
class InheritedPermissions implements PermissionChecker
{
    use Injectable;

    /**
     * Delete permission
     */
    const DELETE = 'delete';

    /**
     * View permission
     */
    const VIEW = 'view';

    /**
     * Edit permission
     */
    const EDIT = 'edit';

    /**
     * Anyone canView permission
     */
    const ANYONE = 'Anyone';

    /**
     * Restrict to logged in users
     */
    const LOGGED_IN_USERS = 'LoggedInUsers';

    /**
     * Restrict to specific groups
     */
    const ONLY_THESE_USERS = 'OnlyTheseUsers';

    /**
     * Inherit from parent
     */
    const INHERIT = 'Inherit';

    /**
     * Class name
     *
     * @var string
     */
    protected $baseClass = null;

    /**
     * Object for evaluating top level permissions designed as "Inherit"
     *
     * @var DefaultPermissionChecker
     */
    protected $defaultPermissions = null;

    /**
     * Global permissions required to edit.
     * If empty no global permissions are required
     *
     * @var array
     */
    protected $globalEditPermissions = [];

    /**
     * Cache of permissions
     *
     * @var array
     */
    protected $cachePermissions = [];

    /**
     * Construct new permissions object
     *
     * @param string $baseClass Base class
     */
    public function __construct($baseClass)
    {
        if (!is_a($baseClass, DataObject::class, true)) {
            throw new InvalidArgumentException('Invalid DataObject class: ' . $baseClass);
        }
        $this->baseClass = $baseClass;
        return $this;
    }

    /**
     * @param DefaultPermissionChecker $callback
     * @return $this
     */
    public function setDefaultPermissions(DefaultPermissionChecker $callback)
    {
        $this->defaultPermissions = $callback;
        return $this;
    }

    /**
     * Global permissions required to edit
     *
     * @param array $permissions
     * @return $this
     */
    public function setGlobalEditPermissions($permissions)
    {
        $this->globalEditPermissions = $permissions;
        return $this;
    }

    /**
     * @return array
     */
    public function getGlobalEditPermissions()
    {
        return $this->globalEditPermissions;
    }

    /**
     * Get root permissions handler, or null if no handler
     *
     * @return DefaultPermissionChecker|null
     */
    public function getDefaultPermissions()
    {
        return $this->defaultPermissions;
    }

    /**
     * Get base class
     *
     * @return string
     */
    public function getBaseClass()
    {
        return $this->baseClass;
    }

    /**
     * Force pre-calculation of a list of permissions for optimisation
     *
     * @param string $permission
     * @param array $ids
     */
    public function prePopulatePermissionCache($permission = 'edit', $ids = [])
    {
        switch ($permission) {
            case self::EDIT:
                $this->canEditMultiple($ids, Security::getCurrentUser(), false);
                break;
            case self::VIEW:
                $this->canViewMultiple($ids, Security::getCurrentUser(), false);
                break;
            case self::DELETE:
                $this->canDeleteMultiple($ids, Security::getCurrentUser(), false);
                break;
            default:
                throw new InvalidArgumentException("Invalid permission type $permission");
        }
    }

    /**
     * This method is NOT a full replacement for the individual can*() methods, e.g. {@link canEdit()}. Rather than
     * checking (potentially slow) PHP logic, it relies on the database group associations, e.g. the "CanEditType" field
     * plus the "SiteTree_EditorGroups" many-many table. By batch checking multiple records, we can combine the queries
     * efficiently.
     *
     * Caches based on $typeField data. To invalidate the cache, use {@link SiteTree::reset()} or set the $useCached
     * property to FALSE.
     *
     * @param string $type Either edit, view, or create
     * @param array $ids Array of IDs
     * @param Member $member Member
     * @param array $globalPermission If the member doesn't have this permission code, don't bother iterating deeper
     * @param bool $useCached Enables use of cache. Cache will be populated even if this is false.
     * @return array A map of permissions, keys are ID numbers, and values are boolean permission checks
     * ID keys to boolean values
     */
    protected function batchPermissionCheck(
        $type,
        $ids,
        Member $member = null,
        $globalPermission = [],
        $useCached = true
    ) {
        // Validate ids
        $ids = array_filter($ids, 'is_numeric');
        if (empty($ids)) {
            return [];
        }

        // Default result: nothing editable
        $result = array_fill_keys($ids, false);

        // Validate member permission
        // Only VIEW allows anonymous (Anyone) permissions
        $memberID = $member ? (int)$member->ID : 0;
        if (!$memberID && $type !== self::VIEW) {
            return $result;
        }

        // Look in the cache for values
        $cacheKey = "{$type}-{$memberID}";
        if ($useCached && isset($this->cachePermissions[$cacheKey])) {
            $cachedValues = array_intersect_key($this->cachePermissions[$cacheKey], $result);

            // If we can't find everything in the cache, then look up the remainder separately
            $uncachedIDs = array_keys(array_diff_key($result, $this->cachePermissions[$cacheKey]));
            if ($uncachedIDs) {
                $uncachedValues = $this->batchPermissionCheck($type, $uncachedIDs, $member, $globalPermission, false);
                return $cachedValues + $uncachedValues;
            }
            return $cachedValues;
        }

        // If a member doesn't have a certain permission then they can't edit anything
        if ($globalPermission && !Permission::checkMember($member, $globalPermission)) {
            return $result;
        }

        // Get the groups that the given member belongs to
        $groupIDsSQLList = '0';
        if ($memberID) {
            $groupIDs = $member->Groups()->column("ID");
            $groupIDsSQLList = implode(", ", $groupIDs) ?: '0';
        }

        // Check if record is versioned
        if ($this->isVersioned()) {
            // Check all records for each stage and merge
            $combinedStageResult = [];
            foreach ([ Versioned::DRAFT, Versioned::LIVE ] as $stage) {
                $stageRecords = Versioned::get_by_stage($this->getBaseClass(), $stage)
                    ->byIDs($ids);
                // Exclude previously calculated records from later stage calculations
                if ($combinedStageResult) {
                    $stageRecords = $stageRecords->exclude('ID', array_keys($combinedStageResult));
                }
                $stageResult = $this->batchPermissionCheckForStage(
                    $type,
                    $globalPermission,
                    $stageRecords,
                    $groupIDsSQLList,
                    $member
                );
                // Note: Draft stage takes precedence over live, but only if draft exists
                $combinedStageResult = $combinedStageResult + $stageResult;
            }
        } else {
            // Unstaged result
            $stageRecords = DataObject::get($this->getBaseClass())->byIDs($ids);
            $combinedStageResult = $this->batchPermissionCheckForStage(
                $type,
                $globalPermission,
                $stageRecords,
                $groupIDsSQLList,
                $member
            );
        }

        // Cache the results
        if (empty($this->cachePermissions[$cacheKey])) {
            $this->cachePermissions[$cacheKey] = [];
        }
        if ($combinedStageResult) {
            $this->cachePermissions[$cacheKey] = $combinedStageResult + $this->cachePermissions[$cacheKey];
        }
        return $combinedStageResult;
    }

    /**
     * @param string $type
     * @param array $globalPermission List of global permissions
     * @param DataList $stageRecords List of records to check for this stage
     * @param string $groupIDsSQLList Group IDs this member belongs to
     * @param Member $member
     * @return array
     */
    protected function batchPermissionCheckForStage(
        $type,
        $globalPermission,
        DataList $stageRecords,
        $groupIDsSQLList,
        Member $member = null
    ) {
        // Initialise all IDs to false
        $result = array_fill_keys($stageRecords->column('ID'), false);

        // Get the uninherited permissions
        $typeField = $this->getPermissionField($type);
        if ($member && $member->ID) {
            // Determine if this member matches any of the group or other rules
            $groupJoinTable = $this->getJoinTable($type);
            $baseTable = DataObject::getSchema()->baseDataTable($this->getBaseClass());
            $uninheritedPermissions = $stageRecords
                ->where([
                    "(\"$typeField\" IN (?, ?) OR " . "(\"$typeField\" = ? AND \"$groupJoinTable\".\"{$baseTable}ID\" IS NOT NULL))"
                    => [
                        self::ANYONE,
                        self::LOGGED_IN_USERS,
                        self::ONLY_THESE_USERS
                    ]
                ])
                ->leftJoin(
                    $groupJoinTable,
                    "\"$groupJoinTable\".\"{$baseTable}ID\" = \"{$baseTable}\".\"ID\" AND " . "\"$groupJoinTable\".\"GroupID\" IN ($groupIDsSQLList)"
                )->column('ID');
        } else {
            // Only view pages with ViewType = Anyone if not logged in
            $uninheritedPermissions = $stageRecords
                ->filter($typeField, self::ANYONE)
                ->column('ID');
        }

        if ($uninheritedPermissions) {
            // Set all the relevant items in $result to true
            $result = array_fill_keys($uninheritedPermissions, true) + $result;
        }

        // Group $potentiallyInherited by ParentID; we'll look at the permission of all those parents and
        // then see which ones the user has permission on
        $groupedByParent = [];
        $potentiallyInherited = $stageRecords->filter($typeField, self::INHERIT);
        foreach ($potentiallyInherited as $item) {
            /** @var DataObject|Hierarchy $item */
            if ($item->ParentID) {
                if (!isset($groupedByParent[$item->ParentID])) {
                    $groupedByParent[$item->ParentID] = [];
                }
                $groupedByParent[$item->ParentID][] = $item->ID;
            } else {
                // Fail over to default permission check for Inherit and ParentID = 0
                $result[$item->ID] = $this->checkDefaultPermissions($type, $member);
            }
        }

        // Copy permissions from parent to child
        if ($groupedByParent) {
            $actuallyInherited = $this->batchPermissionCheck(
                $type,
                array_keys($groupedByParent),
                $member,
                $globalPermission
            );
            if ($actuallyInherited) {
                $parentIDs = array_keys(array_filter($actuallyInherited));
                foreach ($parentIDs as $parentID) {
                    // Set all the relevant items in $result to true
                    $result = array_fill_keys($groupedByParent[$parentID], true) + $result;
                }
            }
        }
        return $result;
    }

    public function canEditMultiple($ids, Member $member = null, $useCached = true)
    {
        return $this->batchPermissionCheck(
            self::EDIT,
            $ids,
            $member,
            $this->getGlobalEditPermissions(),
            $useCached
        );
    }

    public function canViewMultiple($ids, Member $member = null, $useCached = true)
    {
        return $this->batchPermissionCheck(self::VIEW, $ids, $member, [], $useCached);
    }

    public function canDeleteMultiple($ids, Member $member = null, $useCached = true)
    {
        // Validate ids
        $ids = array_filter($ids, 'is_numeric');
        if (empty($ids)) {
            return [];
        }
        $result = array_fill_keys($ids, false);

        // Validate member permission
        if (!$member || !$member->ID) {
            return $result;
        }
        $deletable = [];

        // Look in the cache for values
        $cacheKey = "delete-{$member->ID}";
        if ($useCached && isset($this->cachePermissions[$cacheKey])) {
            $cachedValues = array_intersect_key($this->cachePermissions[$cacheKey], $result);

            // If we can't find everything in the cache, then look up the remainder separately
            $uncachedIDs = array_keys(array_diff_key($result, $this->cachePermissions[$cacheKey]));
            if ($uncachedIDs) {
                $uncachedValues = $this->canDeleteMultiple($uncachedIDs, $member, false);
                return $cachedValues + $uncachedValues;
            }
            return $cachedValues;
        }

        // You can only delete pages that you can edit
        $editableIDs = array_keys(array_filter($this->canEditMultiple($ids, $member)));
        if ($editableIDs) {
            // You can only delete pages whose children you can delete
            $childRecords = DataObject::get($this->baseClass)
                ->filter('ParentID', $editableIDs);

            // Find out the children that can be deleted
            $children = $childRecords->map("ID", "ParentID");
            $childIDs = $children->keys();
            if ($childIDs) {
                $deletableChildren = $this->canDeleteMultiple($childIDs, $member);

                // Get a list of all the parents that have no undeletable children
                $deletableParents = array_fill_keys($editableIDs, true);
                foreach ($deletableChildren as $id => $canDelete) {
                    if (!$canDelete) {
                        unset($deletableParents[$children[$id]]);
                    }
                }

                // Use that to filter the list of deletable parents that have children
                $deletableParents = array_keys($deletableParents);

                // Also get the $ids that don't have children
                $parents = array_unique($children->values());
                $deletableLeafNodes = array_diff($editableIDs, $parents);

                // Combine the two
                $deletable = array_merge($deletableParents, $deletableLeafNodes);
            } else {
                $deletable = $editableIDs;
            }
        }

        // Convert the array of deletable IDs into a map of the original IDs with true/false as the value
        return array_fill_keys($deletable, true) + array_fill_keys($ids, false);
    }

    public function canDelete($id, Member $member = null)
    {
        // No ID: Check default permission
        if (!$id) {
            return $this->checkDefaultPermissions(self::DELETE, $member);
        }

        // Regular canEdit logic is handled by canEditMultiple
        $results = $this->canDeleteMultiple(
            [ $id ],
            $member
        );

        // Check if in result
        return isset($results[$id]) ? $results[$id] : false;
    }

    public function canEdit($id, Member $member = null)
    {
        // No ID: Check default permission
        if (!$id) {
            return $this->checkDefaultPermissions(self::EDIT, $member);
        }

        // Regular canEdit logic is handled by canEditMultiple
        $results = $this->canEditMultiple(
            [ $id ],
            $member
        );

        // Check if in result
        return isset($results[$id]) ? $results[$id] : false;
    }

    public function canView($id, Member $member = null)
    {
        // No ID: Check default permission
        if (!$id) {
            return $this->checkDefaultPermissions(self::VIEW, $member);
        }

        // Regular canView logic is handled by canViewMultiple
        $results = $this->canViewMultiple(
            [ $id ],
            $member
        );

        // Check if in result
        return isset($results[$id]) ? $results[$id] : false;
    }

    /**
     * Get field to check for permission type for the given check.
     * Defaults to those provided by {@see InheritedPermissionsExtension)
     *
     * @param string $type
     * @return string
     */
    protected function getPermissionField($type)
    {
        switch ($type) {
            case self::DELETE:
                // Delete uses edit type - Drop through
            case self::EDIT:
                return 'CanEditType';
            case self::VIEW:
                return 'CanViewType';
            default:
                throw new InvalidArgumentException("Invalid argument type $type");
        }
    }

    /**
     * Get join table for type
     * Defaults to those provided by {@see InheritedPermissionsExtension)
     *
     * @param string $type
     * @return string
     */
    protected function getJoinTable($type)
    {
        switch ($type) {
            case self::DELETE:
                // Delete uses edit type - Drop through
            case self::EDIT:
                return $this->getEditorGroupsTable();
            case self::VIEW:
                return $this->getViewerGroupsTable();
            default:
                throw new InvalidArgumentException("Invalid argument type $type");
        }
    }

    /**
     * Determine default permission for a givion check
     *
     * @param string $type Method to check
     * @param Member $member
     * @return bool
     */
    protected function checkDefaultPermissions($type, Member $member = null)
    {
        $defaultPermissions = $this->getDefaultPermissions();
        if (!$defaultPermissions) {
            return false;
        }
        switch ($type) {
            case self::VIEW:
                return $defaultPermissions->canView($member);
            case self::EDIT:
                return $defaultPermissions->canEdit($member);
            case self::DELETE:
                return $defaultPermissions->canDelete($member);
            default:
                return false;
        }
    }

    /**
     * Check if this model has versioning
     *
     * @return bool
     */
    protected function isVersioned()
    {
        if (!class_exists(Versioned::class)) {
            return false;
        }
        /** @var Versioned|DataObject $singleton */
        $singleton = DataObject::singleton($this->getBaseClass());
        return $singleton->hasExtension(Versioned::class) && $singleton->hasStages();
    }

    public function clearCache()
    {
        $this->cachePermissions = [];
        return $this;
    }

    /**
     * Get table to use for editor groups relation
     *
     * @return string
     */
    protected function getEditorGroupsTable()
    {
        $table = DataObject::getSchema()->tableName($this->baseClass);
        return "{$table}_EditorGroups";
    }

    /**
     * Get table to use for viewer groups relation
     *
     * @return string
     */
    protected function getViewerGroupsTable()
    {
        $table = DataObject::getSchema()->tableName($this->baseClass);
        return "{$table}_ViewerGroups";
    }
}
