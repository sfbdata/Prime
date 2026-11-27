<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Exception\ExclusaoPedeConfirmacao;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\DocumentosDaSelecao;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;

/**
 * "Excluir definitivamente" na Lixeira (desenho): rascunho e também documento enviado ou assinado, e tudo ou nada.
 * Com algum enviado na seleção, só apaga quando o usuário já viu o aviso das evidências (`$confirmouEnviados`); sem a
 * confirmação nada sai e a resposta é {@see ExclusaoPedeConfirmacao}. A conferência é feita dentro da transação, com as
 * linhas travadas e relidas: uma restauração que chegou antes não é apagada, e um envio que chegou antes exige a
 * confirmação. Documento, signatários, eventos e versões do PDF saem no banco (pelo CASCADE); o PDF, a logo própria, o
 * PDF final, a rubrica e a selfie de cada signatário e o arquivo de cada versão, só depois do COMMIT. A cópia que está
 * na pasta (`pasta_documento`) é outro arquivo e nunca é tocada. Documento enviado na Lixeira é sempre cancelado,
 * concluído, recusado ou com prazo vencido: o signatário apagado faz o link responder "inválido" (404) e o código de
 * validação deixa de ser achado.
 */
final readonly class ExcluirDaLixeiraUseCase
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
     * @throws ExclusaoPedeConfirmacao
     */
    public function executar(Tenant $tenant, array $ids, bool $confirmouEnviados = false): void
    {
        $documentos = $this->selecao->carregar($ids, $tenant);

        // A recusa volta do trabalho em vez de ser lançada lá dentro: nada foi mudado, a transação só solta as travas
        // (exceção dentro dela fecharia o EntityManager por uma simples recusa).
        $resultado = $this->documentos->emTransacao(function () use ($documentos, $tenant, $confirmouEnviados): array|AcaoRecusada|ExclusaoPedeConfirmacao {
            $this->selecao->travar($documentos, $tenant);
            $recusa = self::recusa($documentos, $confirmouEnviados);
            if ($recusa !== null) {
                return $recusa;
            }

            $chaves = $this->arquivosDe($documentos);
            foreach ($documentos as $documento) {
                $this->documentos->remover($documento);
            }

            return $chaves;
        });

        if ($resultado instanceof AcaoRecusada || $resultado instanceof ExclusaoPedeConfirmacao) {
            throw $resultado;
        }

        if ($resultado !== []) {
            $this->remocao->remover($resultado, 'assinatura_eletronica_excluir_da_lixeira');
        }
    }

    /**
     * O que não está na Lixeira recusa primeiro; depois, o enviado sem confirmação (nada foi apagado).
     *
     * @param list<DocumentoParaAssinar> $documentos já travados e relidos
     */
    private static function recusa(array $documentos, bool $confirmouEnviados): AcaoRecusada|ExclusaoPedeConfirmacao|null
    {
        foreach ($documentos as $documento) {
            if (!$documento->podeSairDeVez()) {
                return new AcaoRecusada('assinatura_eletronica.erro.excluir_fora_da_lixeira');
            }
        }

        $enviados = \count(array_filter($documentos, static fn (DocumentoParaAssinar $d): bool => !$d->ehRascunho()));
        if ($enviados > 0 && !$confirmouEnviados) {
            return new ExclusaoPedeConfirmacao($enviados);
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
            $chaves[] = ChavesDeAssinaturaEletronica::pdfFinal($documento);
            foreach ($documento->getSignatarios() as $signatario) {
                $chaves[] = ChavesDeAssinaturaEletronica::rubrica($signatario);
                $chaves[] = ChavesDeAssinaturaEletronica::selfie($signatario);
            }
            foreach ($this->versoes->doDocumento($documento) as $versao) {
                $chaves[] = ChavesDeAssinaturaEletronica::versao($versao);
            }
        }

        return array_values(array_filter($chaves));
    }
}
