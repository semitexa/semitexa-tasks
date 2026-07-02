<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * One endpoint for every task mutation from the Tasks app. `action` selects the
 * operation: create | start | complete | status | delete. Returns the fresh
 * list so the client re-renders from one response.
 */
#[AsPublicPayload(
    path: '/os/app/tasks/mutate',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class TasksMutatePayload implements ValidatablePayloadInterface
{
    private string $action = '';
    private string $id = '';
    private string $title = '';
    private string $status = '';
    private bool $automated = false;
    private int $etaSeconds = 0;

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        return [];
    }

    public function getAction(): string { return $this->action; }
    public function setAction(string $action): void { $this->action = $action; }

    public function getId(): string { return $this->id; }
    public function setId(string $id): void { $this->id = $id; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }

    public function getAutomated(): bool { return $this->automated; }
    public function setAutomated(bool $automated): void { $this->automated = $automated; }

    public function getEtaSeconds(): int { return $this->etaSeconds; }
    public function setEtaSeconds(int $etaSeconds): void { $this->etaSeconds = $etaSeconds; }
}
