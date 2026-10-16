<?php

namespace Gedmo\Loggable\Mapping\Event\Adapter;

use BackedEnum;
use Gedmo\Loggable\Mapping\Event\LoggableAdapter;
use Gedmo\Mapping\Event\Adapter\Parse as AdapterParse;
use Parse\ParseObject;
use Redking\ParseBundle\Mapping\ClassMetadata;
use Redking\ParseBundle\Types\Type;
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
        // Prefer the UnitOfWork identifier for a managed object (mirrors the ORM
        // adapter): the reflected `id` property may be null on a partially-loaded
        // object while the UoW still holds the real id.
        $uow = $om->getUnitOfWork();
        if ($uow->isInIdentityMap($object)) {
            $objectId = $uow->getDocumentIdentifier($object);
        } else {
            $identifierField = $this->getSingleIdentifierFieldName($objectMeta);
            $objectId = $objectMeta->getReflectionProperty($identifierField)->getValue($object);
        }

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
            $meta = $this->getObjectManager()->getClassMetadata(get_class($object));
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
                        $this->encryptIfNeeded($meta, $property->getName(), $newValue)
                    ];
                }
            }
        }

        $changesChecksum = md5(json_encode($this->normalizeForChecksum($changes)));

        if (in_array($changesChecksum, $this->objectChangesTracking[$objectSplId])) {
            // changes already processed
            return [];
        } else {
            $this->objectChangesTracking[$objectSplId][] = $changesChecksum;
        }

        return $changes;
    }

    /**
     * Replaces the objects of a change set by scalars identifying them before it is hashed.
     *
     * json_encode() of a PHP 8.4 native lazy ghost initializes it, i.e. queries Parse for a
     * reference only replaced in this change set, and encodes any object without public
     * property as {}, so that changes differing only by the referenced object got the same
     * checksum and the later ones were taken as already logged.
     */
    private function normalizeForChecksum(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeForChecksum($item), $value);
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if ($value instanceof ParseObject) {
            return $value->getClassName().'#'.$value->getObjectId();
        }
        if (\is_object($value)) {
            $uow = $this->getObjectManager()->getUnitOfWork();
            $id = $uow->isInIdentityMap($value) ? $uow->getDocumentIdentifier($value) : null;

            return \get_class($value).'#'.($id ?? 'new-'.spl_object_id($value));
        }

        return $value;
    }

    /**
     * Encrypts the value of an `encrypted_string` field read straight off the PHP object.
     *
     * The changeset the UnitOfWork computes already holds the ciphertext, produced by
     * applyChangesToParseObject(); but the branch above rebuilds the changeset by reflection,
     * and a PHP property holds the cleartext — that is the whole point of the type. Logging it
     * as-is would write passwords in clear into Gedmo_LogEntry, which is exactly what encrypting
     * the field is meant to prevent.
     *
     * The IV is random, so this is not the very ciphertext stored in Parse; it is a different
     * encryption of the same value, and decrypts to the same cleartext with the same key.
     *
     * Public because LoggableListener::readCurrentVersionedData() also re-reads versioned fields
     * by reflection and calls this when the adapter provides it.
     *
     * @param ClassMetadata $meta
     * @param mixed         $value
     *
     * @return mixed
     */
    public function encryptIfNeeded($meta, string $field, $value)
    {
        if (null === $value || !$meta->hasField($field) || Type::ENCRYPTED_STRING !== $meta->getTypeOfField($field)) {
            return $value;
        }

        return Type::getType(Type::ENCRYPTED_STRING)->convertToDatabaseValue($value);
    }
}