<?php

namespace Gedmo\Loggable\Mapping\Event\Adapter;

use BackedEnum;
use Gedmo\Loggable\Mapping\Event\LoggableAdapter;
use Gedmo\Mapping\Event\Adapter\Parse as AdapterParse;
use Parse\ParseObject;
use Redking\ParseBundle\UnitOfWork;
use ReflectionClass;

class Parse extends AdapterParse implements LoggableAdapter
{
    private array $objectChangesTracking = [];

    public function getDefaultLogEntryClass()
    {
        return 'Gedmo\\Loggable\\ParseObject\\LogEntry';
    }

    public function isPostInsertGenerator($meta)
    {
        return true;
    }

    public function getNewVersion($meta, $object)
    {
        /**
         * @var \Redking\ParseBundle\ObjectManager
         */
        $om = $this->getObjectManager();
        $objectMeta = $om->getClassMetadata(get_class($object));
        $identifierField = $this->getSingleIdentifierFieldName($objectMeta);
        $objectId = $objectMeta->getReflectionProperty($identifierField)->getValue($object);

        $qb = $om->createQueryBuilder($meta->name);
        $qb->field('objectId')->equals($objectId);
        $qb->field('objectClass')->equals($objectMeta->name);
        $qb->sort('version', 'DESC');
        $qb->limit(1);
        $q = $qb->getQuery();
        $q->setHydrate(false);

        $result = $q->getSingleResult();
        if ($result) {
            return $result->get('version') + 1;
        } else {
            return 1;
        }
    }

    /**
     * @param UnitOfWork $uow
     * @param object $object
     */
    public function getObjectChangeSet($uow, $object): array
    {
        $objectSplId = spl_object_id($object);
        if (!isset($this->objectChangesTracking[$objectSplId])) {
            $this->objectChangesTracking[$objectSplId] = [];
        }

        $changes = $uow->getObjectChangeSet($object);

        /**
         * getObjectChangeSet return objects from Parse DB but the adapter need managed objects
         */
        foreach ($changes as $fieldName => &$_changes) {
            if ($_changes[0] instanceof ParseObject) {
                $refObject = $uow->getManagedObjectFromParseObject($_changes[0]);
                if (null !== $refObject) {
                    $_changes[0] = $refObject;
                }
            }
            if ($_changes[1] instanceof ParseObject) {
                $refObject = $uow->getManagedObjectFromParseObject($_changes[1]);
                if (null !== $refObject) {
                    $_changes[1] = $refObject;
                }
                // case of reference to new object not yet managed, we fetch the new real object so it can be processed later
                else {
                    $refClass = new ReflectionClass($object);
                    $rawValue = $refClass->getProperty($fieldName)->getValue($object);
                    $_changes[1] = $rawValue;
                }
            }
        }

        /**
         * For inserted objects we return all the properties
         */
        if ($object->getId() === null && empty($changes)) {
            $refClass = new ReflectionClass($object);
            $excludedProperties = [
                'id',
                'createdAt',
                'updatedAt',
                '_publicAcl',
                '_rolesAcl',
                '_usersAcl',
            ];
            foreach ($refClass->getProperties() as $property) {
                if (!in_array($property->getName(), $excludedProperties)) {
                    $newValue = $property->getValue($object);
                    if ($newValue instanceof BackedEnum) {
                        $newValue = $newValue->value;
                    }
                    $changes[$property->getName()] = [
                        null,
                        $newValue
                    ];
                }
            }
        }

        $changesChecksum = md5(json_encode($changes));

        if (in_array($changesChecksum, $this->objectChangesTracking[$objectSplId])) {
            // changes already processed
            return [];
        } else {
            $this->objectChangesTracking[$objectSplId][] = $changesChecksum;
        }

        return $changes;
    }
}