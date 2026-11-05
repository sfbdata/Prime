<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Exception\VinculoDaPublicacaoInvalidoException;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;

/**
 * Prazo em dias úteis que o usuário escolhe no modal da publicação (5, 10, 15, 30 ou "Outro"). Nada vem
 * pré-marcado (decisão do dono, 27/10): só vale o que alguém escolheu. `null` limpa a escolha.
 */
final class DefinirPrazoDaPublicacaoUseCase
{
    public const MAXIMO_DE_DIAS = 399;

    public function __construct(private readonly PublicacaoDjenRepository $publicacoes)
    {
    }

    /**
     * @param list<string> $numerosOcultos processos que a pessoa não enxerga pelo cadeado: a publicação deles não existe para ela
     *
     * @throws VinculoDaPublicacaoInvalidoException
     */
    public function executar(Tenant $tenant, int $publicacaoId, ?int $dias, array $numerosOcultos): void
    {
        $publicacao = $this->publicacoes->findOneByIdVisivelDoTenant($publicacaoId, $tenant, $numerosOcultos)
            ?? throw new VinculoDaPublicacaoInvalidoException('Publicação não encontrada.');
        if ($dias !== null && ($dias < 1 || $dias > self::MAXIMO_DE_DIAS)) {
            throw new VinculoDaPublicacaoInvalidoException(sprintf('Informe um prazo entre 1 e %d dias úteis.', self::MAXIMO_DE_DIAS));
        }

        $publicacao->definirPrazoDias($dias);
        $this->publicacoes->flush();
    }
}
