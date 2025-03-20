<?php

namespace Gedmo\Mapping\Annotation;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\Deprecations\Deprecation;
use Gedmo\Mapping\Annotation\Annotation as GedmoAnnotation;

/**
 * Position annotation for Sortable extension
 *
 * @author Lukas Botsch <lukas.botsch@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 *
 * @Annotation
 * 
 * @Target("PROPERTY")
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Sortable implements GedmoAnnotation
{
    use ForwardCompatibilityTrait;

    /**
     * @var array<string>
     */
    public array $groups = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [], array $groups = [])
    {
        if ([] !== $data) {
            Deprecation::trigger(
                'gedmo/doctrine-extensions',
                'https://github.com/doctrine-extensions/DoctrineExtensions/pull/2374',
                'Passing an array as first argument to "%s()" is deprecated. Use named arguments instead.',
                __METHOD__
            );

            $args = func_get_args();

            $this->groups = $this->getAttributeValue($data, 'groups', $args, 1, $groups);

            return;
        }

        $this->groups = $groups;
    }
}
