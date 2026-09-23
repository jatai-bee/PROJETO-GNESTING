<?php /** @var int $status @var string $message @var string|null $errorId @var Throwable|null $exception */ ?>
<?= $this->partial('errors/body', [
    'status' => $status,
    'heading' => 'Acesso negado',
    'message' => $message,
    'errorId' => $errorId,
    'exception' => $exception,
]) ?>
