<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * Janela em que o AUTOR ainda pode editar ou excluir um comentário da pasta (mensagem do chat,
 * observação da aba Detalhes, observação do Financeiro), contada da criação.
 *
 * É o ÚNICO lugar da regra: os seis UseCases de editar/excluir decidem por aqui, e os templates
 * perguntam a mesma coisa pela extensão Twig `JanelaDeEdicaoExtension` — antes cada template
 * recalculava `date(x) > date('-24 hours')` e podia divergir do servidor.
 *
 * Decisão do dono (2026-10-05, handoff do Claude Designer): 15 minutos, e a interface mostra
 * quanto falta ("· N min"). Antes eram 24 horas.
 */
final class JanelaDeEdicaoDeComentario
{
    /** Duração da janela (ISO 8601). */
    public const DURACAO = 'PT15M';

    /** A mesma duração, em minutos, para quem monta o item no cliente (JS) logo após publicar. */
    public const MINUTOS = 15;

    /** Aberta até o último instante inclusive: `agora <= criadaEm + 15 min`. */
    public function estaAberta(\DateTimeImmutable $criadaEm, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        return $agora <= $this->fim($criadaEm);
    }

    /**
     * Minutos que faltam, arredondados para CIMA (faltando 11min01s, mostra 12). Nunca negativo:
     * janela fechada devolve 0. Janela aberta devolve no mínimo 1 — inclusive no instante exato
     * do limite, em que ainda dá para salvar —, para a tela nunca oferecer o botão com "· 0 min".
     * E no máximo 15, mesmo se `agora` vier antes da criação (relógio fora de sincronia).
     */
    public function minutosRestantes(\DateTimeImmutable $criadaEm, ?\DateTimeImmutable $agora = null): int
    {
        $agora ??= new \DateTimeImmutable();

        if (!$this->estaAberta($criadaEm, $agora)) {
            return 0;
        }

        $segundos = (float) $this->fim($criadaEm)->format('U.u') - (float) $agora->format('U.u');

        return min(self::MINUTOS, max(1, (int) ceil($segundos / 60)));
    }

    private function fim(\DateTimeImmutable $criadaEm): \DateTimeImmutable
    {
        return $criadaEm->add(new \DateInterval(self::DURACAO));
    }
}
