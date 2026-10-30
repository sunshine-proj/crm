<?php

namespace App\Entity;

use App\Repository\TaskDependencyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaskDependencyRepository::class)]
#[ORM\Table(name: 'task_dependency')]
#[ORM\UniqueConstraint(name: 'uniq_task_dependency_pair', columns: ['predecessor_id', 'successor_id'])]
class TaskDependency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $predecessor;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Task $successor;

    public function __construct(Task $predecessor, Task $successor)
    {
        if ($predecessor->getId() === $successor->getId() || $predecessor->getProject()?->getId() !== $successor->getProject()?->getId()) {
            throw new \InvalidArgumentException('Task dependencies must connect distinct tasks in one project.');
        }
        $this->predecessor = $predecessor;
        $this->successor = $successor;
    }

    public function getId(): ?int { return $this->id; }
    public function getPredecessor(): Task { return $this->predecessor; }
    public function getSuccessor(): Task { return $this->successor; }
}
