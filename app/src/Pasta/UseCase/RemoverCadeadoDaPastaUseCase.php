<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CadeadoDaPastaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\CadeadoRecusadoException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Repository\CadeadoDaPastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Remover cadeado" do modal (desenho L543, `cd.remover`): a pasta volta a ser livre para a equipe. Fica
 * no histórico ("Cadeado: Cadeado removido") e na auditoria da conta. Só quem configura (K1); pasta sem
 * cadeado não tem o que remover (o botão nem aparece).
 */
final class RemoverCadeadoDaPastaUseCase
{
    public function __construct(
        private readonly CadeadoDaPastaRepository $cadeados,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Pasta $pasta, User $ator, Tenant $tenant): CadeadoDaPastaOutput
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->acessoAPasta->podeConfigurar($ator, $tenant)) {
            throw new CadeadoRecusadoException(CadeadoRecusadoException::MENSAGEM);
        }

        $cadeado = $this->cadeados->daPasta($pasta);
        if ($cadeado === null) {
            throw new \DomainException('Esta pasta não tem cadeado.');
        }

        $this->cadeados->remover($cadeado);
        $this->registro->registrar($pasta, $ator, 'cadeado', 'Cadeado removido');
        $this->em->flush();
        $this->acessoAPasta->esquecer();

        return CadeadoDaPastaOutput::livre();
    }
}
