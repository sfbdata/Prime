<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\CartaDaAssinatura;
use App\AssinaturaEletronica\DTO\DesfechoDaAcaoPublica;
use App\AssinaturaEletronica\DTO\EntradaDoCertificado;
use App\AssinaturaEletronica\DTO\EvidenciaDaRequisicao;
use App\AssinaturaEletronica\DTO\PdfAssinadoConfirmado;
use App\AssinaturaEletronica\DTO\PdfAssinadoEnviado;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\AssinaturaEletronica\Service\Cadeia\CadeiaIndisponivel;
use App\AssinaturaEletronica\Service\Cadeia\ConferenciaDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\DecisaoDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\RegraDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ValidadorDeCadeia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDoPdfAssinado;
use App\AssinaturaEletronica\Service\CorreioDaAssinatura;
use App\AssinaturaEletronica\Service\PaginaDoSignatario;
use App\AssinaturaEletronica\Service\ResultadoDoPdfAssinado;
use App\AssinaturaEletronica\Service\SerializacaoDaConferencia;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Assinar" da tela pública de quem assina gov.br ou ICP (etapa 5, bloco 5C; etapa 6, E6-4, E6-6 e E6-7): o
 * signatário devolve o PDF que assinou fora do BlueJus, e o arquivo é a prova da assinatura.
 *
 * Na ordem:
 *  1. o signatário pelo token ({@see LinkInvalido} também para quem não é gov.br nem ICP: esta ação não existe para
 *     ele); a vez dele, como na Simples; o validador de cadeia configurado, senão `cadeia_indisponivel`;
 *  2. o formulário, na ordem do desenho (dc:386-388): nome, CPF, CPF igual ao cadastrado (se houver), "Envie o PDF
 *     assinado." (`sem_pdf`) e o aceite;
 *  3. o arquivo, só PDF e até 20 MB ({@see PdfAssinadoEnviado}), senão `ilegivel`;
 *  4. fora da transação, o motor, a regra antes do validador e o CPF do certificado contra o CPF DIGITADO
 *     ({@see ConferenciaDoPdfAssinado}; E5-3);
 *  5. fora da transação (até 45 s), a cadeia no validador ({@see ConferenciaDaCadeia}, com o limitador por
 *     signatário). Validador fora do ar, ocupado ou no limite: `cadeia_indisponivel`, sem gravar nada (E6-4);
 *  6. recusa definitiva do motor, do CPF ou da cadeia: uma transação curta grava `pdf_assinado_recusado` com o código do
 *     catálogo (`motivo`), o código da {@see RegraDaCadeia} (`regra`, nulo na recusa do CPF) e o sha256 do arquivo, e só
 *     depois do COMMIT a recusa é lançada. O arquivo recusado não é guardado;
 *  7. cadeia confirmada, com a linha travada e relida: a vez de novo; a última versão tem de ser a mesma lida antes do
 *     motor (dois signatários sem ordem: quem chega depois recebe `prefixo`, gravado como recusa); o arquivo vira a
 *     versão `devolvida`, com a análise do motor e a resposta do validador (E6-7); a assinatura, `assinado` na trilha
 *     e, se foi o último, a conclusão, como na Simples;
 *  8. depois do COMMIT: os e-mails e, se concluiu, o PDF final ({@see GerarPdfFinalDoDocumento}), como na Simples.
 *
 * A recusa sai como {@see AcaoRecusada} com a chave do texto: a da Simples no formulário e a do catálogo público
 * (`certificado.erro.*`) no arquivo.
 */
final readonly class AssinarComCertificadoUseCase
{
    public function __construct(
        private SignatarioDoLink $link,
        private DocumentoParaAssinarRepository $documentos,
        private VersaoDoPdfRepository $versoes,
        private EventoDaTrilhaRepository $eventos,
        private ValidadorDeCadeia $validador,
        private ConferenciaDoPdfAssinado $conferencia,
        private RegraDaCadeia $regra,
        private ConferenciaDaCadeia $cadeia,
        private TrilhaDoDocumento $trilha,
        private ArmazenamentoDeArquivos $armazenamento,
        private CorreioDaAssinatura $correio,
        private ApresentacaoDoDocumento $apresentacao,
        private PaginaDoSignatario $pagina,
        private TranslatorInterface $tradutor,
        private ClockInterface $relogio,
        private MessageBusInterface $mensagens,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed> o `Publico` depois da assinatura
     *
     * @throws LinkInvalido
     * @throws AcaoRecusada
     */
    public function executar(#[\SensitiveParameter] string $token, EntradaDoCertificado $entrada, EvidenciaDaRequisicao $evidencia): array
    {
        $signatario = $this->signatarioNaVez($token);
        $enviado = self::conferirFormulario($signatario, $entrada);
        $confirmado = $this->conferirArquivo($signatario, $entrada->cpf, $enviado, $evidencia);
        $agora = $this->agora();
        $novas = [];

        $desfecho = $this->documentos->emTransacaoComArquivoNovo(
            function () use ($signatario, $entrada, $confirmado, $evidencia, $agora, &$novas): DesfechoDaAcaoPublica {
                $this->link->travar($signatario);
                $signatario->getDocumento()->exigirVez($signatario, $agora);
                $ordem = $this->ordemDaDevolvida($signatario->getDocumento(), $confirmado);
                if ($ordem === null) {
                    return $this->recusaDaCorrida($signatario, $confirmado->arquivo, $evidencia);
                }
                $this->guardarDevolvida($signatario, $ordem, $confirmado, $agora, $novas);

                return DesfechoDaAcaoPublica::feito($this->registrar($signatario, $entrada, $confirmado, $evidencia, $agora));
            },
            static function () use (&$novas): array {
                /** @var list<ChaveDeArquivo> $novas */
                return $novas;
            },
            'assinatura_eletronica_assinar_com_certificado',
        );
        $desfecho->lancarSeRecusado();
        $this->correio->entregar($desfecho->cartas);
        $this->pedirPdfFinalSeConcluiu($signatario);

        return $this->pagina->publico($signatario, $token, $agora);
    }

    /**
     * @throws LinkInvalido signatário Simples ou Reforçada
     * @throws AcaoRecusada fora da vez ou validador de cadeia não configurado
     */
    private function signatarioNaVez(#[\SensitiveParameter] string $token): Signatario
    {
        $signatario = $this->link->localizar($token);
        if (!$signatario->assinaComCertificado()) {
            throw new LinkInvalido();
        }
        $signatario->getDocumento()->exigirVez($signatario, $this->agora());
        if (!$this->validador->configurado()) {
            throw self::recusa(ResultadoDoPdfAssinado::CADEIA_INDISPONIVEL);
        }

        return $signatario;
    }

    /**
     * O que não depende do banco, na ordem do desenho, e por último o arquivo (tipo e tamanho).
     *
     * @throws AcaoRecusada
     */
    private static function conferirFormulario(Signatario $signatario, EntradaDoCertificado $entrada): PdfAssinadoEnviado
    {
        $entrada->exigirNome();
        $entrada->exigirCpf();
        $cadastrado = preg_replace('/\D/', '', (string) $signatario->getCpf());
        if ($cadastrado !== '' && $cadastrado !== $entrada->cpf) {
            throw new AcaoRecusada('assinatura_eletronica.erro.assinar_cpf_cadastro');
        }
        if ($entrada->pdf === null) {
            throw self::recusa(ResultadoDoPdfAssinado::SEM_PDF);
        }
        if (!$entrada->aceite) {
            throw new AcaoRecusada('assinatura_eletronica.erro.assinar_aceite');
        }

        return PdfAssinadoEnviado::de($entrada->pdf);
    }

    /**
     * O motor e a cadeia, fora da transação. A ordem da última versão é lida antes do motor (ver
     * {@see PdfAssinadoConfirmado}).
     *
     * @throws AcaoRecusada recusa definitiva (gravada) ou cadeia indisponível (nada gravado)
     */
    private function conferirArquivo(Signatario $signatario, string $cpf, PdfAssinadoEnviado $enviado, EvidenciaDaRequisicao $evidencia): PdfAssinadoConfirmado
    {
        $documento = $signatario->getDocumento();
        $ordemDaBase = $this->versoes->ultima($documento)?->getOrdem();
        $conferencia = $this->conferencia->conferir($documento, $enviado->bytes, $cpf);
        if (!$conferencia->aceitou()) {
            $this->recusarGravando($signatario, (string) $conferencia->erro, $this->regraDaRecusa($conferencia->motor), $enviado, $evidencia);
        }
        $motor = $conferencia->motor ?? throw new \LogicException('Conferência aceita sem o resultado do motor.');

        $decisao = $this->conferirCadeia($signatario, $enviado->bytes, $motor);
        if (!$decisao->confirmou()) {
            $this->recusarGravando($signatario, self::erroDaCadeia($decisao), $decisao->motivo, $enviado, $evidencia);
        }

        return PdfAssinadoConfirmado::de($enviado, $conferencia, $ordemDaBase, $decisao);
    }

    /**
     * O código da regra da E6-6 da recusa antes do validador: sem versão base, o motor nem conferiu o prefixo; nulo
     * quando a regra passou e quem recusou foi o CPF.
     */
    private function regraDaRecusa(?ResultadoDaConferencia $motor): ?string
    {
        return $motor === null ? RegraDaCadeia::PREFIXO_NAO_PEDIDO : $this->regra->antesDoValidador($motor);
    }

    /** @throws AcaoRecusada `cadeia_indisponivel`: não configurado, fora do ar, ocupado, tempo esgotado ou no limite */
    private function conferirCadeia(Signatario $signatario, string $pdf, ResultadoDaConferencia $motor): DecisaoDaCadeia
    {
        try {
            return $this->cadeia->conferir($pdf, $motor, $signatario->getNivel(), ConferenciaDaCadeia::quemSignatario((int) $signatario->getId()));
        } catch (CadeiaIndisponivel) {
            throw self::recusa(ResultadoDoPdfAssinado::CADEIA_INDISPONIVEL);
        }
    }

    /** O PDF que o validador não conseguiu ler é `ilegivel`; o resto da recusa dele, `cadeia_recusada`. */
    private static function erroDaCadeia(DecisaoDaCadeia $decisao): string
    {
        return $decisao->cadeia?->ilegivel() === true ? ResultadoDoPdfAssinado::ILEGIVEL : ResultadoDoPdfAssinado::CADEIA_RECUSADA;
    }

    /**
     * A recusa definitiva fora da transação principal: numa transação curta, com a linha travada e a vez conferida de
     * novo, `pdf_assinado_recusado` na trilha; a recusa só é lançada depois do COMMIT.
     *
     * @throws AcaoRecusada sempre
     */
    private function recusarGravando(Signatario $signatario, string $erro, ?string $regra, PdfAssinadoEnviado $enviado, EvidenciaDaRequisicao $evidencia): never
    {
        $this->documentos->emTransacao(function () use ($signatario, $erro, $regra, $enviado, $evidencia): void {
            $this->link->travar($signatario);
            $signatario->getDocumento()->exigirVez($signatario, $this->agora());
            $this->anotarRecusa($signatario, $erro, $regra, $enviado, $evidencia);
        });

        throw self::recusa($erro);
    }

    /** Outra versão entrou depois da base que o motor conferiu: o arquivo não começa pela versão atual. */
    private function recusaDaCorrida(Signatario $signatario, PdfAssinadoEnviado $enviado, EvidenciaDaRequisicao $evidencia): DesfechoDaAcaoPublica
    {
        $this->anotarRecusa($signatario, ResultadoDoPdfAssinado::PREFIXO, RegraDaCadeia::PREFIXO_NAO_CONFERE, $enviado, $evidencia);

        return DesfechoDaAcaoPublica::recusado(self::recusa(ResultadoDoPdfAssinado::PREFIXO));
    }

    private function anotarRecusa(Signatario $signatario, string $erro, ?string $regra, PdfAssinadoEnviado $enviado, EvidenciaDaRequisicao $evidencia): void
    {
        $this->trilha->acrescentar(
            $signatario->getDocumento(),
            TipoDeEvento::PdfAssinadoRecusado,
            ['motivo' => $erro, 'regra' => $regra, 'sha256' => $enviado->sha256],
            null,
            $signatario,
            $evidencia,
        );
    }

    /** A ordem da versão nova; nula se a última versão não é mais a lida antes do motor. */
    private function ordemDaDevolvida(DocumentoParaAssinar $documento, PdfAssinadoConfirmado $confirmado): ?int
    {
        $ultima = $this->versoes->ultima($documento);

        return $ultima !== null && $ultima->getOrdem() === $confirmado->ordemDaBase ? $ultima->getOrdem() + 1 : null;
    }

    /**
     * O arquivo, posto na lista de arquivos novos logo depois de gravado (falhou antes do COMMIT, sai do disco), e a
     * versão `devolvida` com a análise do motor e a cadeia confirmada (E6-7).
     *
     * @param list<ChaveDeArquivo> $novas
     */
    private function guardarDevolvida(Signatario $signatario, int $ordem, PdfAssinadoConfirmado $confirmado, \DateTimeImmutable $agora, array &$novas): void
    {
        $arquivo = $confirmado->arquivo;
        $chave = $this->armazenamento->gravar(ChavesDeAssinaturaEletronica::novaVersao($signatario->getDocumento()), FonteDeConteudo::deTexto($arquivo->bytes))->chave;
        $novas[] = $chave;

        $versao = VersaoDoPdf::devolvida($signatario, $ordem, $chave->nome, $arquivo->nome, $arquivo->sha256, \strlen($arquivo->bytes), SerializacaoDaConferencia::paraJson($confirmado->motor), $agora);
        $confirmado->cadeia->gravarEm($versao, $agora);
        $this->versoes->salvar($versao);
    }

    /**
     * A assinatura, a evidência e, se foi a última, a conclusão. Devolve as cartas a mandar depois do COMMIT (as mesmas
     * da Simples).
     *
     * @return list<CartaDaAssinatura>
     */
    private function registrar(Signatario $signatario, EntradaDoCertificado $entrada, PdfAssinadoConfirmado $confirmado, EvidenciaDaRequisicao $evidencia, \DateTimeImmutable $agora): array
    {
        $documento = $signatario->getDocumento();
        $concluiu = $documento->assinarComCertificado($signatario, $entrada->nome, $entrada->cpf, $evidencia->ip, $evidencia->navegador, $agora);
        $this->trilha->acrescentar($documento, TipoDeEvento::Assinado, $this->evidencias($signatario, $confirmado), null, $signatario, $evidencia);

        if ($concluiu) {
            $this->trilha->acrescentar($documento, TipoDeEvento::DocumentoConcluido, [], null);

            return [CartaDaAssinatura::conclusao($documento)];
        }

        $proximo = $documento->isEmOrdem() ? $documento->primeiroPendente() : null;

        return $proximo !== null
            ? [CartaDaAssinatura::vez($proximo, $this->apresentacao->linkDoSignatario($proximo), $this->eventos->quemEnviou($documento))]
            : [];
    }

    /**
     * Os `dados` do evento `assinado`: o nível como método, o nome do arquivo enviado, quantas assinaturas digitais ele
     * tem, o sha256 dele e o aceite, como na Simples.
     *
     * @return array<string, mixed>
     */
    private function evidencias(Signatario $signatario, PdfAssinadoConfirmado $confirmado): array
    {
        return [
            'metodo' => $signatario->getNivel()->value,
            'arquivo' => $confirmado->arquivo->nome,
            'n' => $confirmado->assinaturas(),
            'sha256' => $confirmado->arquivo->sha256,
            'aceite' => [
                'versao' => PaginaDoSignatario::VERSAO_DO_ACEITE,
                'sha256' => hash('sha256', $this->tradutor->trans(PaginaDoSignatario::chaveDoAceite())),
            ],
            // O CPF digitado foi conferido com o cadastrado (e com o do certificado); sem CPF cadastrado, não havia
            // cadastro com o que conferir.
            'cpf_confere' => (string) $signatario->getCpf() !== '',
        ];
    }

    /**
     * Depois do COMMIT e dos e-mails, só quando esta assinatura concluiu o documento (como na Simples): fila fora do ar
     * só vai para o log; o PDF final sai no primeiro download.
     */
    private function pedirPdfFinalSeConcluiu(Signatario $signatario): void
    {
        $documento = $signatario->getDocumento();
        if ($documento->getSituacao() !== SituacaoDoDocumento::Concluido) {
            return;
        }

        try {
            $this->mensagens->dispatch(new GerarPdfFinalDoDocumento((int) $documento->getId(), (int) $documento->getTenant()->getId()));
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao enfileirar o PDF final do documento {documento}: {erro}', [
                'documento' => $documento->getId(),
                'erro' => $e->getMessage(),
            ]);
        }
    }

    private function agora(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->relogio->now());
    }

    private static function recusa(string $erro): AcaoRecusada
    {
        return new AcaoRecusada(ResultadoDoPdfAssinado::chave($erro));
    }
}
