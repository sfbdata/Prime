<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Tarefa\DTO\AnalisarPrazoDaMetaInput;
use App\Tarefa\DTO\AnaliseDoPrazo;
use App\Tarefa\DTO\EntradaDaAnaliseDoPrazo;
use App\Tarefa\Service\AnaliseDoPrazoDaMeta;

/**
 * A análise do prazo da Nova meta (desenho 02): o motor `AnaliseDoPrazoDaMeta`, chamado a cada alteração do modal.
 * Não grava nada. Devolve null enquanto o texto é curto demais para a análise (menos de 6 letras).
 */
final class AnalisarPrazoDaMetaNovaUseCase
{
    public function __construct(private readonly AnaliseDoPrazoDaMeta $analise)
    {
    }

    public function executar(AnalisarPrazoDaMetaInput $input, \DateTimeImmutable $hoje): ?AnaliseDoPrazo
    {
        return $this->analise->analisar(new EntradaDaAnaliseDoPrazo(
            $input->titulo . "\n" . $input->descricao,
            $hoje->setTime(0, 0),
            $input->publicacao,
            $input->ciencia,
            $input->prazoJuridicoInformado,
            $input->confirmado,
        ));
    }
}
