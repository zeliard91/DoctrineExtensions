<?php

namespace Gedmo\Loggable\ParseObject;

use Gedmo\Loggable\ParseObject\Repository\LogEntryRepository;
use Redking\ParseBundle\Mapping\Annotations as ORM;

#[ORM\ParseObject(collection: "Gedmo_LogEntry",repositoryClass:LogEntryRepository::class)]
class LogEntry extends MappedSuperclass\AbstractLogEntry
{
    /**
     * All required columns are mapped through inherited superclass
     */
}