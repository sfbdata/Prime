<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Service\PontosDoRelatorioInicial;

/**
 * As pendências que acendem a linha vermelha sob as abas da pasta (desenho
 * "02 - EXPEDIENTES 1.2.3", padrão PJe). Calculadas AQUI, não no template: a tela
 * não decide nada — só mostra o que o servidor já concluiu.
 *
 * Só regra com dado REAL por trás; sinal decorativo seria mentira na tela:
 *  · Metas      → meta não concluída;
 *  · Processo   → nenhum processo vinculado;
 *  · Financeiro → contrato pendente e sem pró-bono (regra primária do desenho:
 *                 contrato assinado OU pró-bono = pasta regularizada), somando os
 *                 pagamentos vencidos quando houver — e SÓ para quem vê o financeiro
 *                 (`VisibilidadeDoFinanceiroDaPasta`): "N pagamentos vencidos" é dado
 *                 financeiro, na linha da aba e no resumo impresso;
 *  · Documentos → item do checklist MARCADO como entregue sem nenhum arquivo
 *                 correspondente na pasta (`ConferenciaDeAnexosDoChecklist`, DOC-74/76);
 *  · Detalhes   → ponto do relatório inicial de atendimento sem registro
 *                 (`PontosDoRelatorioInicial`, a heurística `relInicial()` do desenho);
 *  · Push       → publicação não lida.
 *
 * Documentos junta as duas metades do desenho: "N documentos cobrados e ainda não anexados"
 * (cobrança automática do checklist, spec pasta-cobranca-do-checklist) e "N itens marcados sem
 * anexo". Detalhes só acende
 * quando quem chama manda os pontos do relatório: a pasta_show e o resumo impresso mandam
 * (o `imprimirResumo()` do desenho, dc L.4832, lista as pendências de todas as abas).
 */
final readonly class PastaPendenciasOutput
{
    /**
     * @param array<string, array{n: int, txt: string}> $porAba id da aba (`tarefas`, `processo`,
     *                                                           `financeiro`, `documentos`, `detalhes`, `push`) => pendência;
     *                                                           só as abas que têm pendência
     */
    public function __construct(
        public array $porAba,
    ) {}

    /**
     * @param PastaPagamento[] $pagamentos os pagamentos da pasta (os mesmos do cartão Pagamentos)
     *                                    `$pushNaoLidas` é `PastaPushOutput::naoLidas`.
     *                                    `$itensMarcadosSemAnexo` é
     *                                    `ConferenciaDeAnexosDoChecklist::totalMarcadosSemAnexo()`.
     *                                    `$veFinanceiro` é `VisibilidadeDoFinanceiroDaPasta::podeVer()`;
     *                                    o padrão é FECHADO: quem não diz, não vê.
     *                                    `$relatorioInicial` são os pontos do relatório inicial de
     *                                    atendimento (aba Detalhes); null = sem pendência de Detalhes.
     */
    public static function montar(
        Pasta $pasta,
        int $pushNaoLidas,
        array $pagamentos,
        ?\DateTimeImmutable $hoje = null,
        int $itensMarcadosSemAnexo = 0,
        bool $veFinanceiro = false,
        int $documentosCobrados = 0,
        ?PontosDoRelatorioInicial $relatorioInicial = null,
    ): self {
        $porAba = [];

        $metasAbertas = 0;
        foreach ($pasta->getTarefas() as $tarefa) {
            if ($tarefa->getStatus() !== Tarefa::STATUS_CONCLUIDA) {
                ++$metasAbertas;
            }
        }
        if ($metasAbertas > 0) {
            $porAba['tarefas'] = [
                'n'   => $metasAbertas,
                'txt' => self::plural($metasAbertas, 'meta exige atenção', 'metas exigem atenção'),
            ];
        }

        if ($pasta->getPastaProcessos()->isEmpty()) {
            $porAba['processo'] = ['n' => 1, 'txt' => 'nenhum processo vinculado'];
        }

        // Contrato assinado ("REGULAR") ou pró-bono = regularizado: sem traço vermelho,
        // mesmo com pagamento vencido — é a regra primária do desenho.
        $regular = $pasta->getSituacaoContrato() !== 'PENDENTE' || $pasta->isProBono();
        if ($veFinanceiro && !$regular) {
            $vencidos = 0;
            foreach ($pagamentos as $pagamento) {
                if ($pagamento->estaVencido($hoje)) {
                    ++$vencidos;
                }
            }
            $txt = 'contrato de honorários pendente de assinatura';
            if ($vencidos > 0) {
                $txt .= ' e ' . self::plural($vencidos, 'pagamento vencido', 'pagamentos vencidos');
            }
            $porAba['financeiro'] = ['n' => 1 + $vencidos, 'txt' => $txt];
        }

        // Checklist desativado (DOC-73): a conferência "sem anexo" está pausada e não é pendência.
        // O gate mora AQUI, e não em quem chama, para a tela e o resumo impresso obedecerem juntos.
        if ($itensMarcadosSemAnexo + $documentosCobrados > 0 && $pasta->isChecklistAtivo()) {
            $porAba['documentos'] = self::pendenciaDosDocumentos($documentosCobrados, $itensMarcadosSemAnexo);
        }

        if (($detalhes = $relatorioInicial?->pendenciaDaAba()) !== null) {
            $porAba['detalhes'] = $detalhes;
        }

        if ($pushNaoLidas > 0) {
            $porAba['push'] = [
                'n'   => $pushNaoLidas,
                'txt' => self::plural($pushNaoLidas, 'movimentação nova sem leitura', 'movimentações novas sem leitura'),
            ];
        }

        return new self($porAba);
    }

    /**
     * Desenho 02 (dc 5496-5498): "N documentos cobrados e ainda não anexados" e "N itens marcados sem
     * anexo", juntos com " e ".
     *
     * @return array{n: int, txt: string}
     */
    private static function pendenciaDosDocumentos(int $cobrados, int $semAnexo): array
    {
        $partes = array_filter([
            $cobrados > 0 ? self::plural($cobrados, 'documento cobrado e ainda não anexado', 'documentos cobrados e ainda não anexados') : '',
            $semAnexo > 0 ? self::plural($semAnexo, 'item marcado sem anexo', 'itens marcados sem anexo') : '',
        ]);

        return ['n' => $cobrados + $semAnexo, 'txt' => implode(' e ', $partes)];
    }

    /**
     * Sem as pendências das abas que a pessoa não vê (spec pasta-ocultar-aba §4): a folha impressa
     * não pode contar o que a tela esconde.
     *
     * @param list<string> $abas ids das abas ocultas
     */
    public function semAbas(array $abas): self
    {
        return new self(array_diff_key($this->porAba, array_flip($abas)));
    }

    private static function plural(int $n, string $um, string $varios): string
    {
        return $n . ' ' . ($n === 1 ? $um : $varios);
    }
}
