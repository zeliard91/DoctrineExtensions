<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Loggable\Fixture\Parse;

use Gedmo\Loggable\Loggable;
use Gedmo\Mapping\Annotation as Gedmo;
use Redking\ParseBundle\Mapping\Annotations as ORM;
use Redking\ParseBundle\ObjectTrait;
use Redking\ParseBundle\Types\Type;

#[ORM\ParseObject(collection: 'gedmo_loggable_article')]
#[Gedmo\Loggable]
class Article implements Loggable
{
    use ObjectTrait;

    #[ORM\Field(type: Type::STRING)]
    #[Gedmo\Versioned]
    private ?string $title = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }
}
