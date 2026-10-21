<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Proxy;
use Survos\WorkflowExtrasBundle\Service\WorkflowCatalog;
use Survos\WorkflowMessengerBundle\Contract\BatchSubjectStoreInterface;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;

/**
 * Default store for Doctrine entities: the reference is the entity class and its
 * single identifier. Only classes a workflow declares in `supports` are loaded.
 */
final readonly class DoctrineSubjectStore implements BatchSubjectStoreInterface
{
    public function __construct(private ManagerRegistry $doctrine, private WorkflowCatalog $catalog)
    {
    }

    public function reference(object $subject): SubjectReference
    {
        $class = $subject instanceof Proxy ? get_parent_class($subject) : $subject::class;
        $em = $this->manager($class);
        if ($em->getConnection()->isTransactionActive()) {
            throw new \LogicException('Queue workflow transitions only after commit: a worker may not see the subject inside an open transaction. There is no outbox.');
        }
        $id = $em->getClassMetadata($class)->getIdentifierValues($subject);
        $value = 1 === count($id) ? reset($id) : null;
        if (!\is_int($value) && !\is_string($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException(sprintf('Workflow subject "%s" needs one assigned scalar identifier; persist and flush it first.', $class));
        }

        return new SubjectReference($class, (string) $value);
    }

    public function load(SubjectReference $reference): ?object
    {
        $em = $this->manager($reference->type);
        $rootClass = $em->getClassMetadata($reference->type)->rootEntityName;
        $wasManaged = false !== $em->getUnitOfWork()->tryGetById($reference->id, $rootClass);
        $subject = $em->find($reference->type, $reference->id);
        if (null !== $subject && $wasManaged) {
            // A long-running worker may hold a stale identity-map copy, so refresh it. Never detach it:
            // other code in this process (a registry updater with a pending Artifact, say) may already
            // reference this very instance. Doctrine ORM 3.7 refuses to re-assign a readonly mapped
            // property holding an object (BaseMedia::$createdAt), so a refresh that hits one keeps the
            // managed instance, whose other fields were refreshed first. A freshly loaded entity needs
            // no refresh at all.
            try {
                $em->refresh($subject);
            } catch (\LogicException) {
            }
        }

        return $subject;
    }

    public function save(SubjectReference $reference, object $subject, array $context = []): void
    {
        if ($this->reference($subject) != $reference) {
            throw new \InvalidArgumentException('Workflow subject does not match its reference.');
        }
        $this->manager($reference->type)->flush();
    }

    public function saveBatch(array $changes): void
    {
        $managers = [];
        foreach ($changes as [$reference, $subject]) {
            if ($this->reference($subject) != $reference) {
                throw new \InvalidArgumentException('Workflow subject does not match its reference.');
            }
            $manager = $this->manager($reference->type);
            $managers[spl_object_id($manager)] = $manager;
        }
        foreach ($managers as $manager) {
            $manager->flush();
        }
    }

    public function discardBatch(array $references): void
    {
        $managers = [];
        foreach ($references as $reference) {
            $manager = $this->manager($reference->type);
            $managers[spl_object_id($manager)] = $manager;
        }
        foreach ($managers as $manager) {
            $manager->clear();
        }
    }

    private function manager(string $class): EntityManagerInterface
    {
        if (!class_exists($class) || [] === $this->catalog->forClass($class)) {
            throw new \InvalidArgumentException(sprintf('No workflow supports "%s".', $class));
        }
        $em = $this->doctrine->getManagerForClass($class);
        if (!$em instanceof EntityManagerInterface) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a Doctrine ORM entity.', $class));
        }

        return $em;
    }
}
