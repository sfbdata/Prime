<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CriarPastaDTO;
use Doctrine\ORM\EntityManagerInterface;

final class CriarPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GerarNumeroDePasta $gerarNumero,
    ) {}

    public function executar(CriarPastaDTO $dto, User $criadoPor, Tenant $tenant): Pasta
    {
        // A transação é o que dá validade à trava por escritório do GerarNumeroDePasta: a
        // advisory lock só segura até o commit, então gerar o número e gravar a pasta têm de
        // estar dentro da MESMA transação. Separar os dois reabre a corrida que esta mudança
        // veio fechar. `use_savepoints: true` (doctrine.yaml) deixa isso aninhar em quem já
        // abriu transação — o dry-run do ReconciliarCommand é o caso real.
        return $this->em->wrapInTransaction(function () use ($dto, $criadoPor, $tenant): Pasta {
            $nup = $dto->nup === null ? '' : trim($dto->nup);

            // Em branco = automático. Antes daqui isso era erro ("O NUP é obrigatório");
            // agora o sistema atribui, que é o requisito R1 do dono.
            if ($nup === '') {
                $nup = $this->gerarNumero->executar($tenant);
            }

            // NUP ainda pode repetir quando vem explícito (acervo/Drive trazem 10A/10B e
            // números repetidos): a identidade de sincronização é o driveFolderId, não o NUP.
            $pasta = new Pasta();
            $pasta->setNup($nup);
            $pasta->setNomeCliente($dto->nomeCliente);
            $pasta->setNomeAcao($dto->nomeAcao);
            $pasta->setCriadoPor($criadoPor);
            $pasta->setTenant($tenant);

            $this->em->persist($pasta);
            $this->em->flush();

            return $pasta;
        });
    }
}
