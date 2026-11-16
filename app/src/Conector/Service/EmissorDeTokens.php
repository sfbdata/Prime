<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\DTO\RespostaDeTokenOutput;
use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\TokenDoConector;
use App\Conector\Repository\TokenDoConectorRepository;

/**
 * Emite o par do `/oauth/token` numa concessão (`docs/specs/mcp-remoto-oauth.md`, 3.2, 9.1 e decisão 14.c): um token
 * de acesso de 1 hora e uma renovação que vale até o fim da concessão (7 dias da autorização). A renovação sai sempre,
 * peça o app `offline_access` ou não (decisão 7 do planejamento do PR 3).
 *
 * Os dois são segredos opacos ({@see SegredoOpaco}): o banco recebe só o hash; o valor vai no
 * {@see RespostaDeTokenOutput} e não fica em lugar nenhum. Só persiste (sem `flush`): quem chama está numa transação
 * e grava junto com o resto.
 */
final class EmissorDeTokens
{
    public function __construct(
        private readonly TokenDoConectorRepository $tokens,
        private readonly SegredoOpaco $segredos,
    ) {
    }

    /**
     * @param TokenDoConector|null $geradoPor a renovação que gerou este par (nula no primeiro par, que sai do código)
     */
    public function emitirPar(ConcessaoDoConector $concessao, \DateTimeImmutable $agora, ?TokenDoConector $geradoPor = null): RespostaDeTokenOutput
    {
        $acesso = $this->segredos->gerar();
        $renovacao = $this->segredos->gerar();

        $tokenDeAcesso = TokenDoConector::deAcesso($acesso['hash'], $concessao, $agora, $geradoPor);
        $this->tokens->salvar($tokenDeAcesso);
        $this->tokens->salvar(TokenDoConector::deRenovacao($renovacao['hash'], $concessao, $agora, $geradoPor));

        return new RespostaDeTokenOutput(
            $acesso['valor'],
            $renovacao['valor'],
            self::segundosAte($tokenDeAcesso->getExpiraEm(), $agora),
            implode(' ', $concessao->getEscopos()),
        );
    }

    /** Segundos inteiros até o vencimento, pelo menos 1 (a concessão está válida em `$agora`). */
    private static function segundosAte(\DateTimeImmutable $vencimento, \DateTimeImmutable $agora): int
    {
        return max(1, $vencimento->getTimestamp() - $agora->getTimestamp());
    }
}
