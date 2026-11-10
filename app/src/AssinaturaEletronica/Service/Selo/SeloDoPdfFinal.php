<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Selo;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use Psr\Log\LoggerInterface;

/**
 * O selo no PDF final do documento concluído (spec assinatura-documentos-etapa-8, E8-4). É o gancho que a
 * implementação de {@see \App\AssinaturaEletronica\Service\PdfFinalDoDocumento} chama antes de guardar o arquivo final:
 * o que ela guarda (e de que tira o `hash_final`) é o que sai daqui.
 *
 * - Selo não configurado: devolve o PDF como veio e não grava nada na trilha.
 * - Selado: grava `pdf_selado` na trilha e devolve o PDF selado.
 * - Falha definitiva: devolve o PDF como veio na hora, grava `selo_nao_aplicado` com o código em `dados.motivo` e faz
 *   `logger->error`.
 * - Falha passageira, de configuração ou `selo_certificado_invalido`: fora da última tentativa, relança a
 *   {@see FalhaNoSelo} para a mensagem do worker tentar de novo (a de configuração faz `logger->error` a cada vez). Na
 *   última, cai como a definitiva.
 *
 * Só no worker: o scheduler e a web não estão na rede do validador (`assinatura_net`, etapa 6, E6-2). A trilha entra
 * na transação de quem chama (a linha do documento fica travada até o COMMIT); **quem chama faz o flush**.
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
     * @param string $pdf             o PDF final da etapa 3, antes do selo
     * @param bool   $ultimaTentativa a última tentativa da mensagem: a falha que tentaria de novo guarda sem o selo
     *
     * @return string o PDF a guardar: o selado, ou o mesmo `$pdf` quando o selo não está configurado ou não saiu
     *
     * @throws FalhaNoSelo falha que tenta de novo, fora da última tentativa
     */
    public function aplicar(DocumentoParaAssinar $doc, string $pdf, bool $ultimaTentativa): string
    {
        try {
            $selado = $this->selador->selar($pdf);
        } catch (FalhaNoSelo $falha) {
            return $this->semSelo($doc, $pdf, $falha, $ultimaTentativa);
        }
        if ($selado === null) {
            return $pdf;
        }

        $this->trilha->acrescentar($doc, TipoDeEvento::PdfSelado, [], null);

        return $selado;
    }

    private function semSelo(DocumentoParaAssinar $doc, string $pdf, FalhaNoSelo $falha, bool $ultimaTentativa): string
    {
        if ($falha->tentaDeNovo() && !$ultimaTentativa) {
            $this->avisarConfiguracao($doc, $falha);

            throw $falha;
        }

        $this->logger->error('Selo do PDF final: documento {documento} guardado sem o selo ({codigo}, {classe}).', [
            'documento' => $doc->getId(),
            'codigo' => $falha->codigo,
            'classe' => $falha->classe->value,
            'situacao' => $falha->situacao,
        ]);
        $this->trilha->acrescentar($doc, TipoDeEvento::SeloNaoAplicado, ['motivo' => $falha->codigo], null);

        return $pdf;
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
}
