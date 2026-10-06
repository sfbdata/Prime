<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Uma seleção de documentos e subpastas da aba Documentos, pronta para uma ação em lote (D4).
 *
 * Duas coisas que mover-lote e excluir-lote fazem igual, num lugar só:
 *
 *  1. **reconferir posse** de cada item (tenant e pasta) — a rota já provou por consulta, isto é a
 *     segunda barreira, para o UseCase não depender de quem o chama;
 *  2. **tirar a redundância**: uma subpasta cujo ancestral também está selecionado vai junto com
 *     ele (ao mover) ou cai no cascade dele (ao excluir); um documento dentro de uma subpasta
 *     selecionada, idem. Agir neles "por conta própria" achataria a árvore ao mover e contaria
 *     duas vezes ao excluir.
 *
 * Só lê `getPai()`/`getSecao()`, já carregados; não dispara consulta.
 */
final class SelecaoDeItensDaPasta
{
    /**
     * @param list<PastaDocumento> $documentos
     * @param list<PastaSecao>     $secoes
     */
    private function __construct(
        public readonly array $documentos,
        public readonly array $secoes,
    ) {
    }

    /**
     * @param list<PastaDocumento> $documentos
     * @param list<PastaSecao>     $secoes
     *
     * @throws AccessDeniedException     item de outro escritório
     * @throws \InvalidArgumentException item de outra pasta, ou seleção vazia
     */
    public static function de(array $documentos, array $secoes, Pasta $pasta, Tenant $tenant): self
    {
        if ($documentos === [] && $secoes === []) {
            throw new \InvalidArgumentException('Nenhum item selecionado.');
        }

        foreach ($secoes as $secao) {
            if ($secao->getTenant() !== $tenant) {
                throw new AccessDeniedException('Pasta não pertence ao tenant do usuário.');
            }
            if ($secao->getPasta() !== $pasta) {
                throw new \InvalidArgumentException('A pasta selecionada não pertence a esta pasta.');
            }
        }

        foreach ($documentos as $documento) {
            if ($documento->getTenant() !== $tenant) {
                throw new AccessDeniedException('Documento não pertence ao tenant do usuário.');
            }
            if ($documento->getPasta() !== $pasta) {
                throw new \InvalidArgumentException('O documento selecionado não pertence a esta pasta.');
            }
        }

        // Por identidade (spl_object_id), nunca por `==`: comparar entidades Doctrine campo a campo
        // desce pelas coleções e é exatamente o laço que `descendeDe()` existe para evitar.
        $secoesEfetivas = [];
        foreach ($secoes as $secao) {
            if (!self::estaDentroDeAlguma($secao->getPai(), $secoes)) {
                $secoesEfetivas[spl_object_id($secao)] = $secao;
            }
        }

        $documentosEfetivos = [];
        foreach ($documentos as $documento) {
            if (!self::estaDentroDeAlguma($documento->getSecao(), $secoesEfetivas)) {
                $documentosEfetivos[spl_object_id($documento)] = $documento;
            }
        }

        return new self(array_values($documentosEfetivos), array_values($secoesEfetivas));
    }

    /**
     * $ponto (uma seção, ou null para a raiz) é uma das $secoes ou descende de uma delas?
     *
     * @param list<PastaSecao> $secoes
     */
    private static function estaDentroDeAlguma(?PastaSecao $ponto, array $secoes): bool
    {
        if ($ponto === null) {
            return false;
        }

        foreach ($secoes as $secao) {
            if ($ponto === $secao || $ponto->descendeDe($secao)) {
                return true;
            }
        }

        return false;
    }
}
