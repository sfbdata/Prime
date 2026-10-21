<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\CriarSenhaPeloLinkInput;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Enum\SituacaoDoLinkDeConfirmacao;
use App\Auth\Event\EmailDaContaConfirmado;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Auth\Service\ForcaDaSenha;
use App\Auth\Service\LinkDeConfirmacaoDeEmail;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Legal\PoliticaPrivacidadeVigente;
use App\Repository\TenantRepository;
use App\Termo\Entity\AceiteTermo;
use App\Termo\Entity\CienciaPolitica;
use App\Termo\Enum\OrigemDoAceite;
use App\Termo\TermoVigente;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * "Crie sua senha" do link de confirmação (spec `cadastro-senha-no-link.md`): quem clica no link cria a senha naquela
 * hora; a do cadastro nunca valeu. Fecha a brecha do terceiro que cadastra o e-mail de outra pessoa com uma senha que
 * ele escolheu.
 *
 * Ordem: o token (as regras do LinkDeConfirmacaoDeEmail; inválido ou vencido sai como \DomainException), a conta já
 * confirmada (nada é trocado nem conferido), a caixa "Li e aceito" (o texto do cadastro), a régua da senha e a
 * confirmação (\InvalidArgumentException com os textos da troca de senha por código). Nenhum desses erros consome o
 * link. Numa transação só, a senha, a confirmação, a marca de uso do link e um aceite novo dos Termos e da ciência da
 * Política (D2: origem `link_confirmacao`, versão vigente, IP e user agent do pedido; os do cadastro ficam); só quem
 * ganha a corrida grava. EmailDaContaConfirmado sai depois do commit, uma vez só. Não faz login.
 */
final class CriarSenhaPeloLinkUseCase
{
    public function __construct(
        private readonly LinkDeConfirmacaoDeEmail $link,
        private readonly ConfirmacaoEmailRepository $confirmacaoEmailRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $relogio,
        private readonly TermoVigente $termoVigente,
        private readonly TenantRepository $tenantRepository,
    ) {
    }

    /**
     * @throws \DomainException         link inválido ou vencido (a página de erro)
     * @throws \InvalidArgumentException sem o aceite, senha fraca ou diferente da confirmação (volta ao formulário)
     */
    public function executar(CriarSenhaPeloLinkInput $input): SituacaoDoLinkDeConfirmacao
    {
        $confirmacao = $this->link->pendente($input->token);

        if ($confirmacao === null) {
            return SituacaoDoLinkDeConfirmacao::JaConfirmado;
        }

        if (!$input->aceite) {
            throw new \InvalidArgumentException(CriarContaEEscritorioUseCase::ERRO_ACEITE);
        }

        self::exigirSenhaValida($input);

        if (!$this->criarSenhaEConfirmar($confirmacao, $input)) {
            return SituacaoDoLinkDeConfirmacao::JaConfirmado;
        }

        $this->despacharEvento((int) $confirmacao->getUser()->getId());

        return SituacaoDoLinkDeConfirmacao::SenhaCriada;
    }

    /** A régua vem antes da igualdade, como na troca de senha por código; o máximo é o mesmo do hasher. */
    private static function exigirSenhaValida(CriarSenhaPeloLinkInput $input): void
    {
        if (strlen($input->senha) > ForcaDaSenha::MAXIMO_EM_BYTES || !ForcaDaSenha::aceita($input->senha)) {
            throw new \InvalidArgumentException(ForcaDaSenha::ERRO);
        }

        if ($input->senha !== $input->confirmacao) {
            throw new \InvalidArgumentException(ConfirmarCodigoSenhaUseCase::ERRO_CONFIRMACAO);
        }
    }

    /**
     * O hash é calculado fora da transação (é a parte cara); o UPDATE condicional do repositório decide quem ganhou.
     * Quem perdeu não troca nada; quem ganhou só alinha os objetos em memória ao que o banco já tem.
     */
    private function criarSenhaEConfirmar(ConfirmacaoEmail $confirmacao, CriarSenhaPeloLinkInput $input): bool
    {
        $user = $confirmacao->getUser();
        $hash = $this->passwordHasher->hashPassword($user, $input->senha);
        $agora = $this->relogio->now();
        $ganhou = $this->confirmacaoEmailRepository->criarSenhaEConfirmarComLink(
            $confirmacao,
            $hash,
            $agora,
            new AceiteTermo(
                user: $user,
                versao: $this->termoVigente->getVersao(),
                ip: $input->ip,
                userAgent: $input->userAgent,
                tenant: $this->escritorioDoDono($user),
                origem: OrigemDoAceite::LinkConfirmacao,
            ),
            new CienciaPolitica(
                user: $user,
                versao: PoliticaPrivacidadeVigente::VERSAO,
                ip: $input->ip,
                userAgent: $input->userAgent,
                cienteEm: $agora,
                origem: OrigemDoAceite::LinkConfirmacao,
            ),
        );

        if (!$ganhou) {
            return false;
        }

        $user->setPassword($hash);
        $user->confirmarEmail();
        $confirmacao->marcarUsado($agora);

        return true;
    }

    /**
     * O escritório de que a pessoa é dona (criadoPor), como no aceite do cadastro; sem escritório, nulo. A conta do
     * cadastro tem um só; se houver mais, o mais antigo.
     */
    private function escritorioDoDono(User $user): ?Tenant
    {
        return $this->tenantRepository->findOneBy(['criadoPor' => $user], ['id' => 'ASC']);
    }

    /** A senha e o e-mail já estão gravados: um ouvinte com defeito não pode virar erro na tela. */
    private function despacharEvento(int $userId): void
    {
        try {
            $this->eventDispatcher->dispatch(new EmailDaContaConfirmado($userId));
        } catch (\Throwable $e) {
            $this->logger->error('Um ouvinte de EmailDaContaConfirmado falhou.', ['exception' => $e, 'userId' => $userId]);
        }
    }
}
