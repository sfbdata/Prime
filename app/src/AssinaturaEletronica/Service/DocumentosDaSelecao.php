<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\Entity\Tenant\Tenant;

/**
 * Carrega os documentos de uma ação em lote, estejam ou não na Lixeira. Tudo ou nada: se um id não é deste escritório
 * (ou não existe), o lote inteiro vira 404 e nada é feito.
 */
final readonly class DocumentosDaSelecao
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
    ) {
    }

    /**
     * @param list<int> $ids sem repetição
     *
     * @return list<DocumentoParaAssinar>
     *
     * @throws DocumentoNaoEncontrado
     */
    public function carregar(array $ids, Tenant $tenant): array
    {
        $documentos = $this->documentos->porIdsMesmoNaLixeira($ids, $tenant);
        if (\count($documentos) !== \count($ids)) {
            throw new DocumentoNaoEncontrado();
        }

        return $documentos;
    }

    /**
     * Dentro da transação da ação: trava a linha de cada documento (na ordem de id em que vieram, para dois lotes não
     * se travarem em cruz) e relê o estado gravado. A decisão pela situação ou pela Lixeira vem depois disto. Se um
     * documento sumiu nesse meio-tempo, o lote inteiro vira 404 e a transação desfaz o que já foi feito.
     *
     * @param list<DocumentoParaAssinar> $documentos
     *
     * @throws DocumentoNaoEncontrado
     */
    public function travar(array $documentos, Tenant $tenant): void
    {
        foreach ($documentos as $documento) {
            if (!$this->documentos->travarERecarregar($documento, $tenant)) {
                throw new DocumentoNaoEncontrado();
            }
        }
    }
}
