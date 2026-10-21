<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Survos\WorkflowExtrasBundle\Service\WorkflowCatalog;
use Survos\WorkflowMessengerBundle\Doctrine\DoctrineSubjectStore;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class DoctrineBatchStoreTest extends TestCase
{
    public function testBatchFlushesOnceAfterValidatingEveryReference(): void
    {
        $store = $this->store(1);
        $store->saveBatch([
            [new SubjectReference(StoredBatchItem::class, '1'), new StoredBatchItem('1'), []],
            [new SubjectReference(StoredBatchItem::class, '2'), new StoredBatchItem('2'), []],
        ]);
    }

    public function testMismatchedReferencePreventsFlush(): void
    {
        $store = $this->store(0);
        $this->expectException(\InvalidArgumentException::class);
        $store->saveBatch([
            [new SubjectReference(StoredBatchItem::class, '1'), new StoredBatchItem('1'), []],
            [new SubjectReference(StoredBatchItem::class, 'wrong'), new StoredBatchItem('2'), []],
        ]);
    }

    public function testDiscardClearsTheManagerOnceWithoutFlushing(): void
    {
        $this->store(0, 1)->discardBatch([
            new SubjectReference(StoredBatchItem::class, '1'),
            new SubjectReference(StoredBatchItem::class, '2'),
        ]);
    }

    public function testLoadOfAnUnmanagedSubjectOnlyFinds(): void
    {
        $fresh = new StoredBatchItem('1');
        $manager = $this->loadManager(managed: false);
        $manager->expects(self::never())->method('detach');
        $manager->expects(self::never())->method('refresh');
        $manager->expects(self::once())->method('find')->with(StoredBatchItem::class, '1')->willReturn($fresh);

        self::assertSame($fresh, $this->loadStore($manager)->load(new SubjectReference(StoredBatchItem::class, '1')));
    }

    public function testLoadRefreshesAManagedSubjectWithoutDetachingIt(): void
    {
        // Other code in the same process may hold this very instance (a pending Artifact, say).
        $managed = new StoredBatchItem('1');
        $manager = $this->loadManager(managed: true);
        $manager->expects(self::never())->method('detach');
        $manager->expects(self::once())->method('refresh')->with($managed);
        $manager->method('find')->willReturn($managed);

        self::assertSame($managed, $this->loadStore($manager)->load(new SubjectReference(StoredBatchItem::class, '1')));
    }

    public function testLoadKeepsAManagedSubjectWhenARefreshHitsAReadonlyProperty(): void
    {
        // Doctrine ORM 3.7 refuses to re-assign a readonly mapped object property (BaseMedia::$createdAt).
        $managed = new StoredBatchItem('1');
        $manager = $this->loadManager(managed: true);
        $manager->method('find')->willReturn($managed);
        $manager->method('refresh')->willThrowException(new \LogicException('Attempting to change readonly property'));

        self::assertSame($managed, $this->loadStore($manager)->load(new SubjectReference(StoredBatchItem::class, '1')));
    }

    private function loadManager(bool $managed): EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->rootEntityName = StoredBatchItem::class;
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('tryGetById')->willReturn($managed ? new StoredBatchItem('1') : false);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        $manager->method('getClassMetadata')->willReturn($metadata);

        return $manager;
    }

    private function loadStore(EntityManagerInterface $manager): DoctrineSubjectStore
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);
        $catalog = new WorkflowCatalog(new ServiceLocator([]), ['media' => ['supports' => [StoredBatchItem::class]]]);

        return new DoctrineSubjectStore($registry, $catalog);
    }

    private function store(int $flushes, int $clears = 0): DoctrineSubjectStore
    {
        if (!interface_exists(EntityManagerInterface::class)) {
            self::markTestSkipped('Doctrine ORM is optional.');
        }
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturnCallback(static fn ($subject) => ['id' => $subject->id]);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $manager->expects(self::exactly($flushes))->method('flush');
        $manager->expects(self::exactly($clears))->method('clear');
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);
        $catalog = new WorkflowCatalog(new ServiceLocator([]), ['media' => ['supports' => [StoredBatchItem::class]]]);

        return new DoctrineSubjectStore($registry, $catalog);
    }
}

final readonly class StoredBatchItem
{
    public function __construct(public string $id)
    {
    }
}
