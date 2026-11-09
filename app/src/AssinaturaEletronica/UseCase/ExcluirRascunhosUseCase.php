<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\DocumentosDaSelecao;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;

/**
 * "Excluir definitivamente" na Lixeira (desenho): só rascunho, e tudo ou nada. Se algum marcado foi enviado, nada sai
 * e a resposta é "Documentos enviados permanecem na Lixeira para preservar as evidências.". Documento, signatários e
 * eventos saem no banco; o PDF e a logo própria, só depois do COMMIT.
 */
final readonly class ExcluirRascunhosUseCase
{
    public function __construct(
        private DocumentosDaSelecao $selecao,
        private DocumentoParaAssinarRepository $documentos,
        private RemocaoAposTransacao $remocao,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @throws DocumentoNaoEncontrado
     * @throws AcaoRecusada
     */
    public function executar(Tenant $tenant, array $ids): void
    {
        $documentos = $this->selecao->carregar($ids, $tenant);
        self::exigirRascunhosNaLixeira($documentos);
        $chaves = self::arquivosDe($documentos);

        $this->documentos->emTransacao(function () use ($documentos): void {
            foreach ($documentos as $documento) {
                $this->documentos->remover($documento);
            }
        });

        if ($chaves !== []) {
            $this->remocao->remover($chaves, 'assinatura_eletronica_excluir_rascunho');
        }
    }

    /**
     * @param list<DocumentoParaAssinar> $documentos
     *
     * @throws AcaoRecusada
     */
    private static function exigirRascunhosNaLixeira(array $documentos): void
    {
        foreach ($documentos as $documento) {
            if (!$documento->ehRascunho()) {
                throw new AcaoRecusada('assinatura_eletronica.erro.excluir_enviado');
            }
        }

        foreach ($documentos as $documento) {
            if (!$documento->podeSairDeVez()) {
                throw new AcaoRecusada('assinatura_eletronica.erro.excluir_fora_da_lixeira');
            }
        }
    }

    /**
     * @param list<DocumentoParaAssinar> $documentos
     *
     * @return list<ChaveDeArquivo>
     */
    private static function arquivosDe(array $documentos): array
    {
        $chaves = [];
        foreach ($documentos as $documento) {
            $chaves[] = ChavesDeAssinaturaEletronica::arquivo($documento);
            $chaves[] = ChavesDeAssinaturaEletronica::logo($documento);
        }

        return array_values(array_filter($chaves));
    }
}
