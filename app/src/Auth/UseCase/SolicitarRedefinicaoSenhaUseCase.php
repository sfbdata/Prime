<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\SolicitarRedefinicaoSenhaInput;
use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Auth\Service\RedefinicaoSenhaMailerInterface;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Passo 1 da recuperação de senha: cria o pedido e dispara o e-mail com o link.
 *
 * Tudo aqui é construído em torno da RN02 (resposta neutra): quem pede não pode
 * descobrir se o e-mail existe. Por isso o UseCase **retorna void em todos os
 * caminhos** — e-mail inexistente, usuário inativo e sucesso saem iguais. Ele também
 * é quem decide enviar o e-mail; se essa decisão ficasse no controller, bastaria a
 * diferença de comportamento para virar um oráculo de existência de conta.
 *
 * Pela mesma razão, falha de transporte é registrada em log e engolida: um erro
 * exibido só quando há e-mail para enviar revelaria a conta. O usuário legítimo
 * repete o pedido; o log é o que nos avisa que o provedor caiu.
 */
final class SolicitarRedefinicaoSenhaUseCase
{
    /** Validade curta: o link destrava conta existente (RS01). */
    private const VALIDADE = '+1 hour';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RedefinicaoSenhaRepository $redefinicaoRepository,
        private readonly RedefinicaoSenhaMailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    public function executar(SolicitarRedefinicaoSenhaInput $input, string $ip, ?string $userAgent): void
    {
        // Honeypot: robô preencheu o campo oculto. Descarta sem persistir nem enviar (RS10).
        if ($input->confirmacao !== '') {
            return;
        }

        // Busca ignorando a caixa: o cadastro público antigo não normalizava, então há
        // como existir conta gravada com maiúscula. Ela loga normalmente e sumiria de uma
        // busca exata — ficando trancada para fora justamente aqui.
        $digitado    = trim($input->email);
        $encontrados = $this->userRepository->encontrarPorEmailIgnorandoCaixa($digitado);

        $user = $this->desempatar($encontrados, $digitado);

        if ($user === null) {
            return;
        }

        // RS04: conta desativada não recebe link — desativar precisa mesmo tirar do ar.
        if ($user->isActive() === false) {
            return;
        }

        // RS02: pedir de novo derruba os pedidos anteriores ainda vivos.
        $this->redefinicaoRepository->invalidarPendentesDoUsuario($user);

        $token = bin2hex(random_bytes(32));

        $redefinicao = new RedefinicaoSenha(
            user: $user,
            tokenHash: RedefinicaoSenha::hashDoToken($token),
            expiraEm: new \DateTimeImmutable(self::VALIDADE),
            ip: $ip,
            userAgent: $userAgent,
        );

        $this->redefinicaoRepository->salvar($redefinicao, flush: true);

        try {
            $this->mailer->enviarLink($user, $token);
        } catch (\Throwable $e) {
            // \Throwable, e não \RuntimeException: remetente malformado lança
            // RfcComplianceException (que estende InvalidArgumentException) e erro de
            // template lança Twig\Error\Error. Qualquer uma escapando daqui produziria
            // 500 só quando a conta existe — e isso, sozinho, denuncia a conta (RN02).
            $this->logger->error('Falha ao enviar e-mail de redefinição de senha.', [
                'user_id' => $user->getId(),
                // 'exception' (esta chave exata) é o que faz o Monolog gravar o stack trace.
                // Sem ela, engolir \Throwable transformaria um bug de programação numa linha
                // de log sem rastro e num usuário que nunca recebe o e-mail.
                'exception' => $e,
            ]);
        }
    }

    /**
     * Escolhe a conta quando a busca insensível traz mais de uma.
     *
     * Duas contas que diferem só na caixa (`Ana@Adv.com` e `ana@adv.com`) só existem por
     * defeito histórico — a migration de backfill + índice único em `lower(email)` elimina
     * o estado e impede que volte. Enquanto isso, recusar as duas seria trocar "uma conta
     * trancada fora" por "duas contas trancadas fora", que é pior que o problema original.
     *
     * Por isso: se alguma bater EXATAMENTE com o que a pessoa digitou, é dela o e-mail e é
     * dela o link. Só resta ambíguo quando o digitado não bate com nenhuma — aí o silêncio
     * é o certo, porque mandar para a conta errada é pior que não mandar.
     *
     * @param User[] $encontrados
     */
    private function desempatar(array $encontrados, string $digitado): ?User
    {
        if (count($encontrados) <= 1) {
            return $encontrados[0] ?? null;
        }

        foreach ($encontrados as $candidato) {
            if ($candidato->getEmail() === $digitado) {
                return $candidato;
            }
        }

        $this->logger->error('Contas que diferem apenas na caixa do e-mail e nenhuma bate com o digitado; redefinição não enviada.', [
            'ids' => array_map(static fn (User $u): ?int => $u->getId(), $encontrados),
        ]);

        return null;
    }
}
