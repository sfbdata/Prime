<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CriarPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaRepository $pastaRepository,
    ) {}

    public function executar(CriarPastaDTO $dto, User $criadoPor, Tenant $tenant): Pasta
    {
        $nup = trim($dto->nup);

        if ($nup === '') {
            throw new \InvalidArgumentException('O NUP é obrigatório.');
        }

        // NOTA: findOneBy busca pelo valor cru (pré-normalização da entidade).
        // setNup() faz mb_strtoupper(), então "abc" e "ABC" geram conflito silencioso na DB.
        // Bug latente replicado fielmente do controller legado — não corrigir aqui.
        // Escopo explícito por tenant: o NUP é único por escritório (uniq_pasta_tenant_nup).
        // Não dependemos só do filtro global, que fica desligado em CLI (ex.: ImportarAcervoCommand).
        $existente = $this->pastaRepository->findOneBy(['nup' => $nup, 'tenant' => $tenant]);
        if ($existente !== null) {
            throw new \InvalidArgumentException(sprintf('O NUP "%s" já está em uso por outra pasta.', $nup));
        }

        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setNomeCliente($dto->nomeCliente);
        $pasta->setNomeAcao($dto->nomeAcao);
        $pasta->setCriadoPor($criadoPor);
        $pasta->setTenant($tenant);

        $this->em->persist($pasta);
        $this->em->flush();

        return $pasta;
    }
}
