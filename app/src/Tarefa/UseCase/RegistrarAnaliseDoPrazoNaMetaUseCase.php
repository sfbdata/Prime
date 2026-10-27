<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\DTO\AnalisarPrazoDaMetaInput;
use App\Tarefa\Enum\EstadoDoPrazoJuridico;

/**
 * Guarda na meta nova o que a análise do prazo concluiu. O estado é refeito aqui com as datas recebidas: o
 * navegador não decide se o prazo está confirmado. Estimado ou "necessita conferência" não grava: a pessoa
 * confirma a análise, informa o prazo jurídico ou corrige as informações antes (desenho 02, "Confirmar análise").
 */
final class RegistrarAnaliseDoPrazoNaMetaUseCase
{
    public function __construct(private readonly AnalisarPrazoDaMetaNovaUseCase $analisar)
    {
    }

    /** @throws \DomainException quando o prazo jurídico ainda é só uma estimativa */
    public function executar(Tarefa $meta, AnalisarPrazoDaMetaInput $input, \DateTimeImmutable $hoje): void
    {
        $analise = $this->analisar->executar($input, $hoje);
        if (null === $analise) {
            return;
        }
        if (EstadoDoPrazoJuridico::Estimado === $analise->estado || EstadoDoPrazoJuridico::NecessitaConferencia === $analise->estado) {
            throw new \DomainException('Confirme a análise do prazo ou informe o prazo jurídico antes de criar a meta.');
        }

        $usouSugerido = $meta->getPrazo()?->format('Y-m-d') === $analise->prazoInterno->format('Y-m-d');
        $meta->registrarAnaliseDoPrazo($analise->prazoJuridico, $analise->estado, $input->publicacao, $input->ciencia, $usouSugerido);
    }
}
