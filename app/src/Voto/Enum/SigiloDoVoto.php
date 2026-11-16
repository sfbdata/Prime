<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/**
 * Sigilo do voto da pauta (desenho 18), fixado antes de publicar. Secreto é o padrão: nenhuma linha liga a unidade à
 * escolha. Aberto liga a unidade à escolha só nas pautas configuradas assim: visível após o encerramento ou, no
 * "ao vivo", enquanto a votação corre.
 */
enum SigiloDoVoto: string
{
    case Secreto = 'secreto';
    case Aberto = 'aberto';
    case AbertoAoVivo = 'aberto_vivo';

    public function aberto(): bool
    {
        return self::Secreto !== $this;
    }
}
