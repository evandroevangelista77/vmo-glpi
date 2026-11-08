<?php

namespace GlpiPlugin\Vmo;

/**
 * Violação de regra de negócio (RN/RF). O código segue a convenção da API do plugin:
 * 422 regra de negócio, 409 conflito de versão, 403 sem direito.
 */
class RuleException extends \RuntimeException
{
    public function __construct(string $message, int $code = 422)
    {
        parent::__construct($message, $code);
    }

    public static function conflict(string $what = 'registro'): self
    {
        return new self("Este {$what} foi alterado por outra pessoa enquanto você editava. Recarregue a página para ver a versão atual e refaça a alteração.", 409);
    }

    public static function denied(string $message = 'Sem permissão para esta operação.'): self
    {
        return new self($message, 403);
    }
}
