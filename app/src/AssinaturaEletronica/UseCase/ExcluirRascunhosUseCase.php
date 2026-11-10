<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\DocumentosDaSelecao;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;

/**
 * "Excluir definitivamente" na Lixeira (desenho): só rascunho, e tudo ou nada. Se algum marcado foi enviado, nada sai
 * e a resposta é "Documentos enviados permanecem na Lixeira para preservar as evidências.". A conferência é feita
 * dentro da transação, com as linhas travadas e relidas: um envio ou uma restauração que chegou antes não é apagado.
 * Documento, signatários, eventos e versões do PDF saem no banco (as versões pelo CASCADE); o PDF, a logo própria e o
 * arquivo de cada versão (etapa 5: o original já assinado tem arquivo próprio), só depois do COMMIT.
 */
final readonly class ExcluirRascunhosUseCase
{
    public function __construct(
        private DocumentosDaSelecao $selecao,
        private DocumentoParaAssinarRepository $documentos,
        private RemocaoAposTransacao $remocao,
        private VersaoDoPdfRepository $versoes,
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

        // A recusa volta do trabalho em vez de ser lançada lá dentro: nada foi mudado, a transação só solta as travas
        // (exceção dentro dela fecharia o EntityManager por uma simples recusa).
        $resultado = $this->documentos->emTransacao(function () use ($documentos, $tenant): array|AcaoRecusada {
            $this->selecao->travar($documentos, $tenant);
            $recusa = self::recusa($documentos);
            if ($recusa !== null) {
                return $recusa;
            }

            $chaves = $this->arquivosDe($documentos);
            foreach ($documentos as $documento) {
                $this->documentos->remover($documento);
            }

            return $chaves;
        });

        if ($resultado instanceof AcaoRecusada) {
            throw $resultado;
        }

        if ($resultado !== []) {
            $this->remocao->remover($resultado, 'assinatura_eletronica_excluir_rascunho');
        }
    }

    /**
     * Enviado no lote recusa primeiro (o texto do desenho); depois, o que não está na Lixeira.
     *
     * @param list<DocumentoParaAssinar> $documentos já travados e relidos
     */
    private static function recusa(array $documentos): ?AcaoRecusada
    {
        foreach ($documentos as $documento) {
            if (!$documento->ehRascunho()) {
                return new AcaoRecusada('assinatura_eletronica.erro.excluir_enviado');
            }
        }

        foreach ($documentos as $documento) {
            if (!$documento->podeSairDeVez()) {
                return new AcaoRecusada('assinatura_eletronica.erro.excluir_fora_da_lixeira');
            }
        }

        return null;
    }

    /**
     * As chaves lidas antes de apagar: depois do `remover`, o CASCADE leva as linhas das versões.
     *
     * @param list<DocumentoParaAssinar> $documentos
     *
     * @return list<ChaveDeArquivo>
     */
    private function arquivosDe(array $documentos): array
    {
        $chaves = [];
        foreach ($documentos as $documento) {
            $chaves[] = ChavesDeAssinaturaEletronica::arquivo($documento);
            $chaves[] = ChavesDeAssinaturaEletronica::logo($documento);
            foreach ($this->versoes->doDocumento($documento) as $versao) {
                $chaves[] = ChavesDeAssinaturaEletronica::versao($versao);
            }
        }

        return array_values(array_filter($chaves));
    }
}
