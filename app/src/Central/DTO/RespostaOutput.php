<?php

declare(strict_types=1);

namespace App\Central\DTO;

use App\Central\Entity\Mensagem;
use App\Central\Service\MencoesDaCentral;
use App\Entity\Auth\User;

/**
 * A mensagem citada numa resposta (A2): quem escreveu e um trecho, com as menções como texto. É da mesma conversa
 * (invariante da `Mensagem`) — quem vê a resposta já pode ver a original. `autorId` dá a cor da barra e `minha`
 * troca o nome por "Você", como no desenho (L6036).
 *
 * Citação de um despacho que o leitor não vê: o trecho é "Item de pasta sem acesso" e `ocultoPorPermissao = true`
 * (`LeituraDasMensagens`, a única que monta esta saída).
 */
final class RespostaOutput
{
    public const TAMANHO_DO_TRECHO = 120;

    private function __construct(
        public readonly int $id,
        public readonly int $autorId,
        public readonly string $autorNome,
        public readonly string $trecho,
        public readonly bool $minha,
        public readonly bool $ocultoPorPermissao,
    ) {
    }

    /** Só `LeituraDasMensagens` chama: `$lido` já passou pela conferência da pasta. */
    public static function daLeitura(Mensagem $original, User $eu, ConteudoLido $lido): self
    {
        $texto = MencoesDaCentral::comoTexto($lido->conteudo);
        if (mb_strlen($texto) > self::TAMANHO_DO_TRECHO) {
            $texto = mb_substr($texto, 0, self::TAMANHO_DO_TRECHO) . '…';
        }

        $autor = $original->getAutor();

        return new self(
            id: (int) $original->getId(),
            autorId: (int) $autor->getId(),
            autorNome: (string) $autor->getFullName(),
            trecho: $texto,
            minha: $autor->getId() === $eu->getId(),
            ocultoPorPermissao: $lido->oculto,
        );
    }
}
