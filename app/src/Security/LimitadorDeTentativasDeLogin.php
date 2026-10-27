<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\Contract\ConfiguracaoDaPlataforma;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Symfony\Component\Security\Http\RateLimiter\DefaultLoginRateLimiter;

/**
 * Limite de tentativas de login configurável pelo Master (spec `login-config-tentativas.md`).
 *
 * Faz o mesmo que o Symfony monta com `login_throttling.max_attempts`: dois limitadores de janela fixa de 15 minutos
 * (usuário+IP com o limite `n`, IP com `5 * n`), mesmos ids e mesmo storage, delegando a um
 * {@see DefaultLoginRateLimiter} (mesma chave e hash). A diferença: `n` é lido a cada requisição da chave `tentativas`.
 *
 * A chave do contador não inclui o limite, mas a janela guardada no storage carrega o limite com que nasceu (Window do
 * Symfony): mudar `n` vale para as janelas novas (usuário+IP ou IP sem contagem ativa) e as já abertas terminam, no
 * máximo em 15 minutos, com o limite antigo. Sem trava (lock) de propósito, como o `login_throttling` sempre foi neste projeto (o `lock.yaml` só tem recurso nomeado).
 * Nunca lança por causa da configuração: ausente, não numérica, fora de 1..50 ou falha do leitor, vale 5.
 */
final class LimitadorDeTentativasDeLogin implements PeekableRequestRateLimiterInterface
{
    public const PADRAO = 5;
    /** APP_SECRET vazio não pode derrubar o login (o DefaultLoginRateLimiter exige segredo não vazio). */
    private const SEGREDO_RESERVA = 'limitador-de-login';

    public const MINIMO = 1;
    public const MAXIMO = 50;
    public const CHAVE = 'tentativas';
    private const JANELA = '15 minutes';

    public function __construct(
        private readonly ConfiguracaoDaPlataforma $configuracao,
        private readonly StorageInterface $armazenamento,
        #[\SensitiveParameter] private readonly string $segredo,
    ) {
    }

    /** Olha sem gastar: o listener do Symfony só consome na falha do login (como no limitador padrão). */
    public function peek(Request $request): RateLimit
    {
        return $this->limitador()->peek($request);
    }

    public function consume(Request $request): RateLimit
    {
        return $this->limitador()->consume($request);
    }

    /** Zera só o contador usuário+IP; o do IP sobrevive (igual ao limitador padrão do Symfony). */
    public function reset(Request $request): void
    {
        $this->limitador()->reset($request);
    }

    /** O limite vigente (1..50), com a volta ao padrão para qualquer valor ou falha inválidos. */
    public function limiteVigente(): int
    {
        try {
            $bruto = trim($this->configuracao->valor(self::CHAVE));
        } catch (\Throwable) {
            return self::PADRAO;
        }

        if (preg_match('/^\d{1,6}$/', $bruto) !== 1) {
            return self::PADRAO;
        }
        $numero = (int) $bruto;

        return $numero >= self::MINIMO && $numero <= self::MAXIMO ? $numero : self::PADRAO;
    }

    private function limitador(): DefaultLoginRateLimiter
    {
        $limite = $this->limiteVigente();

        return new DefaultLoginRateLimiter(
            $this->fabrica('_login_global_main', 5 * $limite),
            $this->fabrica('_login_local_main', $limite),
            '' !== $this->segredo ? $this->segredo : self::SEGREDO_RESERVA,
        );
    }

    private function fabrica(string $id, int $limite): RateLimiterFactory
    {
        return new RateLimiterFactory([
            'id' => $id,
            'policy' => 'fixed_window',
            'limit' => $limite,
            'interval' => self::JANELA,
        ], $this->armazenamento);
    }
}
