<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\Cadeia\RegraDaCadeia;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDoCpf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * A conferência do PDF que o signatário gov.br ou ICP devolveu assinado, na parte que vem antes do validador de cadeia
 * (etapa 5, bloco 5C): a pré-conferência da tela pública e a assinatura com certificado usam a mesma. Nunca grava nada
 * e nunca chama o validador.
 *
 * Na ordem do desenho (dc:385): a versão base é a última guardada do documento ({@see VersaoDoPdfRepository::ultima()},
 * E5-1), e sem ela o arquivo não tem o que preservar (`prefixo`); o motor confere o arquivo contra ela e o CPF esperado;
 * a regra da E6-6 recusa o que não precisa do validador ({@see RegraDaCadeia::antesDoValidador()}); e o CPF do
 * certificado tem de ser o esperado (E5-3).
 *
 * Os códigos da regra viram os do catálogo público: motor ilegível `ilegivel`; sem assinatura `sem_assinatura`;
 * assinatura alterada e acréscimo que muda o conteúdo ou que não foi conferido `alterada` (quem muda a página no
 * próprio acréscimo assina outro conteúdo, M27); prefixo que não confere ou não pedido `prefixo`; nenhuma assinatura
 * nova `sem_assinatura_nova`. O CPF: certificado sem CPF `sem_cpf`; CPF diferente `cpf_diferente`; CPF que o motor não
 * pôde conferir (assinatura não íntegra ou certificado não vinculado, M26) `cadeia_recusada`.
 *
 * O arquivo da versão base é lido até um byte além do teto do motor (20 MB, E5-8): o PDF devolvido começa por ela e
 * também não pode passar do teto. Arquivo da versão ausente ou pane do armazenamento sobem: é falha nossa, não do
 * arquivo enviado.
 */
final readonly class ConferenciaDoPdfAssinado
{
    public function __construct(
        private VersaoDoPdfRepository $versoes,
        private ArmazenamentoDeArquivos $armazenamento,
        private ConferenciaDePdf $motor,
        private RegraDaCadeia $regra,
    ) {
    }

    /**
     * @param string      $pdf         os bytes do PDF devolvido (até 20 MB)
     * @param string|null $cpfEsperado o CPF cadastrado do signatário; nulo sem ele (o motor não confere o CPF)
     *
     * @throws ArquivoNaoEncontrado o arquivo da versão base sumiu
     * @throws FalhaDeArmazenamento
     */
    public function conferir(DocumentoParaAssinar $documento, string $pdf, ?string $cpfEsperado): ResultadoDoPdfAssinado
    {
        $base = $this->versoes->ultima($documento);
        if ($base === null) {
            return ResultadoDoPdfAssinado::recusado(ResultadoDoPdfAssinado::PREFIXO, null);
        }

        $motor = $this->motor->conferir($pdf, $this->conteudo($base), $cpfEsperado);
        $erro = self::erroDaRegra($this->regra->antesDoValidador($motor)) ?? self::erroDoCpf($motor->cpf);

        return $erro === null ? ResultadoDoPdfAssinado::aceito($motor) : ResultadoDoPdfAssinado::recusado($erro, $motor);
    }

    /** @throws \LogicException código da regra que este mapa não conhece (a regra ganhou um caso novo) */
    private static function erroDaRegra(?string $motivo): ?string
    {
        return match ($motivo) {
            null => null,
            RegraDaCadeia::MOTOR_ILEGIVEL => ResultadoDoPdfAssinado::ILEGIVEL,
            RegraDaCadeia::SEM_ASSINATURA => ResultadoDoPdfAssinado::SEM_ASSINATURA,
            RegraDaCadeia::ASSINATURA_ALTERADA,
            RegraDaCadeia::ACRESCIMO_MUDA_CONTEUDO,
            RegraDaCadeia::ACRESCIMO_NAO_CONFERIDO => ResultadoDoPdfAssinado::ALTERADA,
            RegraDaCadeia::PREFIXO_NAO_CONFERE, RegraDaCadeia::PREFIXO_NAO_PEDIDO => ResultadoDoPdfAssinado::PREFIXO,
            RegraDaCadeia::SEM_ASSINATURA_NOVA => ResultadoDoPdfAssinado::SEM_ASSINATURA_NOVA,
            default => throw new \LogicException(\sprintf('Código da regra da cadeia sem erro no catálogo: %s.', $motivo)),
        };
    }

    /**
     * Depois da regra há assinatura nova, e o alvo do CPF do motor (a última que não é carimbo) é uma delas: o "sem
     * assinatura" do CPF não acontece aqui, e se acontecer é o mesmo que nenhuma assinatura nova.
     */
    private static function erroDoCpf(ConferenciaDoCpf $cpf): ?string
    {
        return match ($cpf) {
            ConferenciaDoCpf::NaoPedida, ConferenciaDoCpf::Confere => null,
            ConferenciaDoCpf::SemCpfNoCertificado => ResultadoDoPdfAssinado::SEM_CPF,
            ConferenciaDoCpf::NaoConfere => ResultadoDoPdfAssinado::CPF_DIFERENTE,
            ConferenciaDoCpf::NaoConferido => ResultadoDoPdfAssinado::CADEIA_RECUSADA,
            ConferenciaDoCpf::SemAssinatura => ResultadoDoPdfAssinado::SEM_ASSINATURA_NOVA,
        };
    }

    /** Os bytes da versão base, até um byte além do teto do motor. */
    private function conteudo(VersaoDoPdf $versao): string
    {
        $recurso = $this->armazenamento->abrir(ChavesDeAssinaturaEletronica::versao($versao));

        try {
            $conteudo = stream_get_contents($recurso, LimitesDaConferencia::PDF_MAXIMO_EM_BYTES + 1);
        } finally {
            fclose($recurso);
        }

        return $conteudo !== false ? $conteudo : throw new FalhaDeArmazenamento('Não foi possível ler a versão base do PDF assinado.');
    }
}
