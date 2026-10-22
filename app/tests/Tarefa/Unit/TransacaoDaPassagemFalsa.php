<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\Service\TransacaoDaPassagem;

/**
 * Dublê da {@see TransacaoDaPassagem} para os testes de concluir e reabrir: conta as transações que chegaram ao fim
 * (o `flush` sai no fim da de verdade) e, na releitura da meta, aplica o que outra requisição já gravou no banco.
 */
final class TransacaoDaPassagemFalsa implements TransacaoDaPassagem
{
    public int $concluidas = 0;

    /** @var (\Closure(Tarefa): void)|null */
    public ?\Closure $noBanco = null;

    public function emTransacao(\Closure $trabalho): mixed
    {
        $resultado = $trabalho();
        ++$this->concluidas;

        return $resultado;
    }

    public function travarERecarregar(Tarefa $meta): void
    {
        if ($this->noBanco !== null) {
            ($this->noBanco)($meta);
        }
    }

    public function gravarMetaNova(Tarefa $nova): void
    {
        throw new \LogicException('Concluir e reabrir não criam meta.');
    }
}
