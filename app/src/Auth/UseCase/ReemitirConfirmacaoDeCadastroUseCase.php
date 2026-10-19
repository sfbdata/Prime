<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\ConfirmacaoReemitidaOutput;
use App\Auth\Repository\CadastroPendenteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Lembrete "E-mail não confirmado" do desenho 05 (docs/specs/comercial-e-emails.md §3.5, P3b): link novo, valendo
 * mais 24 h, uma vez só por cadastro. Quem manda o e-mail é o Comercial; aqui só a regra do cadastro.
 */
final class ReemitirConfirmacaoDeCadastroUseCase
{
    public function __construct(
        private readonly CadastroPendenteRepository $pendentes,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @throws \DomainException se não houver o pendente ou se ele não aceitar outro lembrete */
    public function executar(int $pendenteId): ConfirmacaoReemitidaOutput
    {
        $cadastro = $this->pendentes->find($pendenteId);
        if ($cadastro === null) {
            throw new \DomainException('Cadastro não encontrado.');
        }

        // Mesmo formato do token do IniciarCadastroPublicoUseCase.
        $cadastro->reemitir(bin2hex(random_bytes(32)), $this->clock->now());
        $this->em->flush();

        return new ConfirmacaoReemitidaOutput($cadastro->getEmail(), $cadastro->getNomeCompleto(), $cadastro->getToken());
    }
}
