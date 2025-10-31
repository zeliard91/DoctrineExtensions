<?php

namespace Gedmo\Loggable\ParseObject\Repository;

use Doctrine\Common\Collections\ArrayCollection;
use Gedmo\Loggable\Document\LogEntry;
use Gedmo\Loggable\LoggableListener;
use Gedmo\Tool\Wrapper\ParseWrapper;
use Redking\ParseBundle\ObjectRepository;

/**
 * The LogEntryRepository has some useful functions
 * to interact with log entries.
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class LogEntryRepository extends ObjectRepository
{
    /**
     * Currently used loggable listener
     *
     * @var LoggableListener
     */
    private $listener;

    /**
     * Loads all log entries for the
     * given $object
     *
     * @param object $object
     * @param null|int $limit
     * @param null|int $skip
     *
     * @return LogEntry[]
     */
    public function getLogEntries($object, $limit = null, $skip = null)
    {
        $wrapped = new ParseWrapper($object, $this->_om);
        $objectId = $wrapped->getIdentifier();

        $qb = $this->createQueryBuilder();
        $qb
            ->field('objectId')->equals($objectId)
            ->field('objectClass')->equals($wrapped->getMetadata()->name)
            ->sort('version', 'DESC')
        ;
        if ($limit) {
            $qb->limit($limit);
        }
        if ($skip) {
            $qb->skip($skip);
        }
        $q = $qb->getQuery();

        $result = $q->execute();

        return $result->toArray();
    }

    public function getNbEntries($object): int
    {
        $wrapped = new ParseWrapper($object, $this->_om);
        $objectId = $wrapped->getIdentifier();

        $qb = $this->createQueryBuilder();
        $qb
            ->field('objectId')->equals($objectId)
            ->field('objectClass')->equals($wrapped->getMetadata()->name)
            ->count()
        ;

        return $qb->getQuery()->execute();
    }

    /**
     * Reverts given $object to $revision by
     * restoring all fields from that $revision.
     * After this operation you will need to
     * persist and flush the $object.
     *
     * @param object $object
     * @param integer $version
     *
     * @throws \Gedmo\Exception\UnexpectedValueException
     *
     * @return void
     */
    public function revert($object, $version = 1)
    {
        $wrapped = new ParseWrapper($object, $this->_om);
        $objectMeta = $wrapped->getMetadata();
        $objectId = $wrapped->getIdentifier();

        $qb = $this->createQueryBuilder();
        $qb->field('objectId')->equals($objectId);
        $qb->field('objectClass')->equals($objectMeta->name);
        $qb->field('version')->lte(intval($version));
        $qb->sort('version', 'ASC');
        $q = $qb->getQuery();

        $logs = $q->execute()->toArray();

        if ($logs) {
            $data = [];
            while (($log = array_shift($logs))) {
                $object->setUpdatedAt($log->getLoggedAt());
                $logData = $log->getData();
                foreach ($logData as $field => $value) {
                    if ($value && $wrapped->isEmbeddedCollectionAssociation($field)) {
                        foreach ($value as $i => $item) {
                            $logData[$field][$i] = array_merge(@$data[$field][$i] ?: [], $item);
                        }
                    }
                }
                $data = array_merge($data, $logData);
            }
            $this->fillDocument($object, $data, $objectMeta);
            
        } else {
            throw new \Gedmo\Exception\UnexpectedValueException('Count not find any log entries under version: '.$version);
        }
    }

    /**
     * Fills a documents versioned fields with data
     *
     * @param object $document
     * @param array $data
     */
    protected function fillDocument($document, array $data)
    {
        $wrapped = new ParseWrapper($document, $this->_om);
        $objectMeta = $wrapped->getMetadata();
        $config = $this->getLoggableListener()->getConfiguration($this->_om, $objectMeta->name);
        $fields = $config['versioned'];
        foreach ($data as $field => $value) {
            if (!in_array($field, $fields)) {
                continue;
            }
            $mapping = $objectMeta->getFieldMapping($field);
            // Fill the embedded document
            if ($wrapped->isEmbeddedCollectionAssociation($field)) {
                if (!empty($value)) {
                    $items = [];
                    foreach ($value as $item) {
                        $items[] = $this->fillEmbeddedDocument($item, $mapping);
                    }
                    $value = new ArrayCollection($items);
                }
            } elseif ($wrapped->isEmbeddedAssociation($field)) {
                $value = $this->fillEmbeddedDocument($value, $mapping);
            } elseif ($objectMeta->isSingleValuedAssociation($field)) {
                $value = $value ? $this->_om->getReference($mapping['targetDocument'], $value) : null;
            }
            $wrapped->setPropertyValue($field, $value);
            unset($fields[$field]);
        }

        /*
        if (count($fields)) {
            throw new \Gedmo\Exception\UnexpectedValueException('Cound not fully revert the document to version: '.$version);
        }
        */
    }

    /**
     * @param $value
     * @param $mapping
     * @return object
     */
    protected function fillEmbeddedDocument($value, $mapping) {
        if (!empty($value)) {
            $embeddedMetadata = $this->_om->getClassMetadata($mapping['targetDocument']);
            $document = $embeddedMetadata->newInstance();
            $this->fillDocument($document, $value);
            return $document;
        }
        return $value;
    }

    /**
     * Get the currently used LoggableListener
     *
     * @throws \Gedmo\Exception\RuntimeException - if listener is not found
     *
     * @return LoggableListener
     */
    private function getLoggableListener()
    {
        if (null === $this->listener) {
            foreach ($this->_om->getEventManager()->getAllListeners() as $event => $listeners) {
                foreach ($listeners as $hash => $listener) {
                    if ($listener instanceof LoggableListener) {
                        $this->listener = $listener;
                        break;
                    }
                }
                if ($this->listener) {
                    break;
                }
            }

            if (null === $this->listener) {
                throw new \Gedmo\Exception\RuntimeException('The loggable listener could not be found');
            }
        }
        return $this->listener;
    }
}
