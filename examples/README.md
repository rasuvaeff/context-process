# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `budget.php` | A 10 s command stopped by a 0.5 s budget (SIGTERM, then SIGKILL after the grace period); a command within the budget returning its exit code and streaming its output | no |

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/budget.php
```
