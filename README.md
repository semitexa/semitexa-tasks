# Semitexa Tasks

`semitexa/tasks`

The task manager for Semitexa OS: an ORM-backed task list with status, progress and deadlines, and an assistant integration that reports tasks the system completed on its own.

## Install

Not included by the installer. Add it to an existing project from the project root:

```bash
docker compose run --rm --no-deps --user "$(id -u):$(id -g)" app composer require semitexa/tasks
bin/semitexa server:restart
bin/semitexa orm:sync
```

It depends on `semitexa/os` (not in the installer's set); Composer installs it with it.

## What it provides

- The `os_task` table (statuses: `todo`, `in_progress`, `blocked`, `done`, `cancelled`).
- The **Tasks** OS app at `/os/app/tasks`, with `/os/app/tasks/list` and `/os/app/tasks/mutate`.
- Assistant skills: `Tasks` (open the app), `create-task`, `list-tasks`, `complete-task`.
- A per-worker timer (on worker 0 only) that advances automated tasks and completes them at their ETA, plus `bin/semitexa tasks:tick` to run one tick by hand.

## Documentation

Commands: https://semitexa.com/docs/reference/commands-tasks

## License

MIT, see [LICENSE](LICENSE).
