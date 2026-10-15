# rasuvaeff/context-process

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/context-process/v)](https://packagist.org/packages/rasuvaeff/context-process)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/context-process/downloads)](https://packagist.org/packages/rasuvaeff/context-process)
[![Build](https://github.com/rasuvaeff/context-process/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/context-process/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/context-process/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/context-process/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/context-process/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/context-process/php)](https://packagist.org/packages/rasuvaeff/context-process)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Запускает внешние процессы (ImageMagick, wkhtmltopdf, git, ffmpeg, ваши
скрипты) под бюджетом [`rasuvaeff/context`](https://github.com/rasuvaeff/context).
Когда дедлайн прошёл или контекст отменён, процесс действительно
останавливается — SIGTERM, затем SIGKILL после grace-периода, — а не
дорабатывает после ухода вызывающего.

> Пользуетесь AI-ассистентом? В [llms.txt](llms.txt) — компактный справочник по API, который можно передать модели.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills)
> дополнительно получают agent-скилл этого пакета в `.agents/skills/`
> автоматически при установке.

## Требования

- PHP 8.3+
- `rasuvaeff/context` ^0.3
- `symfony/process` ^7.0 или ^8.0

## Установка

```bash
composer require rasuvaeff/context-process
```

## Ограничения

- **Сигнал получает только сам процесс.** Передавайте команду argv-массивом
  (`new Process(['convert', $in, $out])`), а не shell-строкой: с
  `Process::fromShellCommandline()` SIGTERM получит shell, а его дочерние
  процессы могут продолжить работу.
- **Grace-период не ограничен бюджетом**: процесс, обрабатывающий SIGTERM,
  получает `$gracePeriod` секунд на очистку даже после дедлайна.
- **Завершение замечается в пределах одного интервала опроса**
  (`$pollInterval`, по умолчанию 50 мс); отмена, запрошенная из обработчика
  сигнала, будит ожидание сразу.
- **Windows**: сигналов нет — symfony/process завершает процесс
  (`taskkill`) вместо SIGTERM/SIGKILL.

## Использование

```php
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Process\ContextProcess;
use Symfony\Component\Process\Process;

try {
    $exitCode = ContextProcess::run(
        new Process(['convert', $in, '-resize', '50%', $out]),
        $context,                                   // например, контекст запроса или задачи
        callback: static fn(string $type, string $chunk) => $log->debug($chunk),
        gracePeriod: 2.0,                           // SIGTERM → ждём → SIGKILL
    );
} catch (ContextExpiredException) {
    // бюджет кончился: процесс уже остановлен
}
```

| Ситуация | Что происходит |
|---|---|
| Процесс завершился вовремя | Возвращается его код выхода |
| Дедлайн прошёл | Процесс останавливается (SIGTERM, SIGKILL после `gracePeriod`); `ContextExpiredException` |
| Контекст отменён (в том числе через `requestCancel()` из обработчика сигнала, например [`rasuvaeff/context-cli`](https://github.com/rasuvaeff/context-cli)) | Процесс останавливается; `ContextCancelledException` с причиной в `previous` |
| Контекст уже завершён | Процесс не запускается |
| Сработал собственный `setTimeout()` / `setIdleTimeout()` процесса | symfony/process останавливает его; `ProcessTimedOutException` пробрасывается без изменений |
| Callback бросил исключение | Процесс останавливается; исключение callback пробрасывается |
| Процесс уже запущен | `LogicException` |

Бюджет не превращается в собственный таймаут процесса: symfony/process по
таймауту убивает без grace-периода, а дедлайн контекста идёт тем же путём
SIGTERM → grace → SIGKILL, что и отмена.

### Публичный API

| Класс | Описание |
|---|---|
| `ContextProcess` | `run(Process $process, Context $context, ?callable $callback = null, float $gracePeriod = 2.0, float $pollInterval = 0.05): int` |

## Безопасность

Аргументы передаются в symfony/process argv-массивом, никогда через shell.
Пакет не добавляет в командную строку ничего своего.

## Примеры

Исполняемые скрипты — в [examples/](examples/).

| Скрипт | Что показывает | Нужен сервер? |
|---|---|---|
| `budget.php` | Команда на 10 с, остановленная бюджетом 0,5 с; команда в пределах бюджета, возвращающая код выхода и вывод | нет |

## Разработка

PHP/Composer на хосте нет — всё в Docker через образ `composer:2`:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

## Лицензия

[BSD-3-Clause](LICENSE.md)
