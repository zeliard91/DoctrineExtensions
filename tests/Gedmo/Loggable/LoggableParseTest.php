<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine Behavioral Extensions package.
 * (c) Gediminas Morkevicius <gediminas.morkevicius@gmail.com> http://www.gediminasm.org
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gedmo\Tests\Loggable;

use Doctrine\Common\EventArgs;
use Doctrine\Common\EventManager;
use Doctrine\Common\EventSubscriber;
use Gedmo\Loggable\LoggableListener;
use Gedmo\Loggable\ParseObject\LogEntry;
use Gedmo\Tests\Loggable\Fixture\Parse\Article;
use Gedmo\Tests\Loggable\Fixture\Parse\EnumArticle;
use Gedmo\Tests\Loggable\Fixture\Parse\Status;
use Gedmo\Tests\Tool\BaseTestCaseParse;

/**
 * Integration tests for the Parse adapter of the Loggable extension.
 *
 * Backed by the Parse Server reachable through DOCTRINE_PARSE_* env variables.
 *
 * @group parse
 */
final class LoggableParseTest extends BaseTestCaseParse
{
    private LoggableListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listener = new LoggableListener();
        $this->listener->setUsername('jules');

        $evm = new EventManager();
        $evm->addEventSubscriber($this->listener);

        $this->getDefaultParseObjectManager($evm, [
            __DIR__.'/Fixture/Parse',
            \dirname(__DIR__, 3).'/src/Loggable/ParseObject',
        ]);
    }

    public function testLogCreateUpdateRemove(): void
    {
        $logRepo = $this->om->getRepository(LogEntry::class);
        $articleRepo = $this->om->getRepository(Article::class);
        self::assertCount(0, $logRepo->findAll());

        // CREATE
        $article = new Article();
        $article->setTitle('Title');
        $this->om->persist($article);
        $this->om->flush();
        $this->om->clear();

        $log = $logRepo->findOneBy(['objectId' => $article->getId()]);
        self::assertNotNull($log);
        self::assertSame('create', $log->getAction());
        self::assertSame(Article::class, $log->getObjectClass());
        self::assertSame('jules', $log->getUsername());
        self::assertSame(1, $log->getVersion());
        self::assertSame(['title' => 'Title'], $log->getData());

        // UPDATE
        $article = $articleRepo->findOneBy(['title' => 'Title']);
        self::assertInstanceOf(Article::class, $article);
        $article->setTitle('Updated');
        $this->om->flush();
        $this->om->clear();

        $log = $logRepo->findOneBy(['version' => 2, 'objectId' => $article->getId()]);
        self::assertNotNull($log);
        self::assertSame('update', $log->getAction());
        self::assertSame(['title' => 'Updated'], $log->getData());

        // REMOVE
        $article = $articleRepo->findOneBy(['title' => 'Updated']);
        $articleId = $article->getId();
        $this->om->remove($article);
        $this->om->flush();
        $this->om->clear();

        $log = $logRepo->findOneBy(['version' => 3, 'objectId' => $articleId]);
        self::assertNotNull($log);
        self::assertSame('remove', $log->getAction());
        self::assertNull($log->getData());
    }

    public function testVersionIncrements(): void
    {
        $logRepo = $this->om->getRepository(LogEntry::class);

        $article = new Article();
        $article->setTitle('v1');
        $this->om->persist($article);
        $this->om->flush();

        $article->setTitle('v2');
        $this->om->flush();

        $article->setTitle('v3');
        $this->om->flush();
        $this->om->clear();

        $logs = $logRepo->findBy(['objectId' => $article->getId()], ['version' => 'ASC']);
        self::assertCount(3, $logs);
        self::assertSame(1, $logs[0]->getVersion());
        self::assertSame(2, $logs[1]->getVersion());
        self::assertSame(3, $logs[2]->getVersion());
        self::assertSame(['title' => 'v1'], $logs[0]->getData());
        self::assertSame(['title' => 'v2'], $logs[1]->getData());
        self::assertSame(['title' => 'v3'], $logs[2]->getData());
    }

    /**
     * Regression for commit fd042f1b: when a versioned field holds a BackedEnum
     * value, LogEntry.data must store its scalar `->value`, not the enum object.
     */
    public function testBackedEnumIsStoredAsScalar(): void
    {
        $logRepo = $this->om->getRepository(LogEntry::class);

        $article = new EnumArticle();
        $article->setTitle('Hello');
        $article->setStatus(Status::Draft);
        $this->om->persist($article);
        $this->om->flush();
        $this->om->clear();

        $log = $logRepo->findOneBy(['objectId' => $article->getId()]);
        self::assertNotNull($log);
        $data = $log->getData();
        self::assertArrayHasKey('status', $data);
        self::assertSame(Status::Draft->value, $data['status'], 'BackedEnum must be persisted as its scalar value, not the enum object.');
    }

    /**
     * Regression for commit 3930f9a1: a preUpdate listener that mutates the
     * source object after onFlush (and forces recomputeSingleObjectChangeSet)
     * brings in a new versioned field — postUpdate must enrich the existing
     * UPDATE LogEntry.data with the fresh value.
     */
    public function testPreUpdateMutationsAreCapturedIntoLogEntryData(): void
    {
        $mutator = new class () implements EventSubscriber {
            public function getSubscribedEvents(): array
            {
                return ['preUpdate'];
            }

            public function preUpdate(EventArgs $args): void
            {
                $object = $args->getObject();
                if (!$object instanceof EnumArticle) {
                    return;
                }
                if (Status::Published !== $object->getStatus()) {
                    $object->setStatus(Status::Published);
                    $om = $args->getObjectManager();
                    $meta = $om->getClassMetadata(EnumArticle::class);
                    $om->getUnitOfWork()->recomputeSingleObjectChangeSet($meta, $object);
                }
            }
        };
        $this->om->getEventManager()->addEventSubscriber($mutator);

        $logRepo = $this->om->getRepository(LogEntry::class);

        $article = new EnumArticle();
        $article->setTitle('Hello');
        $article->setStatus(Status::Draft);
        $this->om->persist($article);
        $this->om->flush();

        // Trigger an UPDATE — the preUpdate listener will flip status to Published.
        $article->setTitle('Goodbye');
        $this->om->flush();
        $this->om->clear();

        $updateLog = $logRepo->findOneBy(['version' => 2, 'objectId' => $article->getId()]);
        self::assertNotNull($updateLog);
        $data = $updateLog->getData();
        self::assertArrayHasKey('title', $data, 'Original onFlush field must survive.');
        self::assertSame('Goodbye', $data['title']);
        self::assertArrayHasKey('status', $data, 'preUpdate-induced versioned field must be captured.');
        self::assertSame(Status::Published->value, $data['status']);
    }

    /**
     * Regression for commit 91822239: a postPersist listener that mutates the
     * just-inserted object (typically via a nested flush, which under Parse
     * bypasses the outer onFlush via the commitDepth>1 guard) must still
     * end up reflected in the CREATE LogEntry.data — captured in postFlush.
     */
    public function testPostPersistMutationsAreCapturedIntoCreateLogEntry(): void
    {
        $mutator = new class () implements EventSubscriber {
            public bool $fired = false;

            public function getSubscribedEvents(): array
            {
                return ['postPersist'];
            }

            public function postPersist(EventArgs $args): void
            {
                $object = $args->getObject();
                if (!$object instanceof EnumArticle || $this->fired) {
                    return;
                }
                $this->fired = true;
                $object->setStatus(Status::Published);
                // Nested flush — under Parse this runs at commitDepth=2 and
                // skips onFlush/postFlush, so the CREATE LogEntry would miss
                // this mutation without the postFlush hook from 91822239.
                $args->getObjectManager()->flush($object);
            }
        };
        $this->om->getEventManager()->addEventSubscriber($mutator);

        $logRepo = $this->om->getRepository(LogEntry::class);

        $article = new EnumArticle();
        $article->setTitle('Hello');
        $article->setStatus(Status::Draft);
        $this->om->persist($article);
        $this->om->flush();
        $this->om->clear();

        $createLog = $logRepo->findOneBy(['version' => 1, 'objectId' => $article->getId()]);
        self::assertNotNull($createLog);
        $data = $createLog->getData();
        self::assertArrayHasKey('status', $data);
        self::assertSame(
            Status::Published->value,
            $data['status'],
            'postPersist-induced mutation must end up in the CREATE LogEntry data.',
        );
    }

    /**
     * Regression: when a managed object's reflected `id` property is null (as
     * happened in production with a partially-loaded ReferenceOne under the
     * 'doctrine.do_not_manage' hint), the Loggable Parse adapter must still
     * resolve the identifier from the UnitOfWork — like the ORM adapter — so the
     * LogEntry carries the real objectId, never null.
     */
    public function testUpdateLogUsesUnitOfWorkIdentifierWhenReflectedIdIsNull(): void
    {
        $logRepo = $this->om->getRepository(LogEntry::class);

        $article = new Article();
        $article->setTitle('Title');
        $this->om->persist($article);
        $this->om->flush();
        $realId = $article->getId();
        self::assertNotNull($realId);

        // Simulate the production divergence: the object is still managed in the
        // UoW (correct identifier) but its reflected `id` property is null.
        $this->om->getClassMetadata(Article::class)
            ->getReflectionProperty('id')
            ->setValue($article, null);
        self::assertTrue($this->om->getUnitOfWork()->isInIdentityMap($article));

        // Trigger an UPDATE.
        $article->setTitle('Updated');
        $this->om->flush();
        $this->om->clear();

        $log = $logRepo->findOneBy(['version' => 2, 'objectId' => $realId]);
        self::assertNotNull(
            $log,
            'UPDATE LogEntry must carry the real objectId from the UnitOfWork, not null.'
        );
        self::assertSame('update', $log->getAction());
        self::assertSame(['title' => 'Updated'], $log->getData());
    }

    /**
     * Regression: a postUpdate listener that mutates a versioned field and
     * triggers a NESTED flush (which under Parse runs at commitDepth>1 and skips
     * onFlush) must still have that change recorded. The pending UPDATE LogEntry
     * was already finalized by the first postUpdate, so the nested change would
     * otherwise be lost — postUpdate must create a fresh UPDATE LogEntry for it.
     *
     * Mirrors the production "renseigner_resultat_visite" flow where
     * ActionEventSubscriber::onStatutDone updates the Salarie inside a nested
     * flush after the outer LogEntry was already written.
     */
    public function testPostUpdateNestedFlushMutationsAreVersioned(): void
    {
        $mutator = new class () implements EventSubscriber {
            public bool $fired = false;

            public function getSubscribedEvents(): array
            {
                return ['postUpdate'];
            }

            public function postUpdate(EventArgs $args): void
            {
                $object = $args->getObject();
                if (!$object instanceof EnumArticle || $this->fired) {
                    return;
                }
                if (Status::Published !== $object->getStatus()) {
                    $this->fired = true;
                    $object->setStatus(Status::Published);
                    // Nested flush — under Parse this runs at commitDepth=2 and
                    // skips onFlush, so the status change would be lost from the
                    // audit trail without the postUpdate create-if-missing hook.
                    $args->getObjectManager()->flush($object);
                }
            }
        };
        $this->om->getEventManager()->addEventSubscriber($mutator);

        $logRepo = $this->om->getRepository(LogEntry::class);

        $article = new EnumArticle();
        $article->setTitle('Hello');
        $article->setStatus(Status::Draft);
        $this->om->persist($article);
        $this->om->flush();

        // Trigger an UPDATE; the postUpdate listener flips status via a nested flush.
        $article->setTitle('Goodbye');
        $this->om->flush();
        $this->om->clear();

        $logs = $logRepo->findBy(['objectId' => $article->getId()], ['version' => 'ASC']);
        $data = [];
        foreach ($logs as $log) {
            if ('update' === $log->getAction()) {
                $data = array_merge($data, $log->getData() ?? []);
            }
        }

        self::assertArrayHasKey('title', $data, 'onFlush update field must be recorded.');
        self::assertSame('Goodbye', $data['title']);
        self::assertArrayHasKey('status', $data, 'Nested-flush versioned change must be recorded.');
        self::assertSame(Status::Published->value, $data['status']);
    }

    protected function getUsedFixtures(): array
    {
        return [
            Article::class,
            EnumArticle::class,
            LogEntry::class,
        ];
    }
}
