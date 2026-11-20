<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use LogicException;
use PDO;
use Throwable;

final readonly class TransactionManager
{
    public function __construct(private PDO $connection) {}

    /**
     * Execute one application use-case in a single database transaction.
     *
     * @template T
     * @param callable(PDO): T $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        if ($this->connection->inTransaction()) {
            throw new LogicException('Nested transaction boundaries are not supported.');
        }

        $this->connection->beginTransaction();

        try {
            $result = $operation($this->connection);
            if (!$this->connection->inTransaction()) {
                throw new LogicException('The operation ended its transaction unexpectedly.');
            }
            $this->connection->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                try {
                    $this->connection->rollBack();
                } catch (Throwable $rollbackFailure) {
                    error_log(sprintf(
                        'POS_V3 transaction_rollback_failed exception=%s',
                        $rollbackFailure::class,
                    ));
                }
            }
            throw $exception;
        }
    }
}
