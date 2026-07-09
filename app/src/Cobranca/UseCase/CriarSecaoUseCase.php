<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Cria uma seção (pasta lógica) de documentos para um Caso de Cobrança (SPEC §15, Etapa 6).
 *
 * História: o gestor organiza os documentos do caso em seções nomeadas (ex.: "TERMOS", "COMPROVANTES").
 * A seção nasce ao final da lista (próxima ordem) e sempre pertence ao mesmo escritório do caso —
 * um caso de outro tenant nunca recebe seção (guarda multi-tenant, IDOR). O nome é normalizado pela
 * própria entidade (upper/trim); aqui só barramos nome vazio como erro de entrada.
 */
final class CriarSecaoUseCase
{
    public function __construct(
        private readonly CobrancaSecaoRepository $secaoRepository,
    ) {
    }

    public function executar(CasoCobranca $caso, string $nome, Tenant $tenant): CobrancaSecao
    {
        // Guarda multi-tenant: não se cria seção em caso de outro escritório.
        if ($caso->getTenant() !== $tenant) {
            throw new AccessDeniedException('Caso não pertence ao tenant do usuário.');
        }

        if (trim($nome) === '') {
            throw new \InvalidArgumentException('O nome da seção é obrigatório.');
        }

        $secao = (new CobrancaSecao())
            ->setCaso($caso)
            ->setTenant($tenant)
            ->setNome($nome)
            ->setOrdem($this->secaoRepository->proximaOrdem($caso));

        $this->secaoRepository->salvar($secao, true);

        return $secao;
    }
}
