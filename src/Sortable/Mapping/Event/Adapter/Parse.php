<?php

namespace Gedmo\Sortable\Mapping\Event\Adapter;

use Gedmo\Mapping\Event\Adapter\Parse as BaseAdapter;
use Gedmo\Sortable\Mapping\Event\SortableAdapter;
use Doctrine\Common\Util\ClassUtils;

/**
 * Doctrine event adapter for Parse adapted
 * for sortable behavior
 *
 * @author Lukas Botsch <lukas.botsch@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
final class Parse extends BaseAdapter implements SortableAdapter
{
    public function getMaxPosition(array $config, $meta, $groups)
    {
        $om = $this->getObjectManager();

        $qb = $om->createQueryBuilder($config['useObjectClass']);
        foreach ($groups as $group => $value) {
            if (is_object($value) && !$om->getMetadataFactory()->isTransient(ClassUtils::getClass($value))) {
                $qb->field($group)->references($value);
            } else {
                $qb->field($group)->equals($value);
            }
        }
        $qb->sort($config['position'], 'desc');
        $object = $qb->getQuery()->getSingleResult();

        if ($object) {
            return $meta->getReflectionProperty($config['position'])->getValue($object);
        }

        return -1;
    }

    public function updatePositions($relocation, $delta, $config)
    {
        $om = $this->getObjectManager();

        $delta = array_map('intval', $delta);

        $qb = $om->createQueryBuilder($config['useObjectClass']);
        $qb->field($config['position'])->gte($delta['start']);
        if ($delta['stop'] > 0) {
            $qb->field($config['position'])->lt($delta['stop']);
        }
        foreach ($relocation['groups'] as $group => $value) {
            if (is_object($value) && !$om->getMetadataFactory()->isTransient(ClassUtils::getClass($value))) {
                $qb->field($group)->references($value);
            } else {
                $qb->field($group)->equals($value);
            }
        }

        $results = $qb->getQuery()->getParseQuery()->find($om->isMasterRequest());;
        $fieldName = $om->getClassMetadata($config['useObjectClass'])->getNameOfField($config['position']);
        
        foreach ($results as $result) {
            $value = $result->get($fieldName);
            $value+=$delta['delta'];
            $result->set($fieldName, $value);
            $result->save();
        }
    }
}
