<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Selo;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Service\PdfDaAssinatura;
use App\AssinaturaEletronica\Service\PdfNoTemporario;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use Psr\Log\LoggerInterface;

/**
 * O selo no PDF final do documento concluído (spec assinatura-documentos-etapa-8, E8-4). É o gancho que a
 * implementação de {@see \App\AssinaturaEletronica\Service\PdfFinalDoDocumento} chama entre gerar o PDF no temporário
 * e gravá-lo no armazenamento: o que ela grava (e de que tira o `hash_final`) é o arquivo que sai daqui.
 *
 * O pedido, o registro e o aviso são separados: o pedido ao validador (até 45 s) roda fora da transação, a trilha só
 * entra na transação de quem guarda, com a linha do documento travada, e o log só sai depois do COMMIT.
 *
 * - {@see aplicar()}: selado, regrava o PDF selado por cima do mesmo arquivo da área. Não configurado: deixa o arquivo
 *   como veio. Falha definitiva (entre elas o PDF acima do {@see PdfDaAssinatura::TETO_PARA_O_MOTOR}, de 19 MiB, que
 *   nem é lido), ou qualquer falha na última tentativa: deixa o arquivo como veio e devolve a falha. Falha passageira,
 *   de configuração ou `selo_certificado_invalido` fora da última tentativa: relança a {@see FalhaNoSelo} para tentar
 *   de novo (a de configuração faz `logger->error` a cada vez).
 * - {@see registrar()}, na transação: `pdf_selado`, ou `selo_nao_aplicado` com o código em `dados.motivo`; nada quando
 *   o selo não está configurado.
 * - {@see avisar()}, depois do COMMIT: o `logger->error` do PDF guardado sem o selo.
 *
 * O php e o worker estão na rede do validador (`assinatura_net`, etapa 6, E6-2). O worker tenta até 4 vezes (a
 * mensagem leva a tentativa); a web tenta uma vez e, na falha que tenta de novo, passa o pedido ao worker.
 */
final readonly class SeloDoPdfFinal
{
    public function __construct(
        private SeladorDePdf $selador,
        private TrilhaDoDocumento $trilha,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Fora da transação: não grava trilha nem o log do "guardado sem o selo" ({@see registrar()} e {@see avisar()}).
     *
     * @param AreaTemporariaPrivada $area            a área de quem chama, onde está o PDF
     * @param string                $caminho         o PDF final da etapa 3, um arquivo direto na área; selado, é
     *                                               regravado ali
     * @param bool                  $ultimaTentativa a última tentativa: a falha que tentaria de novo deixa o PDF sem o
     *                                               selo
     *
     * @throws FalhaNoSelo       falha que tenta de novo, fora da última tentativa
     * @throws FalhaNoTemporario o caminho não é um arquivo da área, ou não conseguiu medir, ler ou regravar o PDF
     */
    public function aplicar(DocumentoParaAssinar $doc, AreaTemporariaPrivada $area, string $caminho, bool $ultimaTentativa): ResultadoDoSelo
    {
        // Sem configuração nenhum pedido sairia: o PDF nem é lido (e o maior que o teto não vira falha).
        if ($this->selador instanceof SeladorDePdfNaoConfigurado) {
            return ResultadoDoSelo::naoConfigurado();
        }

        try {
            $selado = $this->selador->selar(self::lerAteOTeto($area, $caminho));
        } catch (FalhaNoSelo $falha) {
            return $this->semSelo($doc, $falha, $ultimaTentativa);
        }
        if ($selado === null) {
            return ResultadoDoSelo::naoConfigurado();
        }

        PdfNoTemporario::regravar($area, $caminho, $selado);

        return ResultadoDoSelo::selado();
    }

    /**
     * Leva à trilha o que o {@see aplicar()} fez. Só dentro da transação de quem guarda o PDF final (a trilha trava a
     * linha do documento e exige transação aberta), e só quando é ele quem guarda. Não loga: o aviso é do {@see avisar()}.
     */
    public function registrar(DocumentoParaAssinar $doc, ResultadoDoSelo $resultado): void
    {
        if ($resultado->selou) {
            $this->trilha->acrescentar($doc, TipoDeEvento::PdfSelado, [], null);

            return;
        }

        $falha = $resultado->falha;
        if ($falha === null) {
            return;
        }

        $this->trilha->acrescentar($doc, TipoDeEvento::SeloNaoAplicado, ['motivo' => $falha->codigo], null);
    }

    /**
     * O `logger->error` do PDF guardado sem o selo. Só depois do COMMIT de quem guardou: a transação que falha não deixa
     * no log um "guardado" que não aconteceu.
     */
    public function avisar(DocumentoParaAssinar $doc, ResultadoDoSelo $resultado): void
    {
        $falha = $resultado->falha;
        if ($falha === null) {
            return;
        }

        $this->logger->error('Selo do PDF final: documento {documento} guardado sem o selo ({codigo}, {classe}).', [
            'documento' => $doc->getId(),
            'codigo' => $falha->codigo,
            'classe' => $falha->classe->value,
            'situacao' => $falha->situacao,
        ]);
    }

    private function semSelo(DocumentoParaAssinar $doc, FalhaNoSelo $falha, bool $ultimaTentativa): ResultadoDoSelo
    {
        if ($falha->tentaDeNovo() && !$ultimaTentativa) {
            $this->avisarConfiguracao($doc, $falha);

            throw $falha;
        }

        return ResultadoDoSelo::semSelo($falha);
    }

    /** Erro do nosso lado (token, rota, pedido malformado): o log avisa a cada tentativa, sem esperar a última. */
    private function avisarConfiguracao(DocumentoParaAssinar $doc, FalhaNoSelo $falha): void
    {
        if ($falha->classe !== ClasseDaFalhaNoSelo::Configuracao) {
            return;
        }

        $this->logger->error('Selo do PDF final: erro de configuração no pedido do documento {documento} ({codigo}); confira a URL e o token do validador.', [
            'documento' => $doc->getId(),
            'codigo' => $falha->codigo,
            'situacao' => $falha->situacao,
        ]);
    }

    /**
     * Os bytes do PDF no temporário. Acima do {@see PdfDaAssinatura::TETO_PARA_O_MOTOR} (19 MiB), a falha definitiva sai
     * pelo tamanho, sem ler o arquivo. O teto é o do motor, não os 20 MiB do validador: o selo acrescenta de 10 a 20 KB, e
     * o selado ainda precisa passar no Validar do BlueJus, que recusa acima de 20 MiB (decisão de 10/11).
     *
     * @throws FalhaNoSelo       `pdf_grande_demais`, definitiva
     * @throws FalhaNoTemporario
     */
    private static function lerAteOTeto(AreaTemporariaPrivada $area, string $caminho): string
    {
        if (PdfNoTemporario::tamanho($area, $caminho) > PdfDaAssinatura::TETO_PARA_O_MOTOR) {
            throw new FalhaNoSelo(HttpSeladorDePdf::PDF_GRANDE_DEMAIS, ClasseDaFalhaNoSelo::Definitiva);
        }

        return PdfNoTemporario::ler($area, $caminho);
    }
}
