<?php

declare(strict_types=1);

namespace App\Shared\Scheduler;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O nome de uma tarefa agendada para o Master, nunca o comando do console (regra de tom de 24/10/2026: a tela
 * do Master não mostra nome técnico). `app:djen:sincronizar --dias=2` vira a tradução de
 * `moldura.aviso_falha_agendada.tarefa.djen_sincronizar`; comando sem nome traduzido vira "Tarefa agendada".
 */
final class NomeDaTarefaAgendada
{
    private const PREFIXO = 'moldura.aviso_falha_agendada.tarefa.';

    public function __construct(
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    public function de(string $comando): string
    {
        $primeiro = explode(' ', trim($comando))[0];
        $base = (string) preg_replace('/^app:/', '', $primeiro);
        $chave = self::PREFIXO . (string) preg_replace('/[^a-z0-9]+/', '_', strtolower($base));
        $nome = $this->tradutor->trans($chave);

        return $nome !== $chave ? $nome : $this->tradutor->trans(self::PREFIXO . 'outra');
    }
}
