<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Entity\Tenant\Tenant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/** Publicação, revisão e edição do mesmo evento aguardam a conclusão umas das outras. */
final class SerializacaoDaConfiguracao
{
    public function __construct(
        #[Autowire(service: 'lock.voto_configuracao.factory')] private readonly LockFactory $travas,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $trabalho
     *
     * @return T
     */
    public function executar(Tenant $tenant, int $assembleia, callable $trabalho): mixed
    {
        $chave = 'voto_configuracao.' . $tenant->getId() . '.' . $assembleia;
        $trava = $this->travas->createLock($chave);
        if (!$trava->acquire(true)) {
            throw new \RuntimeException('Não foi possível reservar a configuração da votação.');
        }
        try {
            return $trabalho();
        } finally {
            $trava->release();
        }
    }
}
