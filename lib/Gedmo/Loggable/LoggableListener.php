<?php

namespace Gedmo\Loggable;

use Doctrine\Common\EventArgs;
use Gedmo\Mapping\MappedEventSubscriber;
use Gedmo\Loggable\Mapping\Event\LoggableAdapter;
use Gedmo\Tool\Wrapper\AbstractWrapper;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Loggable listener
 *
 * @author Boussekeyt Jules <jules.boussekeyt@gmail.com>
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class LoggableListener extends MappedEventSubscriber
{
    /**
     * Create action
     */
    const ACTION_CREATE = 'create';

    /**
     * Update action
     */
    const ACTION_UPDATE = 'update';

    /**
     * Remove action
     */
    const ACTION_REMOVE = 'remove';

    /**
     * Username for identification
     *
     * @var string
     */
    protected $username;

    /**
     * List of log entries which do not have the foreign
     * key generated yet - MySQL case. These entries
     * will be updated with new keys on postPersist event
     *
     * @var array
     */
    protected $pendingLogEntryInserts = array();

    /**
     * For log of changed relations we use
     * its identifiers to avoid storing serialized Proxies.
     * These are pending relations in case it does not
     * have an identifier yet
     *
     * @var array
     */
    protected $pendingRelatedObjects = array();

    /**
     * LogEntries created in onFlush for ACTION_UPDATE that still need their `data`
     * recomputed in postUpdate, after all preUpdate listeners (which may have
     * mutated the source object via $object->setFoo(...) and called
     * recomputeSingleObjectChangeSet) have run. Without this second pass the
     * LogEntry only contains the changeset visible at onFlush time and misses
     * any field set by a preUpdate listener.
     *
     * Mapping: source object spl_object_hash → LogEntry instance.
     *
     * @var array
     */
    protected $pendingLogEntryDataUpdates = array();

    /**
     * LogEntries created in onFlush for ACTION_CREATE that still need their
     * `data` recomputed in postFlush, after all postPersist listeners have
     * run. Application postPersist listeners commonly trigger a NESTED
     * $om->flush() after mutating the just-inserted object — under the
     * Parse adapter the nested commit skips onFlush/postFlush at
     * commitDepth > 1 (DoctrineParseBundle guard), so Gedmo never sees
     * the post-insert mutation through its normal channel. We re-read
     * the final state in postFlush of the outer commit and patch the
     * CREATE LogEntry's data accordingly.
     *
     * Mapping: source object spl_object_hash → ['logEntry' => ..., 'object' => ..., 'ea' => ...].
     *
     * @var array
     */
    protected $pendingLogEntryCreateDataUpdates = array();

    /**
     * Set username for identification
     *
     * @param mixed $username
     *
     * @throws \Gedmo\Exception\InvalidArgumentException Invalid username
     */
    public function setUsername($username)
    {
        if (is_string($username)) {
            $this->username = $username;
        } elseif (is_object($username) && method_exists($username, 'getUsername')) {
            $this->username = (string) $username->getUsername();
        } elseif ($username instanceof UserInterface) {
            $this->username = $username->getUserIdentifier();
        } else {
            throw new \Gedmo\Exception\InvalidArgumentException("Username must be a string, or object should have method: getUsername");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents()
    {
        return array(
            'onFlush',
            'loadClassMetadata',
            'postPersist',
            'postUpdate',
            'postFlush',
        );
    }

    /**
     * Get the LogEntry class
     *
     * @param LoggableAdapter $ea
     * @param string $class
     *
     * @return string
     */
    protected function getLogEntryClass(LoggableAdapter $ea, $class)
    {
        return isset(self::$configurations[$this->name][$class]['logEntryClass']) ?
            self::$configurations[$this->name][$class]['logEntryClass'] :
            $ea->getDefaultLogEntryClass();
    }

    /**
     * Maps additional metadata
     *
     * @param EventArgs $eventArgs
     *
     * @return void
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        $ea = $this->getEventAdapter($eventArgs);
        $meta = $eventArgs->getClassMetadata();
        $this->loadMetadataForObjectClass($ea->getObjectManager(), $meta);

        // map indexes
        if ($meta->name === 'Gedmo\Loggable\Entity\LogEntry') {
            $meta->table['indexes']['log_class_lookup_idx']['columns'] = [$meta->columnNames['objectClass']];
            $meta->table['indexes']['log_date_lookup_idx']['columns'] = [$meta->columnNames['loggedAt']];
            $meta->table['indexes']['log_user_lookup_idx']['columns'] = [$meta->columnNames['username']];
            $meta->table['indexes']['log_version_lookup_idx']['columns'] = [
                $meta->columnNames['objectId'],
                $meta->columnNames['objectClass'],
                $meta->columnNames['username'],
            ];
        }
    }

    /**
     * Checks for inserted object to update its logEntry
     * foreign key
     *
     * @param EventArgs $args
     *
     * @return void
     */
    public function postPersist(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $object = $ea->getObject();
        $om = $ea->getObjectManager();
        $oid = spl_object_hash($object);
        $uow = $om->getUnitOfWork();
        if ($this->pendingLogEntryInserts && array_key_exists($oid, $this->pendingLogEntryInserts)) {
            $wrapped = AbstractWrapper::wrap($object, $om);

            $logEntry = $this->pendingLogEntryInserts[$oid];
            $logEntryMeta = $om->getClassMetadata(get_class($logEntry));
            $dbFieldName = $logEntryMeta->getFieldMapping('objectId')['name'];

            $id = $wrapped->getIdentifier();
            $logEntryMeta->getReflectionProperty('objectId')->setValue($logEntry, $id);
            $uow->scheduleExtraUpdate($logEntry, array(
                $dbFieldName => array(null, $id),
            ));
            $ea->setOriginalObjectProperty($uow, spl_object_hash($logEntry), $dbFieldName, $id);
            unset($this->pendingLogEntryInserts[$oid]);
        }
        if ($this->pendingRelatedObjects && array_key_exists($oid, $this->pendingRelatedObjects)) {
            $wrapped = AbstractWrapper::wrap($object, $om);
            $identifiers = $wrapped->getIdentifier(false);
            foreach ($this->pendingRelatedObjects[$oid] as $props) {
                $logEntry = $props['log'];
                $oldData = $data = $logEntry->getData();
                $data[$props['field']] = $identifiers;

                $logEntry->setData($data);

                $uow->scheduleExtraUpdate($logEntry, array(
                    'data' => array($oldData, $data),
                ));
                $ea->setOriginalObjectProperty($uow, spl_object_hash($logEntry), 'data', $data);
            }
            unset($this->pendingRelatedObjects[$oid]);
        }
    }

    /**
     * Handle any custom LogEntry functionality that needs to be performed
     * before persisting it
     *
     * @param object $logEntry The LogEntry being persisted
     * @param object $object   The object being Logged
     */
    protected function prePersistLogEntry($logEntry, $object)
    {

    }

    /**
     * Looks for loggable objects being inserted or updated
     * for further processing
     *
     * @param EventArgs $eventArgs
     *
     * @return void
     */
    public function onFlush(EventArgs $eventArgs)
    {
        $ea = $this->getEventAdapter($eventArgs);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();

        foreach ($ea->getScheduledObjectInsertions($uow) as $object) {
            $logEntry = $this->createLogEntry(self::ACTION_CREATE, $object, $ea);
            if ($logEntry !== null) {
                // Remember this CREATE LogEntry so postFlush can re-read the
                // source object's final state after all postPersist listeners
                // (and any nested $om->flush() they triggered) have run.
                $this->pendingLogEntryCreateDataUpdates[spl_object_hash($object)] = array(
                    'logEntry' => $logEntry,
                    'object'   => $object,
                    'ea'       => $ea,
                );
            }
        }
        foreach ($ea->getScheduledObjectUpdates($uow) as $object) {
            $logEntry = $this->createLogEntry(self::ACTION_UPDATE, $object, $ea);
            if ($logEntry !== null) {
                // Remember this LogEntry so that postUpdate can re-read the changeset
                // once all preUpdate listeners (and the bundle's recomputeSingleObjectChangeSet)
                // have run, in case they brought new versioned fields into play.
                $this->pendingLogEntryDataUpdates[spl_object_hash($object)] = $logEntry;
            }
        }
        foreach ($ea->getScheduledObjectDeletions($uow) as $object) {
            $this->createLogEntry(self::ACTION_REMOVE, $object, $ea);
        }
    }

    /**
     * Re-read the changeset of an updated loggable object after all preUpdate
     * listeners have run. If new versioned fields appeared (typically because
     * another listener mutated the object via $object->setFoo(...) and forced
     * a recomputeSingleObjectChangeSet), merge them into the LogEntry's `data`
     * via scheduleExtraUpdate — the LogEntry insert has already been queued
     * with the initial data, so we need a follow-up update to enrich it.
     *
     * Reuses the same scheduleExtraUpdate mechanism already used by postPersist
     * to fix up the LogEntry's `objectId` after the source insert generates it.
     *
     * @param EventArgs $args
     *
     * @return void
     */
    public function postUpdate(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $object = $ea->getObject();
        $oid = spl_object_hash($object);

        if (!array_key_exists($oid, $this->pendingLogEntryDataUpdates)) {
            return;
        }

        $logEntry = $this->pendingLogEntryDataUpdates[$oid];
        unset($this->pendingLogEntryDataUpdates[$oid]);

        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();

        $oldData = $logEntry->getData() ?? array();
        $newData = $this->getObjectChangeSetData($ea, $object, $logEntry);

        if (empty($newData)) {
            return;
        }

        // Merge the new fields on top of the data captured at onFlush time.
        // Fields that already had a value keep the post-recompute one (which is
        // also the value the source object was actually persisted with).
        $mergedData = array_merge($oldData, $newData);

        if ($mergedData === $oldData) {
            return;
        }

        $logEntry->setData($mergedData);
        $uow->scheduleExtraUpdate($logEntry, array(
            'data' => array($oldData, $mergedData),
        ));
        $ea->setOriginalObjectProperty($uow, spl_object_hash($logEntry), 'data', $mergedData);
    }

    /**
     * After the outer commit has fully completed (all executeInserts,
     * postPersist listeners — including those that triggered nested
     * $om->flush() — and executeUpdates have run), walk pending CREATE
     * LogEntries and re-read versioned fields directly from the source
     * object. If a postPersist listener mutated it (typically via a
     * nested $om->flush() which the Parse UnitOfWork correctly persisted
     * but which bypassed onFlush via the commitDepth > 1 guard), patch
     * the LogEntry's `data` to reflect the final persisted state.
     *
     * Mirrors the rationale of postUpdate (commit 3930f9a1) but for the
     * CREATE path — postFlush is the only safe hook for INSERT because
     * postPersist listener invocation order is not guaranteed.
     *
     * @param EventArgs $args
     *
     * @return void
     */
    public function postFlush(EventArgs $args)
    {
        if (empty($this->pendingLogEntryCreateDataUpdates)) {
            return;
        }

        $pending = $this->pendingLogEntryCreateDataUpdates;
        $this->pendingLogEntryCreateDataUpdates = array();

        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();

        foreach ($pending as $entry) {
            $logEntry = $entry['logEntry'];
            $object   = $entry['object'];

            $meta   = $om->getClassMetadata(get_class($object));
            $config = $this->getConfiguration($om, $meta->name);
            if (empty($config['versioned'])) {
                continue;
            }

            $oldData = $logEntry->getData() ?? array();
            $newData = $this->readCurrentVersionedData($ea, $object, $config['versioned']);

            if (empty($newData)) {
                continue;
            }

            // Merge so the post-postPersist values win for fields they touched,
            // while preserving any field-shape massaging performed by
            // getObjectChangeSetData at onFlush time (embedded collections etc.)
            // that we don't re-emit through reflection.
            $mergedData = array_merge($oldData, $newData);
            if ($mergedData === $oldData) {
                continue;
            }

            $logEntry->setData($mergedData);

            // Nested flush limited to the LogEntry; under the Parse adapter
            // this runs at commitDepth=2 and skips onFlush/postFlush, so no
            // recursion. LogEntry itself is not Loggable.
            $logEntryMeta = $om->getClassMetadata(get_class($logEntry));
            $om->getUnitOfWork()->recomputeSingleObjectChangeSet($logEntryMeta, $logEntry);
            $om->flush($logEntry);
        }
    }

    /**
     * Re-read current values of the given versioned fields directly from the
     * source object via reflection. Applies the same normalization as
     * getObjectChangeSetData (BackedEnum → scalar value, single-valued
     * association → identifier). Used at postFlush time when the UnitOfWork
     * changeset is no longer authoritative (the source object was an INSERT
     * at outer onFlush; later postPersist-driven mutations may have been
     * persisted via a nested commit that bypassed our onFlush hook).
     *
     * @param LoggableAdapter $ea
     * @param object          $object
     * @param array           $versionedFields
     *
     * @return array
     */
    protected function readCurrentVersionedData($ea, $object, array $versionedFields)
    {
        $om   = $ea->getObjectManager();
        $meta = $om->getClassMetadata(get_class($object));
        $data = array();

        foreach ($versionedFields as $field) {
            if (!$meta->hasField($field) && !$meta->hasAssociation($field)) {
                continue;
            }
            $refl = $meta->getReflectionProperty($field);
            if ($refl === null) {
                continue;
            }
            $value = $refl->getValue($object);

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            } elseif ($meta->isSingleValuedAssociation($field) && is_object($value)) {
                $wrapped = AbstractWrapper::wrap($value, $om);
                $value = $wrapped->getIdentifier(false);
            }

            $data[$field] = $value;
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    protected function getNamespace()
    {
        return __NAMESPACE__;
    }

    /**
     * Returns an objects changeset data
     *
     * @param LoggableAdapter $ea
     * @param object $object
     * @param object $logEntry
     *
     * @return array
     */
    protected function getObjectChangeSetData($ea, $object, $logEntry)
    {
        $om        = $ea->getObjectManager();
        $wrapped   = AbstractWrapper::wrap($object, $om);
        $meta      = $wrapped->getMetadata();
        $config    = $this->getConfiguration($om, $meta->name);
        $uow       = $om->getUnitOfWork();
        $newValues = array();

        foreach ($ea->getObjectChangeSet($uow, $object) as $field => $changes) {
            if (empty($config['versioned']) || !in_array($field, $config['versioned'])) {
                continue;
            }
            $value = $changes[1];
            if (method_exists($meta, 'isCollectionValuedEmbed') && $meta->isCollectionValuedEmbed($field) && $value) {
                $embedValues = array();
                foreach ($value as $embedValue) {
                    $wrapped = AbstractWrapper::wrap($embedValue, $om);
                    $embedValues[] = $this->getObjectChangeSetData($ea, $embedValue, $logEntry);
                }
                $value = $embedValues;
            }
            if ($meta->isSingleValuedAssociation($field) && $value) {
                if ($wrapped->isEmbeddedAssociation($field)) {
                    $value = $this->getObjectChangeSetData($ea, $value, $logEntry);
                } else {
                    $oid          = spl_object_hash($value);
                    $wrappedAssoc = AbstractWrapper::wrap($value, $om);
                    $value        = $wrappedAssoc->getIdentifier(false);
                    if (!is_array($value) && !$value) {
                        $this->pendingRelatedObjects[$oid][] = array(
                            'log'   => $logEntry,
                            'field' => $field,
                        );
                    }
                }
            }
            $newValues[$field] = $value;
        }

        return $newValues;
    }

    /**
     * Create a new Log instance
     *
     * @param string          $action
     * @param object          $object
     * @param LoggableAdapter $ea
     *
     * @return \Gedmo\Loggable\Entity\MappedSuperclass\AbstractLogEntry|null
     */
    protected function createLogEntry($action, $object, LoggableAdapter $ea)
    {
        $om = $ea->getObjectManager();
        $wrapped = AbstractWrapper::wrap($object, $om);
        $meta = $wrapped->getMetadata();

        // Filter embedded documents
        if (isset($meta->isEmbeddedDocument) && $meta->isEmbeddedDocument) {
            return;
        }

        if ($config = $this->getConfiguration($om, $meta->name)) {
            $logEntryClass = $this->getLogEntryClass($ea, $meta->name);
            $logEntryMeta = $om->getClassMetadata($logEntryClass);
            /** @var \Gedmo\Loggable\Entity\LogEntry $logEntry */
            $logEntry = $logEntryMeta->newInstance();

            $logEntry->setAction($action);
            $logEntry->setUsername($this->username);
            $logEntry->setObjectClass($meta->name);
            $logEntry->setLoggedAt();

            // check for the availability of the primary key
            $uow = $om->getUnitOfWork();
            if ($action === self::ACTION_CREATE && $ea->isPostInsertGenerator($meta)) {
                $this->pendingLogEntryInserts[spl_object_hash($object)] = $logEntry;
            } else {
                $logEntry->setObjectId($wrapped->getIdentifier());
            }
            $newValues = array();
            if ($action !== self::ACTION_REMOVE && isset($config['versioned'])) {
                $newValues = $this->getObjectChangeSetData($ea, $object, $logEntry);
                $logEntry->setData($newValues);
            }

            if($action === self::ACTION_UPDATE && 0 === count($newValues)) {
                return null;
            }

            $version = 1;
            if ($action !== self::ACTION_CREATE) {
                $version = $ea->getNewVersion($logEntryMeta, $object);
                if (empty($version)) {
                    // was versioned later
                    $version = 1;
                }
            }
            $logEntry->setVersion($version);

            $this->prePersistLogEntry($logEntry, $object);

            $om->persist($logEntry);
            $uow->computeChangeSet($logEntryMeta, $logEntry);

            return $logEntry;
        }

        return null;
    }
}
