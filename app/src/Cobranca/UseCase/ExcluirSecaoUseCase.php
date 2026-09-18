<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exclui uma seção de documentos de um Caso de Cobrança (SPEC §15, Etapa 6).
 *
 * Decisão de negócio: excluir a seção EXCLUI seus documentos (espelha ExcluirPastaSecaoUseCase do
 * domínio Pasta) — eles caem no banco por cascade:['remove']. Só uma seção do próprio escritório
 * pode ser excluída (guarda multi-tenant, IDOR).
 *
 * **Ordem (E2.5, INV-6):** as chaves de todos os documentos são montadas ANTES de a seção sair
 * (depois do `flush` a coleção pertence a uma entidade removida); a seção é removida e confirmada;
 * só então os arquivos saem, um a um. Falha em um deles não impede os demais nem desfaz a
 * exclusão — vira registro no log e órfão recuperável. Antes da E2.5 os arquivos saíam primeiro, e
 * um `flush` recusado deixava a seção inteira apontando para arquivos apagados.
 */
final class ExcluirSecaoUseCase
{
    public function __construct(
        private readonly CobrancaSecaoRepository $secaoRepository,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function executar(CobrancaSecao $secao, Tenant $tenant): void
    {
        // Guarda multi-tenant: não se exclui seção de outro escritório.
        if ($secao->getTenant() !== $tenant) {
            throw new AccessDeniedException('Seção não pertence ao tenant do usuário.');
        }

        $chaves = [];
        foreach ($secao->getDocumentos() as $documento) {
            $chaves[] = ChavesDeCobranca::documentoDeCaso($documento);
        }

        // Remove a seção; os documentos caem por cascade:['remove'].
        $this->secaoRepository->remover($secao, true);

        $this->remocao->remover($chaves, 'ExcluirSecaoUseCase');
    }
}
