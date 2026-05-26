<?php

namespace Gedmo\Tool\Wrapper;

use Redking\ParseBundle\ObjectManager;
use Redking\ParseBundle\Proxy\Proxy;

/**
 * Wraps document or proxy for more convenient
 * manipulation
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class ParseWrapper extends AbstractWrapper
{
    /**
     * Document identifier
     *
     * @var mixed
     */
    private $identifier;

    /**
     * True if document or proxy is loaded
     *
     * @var boolean
     */
    private $initialized = false;

    /**
     * Wrap document
     *
     * @param object                                $document
     * @param ObjectManager $om
     */
    public function __construct($document, ObjectManager $om)
    {
        $this->om = $om;
        $this->object = $document;
        $this->meta = $om->getClassMetadata(get_class($this->object));
    }

    /**
     * {@inheritDoc}
     */
    public function getPropertyValue($property)
    {
        $this->initialize();

        return $this->meta->getReflectionProperty($property)->getValue($this->object);
    }

    /**
     * {@inheritDoc}
     */
    public function getRootObjectName()
    {
        return $this->meta->rootDocumentName;
    }

    /**
     * {@inheritDoc}
     */
    public function setPropertyValue($property, $value)
    {
        $this->initialize();
        $this->meta->getReflectionProperty($property)->setValue($this->object, $value);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function hasValidIdentifier()
    {
        return (bool) $this->getIdentifier();
    }

    /**
     * {@inheritDoc}
     */
    public function getIdentifier($single = true)
    {
        if (!$this->identifier) {
            $uow = $this->om->getUnitOfWork();
            // Prefer the UnitOfWork identifier for any managed object (mirrors the
            // ORM EntityWrapper): the reflected `id` property may be null on a
            // partially-loaded object while the UoW still holds the real id.
            if ($uow->isInIdentityMap($this->object)) {
                $this->identifier = (string) $uow->getDocumentIdentifier($this->object);
            } elseif ($this->object instanceof Proxy) {
                $this->initialize();
            }
            if (!$this->identifier) {
                $this->identifier = (string) $this->getPropertyValue($this->meta->identifier);
            }
        }

        return $this->identifier;
    }

    /**
     * Initialize the document if it is proxy
     * required when is detached or not initialized
     */
    protected function initialize()
    {
        if (!$this->initialized) {
            if ($this->object instanceof Proxy) {
                $uow = $this->om->getUnitOfWork();
                if (!$this->object->__isInitialized__) {
                    $persister = $uow->getDocumentPersister($this->meta->name);
                    $identifier = null;
                    if ($uow->isInIdentityMap($this->object)) {
                        $identifier = $this->getIdentifier();
                    } else {
                        // this may not happen but in case
                        $reflProperty = new \ReflectionProperty($this->object, 'identifier');
                        $reflProperty->setAccessible(true);
                        $identifier = $reflProperty->getValue($this->object);
                    }
                    $this->object->__isInitialized__ = true;
                    $persister->load($identifier, $this->object);
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isEmbeddedAssociation($field)
    {
        // return $this->getMetadata()->isSingleValuedEmbed($field);
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function isEmbeddedCollectionAssociation($field)
    {
        // return $this->getMetadata()->isCollectionValuedEmbed($field);
        return false;
    }
}
