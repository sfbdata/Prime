<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Reconciliacao;

/**
 * O critério do alerta da reconciliação agendada (P2.7, spec P2 §9): a rodada falha, e o agendador
 * avisa por e-mail, quando há divergência que exige ação.
 *
 * Exigem ação: ausente no espelho sem envio na fila, tamanho diferente, SHA diferente, erro de consulta
 * (o R2 fora do ar precisa avisar) e linhas de envio em `falhou` na outbox. NÃO exigem:
 * `presente_sem_sha_conferido` (fora da fatia do SHA), `suspeito_erro_do_drive` (os 90 conhecidos da P1),
 * `ausente_pendente_na_outbox` (em fila) e `local_ausente`, `chave_invalida` e `sem_sha_no_espelho`:
 * problemas conhecidos do acervo local (P1), não do espelho. Todos aparecem na contagem.
 *
 * A mensagem leva só contagens por classe e por categoria: nunca nome de arquivo, chave remota ou
 * nome de pessoa (vai para o log do agendador).
 */
final class AlertaDaReconciliacao
{
    public const CLASSES_QUE_EXIGEM_ACAO = [
        ClasseDeReconciliacao::AUSENTE_NO_ESPELHO,
        ClasseDeReconciliacao::TAMANHO_DIVERGENTE,
        ClasseDeReconciliacao::SHA_DIVERGENTE,
        ClasseDeReconciliacao::ERRO_DE_CONSULTA,
    ];

    public const FALHOU_NA_OUTBOX = 'falhou_na_outbox';

    /** @param list<string> $linhas */
    private function __construct(private readonly array $linhas)
    {
    }

    /** @param array<string, int> $falhasPorCategoria categoria => linhas de envio em `falhou` */
    public static function avaliar(RelatorioDeReconciliacao $relatorio, array $falhasPorCategoria): self
    {
        $linhas = [];
        foreach (self::CLASSES_QUE_EXIGEM_ACAO as $classe) {
            $quantos = $relatorio->quantos($classe);
            if ($quantos > 0) {
                $linhas[] = self::linha($classe->value, $quantos, $relatorio->porCategoria($classe));
            }
        }

        ksort($falhasPorCategoria);
        $falhas = array_sum($falhasPorCategoria);
        if ($falhas > 0) {
            $linhas[] = self::linha(self::FALHOU_NA_OUTBOX, $falhas, $falhasPorCategoria);
        }

        return new self($linhas);
    }

    public function disparou(): bool
    {
        return $this->linhas !== [];
    }

    /** @return list<string> uma linha por classe: "classe: N (categoria: n, ...)" */
    public function linhas(): array
    {
        return $this->linhas;
    }

    /** @param array<string, int> $porCategoria */
    private static function linha(string $classe, int $quantos, array $porCategoria): string
    {
        $partes = [];
        foreach ($porCategoria as $categoria => $n) {
            $partes[] = $categoria . ': ' . $n;
        }

        return sprintf('%s: %d (%s)', $classe, $quantos, implode(', ', $partes));
    }
}
