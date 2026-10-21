<?php

declare(strict_types=1);

namespace App\Tests\Auth\Integration;

use App\Auth\DTO\CriarSenhaPeloLinkInput;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Enum\SituacaoDoLinkDeConfirmacao;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Auth\UseCase\CriarSenhaPeloLinkUseCase;
use App\Entity\Auth\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A corrida de dois "Criar senha e confirmar" ao mesmo tempo (spec `cadastro-senha-no-link.md`), com o banco de
 * verdade. Os dois pedidos leram a conta ainda a confirmar; o primeiro grava. O segundo chega ao UPDATE condicional
 * com o objeto velho na memória: não acha mais a pendência e não grava a senha dele.
 */
#[CoversClass(ConfirmacaoEmailRepository::class)]
#[CoversClass(CriarSenhaPeloLinkUseCase::class)]
final class CriarSenhaPeloLinkCorridaTest extends KernelTestCase
{
    private string $token;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->token = bin2hex(random_bytes(32));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function contaAConfirmar(): ConfirmacaoEmail
    {
        $user = (new User())->setEmail('corrida_' . uniqid() . '@adv.com')->setFullName('Ana Souza')
            ->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('hash-inutilizavel');
        $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 hour'));
        $confirmacao = new ConfirmacaoEmail($user, ConfirmacaoEmail::hashDoToken($this->token), new \DateTimeImmutable('+6 days'));
        $this->em()->persist($user);
        $this->em()->persist($confirmacao);
        $this->em()->flush();

        return $confirmacao;
    }

    /** @return array{password: string, email_a_confirmar_desde: ?string} */
    private function linhaDoUsuario(int $id): array
    {
        /** @var array{password: string, email_a_confirmar_desde: ?string} $linha */
        $linha = static::getContainer()->get(Connection::class)
            ->fetchAssociative('SELECT password, email_a_confirmar_desde FROM "user" WHERE id = :id', ['id' => $id]);

        return $linha;
    }

    #[TestDox('Repositório: o segundo UPDATE não acha a pendência e não troca a senha gravada pelo primeiro')]
    public function testSoOPrimeiroGravaASenha(): void
    {
        $confirmacao = $this->contaAConfirmar();
        $repositorio = static::getContainer()->get(ConfirmacaoEmailRepository::class);
        $agora = new \DateTimeImmutable();

        self::assertTrue($repositorio->criarSenhaEConfirmarComLink($confirmacao, 'hash-do-primeiro', $agora));
        self::assertFalse($repositorio->criarSenhaEConfirmarComLink($confirmacao, 'hash-do-segundo', $agora));

        $linha = $this->linhaDoUsuario((int) $confirmacao->getUser()->getId());
        self::assertSame('hash-do-primeiro', $linha['password']);
        self::assertNull($linha['email_a_confirmar_desde']);
        $usadoEm = static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT usado_em FROM confirmacao_email WHERE id = :id', ['id' => $confirmacao->getId()]);
        self::assertNotNull($usadoEm);
    }

    #[TestDox('Caso de uso: quem chega ao UPDATE depois do outro recebe "já confirmado" e a senha do primeiro fica')]
    public function testQuemPerdeACorridaNaoGravaASenha(): void
    {
        $confirmacao = $this->contaAConfirmar();
        $id = (int) $confirmacao->getUser()->getId();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $hashDoPrimeiro = $hasher->hashPassword($confirmacao->getUser(), 'primeiraSenha1');

        // O outro pedido ganhou entre a leitura e o UPDATE deste: o banco mudou, o objeto na memória não.
        static::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE "user" SET password = :senha, email_a_confirmar_desde = NULL WHERE id = :id',
            ['senha' => $hashDoPrimeiro, 'id' => $id],
        );
        self::assertFalse($confirmacao->getUser()->isEmailConfirmado(), 'o objeto em memória ainda está a confirmar');

        $situacao = static::getContainer()->get(CriarSenhaPeloLinkUseCase::class)
            ->executar(new CriarSenhaPeloLinkInput($this->token, 'segundaSenha2', 'segundaSenha2'));

        self::assertSame(SituacaoDoLinkDeConfirmacao::JaConfirmado, $situacao);
        self::assertSame($hashDoPrimeiro, $this->linhaDoUsuario($id)['password']);
    }
}
