<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\ArquivoParaEntregar;
use App\AssinaturaEletronica\Enum\OrigemDaVersao;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\Entity\Tenant\Tenant;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Baixar original com as assinaturas" (etapa 5, bloco 5A; o `presOrig` do desenho, dc:383): a versão mais recente do
 * PDF que chegou assinado (o original ou o último devolvido), byte a byte, com o nome "{nome} - original.pdf". O PDF
 * para assinar congelado não conta. Documento de outro escritório ou sem essa versão: não encontrado.
 */
final readonly class ObterOriginalComAssinaturasUseCase
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private VersaoDoPdfRepository $versoes,
        private TranslatorInterface $tradutor,
    ) {
    }

    /** @throws DocumentoNaoEncontrado */
    public function executar(Tenant $tenant, int $id): ArquivoParaEntregar
    {
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();
        $versao = $this->versoes->ultima($documento);
        if ($versao === null || $versao->getOrigem() === OrigemDaVersao::ParaAssinar) {
            throw new DocumentoNaoEncontrado();
        }

        return new ArquivoParaEntregar(
            ChavesDeAssinaturaEletronica::versao($versao),
            $this->tradutor->trans('assinatura_eletronica_tela.js.ja_assinado.nome_original', ['nome' => $documento->getNome()]),
        );
    }
}
