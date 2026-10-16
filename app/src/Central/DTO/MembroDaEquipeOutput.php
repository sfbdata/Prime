<?php

declare(strict_types=1);

namespace App\Central\DTO;

/**
 * Uma pessoa do Painel "Equipe · em aberto" (desenho L5641): quem é, quanto tem em aberto e a presença. Só números e
 * estado: nenhum título, texto ou conversa dela sai daqui.
 */
final class MembroDaEquipeOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly ?string $cargo,
        /** Nome do arquivo da foto do perfil (a URL sai de `app_profile_foto_serve`), ou null. */
        public readonly ?string $foto,
        public readonly bool $eu,
        /** Mensagens da Central não lidas por ela + notificações do sino não lidas dela, neste escritório. */
        public readonly int $emAberto,
        /** `disponivel` | `ocupado` | `ausente` quando on-line; `offline` quando não. */
        public readonly string $status,
        public readonly bool $online,
        /** On-line: desde quando está neste status. */
        public readonly ?string $desde,
        /** Off-line: o último batimento (null = nunca deu). */
        public readonly ?string $vistoEm,
    ) {
    }
}
