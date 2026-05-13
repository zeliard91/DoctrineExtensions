<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Tool;

use Doctrine\Common\EventManager;
use Gedmo\Loggable\LoggableListener;
use Gedmo\Sluggable\SluggableListener;
use Gedmo\Sortable\SortableListener;
use Gedmo\Timestampable\TimestampableListener;
use Gedmo\Tree\TreeListener;
use Parse\ParseMemoryStorage;
use Parse\ParseObject;
use Parse\ParseQuery;
use PHPUnit\Framework\TestCase;
use Redking\ParseBundle\Configuration;
use Redking\ParseBundle\Mapping\Driver\AttributeDriver;
use Redking\ParseBundle\ObjectManager as ParseObjectManager;

/**
 * Base test case for the Parse adapter — wires a real Parse ObjectManager backed
 * by the Parse Server reachable through the DOCTRINE_PARSE_* env variables.
 *
 * Drops the fixture collections in tearDown so suites stay isolated.
 */
abstract class BaseTestCaseParse extends TestCase
{
    protected ?ParseObjectManager $om = null;

    protected function setUp(): void
    {
        if (!class_exists(ParseObjectManager::class)) {
            self::markTestSkipped('redking/doctrine-parse-bundle is not installed.');
        }

        if (!getenv('DOCTRINE_PARSE_SERVER_URL') || !getenv('DOCTRINE_PARSE_APP_ID')) {
            self::markTestSkipped('Parse Server env variables (DOCTRINE_PARSE_*) are not set.');
        }
    }

    protected function tearDown(): void
    {
        if (null === $this->om) {
            return;
        }

        foreach ($this->getUsedFixtures() as $class) {
            $collection = $this->om->getClassMetadata($class)->getCollection();
            $query = new ParseQuery($collection);
            $query->each(static function (ParseObject $obj): void {
                $obj->destroy(true);
            }, true);
        }

        $this->om = null;
    }

    /**
     * @return array<int, class-string>
     */
    abstract protected function getUsedFixtures(): array;

    /**
     * Build a fully wired Parse ObjectManager — the caller provides the
     * EventManager already populated with the listeners under test.
     *
     * @param string[] $mappingPaths
     */
    protected function getDefaultParseObjectManager(?EventManager $evm = null, array $mappingPaths = []): ParseObjectManager
    {
        $config = new Configuration();
        $config->setAutoGenerateProxyClasses(Configuration::AUTOGENERATE_EVAL);
        $config->setProxyDir(sys_get_temp_dir().'/GedmoParseProxies');
        $config->setProxyNamespace('GedmoParseProxies');
        $config->setConnectionParameters([
            'server_url' => getenv('DOCTRINE_PARSE_SERVER_URL'),
            'app_id' => getenv('DOCTRINE_PARSE_APP_ID'),
            'master_key' => getenv('DOCTRINE_PARSE_MASTER_KEY'),
            'rest_key' => getenv('DOCTRINE_PARSE_REST_KEY'),
            'mount_path' => getenv('DOCTRINE_PARSE_MOUNT_PATH') ?: 'parse',
        ]);
        $config->setMetadataDriverImpl(AttributeDriver::create($mappingPaths));

        $this->om = new ParseObjectManager($config, $evm ?? $this->getEventManager(), new ParseMemoryStorage());

        return $this->om;
    }

    /**
     * Listeners loaded by default — mirrors BaseTestCaseORM. Override in
     * subclasses if a test needs a tighter setup.
     */
    protected function getEventManager(): EventManager
    {
        $evm = new EventManager();
        $evm->addEventSubscriber(new TreeListener());
        $evm->addEventSubscriber(new SluggableListener());
        $evm->addEventSubscriber(new LoggableListener());
        $evm->addEventSubscriber(new TimestampableListener());
        $evm->addEventSubscriber(new SortableListener());

        return $evm;
    }
}
