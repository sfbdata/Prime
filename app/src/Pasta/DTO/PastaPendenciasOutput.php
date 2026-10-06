<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;

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
 *                 pagamentos vencidos quando houver;
 *  · Documentos → item do checklist MARCADO como entregue sem nenhum arquivo
 *                 correspondente na pasta (`ConferenciaDeAnexosDoChecklist`, DOC-74/76);
 *  · Push       → publicação não lida.
 *
 * Da regra de Documentos do desenho só entra a metade "marcado sem anexo": a outra
 * metade ("documento cobrado e ainda não anexado") depende da cobrança automática do
 * checklist, que não existe no sistema (DOC-75, decisão S-6 do dono). Detalhes fica sem
 * pendência: o relatório inicial de atendimento ainda não existe como dado.
 */
final readonly class PastaPendenciasOutput
{
    /**
     * @param array<string, array{n: int, txt: string}> $porAba id da aba (`tarefas`, `processo`,
     *                                                           `financeiro`, `documentos`, `push`) => pendência;
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
     */
    public static function montar(
        Pasta $pasta,
        int $pushNaoLidas,
        array $pagamentos,
        ?\DateTimeImmutable $hoje = null,
        int $itensMarcadosSemAnexo = 0,
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
        if (!$regular) {
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

        if ($itensMarcadosSemAnexo > 0) {
            $porAba['documentos'] = [
                'n'   => $itensMarcadosSemAnexo,
                'txt' => self::plural(
                    $itensMarcadosSemAnexo,
                    'item do checklist marcado sem anexo',
                    'itens do checklist marcados sem anexo',
                ),
            ];
        }

        if ($pushNaoLidas > 0) {
            $porAba['push'] = [
                'n'   => $pushNaoLidas,
                'txt' => self::plural($pushNaoLidas, 'movimentação nova sem leitura', 'movimentações novas sem leitura'),
            ];
        }

        return new self($porAba);
    }

    private static function plural(int $n, string $um, string $varios): string
    {
        return $n . ' ' . ($n === 1 ? $um : $varios);
    }
}
