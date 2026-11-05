<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\Contract\ConfiguracaoDaPlataforma;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Symfony\Component\Security\Http\RateLimiter\DefaultLoginRateLimiter;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

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
 *
 * Teto por conta (auditoria 3.28): os dois limitadores acima têm o IP na chave, e quem troca de IP tenta a senha de
 * uma conta sem fim. No formulário de senha (POST /login) vale também um terceiro, só pelo e-mail normalizado:
 * `10 * n` falhas por hora (`_login_conta_main`). Passado o teto, nem a senha certa entra, com o mesmo aviso de
 * "Muitas tentativas" do limite usuário+IP (a chave é o e-mail digitado, exista a conta ou não: não revela conta).
 * O Google e o Facebook não passam por ele (outra rota). Login certo e troca de senha pela recuperação zeram o
 * contador da conta. Troca aceita: quem erra de propósito, sem sessão nem CSRF válido, fecha o login por senha da
 * conta alheia enquanto mantiver 50 falhas por hora; a recuperação de senha segue aberta. E-mails que só diferem na
 * caixa dividem o teto. A defesa contra "trocar de IP" depende do proxy confiável (SYMFONY_TRUSTED_PROXIES).
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
    /** O teto da conta é este múltiplo do limite usuário+IP, por hora. */
    public const MULTIPLO_DA_CONTA = 10;
    private const JANELA_DA_CONTA = '1 hour';

    public function __construct(
        private readonly ConfiguracaoDaPlataforma $configuracao,
        private readonly StorageInterface $armazenamento,
        #[\SensitiveParameter] private readonly string $segredo,
    ) {
    }

    /** Olha sem gastar: o listener do Symfony só consome na falha do login (como no limitador padrão). */
    public function peek(Request $request): RateLimit
    {
        $padrao = $this->limitador()->peek($request);
        $conta = $this->limitadorDaConta($request)?->consume(0);
        if ($conta === null) {
            return $padrao;
        }
        // O listener do Symfony barra com 0 restante e, se o que receber estiver intocado, não zera no login certo:
        // devolve a conta quando ela barra, ou quando é a única já tocada (para o login certo zerá-la).
        $soAContaTocada = $padrao->getRemainingTokens() === $padrao->getLimit() && $conta->getRemainingTokens() < $conta->getLimit();
        if ($conta->getRemainingTokens() === 0 || !$conta->isAccepted() || $soAContaTocada) {
            return $conta;
        }

        return $padrao;
    }

    public function consume(Request $request): RateLimit
    {
        $padrao = $this->limitador()->consume($request);
        $conta = $this->limitadorDaConta($request)?->consume();

        return $conta !== null && !$conta->isAccepted() ? $conta : $padrao;
    }

    /** Zera o contador usuário+IP e o da conta; o do IP sobrevive (igual ao limitador padrão do Symfony). */
    public function reset(Request $request): void
    {
        $this->limitador()->reset($request);
        $this->limitadorDaConta($request)?->reset();
    }

    /** Zera o teto da conta (auditoria 3.28) de quem acabou de trocar a senha pela recuperação (link ou código). */
    public function zerarConta(string $email): void
    {
        $this->fabricaDaConta()->create($this->chaveDaConta($email))->reset();
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
            $this->segredoDoHash(),
        );
    }

    /** Só o formulário de senha (POST /login, o `UserAuthenticator`); chave = HMAC do e-mail normalizado, sem IP. */
    private function limitadorDaConta(Request $request): ?LimiterInterface
    {
        if (!$request->isMethod('POST') || $request->getPathInfo() !== '/login') {
            return null;
        }
        $usuario = (string) $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');

        return $this->fabricaDaConta()->create($this->chaveDaConta($usuario));
    }

    private function fabricaDaConta(): RateLimiterFactory
    {
        return $this->fabrica('_login_conta_main', self::MULTIPLO_DA_CONTA * $this->limiteVigente(), self::JANELA_DA_CONTA);
    }

    private function chaveDaConta(string $email): string
    {
        $email = mb_strtolower(trim($email), 'UTF-8');

        return strtr(substr(base64_encode(hash_hmac('sha256', 'conta-' . $email, $this->segredoDoHash(), true)), 0, 12), '/+', '._');
    }

    private function segredoDoHash(): string
    {
        return '' !== $this->segredo ? $this->segredo : self::SEGREDO_RESERVA;
    }

    private function fabrica(string $id, int $limite, string $janela = self::JANELA): RateLimiterFactory
    {
        return new RateLimiterFactory([
            'id' => $id,
            'policy' => 'fixed_window',
            'limit' => $limite,
            'interval' => $janela,
        ], $this->armazenamento);
    }
}
