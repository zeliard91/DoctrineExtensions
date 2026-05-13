<?php

namespace Gedmo\Loggable\ParseObject\MappedSuperclass;

use DateTime;
use Redking\ParseBundle\Mapping\Annotations as ORM;
use Redking\ParseBundle\Types\Type;

#[ORM\MappedSuperclass()]
abstract class AbstractLogEntry
{
    #[ORM\Id()]
    protected ?string $id = null;

    #[ORM\Field(type:Type::DATE,nullable:true)]
    protected ?DateTime $createdAt = null;

    #[ORM\Field(Type::DATE,nullable:true)]
    protected ?DateTime $updatedAt = null;

    #[ORM\Field(type:Type::STRING)]
    protected string $action;

    #[ORM\Field(type:Type::DATE)]
    protected DateTime $loggedAt;

    #[ORM\Field(name:'myObjectId',type:Type::STRING,nullable:true)]
    protected ?string $objectId = null;

    #[ORM\Field(type:Type::STRING)]
    protected string $objectClass;

    #[ORM\Field(type:Type::INTEGER)]
    protected int $version;

    #[ORM\Field(type:Type::TOBJECT,nullable:true)]
    protected ?array $data = null;

    #[ORM\Field(type:Type::STRING,nullable:true)]
    protected ?string $username = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->getObjectClass() . '['. $this->getObjectId() .'] - ' . $this->getAction();
    }

    public function setCreatedAt(?\DateTime $createdAt = null): static
    {
        $this->createdAt = $createdAt;
    
        return $this;
    }

    public function getCreatedAt(): null|\DateTime 
    {
        return $this->createdAt;
    }

    public function setUpdatedAt(?\DateTime $updatedAt = null): static
    {
        $this->updatedAt = $updatedAt;
    
        return $this;
    }

    public function getUpdatedAt(): null|\DateTime 
    {
        return $this->updatedAt;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getObjectClass(): string
    {
        return $this->objectClass;
    }

    public function setObjectClass(string $objectClass): static
    {
        $this->objectClass = $objectClass;

        return $this;
    }

    public function getObjectId(): ?string
    {
        return $this->objectId;
    }

    public function setObjectId(?string $objectId): static
    {
        $this->objectId = $objectId;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getLoggedAt(): DateTime
    {
        return $this->loggedAt;
    }

    public function setLoggedAt(): static
    {
        $this->loggedAt = new DateTime();

        return $this;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function setData(?array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function setVersion(int $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}