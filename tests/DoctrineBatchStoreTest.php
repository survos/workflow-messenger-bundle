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

    public function testLoadReplacesAManagedCopyInsteadOfRefreshingIt(): void
    {
        // refresh() re-assigns readonly mapped properties, which Doctrine ORM 3.7 rejects for objects.
        $managed = new StoredBatchItem('1');
        $fresh = new StoredBatchItem('1');
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->rootEntityName = StoredBatchItem::class;
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('tryGetById')->with('1', StoredBatchItem::class)->willReturn($managed);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $manager->expects(self::once())->method('detach')->with($managed);
        $manager->expects(self::never())->method('refresh');
        $manager->expects(self::once())->method('find')->with(StoredBatchItem::class, '1')->willReturn($fresh);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);
        $catalog = new WorkflowCatalog(new ServiceLocator([]), ['media' => ['supports' => [StoredBatchItem::class]]]);

        self::assertSame($fresh, (new DoctrineSubjectStore($registry, $catalog))->load(new SubjectReference(StoredBatchItem::class, '1')));
    }

    public function testLoadDoesNotDetachAnUnmanagedSubject(): void
    {
        $fresh = new StoredBatchItem('1');
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->rootEntityName = StoredBatchItem::class;
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('tryGetById')->willReturn(false);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $manager->expects(self::never())->method('detach');
        $manager->expects(self::never())->method('refresh');
        $manager->method('find')->willReturn($fresh);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);
        $catalog = new WorkflowCatalog(new ServiceLocator([]), ['media' => ['supports' => [StoredBatchItem::class]]]);

        self::assertSame($fresh, (new DoctrineSubjectStore($registry, $catalog))->load(new SubjectReference(StoredBatchItem::class, '1')));
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
