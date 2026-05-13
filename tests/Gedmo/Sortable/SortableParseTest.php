<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Sortable;

use Doctrine\Common\EventManager;
use Gedmo\Sortable\SortableListener;
use Gedmo\Tests\Sortable\Fixture\Parse\Node;
use Gedmo\Tests\Tool\BaseTestCaseParse;

/**
 * Integration tests for the Parse adapter of the Sortable extension.
 *
 * @group parse
 */
final class SortableParseTest extends BaseTestCaseParse
{
    protected function setUp(): void
    {
        parent::setUp();

        $evm = new EventManager();
        $evm->addEventSubscriber(new SortableListener());

        $this->getDefaultParseObjectManager($evm, [
            __DIR__.'/Fixture/Parse',
        ]);
    }

    public function testFirstInsertedNodeGetsPositionZero(): void
    {
        $node = $this->createNode('Node1');
        $this->om->persist($node);
        $this->om->flush();

        self::assertSame(0, $node->getPosition());
    }

    public function testMultipleInsertsAreSequenced(): void
    {
        $nodes = [];
        for ($i = 1; $i <= 4; ++$i) {
            $node = $this->createNode('Node'.$i);
            $this->om->persist($node);
            $nodes[] = $node;
        }
        $this->om->flush();

        self::assertSame(0, $nodes[0]->getPosition());
        self::assertSame(1, $nodes[1]->getPosition());
        self::assertSame(2, $nodes[2]->getPosition());
        self::assertSame(3, $nodes[3]->getPosition());
    }

    /**
     * Regression for commit d78c15fc — the Parse Sortable adapter must compute
     * the max position using a raw ParseQuery, not by reloading an existing object
     * (which would clobber any in-memory mutation). Tested indirectly: a new node
     * inserted after a clear/reload still receives the right next position.
     */
    public function testNextPositionIsComputedFromPersistedState(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $node = $this->createNode('Node'.$i);
            $this->om->persist($node);
        }
        $this->om->flush();
        $this->om->clear();

        $extra = $this->createNode('Extra');
        $this->om->persist($extra);
        $this->om->flush();

        self::assertSame(3, $extra->getPosition(), 'New node must take the next free position after a clear/reload cycle.');
    }

    /**
     * Regression for commit f9e4de61 — the adapter must propagate the master-request
     * flag when re-saving relocated objects after a position move. Without it, the
     * Parse Server would reject the relocation save() under a master-only ACL.
     */
    public function testMovingPositionRelocatesOtherNodes(): void
    {
        $nodes = [];
        for ($i = 1; $i <= 4; ++$i) {
            $node = $this->createNode('Node'.$i);
            $this->om->persist($node);
            $nodes[] = $node;
        }
        $this->om->flush();

        // Move the first node to the last position. This should not raise any
        // "unauthorized" error from the Parse Server (master key fix).
        $nodes[0]->setPosition(3);
        $this->om->flush();
        $this->om->clear();

        $repo = $this->om->getRepository(Node::class);
        $byName = [];
        foreach ($repo->findAll() as $n) {
            $byName[$n->getName()] = $n->getPosition();
        }

        self::assertSame(3, $byName['Node1']);
        self::assertSame(0, $byName['Node2']);
        self::assertSame(1, $byName['Node3']);
        self::assertSame(2, $byName['Node4']);
    }

    public function testNodesAreGroupedByPath(): void
    {
        $rootA = $this->createNode('A1', '/a');
        $rootA2 = $this->createNode('A2', '/a');
        $rootB = $this->createNode('B1', '/b');

        $this->om->persist($rootA);
        $this->om->persist($rootA2);
        $this->om->persist($rootB);
        $this->om->flush();

        self::assertSame(0, $rootA->getPosition());
        self::assertSame(1, $rootA2->getPosition());
        self::assertSame(0, $rootB->getPosition(), 'Different group must restart positions at 0.');
    }

    protected function getUsedFixtures(): array
    {
        return [Node::class];
    }

    private function createNode(string $name, string $path = '/'): Node
    {
        $node = new Node();
        $node->setName($name);
        $node->setPath($path);

        return $node;
    }
}
