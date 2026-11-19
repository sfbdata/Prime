<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\DTO\PastaRecebida;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Exception\EscritaNaPastaRecebidaNegadaException;

/**
 * A porta comum das escritas de quem recebeu a pasta de outra conta (spec acesso-restrito-e-compartilhar §4.5.1, fatia
 * 9b), depois do `PastaRecebidaDoUsuario::localizar()`: a conta da pasta fora do ar deixa tudo só leitura, e cada ação
 * pede a caixa do convite e a aba de onde ela sai visível para quem recebeu.
 */
final class EscritaNaPastaRecebida
{
    /** @throws EscritaNaPastaRecebidaNegadaException */
    public static function exigir(PastaRecebida $recebida, bool $caixaDoConvite, AbaDaPasta $aba): void
    {
        if ($recebida->soLeitura || !$caixaDoConvite || !$recebida->abaVisivel($aba)) {
            throw new EscritaNaPastaRecebidaNegadaException();
        }
    }

    /** "Movimentar" sem aba: a troca de fase mora no cabeçalho da pasta. */
    public static function exigirMovimentar(PastaRecebida $recebida): void
    {
        if ($recebida->soLeitura || !$recebida->compartilhamento->permissoes()->movimentar) {
            throw new EscritaNaPastaRecebidaNegadaException();
        }
    }
}
