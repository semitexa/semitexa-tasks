<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * ORM resource for one task in the OS task manager (`os_task`).
 *
 * Primary key is a UUIDv7 supplied by the store, so id order is chronological.
 *
 * An `automated` task carries an `eta_seconds` estimate and a `started_at`; the
 * background tick advances `progress` from `elapsed/eta` and flips the task to
 * `done` at 100% — the moment the assistant proactively reports.
 *
 * `final readonly` per the ORM contract: mutations rebuild the row (see TaskStore).
 */
#[FromTable(name: 'os_task')]
#[Index(columns: ['status'], name: 'idx_os_task_status')]
#[Index(columns: ['created_at'], name: 'idx_os_task_created')]
final readonly class TaskResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $title,

        /** One of {@see \Semitexa\Tasks\Domain\Enum\TaskStatus}. */
        #[Column(type: MySqlType::Varchar, length: 16)]
        public string $status,

        /** 0–100. */
        #[Column(type: MySqlType::Int)]
        public int $progress,

        /** Whether the background tick advances + completes this task on its own. */
        #[Column(type: MySqlType::Boolean)]
        public bool $automated,

        /** 'user' | 'assistant' — who created it. */
        #[Column(type: MySqlType::Varchar, length: 16)]
        public string $source,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $created_at,

        /** For automated tasks: expected run duration, drives progress. */
        #[Column(type: MySqlType::Int, nullable: true)]
        public ?int $eta_seconds = null,

        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $deadline = null,

        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $started_at = null,

        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $completed_at = null,
    ) {}
}
