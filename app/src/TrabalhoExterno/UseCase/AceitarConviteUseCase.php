<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use App\TrabalhoExterno\Service\ConviteDisponivel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Aceitar convite" com a conta já logada (spec §8).
 *
 * Quem: o profissional convidado, logado com o MESMO e-mail do convite.
 * Pré-condições: token conhecido, convite não revogado e dentro da validade.
 * Pós-condição: o convite fica aceito e ligado à conta; nenhum vínculo com escritório é criado.
 * Alternativos: token desconhecido → não encontrado; revogado, vencido ou de outro e-mail → recusa; escritório
 * dono da demanda fora do ar → recusa com a frase do convite vencido, sem revelar a situação
 * (docs/specs/te-convidado-escritorio-fora-do-ar.md).
 */
final class AceitarConviteUseCase
{
    public function __construct(
        private readonly ConviteDemandaRepository $convites,
        private readonly EntityManagerInterface $em,
        private readonly ConviteDisponivel $disponivel,
    ) {
    }

    public function executar(string $token, User $usuario): ConviteDemanda
    {
        $convite = $this->convites->peloToken($token) ?? throw new DemandaNaoEncontrada();
        $this->exigirDisponivel($convite);
        $convite->aceitar($usuario, new \DateTimeImmutable('today'));
        $this->em->flush();

        return $convite;
    }

    private function exigirDisponivel(ConviteDemanda $convite): void
    {
        if (!$this->disponivel->para($convite, new \DateTimeImmutable('today'))) {
            throw new TransicaoInvalida('Este convite não está mais disponível.');
        }
    }
}
