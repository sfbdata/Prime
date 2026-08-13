<?php

declare(strict_types=1);

namespace App\Sync\Enum;

/**
 * Sentido de uma rodada de sincronização (spec fase2 §12.5 — requisito R2).
 *
 * Até 2026-08 o motor rodava sempre nos DOIS sentidos, e conectar um Drive ligava a importação
 * contínua junto. Para um escritório novo isso era o inverso do desejado: conectar passava a
 * CRIAR pastas no sistema e BAIXAR arquivos a partir do Drive.
 *
 * A decisão do dono foi tirar a importação do caminho AUTOMÁTICO sem perder a capacidade: o
 * código de importação continua inteiro, mas só roda quando alguém pede de propósito
 * (`--modo=importar`). É ele que vai alimentar o botão "Importar" da Fatia B e a migração do
 * acervo (R7) — apagar agora seria reescrever depois.
 */
enum ModoSincronizacao: string
{
    /** Só sistema→Drive: cria a pasta no Drive e sobe documentos. É o padrão (cron e worker). */
    case Enviar = 'enviar';

    /** Só Drive→sistema: descobre subpastas novas e baixa arquivos. Nunca automático. */
    case Importar = 'importar';

    /** Os dois sentidos. Sobrou para uso explícito por CLI; não é mais o padrão de nada. */
    case Ambos = 'ambos';

    public function envia(): bool
    {
        return $this === self::Enviar || $this === self::Ambos;
    }

    public function importa(): bool
    {
        return $this === self::Importar || $this === self::Ambos;
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
