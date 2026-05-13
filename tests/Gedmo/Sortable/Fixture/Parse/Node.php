<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Sortable\Fixture\Parse;

use Gedmo\Mapping\Annotation as Gedmo;
use Redking\ParseBundle\Mapping\Annotations as ORM;
use Redking\ParseBundle\ObjectTrait;
use Redking\ParseBundle\Types\Type;

#[ORM\ParseObject(collection: 'gedmo_sortable_node')]
class Node
{
    use ObjectTrait;

    #[ORM\Field(type: Type::STRING)]
    private ?string $name = null;

    #[Gedmo\SortableGroup]
    #[ORM\Field(type: Type::STRING)]
    private ?string $path = null;

    #[Gedmo\SortablePosition]
    #[ORM\Field(type: Type::INTEGER)]
    private ?int $position = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setPath(?string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPosition(?int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }
}
