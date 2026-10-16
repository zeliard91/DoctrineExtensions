<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Loggable\Fixture\Parse;

use Redking\ParseBundle\Mapping\Annotations as ORM;
use Redking\ParseBundle\ObjectTrait;
use Redking\ParseBundle\Types\Type;

#[ORM\ParseObject(collection: 'gedmo_loggable_category')]
class Category
{
    use ObjectTrait;

    #[ORM\Field(type: Type::STRING)]
    private ?string $name = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }
}
