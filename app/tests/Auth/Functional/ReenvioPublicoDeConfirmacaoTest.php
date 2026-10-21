<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\ContaConfirmacaoController;
use App\Auth\Controller\ReenvioPublicoDeConfirmacaoController;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Service\ContaAConfirmar;
use App\Auth\Service\TetoDoReenvioDeConfirmacao;
use App\Entity\Auth\User;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Reenviar o link de confirmação" sem login (spec `cadastro-exige-confirmacao.md`): a conta vem só da sessão (nunca
 * de um e-mail digitado), com CSRF e os mesmos tetos da rota logada. Formulário recebe flash e volta ao login; o
 * `fetch` do modal recebe JSON.
 */
#[CoversClass(ReenvioPublicoDeConfirmacaoController::class)]
#[CoversClass(TetoDoReenvioDeConfirmacao::class)]
final class ReenvioPublicoDeConfirmacaoTest extends JusPrimeWebTestCase
{
    private const URL = '/login/reenviar-confirmacao';
    private const CSRF_VALIDO = 'TOKEN_' . ContaAConfirmar::CSRF;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        });
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function conta(bool $aConfirmar = true): User
    {
        $user = (new User())->setEmail('reenvio_' . uniqid() . '@adv.com')->setFullName('Ana Advogada')
            ->setPassword('x')->setRoles(['ROLE_USER'])->setIsActive(true);
        if ($aConfirmar) {
            $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 hour'));
        }
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function guardarNaSessao(int $id): void
    {
        $sessao = $this->client->getSession();
        $sessao->set(ContaAConfirmar::CHAVE_SESSAO, $id);
        $sessao->save();
    }

    private function pedir(string $token = self::CSRF_VALIDO): void
    {
        $this->client->request('POST', self::URL, ['_token' => $token]);
    }

    /** @return array<string, mixed> */
    private function pedirJson(string $token = self::CSRF_VALIDO): array
    {
        $this->client->jsonRequest('POST', self::URL, ['_token' => $token], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, list<string>> */
    private function flashes(): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peekAll();
    }

    private function linksDa(User $user): int
    {
        return $this->em()->getRepository(ConfirmacaoEmail::class)->count(['user' => $user->getId()]);
    }

    /** @return list<Email> */
    private function emailsEnviados(): array
    {
        $enviados = [];
        foreach (static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getEvents() as $evento) {
            if (!$evento->isQueued() && $evento->getMessage() instanceof Email) {
                $enviados[] = $evento->getMessage();
            }
        }

        return $enviados;
    }

    private function emitidos(User $user, string ...$idades): void
    {
        foreach ($idades as $i => $idade) {
            $criado = new \DateTimeImmutable($idade);
            $this->em()->persist(new ConfirmacaoEmail($user, hash('sha256', $idade . $i . uniqid()), $criado->modify('+7 days'), criadoEm: $criado));
        }
        $this->em()->flush();
    }

    #[TestDox('Sem a conta na sessão: não grava nem envia nada e responde neutro, voltando ao login')]
    public function testSemChaveNaoFazNada(): void
    {
        $user = $this->conta();

        $this->pedir();

        self::assertResponseRedirects('/login');
        self::assertSame(['info' => [ContaAConfirmar::SEM_CONTA_NA_SESSAO]], $this->flashes());
        self::assertSame([], $this->emailsEnviados());
        self::assertSame(0, $this->linksDa($user));
    }

    #[TestDox('Sem a conta na sessão, pelo fetch do modal: JSON neutro, nada enviado')]
    public function testSemChaveJson(): void
    {
        self::assertSame(['ok' => true, 'mensagem' => ContaAConfirmar::SEM_CONTA_NA_SESSAO], $this->pedirJson());
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->emailsEnviados());
    }

    #[TestDox('Com a conta na sessão: grava um link novo (sem gastar o lembrete) e envia para o e-mail da própria conta')]
    public function testComChaveReenvia(): void
    {
        $user = $this->conta();
        $this->guardarNaSessao((int) $user->getId());

        $this->pedir();

        self::assertResponseRedirects('/login');
        self::assertSame(['success' => [ContaConfirmacaoController::FLASH_ENVIADO]], $this->flashes());
        $enviados = $this->emailsEnviados();
        self::assertCount(1, $enviados);
        self::assertSame((string) $user->getEmail(), $enviados[0]->getTo()[0]->getAddress());
        $link = $this->em()->getRepository(ConfirmacaoEmail::class)->findOneBy(['user' => $user->getId()]);
        self::assertNotNull($link);
        self::assertFalse($link->isLembrete());
    }

    #[TestDox('Com a conta na sessão, pelo fetch do modal: JSON de sucesso')]
    public function testComChaveJson(): void
    {
        $user = $this->conta();
        $this->guardarNaSessao((int) $user->getId());

        self::assertSame(['ok' => true, 'mensagem' => ContaConfirmacaoController::FLASH_ENVIADO], $this->pedirJson());
        self::assertCount(1, $this->emailsEnviados());
    }

    #[TestDox('Teto de 2 minutos: a segunda chamada logo depois não reenvia e pede para aguardar')]
    public function testTetoDeDoisMinutos(): void
    {
        $user = $this->conta();
        $this->guardarNaSessao((int) $user->getId());
        $this->pedirJson();

        self::assertSame(['ok' => false, 'mensagem' => TetoDoReenvioDeConfirmacao::AGUARDE], $this->pedirJson());
        self::assertSame([], $this->emailsEnviados());
        self::assertSame(1, $this->linksDa($user));
    }

    #[TestDox('Teto diário: com 5 links nas últimas 24 h, o 6º não sai')]
    public function testTetoDiario(): void
    {
        $user = $this->conta();
        $this->emitidos($user, '-20 hours', '-10 hours', '-5 hours', '-1 hour', '-10 minutes');
        $this->guardarNaSessao((int) $user->getId());

        $this->pedir();

        self::assertSame(['warning' => [TetoDoReenvioDeConfirmacao::TETO_DIARIO_ATINGIDO]], $this->flashes());
        self::assertSame([], $this->emailsEnviados());
        self::assertSame(TetoDoReenvioDeConfirmacao::TETO_DIARIO, $this->linksDa($user));
    }

    #[TestDox('CSRF inválido: nada gravado nem enviado (403 no JSON; aviso e volta ao login no formulário)')]
    public function testCsrfInvalido(): void
    {
        $user = $this->conta();
        $this->guardarNaSessao((int) $user->getId());

        self::assertSame(['ok' => false, 'mensagem' => ReenvioPublicoDeConfirmacaoController::SESSAO_EXPIRADA], $this->pedirJson('TOKEN_errado'));
        self::assertResponseStatusCodeSame(403);

        $this->pedir('TOKEN_errado');
        self::assertResponseRedirects('/login');
        self::assertSame(['warning' => [ReenvioPublicoDeConfirmacaoController::SESSAO_EXPIRADA]], $this->flashes());
        self::assertSame([], $this->emailsEnviados());
        self::assertSame(0, $this->linksDa($user));
    }

    #[TestDox('Conta já confirmada: não envia, avisa e a chave sai da sessão')]
    public function testContaJaConfirmada(): void
    {
        $user = $this->conta(aConfirmar: false);
        $this->guardarNaSessao((int) $user->getId());

        $this->pedir();

        self::assertSame(['info' => [ContaConfirmacaoController::FLASH_JA_CONFIRMADO]], $this->flashes());
        self::assertSame([], $this->emailsEnviados());
        self::assertSame(0, $this->linksDa($user));
        self::assertFalse($this->client->getRequest()->getSession()->has(ContaAConfirmar::CHAVE_SESSAO));
    }

    #[TestDox('Chave com conta que não existe mais: resposta neutra e a chave sai da sessão')]
    public function testContaInexistente(): void
    {
        $this->guardarNaSessao(2147483000);

        $this->pedir();

        self::assertSame(['info' => [ContaAConfirmar::SEM_CONTA_NA_SESSAO]], $this->flashes());
        self::assertFalse($this->client->getRequest()->getSession()->has(ContaAConfirmar::CHAVE_SESSAO));
    }
}
