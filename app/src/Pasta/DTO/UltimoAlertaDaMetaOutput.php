<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Estado "Alertado" do sino da meta (desenho 1.2.3, `sinoMeta`, dc L.4429-4438).
 *
 * O desenho guarda o último alerta só na memória de quem clicou. Aqui ele vem da
 * `notificacao` gravada pelo `AlertarResponsavelDaMetaUseCase` (tipo
 * `tarefa_alerta_verificar`): é o último alerta enviado sobre a meta, por QUALQUER
 * pessoa do escritório — a notificação não registra o remetente.
 *
 * Desvio declarado: o desenho só conhece alerta do mesmo dia ("às HH:MM"). Alerta
 * de outro dia leva a data ("em dd/mm às HH:MM"; de outro ano, "dd/mm/aaaa").
 */
final readonly class UltimoAlertaDaMetaOutput
{
    /** Janela da animação `sinoBalanca` depois do clique (desenho: 8 s). */
    public const SEGUNDOS_RECENTE = 8;

    private function __construct(
        /** Nome do destinatário do último alerta. */
        public string $nome,
        public \DateTimeImmutable $em,
        /** "14:32" no mesmo dia; "03/10 às 14:32" em outro dia; "03/10/2025 às 14:32" em outro ano. */
        public string $quando,
        /** `title`/`aria-label` do sino. */
        public string $titulo,
        /** O alerta acabou de sair (volta do POST): o sino balança. */
        public bool $recente,
    ) {
    }

    public static function de(?string $nome, \DateTimeImmutable $em, \DateTimeImmutable $agora): self
    {
        $nome = trim((string) $nome);
        if ($nome === '') {
            $nome = 'o responsável';
        }

        $hora = $em->format('H:i');
        if ($em->format('Y-m-d') === $agora->format('Y-m-d')) {
            $quando = $hora;
            $titulo = sprintf('Alertado: %s às %s. Clique para alertar de novo', $nome, $hora);
        } else {
            $dia    = $em->format($em->format('Y') === $agora->format('Y') ? 'd/m' : 'd/m/Y');
            $quando = $dia . ' às ' . $hora;
            $titulo = sprintf('Alertado: %s em %s às %s. Clique para alertar de novo', $nome, $dia, $hora);
        }

        $decorrido = $agora->getTimestamp() - $em->getTimestamp();

        return new self(
            nome: $nome,
            em: $em,
            quando: $quando,
            titulo: $titulo,
            recente: $decorrido >= 0 && $decorrido < self::SEGUNDOS_RECENTE,
        );
    }
}
