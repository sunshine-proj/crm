<?php

namespace App\Entity;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Done = 'done';
    case Cancelled = 'cancelled';
}