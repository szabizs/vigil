<?php

namespace Materom\Vigil\Hooks;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * @internal
 */
final class LogRecordProcessor implements ProcessorInterface
{
    private FormatterInterface $formatter;

    /**
     * @param  Core<RequestState|CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
        private string $dateFormat,
    ) {
        //
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            /** @var array<string, mixed> */
            $formatted = $this->formatter()->format($record);

            return $record->with(
                message: $formatted['message'] ?? '',
                context: $formatted['context'] ?? [],
                level: $record->level,
                channel: $formatted['channel'] ?? '',
                datetime: $record->datetime,
                extra: $formatted['extra'] ?? [],
            );
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }

        return $record;
    }

    private function formatter(): FormatterInterface
    {
        return $this->formatter ??= new class($this->dateFormat) extends NormalizerFormatter
        {
            protected function formatDate(DateTimeInterface $date): string
            {
                return parent::formatDate(
                    DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))
                );
            }
        };
    }
}
