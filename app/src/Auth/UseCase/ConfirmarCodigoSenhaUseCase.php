<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\ConfirmarCodigoSenhaInput;
use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\Event\EmailDaContaConfirmado;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Auth\Service\ForcaDaSenha;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Passo 2 da senha por código (desenho 10): confere o código e grava a senha nova.
 *
 * Erros saem como \DomainException com o texto EXATO do desenho, na ordem do desenho:
 * código, régua de senha, confirmação. Código errado, vencido, usado, inexistente, conta
 * inexistente ou inativa dão todos o mesmo texto neutro (RN02): a resposta não pode dizer
 * se a conta existe.
 *
 * Força bruta: o pedido é buscado pela conta (nunca pelo hash) e cada código errado conta
 * uma tentativa; na quinta o pedido queima e nenhum código passa mais. Código fora do
 * formato (não 6 dígitos) é recusado sem gastar tentativa.
 *
 * Trocar o hash da senha derruba sozinho as outras sessões e os cookies "lembrar-me" (ver
 * RedefinirSenhaUseCase); o RecuperacaoSenhaControllerTest prova os dois.
 *
 * Trocar a senha pelo código confirma o e-mail da conta; se ela estava com o e-mail a confirmar, sai o
 * EmailDaContaConfirmado depois do commit, uma vez só (o UPDATE condicional decide, como no clique do link).
 */
final class ConfirmarCodigoSenhaUseCase
{
    public const ERRO_CODIGO = 'Código incorreto. Confira o e-mail ou reenvie.';
    public const ERRO_CONFIRMACAO = 'As senhas não conferem.';
    public const MAX_TENTATIVAS = 5;

    /** Acima disso o hasher do Symfony recusa a senha (proteção contra DoS no hash). */
    private const SENHA_MAXIMA = 4096;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly RedefinicaoSenhaRepository $redefinicaoRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%kernel.secret%')]
        private readonly string $segredo,
        private readonly ConfirmacaoEmailRepository $confirmacaoEmailRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \DomainException com o texto do desenho, para a resposta 422
     */
    public function executar(ConfirmarCodigoSenhaInput $input): void
    {
        // A régua vem antes do código: senha fraca não gasta tentativa (a tela já confere antes de enviar).
        // Limite em BYTES, como o do hasher do Symfony: em caracteres, acento passaria e daria 500.
        if (strlen($input->senha) > self::SENHA_MAXIMA || !ForcaDaSenha::aceita($input->senha)) {
            throw new \DomainException(ForcaDaSenha::ERRO);
        }

        if ($input->senha !== $input->confirmacao) {
            throw new \DomainException(self::ERRO_CONFIRMACAO);
        }

        $this->trocarSenha($this->conferirCodigo($input), $input->senha);
    }

    /**
     * Devolve o pedido cujo código bateu, ou lança o erro neutro. Não consome nada: com a
     * senha fraca a pessoa corrige e reenvia o mesmo código.
     */
    private function conferirCodigo(ConfirmarCodigoSenhaInput $input): RedefinicaoSenha
    {
        $codigo = trim($input->codigo);
        $user   = preg_match('/^\d{6}$/', $codigo) === 1 ? $this->localizarConta(trim($input->email)) : null;
        $pedido = $user !== null ? $this->redefinicaoRepository->encontrarPendenteMaisNovoDoUsuario($user) : null;

        if ($user === null || $pedido === null || !$this->pedidoAindaVale($pedido)) {
            throw new \DomainException(self::ERRO_CODIGO);
        }

        // Reserva a tentativa no banco ANTES de comparar: com POSTs paralelos, só os que ganharam uma das 5 vagas
        // chegam a comparar o código. Ler, comparar e só depois contar deixaria N requisições simultâneas chutarem.
        if (!$this->redefinicaoRepository->reservarTentativa($pedido, self::MAX_TENTATIVAS)) {
            throw new \DomainException(self::ERRO_CODIGO);
        }

        $esperado = SolicitarCodigoSenhaUseCase::hashDoCodigo((int) $user->getId(), $codigo, $this->segredo);

        if (!hash_equals($pedido->getTokenHash(), $esperado)) {
            $this->redefinicaoRepository->queimarSeEsgotado($pedido, self::MAX_TENTATIVAS, new \DateTimeImmutable());

            throw new \DomainException(self::ERRO_CODIGO);
        }

        return $pedido;
    }

    private function pedidoAindaVale(RedefinicaoSenha $pedido): bool
    {
        return $pedido->estaValido()
            && $pedido->getTentativas() < self::MAX_TENTATIVAS
            && $pedido->getUser()->isActive() === true;
    }

    /**
     * Consumo atômico e troca de senha numa transação só, como no RedefinirSenhaUseCase:
     * o `WHERE usado_em IS NULL` decide quem chegou primeiro quando dois POSTs trazem o
     * mesmo código, e um erro no flush não deixa o código queimado com a senha antiga.
     */
    private function trocarSenha(RedefinicaoSenha $pedido, string $novaSenha): void
    {
        $confirmouAgora = $this->em->wrapInTransaction(function () use ($pedido, $novaSenha): bool {
            $agora = new \DateTimeImmutable();

            if (!$this->redefinicaoRepository->consumir($pedido, $agora)) {
                throw new \DomainException(self::ERRO_CODIGO);
            }

            $pedido->marcarUsado($agora);

            $user = $pedido->getUser();
            $user->setPassword($this->passwordHasher->hashPassword($user, $novaSenha));
            // Quem trocou a senha com o código que chegou no e-mail provou que lê o endereço: confirma o e-mail.
            // O UPDATE condicional diz se FOI ESTA troca que confirmou; o objeto só se alinha ao banco.
            $confirmou = $this->confirmacaoEmailRepository->confirmarEmailDaConta($user);
            $user->confirmarEmail();

            $this->redefinicaoRepository->invalidarPendentesDoUsuario($user, exceto: $pedido);
            $this->redefinicaoRepository->salvar($pedido, flush: true);

            return $confirmou;
        });

        if ($confirmouAgora === true) {
            $this->despacharEmailConfirmado((int) $pedido->getUser()->getId());
        }
    }

    /** Depois do commit: a senha e o e-mail já estão gravados, e um ouvinte com defeito não pode virar erro na tela. */
    private function despacharEmailConfirmado(int $userId): void
    {
        try {
            $this->eventDispatcher->dispatch(new EmailDaContaConfirmado($userId));
        } catch (\Throwable $e) {
            $this->logger->error('Um ouvinte de EmailDaContaConfirmado falhou.', ['exception' => $e, 'userId' => $userId]);
        }
    }

    /**
     * Mesma busca do SolicitarCodigoSenhaUseCase: ignorando a caixa e, com duas contas que
     * diferem só na caixa, a que bate exatamente com o digitado (sem ela, silêncio).
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

        return null;
    }
}
