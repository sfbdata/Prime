<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\DTO\IdentidadeDoGoogle;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * "Continuar com Google" sem conta (spec entrar-com-google, entrega 2; desenho 10 VS 24.10.26): a identidade que o
 * Google acabou de provar espera na sessão até a pessoa completar o "Criar conta" (escritório, gênero e aceite). O
 * cadastro lê a identidade daqui, nunca do navegador. Num navegador compartilhado ela não pode ficar para a próxima
 * pessoa: vale por `VALIDADE`, sai no "Usar outro e-mail", quando a conta é criada e quando outra conta entra.
 */
final class CadastroPeloGoogle
{
    public const CHAVE_SESSAO = 'cadastro_pelo_google';

    /**
     * Quanto tempo a identidade espera depois da volta do Google. Curto de propósito: no navegador compartilhado,
     * quem chega depois veria o cartão de quem saiu da aba (a sessão do próprio Google, no mesmo navegador, já
     * permitiria o mesmo por muito mais tempo).
     */
    public const VALIDADE = '+15 minutes';

    public const EXPIROU = 'A conexão com o Google expirou. Clique em "Continuar com Google" de novo.';

    public function __construct(
        private readonly ClockInterface $relogio,
    ) {
    }

    public function guardar(SessionInterface $sessao, IdentidadeDoGoogle $identidade): void
    {
        $sessao->set(self::CHAVE_SESSAO, $identidade->paraSessao() + ['em' => $this->relogio->now()->getTimestamp()]);
    }

    /** A identidade guardada, se não venceu; senão, esquece a chave e devolve null. */
    public function ler(SessionInterface $sessao): ?IdentidadeDoGoogle
    {
        $valor = $sessao->get(self::CHAVE_SESSAO);
        if (null === $valor) {
            return null;
        }
        $em = is_array($valor) ? ($valor['em'] ?? null) : null;
        $agora = $this->relogio->now();
        $identidade = is_array($valor) ? IdentidadeDoGoogle::daSessao($valor) : null;
        if (null === $identidade || !is_int($em) || $agora > $agora->setTimestamp($em)->modify(self::VALIDADE)) {
            self::esquecer($sessao);

            return null;
        }

        return $identidade;
    }

    public static function esquecer(SessionInterface $sessao): void
    {
        $sessao->remove(self::CHAVE_SESSAO);
    }
}
