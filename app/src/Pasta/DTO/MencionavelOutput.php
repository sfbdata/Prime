<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Uma pessoa que pode ser @mencionada no Registro da pasta — o que a lista do autocompletar mostra
 * (desenho `bluejus-central.js` `menAbrir`: avatar, nome, função). Sem e-mail, de propósito: a
 * lista é para escolher um colega pelo nome, não para expor contato.
 */
final class MencionavelOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $iniciais,
        public readonly ?string $cargo,
        /** NOME do arquivo da foto (a URL sai de `app_profile_foto_serve`), ou null. */
        public readonly ?string $foto,
    ) {
    }

    /** @return array{id: int, nome: string, iniciais: string, cargo: ?string} */
    public function paraArray(): array
    {
        return [
            'id'       => $this->id,
            'nome'     => $this->nome,
            'iniciais' => $this->iniciais,
            'cargo'    => $this->cargo,
        ];
    }

    /** Iniciais do avatar: primeira letra do primeiro e do último nome (como o cartão do Registro). */
    public static function iniciaisDe(string $nome): string
    {
        $partes = array_values(array_filter(explode(' ', trim($nome)), static fn (string $p): bool => $p !== ''));
        if ($partes === []) {
            return '?';
        }

        $ultima = count($partes) > 1 ? mb_substr($partes[count($partes) - 1], 0, 1) : '';

        return mb_strtoupper(mb_substr($partes[0], 0, 1) . $ultima);
    }
}
