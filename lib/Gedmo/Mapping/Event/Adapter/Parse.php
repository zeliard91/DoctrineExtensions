<?php

namespace Gedmo\Mapping\Event\Adapter;

use Doctrine\Common\EventArgs;
use Doctrine\Common\Persistence\Event\LifecycleEventArgs;
use Gedmo\Mapping\Event\AdapterInterface;
use Gedmo\Exception\RuntimeException;
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
        return $uow->getEntityState($object);
    }

    /**
     * {@inheritdoc}
     */
    public function getObjectChangeSet($uow, $object)
    {
        return $uow->getEntityChangeSet($object);
    }

    /**
     * {@inheritdoc}
     */
    public function getSingleIdentifierFieldName($meta)
    {
        return $meta->getSingleIdentifierFieldName();
    }

    /**
     * {@inheritdoc}
     */
    public function recomputeSingleObjectChangeSet($uow, $meta, $object)
    {
        $uow->recomputeSingleEntityChangeSet($meta, $object);
    }

    /**
     * {@inheritdoc}
     */
    public function getScheduledObjectUpdates($uow)
    {
        return $uow->getScheduledEntityUpdates();
    }

    /**
     * {@inheritdoc}
     */
    public function getScheduledObjectInsertions($uow)
    {
        return $uow->getScheduledEntityInsertions();
    }

    /**
     * {@inheritdoc}
     */
    public function getScheduledObjectDeletions($uow)
    {
        return $uow->getScheduledEntityDeletions();
    }

    /**
     * {@inheritdoc}
     */
    public function setOriginalObjectProperty($uow, $oid, $property, $value)
    {
        $uow->setOriginalEntityProperty($oid, $property, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function clearObjectChangeSet($uow, $oid)
    {
        $uow->clearEntityChangeSet($oid);
    }

    /**
     * Creates a ORM specific LifecycleEventArgs.
     *
     * @param object                                $document
     * @param \Doctrine\ODM\MongoDB\DocumentManager $documentManager
     *
     * @return \Doctrine\ODM\MongoDB\Event\LifecycleEventArgs
     */
    public function createLifecycleEventArgsInstance($document, $documentManager)
    {
        return new LifecycleEventArgs($document, $documentManager);
    }
}
