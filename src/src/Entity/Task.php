<?php

namespace App\Entity;

use App\Repository\TaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'task')]
class Task
{
	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	private ?int $id = null;

	#[ORM\Column(length: 255)]
	private ?string $title = null;

	#[ORM\Column(type: Types::TEXT)]
	private ?string $description = null;

	#[ORM\ManyToOne(targetEntity: Project::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	private ?Project $project = null;

	#[ORM\ManyToOne(targetEntity: User::class)]
	#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
	private ?User $assignee = null;

	#[ORM\Column(length: 20, enumType: TaskStatus::class, options: ['default' => 'todo'])]
	private TaskStatus $status = TaskStatus::Todo;

	#[ORM\Column(length: 20, enumType: TaskPriority::class, options: ['default' => 'normal'])]
	private TaskPriority $priority = TaskPriority::Normal;

	#[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
	private ?\DateTimeImmutable $dueDate = null;

	#[ORM\Column]
	private ?\DateTimeImmutable $startDatetime = null;

	#[ORM\Column]
	private ?\DateTimeImmutable $endDatetime = null;

	#[ORM\Column]
	private ?\DateTimeImmutable $createdAt = null;

	#[ORM\Column]
	private ?\DateTimeImmutable $updatedAt = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getTitle(): ?string
	{
		return $this->title;
	}

	public function setTitle(string $title): static
	{
		$this->title = $title;

		return $this;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function setDescription(string $description): static
	{
		$this->description = $description;

		return $this;
	}

	public function getProject(): ?Project
	{
		return $this->project;
	}

	public function setProject(?Project $project): static
	{
		$this->project = $project;

		return $this;
	}

	public function getAssignee(): ?User
	{
		return $this->assignee;
	}

	public function setAssignee(?User $assignee): static
	{
		$this->assignee = $assignee;

		return $this;
	}

	public function getStatus(): TaskStatus
	{
		return $this->status;
	}

	public function setStatus(TaskStatus $status): static
	{
		$this->status = $status;

		return $this;
	}

	public function isOpen(): bool
	{
		return !in_array($this->status, [TaskStatus::Done, TaskStatus::Cancelled], true);
	}

	public function getPriority(): TaskPriority
	{
		return $this->priority;
	}

	public function setPriority(TaskPriority $priority): static
	{
		$this->priority = $priority;

		return $this;
	}

	public function getDueDate(): ?\DateTimeImmutable
	{
		return $this->dueDate;
	}

	public function setDueDate(?\DateTimeImmutable $dueDate): static
	{
		$this->dueDate = $dueDate;

		return $this;
	}

	public function getStartDatetime(): ?\DateTimeImmutable
	{
		return $this->startDatetime;
	}

	public function setStartDatetime(\DateTimeImmutable $startDatetime): static
	{
		return $this->setSchedule($startDatetime, $this->endDatetime);
	}

	public function getEndDatetime(): ?\DateTimeImmutable
	{
		return $this->endDatetime;
	}

	public function setEndDatetime(\DateTimeImmutable $endDatetime): static
	{
		return $this->setSchedule($this->startDatetime, $endDatetime);
	}

	public function setSchedule(?\DateTimeImmutable $startDatetime, ?\DateTimeImmutable $endDatetime): static
	{
		if ($startDatetime !== null && $endDatetime !== null && $endDatetime < $startDatetime) {
			throw new \InvalidArgumentException('Task end must be on or after its start.');
		}

		$this->startDatetime = $startDatetime;
		$this->endDatetime = $endDatetime;

		return $this;
	}

	public function getCreatedAt(): ?\DateTimeImmutable
	{
		return $this->createdAt;
	}

	public function setCreatedAt(\DateTimeImmutable $createdAt): static
	{
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getUpdatedAt(): ?\DateTimeImmutable
	{
		return $this->updatedAt;
	}

	public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
	{
		$this->updatedAt = $updatedAt;

		return $this;
	}
}
