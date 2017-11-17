<?php

namespace Gedmo\Mapping\Event\Adapter;

use Doctrine\Common\EventArgs;
use Gedmo\Mapping\Event\AdapterInterface;
use Gedmo\Exception\RuntimeException;
use Redking\ParseBundle\Event\LifecycleEventArgs;
use Redking\ParseBundle\ObjectManager;

/**
 * Doctrine event adapter for Parse specific
 * event arguments
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class Parse implements AdapterInterface
{
    /**
     * @var \Doctrine\Common\EventArgs
     */
    private $args;

    /**
     * @var \Redking\ParseBundle\ObjectManager
     */
    private $om;

    /**
     * {@inheritdoc}
     */
    public function setEventArgs(EventArgs $args)
    {
        $this->args = $args;
    }

    /**
     * {@inheritdoc}
     */
    public function getDomainObjectName()
    {
        return 'Object';
    }

    /**
     * {@inheritdoc}
     */
    public function getManagerName()
    {
        return 'default';
    }

    /**
     * {@inheritdoc}
     */
    public function getRootObjectClass($meta)
    {
        return $meta->rootEntityName;
    }

    /**
     * {@inheritdoc}
     */
    public function __call($method, $args)
    {
        if (null === $this->args) {
            throw new RuntimeException("Event args must be set before calling its methods");
        }
        $method = str_replace('Object', $this->getDomainObjectName(), $method);

        return call_user_func_array(array($this->args, $method), $args);
    }

    /**
     * Set the entity manager
     *
     * @param \Redking\ParseBundle\ObjectManager $om
     */
    public function setObjectManager(ObjectManager $om)
    {
        $this->om = $om;
    }

    /**
     * {@inheritdoc}
     */
    public function getObjectManager()
    {
        if (null !== $this->om) {
            return $this->om;
        }

        return $this->__call('getObjectManager', array());
    }

    /**
     * {@inheritdoc}
     */
    public function getObjectState($uow, $object)
    {
        return $uow->getObjectState($object);
    }

    /**
     * {@inheritdoc}
     */
    public function getObjectChangeSet($uow, $object)
    {
        return $uow->getObjectChangeSet($object);
    }

    /**
     * {@inheritdoc}
     */
    public function getSingleIdentifierFieldName($meta)
    {
        return $meta->getIdentifierFieldNames()[0];
    }

    /**
     * {@inheritdoc}
     */
    public function recomputeSingleObjectChangeSet($uow, $meta, $object)
    {
        $uow->recomputeSingleObjectChangeSet($meta, $object);
    }

    /**
     * {@inheritdoc}
     */
    public function getScheduledObjectUpdates($uow)
    {
        return $uow->getScheduleForUpdate();
    }

    /**
     * {@inheritdoc}
     */
    public function getScheduledObjectInsertions($uow)
    {
        return $uow->getScheduleForInsert();
    }

    /**
     * {@inheritdoc}
     */
    public function getScheduledObjectDeletions($uow)
    {
        return $uow->getScheduleForDelete();
    }

    /**
     * {@inheritdoc}
     */
    public function setOriginalObjectProperty($uow, $oid, $property, $value)
    {
        $uow->setOriginalObjectProperty($oid, $property, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function clearObjectChangeSet($uow, $oid)
    {
        $uow->clearObjectChangeSet($oid);
    }

    /**
     * Creates a ORM specific LifecycleEventArgs.
     *
     * @param object                                $object
     * @param \Redking\ParseBundle\ObjectManager $objectManager
     *
     * @return \Doctrine\ODM\MongoDB\Event\LifecycleEventArgs
     */
    public function createLifecycleEventArgsInstance($object, $objectManager)
    {
        return new LifecycleEventArgs($object, $objectManager);
    }
}
