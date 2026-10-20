<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\SolicitarCodigoSenhaInput;
use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Auth\Service\RedefinicaoSenhaMailerInterface;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Passo 1 da senha por código (desenho 10): cria o pedido e envia o código de 6 números.
 *
 * Tudo aqui gira em torno da RN02 (resposta neutra): quem pede não pode descobrir se o
 * e-mail existe. Por isso o UseCase retorna void em todos os caminhos (conta inexistente,
 * inativa ou sucesso saem iguais) e é ele, não o controller, quem decide enviar.
 *
 * Falha de transporte vai para o log e é engolida: um erro que só aparece quando há conta
 * para quem enviar revelaria a conta. O usuário legítimo reenvia; o log nos avisa.
 *
 * O código não é guardado em claro: `token_hash` recebe o HMAC de `userId:codigo` com o
 * segredo do kernel. Sem o segredo, quem lê o banco não consegue testar um milhão de
 * códigos contra o hash.
 */
final class SolicitarCodigoSenhaUseCase
{
    public const VALIDADE = '+15 minutes';

    /**
     * Teto diário por conta, além dos limitadores por hora do controller: passado dele, nada é criado nem enviado
     * (silêncio, RN02). Conta os pedidos das últimas 24 h pelas linhas de `redefinicao_senha`.
     */
    public const TETO_DIARIO = 10;
    public const JANELA_DO_TETO = '-24 hours';

    /** Tentativas de sortear um código cujo hash ainda não existe (índice único). */
    private const SORTEIOS = 5;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RedefinicaoSenhaRepository $redefinicaoRepository,
        private readonly RedefinicaoSenhaMailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $segredo,
    ) {
    }

    /**
     * Único ponto que decide como o código vira o valor de `token_hash`. O
     * ConfirmarCodigoSenhaUseCase confere com esta mesma conta.
     */
    public static function hashDoCodigo(int $userId, string $codigo, string $segredo): string
    {
        return hash_hmac('sha256', $userId . ':' . $codigo, $segredo);
    }

    public function executar(SolicitarCodigoSenhaInput $input, string $ip, ?string $userAgent): void
    {
        $user = $this->localizarConta(trim($input->email));

        // RS04: conta desativada não recebe código; desativar precisa mesmo tirar do ar.
        if ($user === null || $user->isActive() === false) {
            return;
        }

        $agora = new \DateTimeImmutable();
        if ($this->redefinicaoRepository->contarPedidosDesde($user, $agora->modify(self::JANELA_DO_TETO)) >= self::TETO_DIARIO) {
            $this->logger->warning('Teto diário de pedidos de código de senha atingido; código não enviado.', [
                'user_id' => $user->getId(),
                'ip' => $ip,
            ]);

            return;
        }

        // RS02: pedir de novo derruba os pedidos anteriores ainda vivos (código ou link). Encerra em vez de apagar:
        // a linha fica até a purga, para o teto diário contar o pedido.
        $this->redefinicaoRepository->encerrarPendentesDoUsuario($user, $agora);

        [$codigo, $hash] = $this->sortearCodigo((int) $user->getId());

        $this->redefinicaoRepository->salvar(new RedefinicaoSenha(
            user: $user,
            tokenHash: $hash,
            expiraEm: new \DateTimeImmutable(self::VALIDADE),
            ip: $ip,
            userAgent: $userAgent,
        ), flush: true);

        $this->enviarSemVazar($user, $codigo);
    }

    /**
     * Seis dígitos com zero à esquerda. Sorteia de novo se o hash já existir (o mesmo número
     * saiu antes para a mesma conta e a linha consumida ainda não foi purgada): o índice
     * único de `token_hash` estouraria e o 500 só aconteceria para conta existente.
     *
     * @return array{0: string, 1: string} código em claro e o hash dele
     */
    private function sortearCodigo(int $userId): array
    {
        $sorteios = 0;

        do {
            $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash   = self::hashDoCodigo($userId, $codigo, $this->segredo);
            ++$sorteios;
        } while ($sorteios < self::SORTEIOS && $this->redefinicaoRepository->existeTokenHash($hash));

        return [$codigo, $hash];
    }

    private function enviarSemVazar(User $user, string $codigo): void
    {
        try {
            $this->mailer->enviarCodigo($user, $codigo);
        } catch (\Throwable $e) {
            // \Throwable, e não \RuntimeException: remetente malformado lança
            // RfcComplianceException e erro de template lança Twig\Error\Error. Qualquer uma
            // escapando daqui produziria 500 só quando a conta existe (RN02).
            $this->logger->error('Falha ao enviar o código de redefinição de senha.', [
                'user_id' => $user->getId(),
                // 'exception' (esta chave exata) é o que faz o Monolog gravar o stack trace.
                'exception' => $e,
            ]);
        }
    }

    /**
     * Busca ignorando a caixa (o cadastro antigo não normalizava) e desempata quando há
     * duas contas que diferem só na caixa: vence a que bate EXATAMENTE com o digitado; se
     * nenhuma bate, silêncio, porque mandar para a conta errada é pior que não mandar.
     */
    private function localizarConta(string $digitado): ?User
    {
        $encontrados = $this->userRepository->encontrarPorEmailIgnorandoCaixa($digitado);

        if (count($encontrados) <= 1) {
            return $encontrados[0] ?? null;
        }

        foreach ($encontrados as $candidato) {
            if ($candidato->getEmail() === $digitado) {
                return $candidato;
            }
        }

        $this->logger->error('Contas que diferem apenas na caixa do e-mail e nenhuma bate com o digitado; código não enviado.', [
            'ids' => array_map(static fn (User $u): ?int => $u->getId(), $encontrados),
        ]);

        return null;
    }
}
