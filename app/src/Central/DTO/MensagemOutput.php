<?php

declare(strict_types=1);

namespace App\Central\DTO;

use App\Central\Entity\Mensagem;
use App\Entity\Auth\User;

/**
 * Uma mensagem como a tela a recebe. `minha` é do ponto de vista de quem pediu. `conteudo` vem com as menções no
 * token `@[Nome](user:ID)` — a tela destaca; `respostaA` é a citação (A2), ou `null`.
 *
 * Despacho (`docs/specs/encaminhar-via-chat-ia.md` §4.1): só nasce por `LeituraDasMensagens`, que confere a pasta na
 * leitura. `despacho` = `{pastaId, nup}` só quando quem lê vê a pasta (o chip "Pasta N" sai daqui, não do texto);
 * `ocultoPorPermissao = true` quando não vê, e então `conteudo` é "Item de pasta sem acesso" e `despacho` é nulo.
 * Mensagem comum: `despacho = null`, `ocultoPorPermissao = false`.
 *
 * `deIa` = fala do BlueJus IA (F3): o autor gravado é o dono da conversa, mas a tela a mostra como da IA: `autorNome`
 * vira "BlueJus IA" e `minha` é falso.
 */
final class MensagemOutput
{
    public const NOME_DA_IA = 'BlueJus IA';

    private function __construct(
        public readonly int $id,
        public readonly int $conversaId,
        public readonly int $autorId,
        public readonly string $autorNome,
        public readonly string $conteudo,
        public readonly string $criadaEm,
        public readonly bool $minha,
        public readonly ?RespostaOutput $respostaA,
        public readonly bool $sistema,
        public readonly ?DespachoOutput $despacho,
        public readonly bool $ocultoPorPermissao,
        public readonly bool $deIa,
    ) {
    }

    /** Só `LeituraDasMensagens` chama: `$lido` e `$respostaA` já passaram pela conferência da pasta. */
    public static function daLeitura(Mensagem $mensagem, User $eu, ConteudoLido $lido, ?RespostaOutput $respostaA): self
    {
        $autor = $mensagem->getAutor();

        return new self(
            id: (int) $mensagem->getId(),
            conversaId: (int) $mensagem->getConversa()->getId(),
            autorId: (int) $autor->getId(),
            autorNome: $mensagem->isDeIa() ? self::NOME_DA_IA : (string) $autor->getFullName(),
            conteudo: $lido->conteudo,
            criadaEm: $mensagem->getCriadaEm()->format(\DateTimeInterface::ATOM),
            minha: !$mensagem->isDeIa() && $autor->getId() === $eu->getId(),
            respostaA: $respostaA,
            sistema: $mensagem->isSistema(),
            despacho: $lido->despacho,
            ocultoPorPermissao: $lido->oculto,
            deIa: $mensagem->isDeIa(),
        );
    }
}
