<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Integration;

use App\Entity\Auth\User;
use App\Pasta\Service\CodigoPessoal;
use App\Tenant\Service\GeradorDeCodigoDaConta;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `CodigoPessoal::garantir` na primeira visita: duas requisições ao mesmo tempo (Perfil e Recebidas, ou duas abas)
 * não podem gravar códigos diferentes. O primeiro que grava vale; o outro lê o que ficou no banco.
 */
#[CoversClass(CodigoPessoal::class)]
final class CodigoPessoalGarantirTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CodigoPessoal $servico;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->servico = self::getContainer()->get(CodigoPessoal::class);
    }

    #[TestDox('Sem código, sorteia um no formato BJ-XXXX-XXXX-XXXX, grava no banco e devolve o mesmo nas próximas vezes')]
    public function testSorteiaEGrava(): void
    {
        $pessoa = UserFactory::createOne()->_real();

        $codigo = $this->servico->garantir($pessoa);

        self::assertMatchesRegularExpression(GeradorDeCodigoDaConta::PADRAO, $codigo);
        self::assertSame($codigo, $this->noBanco($pessoa));
        self::assertSame($codigo, $pessoa->getCodigoPessoal());
        self::assertSame($codigo, $this->servico->garantir($pessoa));
    }

    #[TestDox('Outra requisição gravou primeiro: devolve o código do banco e o flush seguinte não o troca')]
    public function testOutraRequisicaoGravouPrimeiro(): void
    {
        $pessoa = UserFactory::createOne()->_real();
        self::assertNull($pessoa->getCodigoPessoal());
        // A outra aba gravou enquanto esta ainda tinha a pessoa sem código na memória.
        $this->em->getConnection()->executeStatement(
            'UPDATE "user" SET codigo_pessoal = ? WHERE id = ?',
            ['BJ-AAAA-BBBB-CCCC', $pessoa->getId()],
        );

        $codigo = $this->servico->garantir($pessoa);
        $this->em->flush();

        self::assertSame('BJ-AAAA-BBBB-CCCC', $codigo);
        self::assertSame('BJ-AAAA-BBBB-CCCC', $pessoa->getCodigoPessoal());
        self::assertSame('BJ-AAAA-BBBB-CCCC', $this->noBanco($pessoa));
    }

    #[TestDox('Grava só o código: outra mudança pendente da pessoa não vai ao banco junto')]
    public function testNaoGravaOutraMudancaPendente(): void
    {
        $pessoa = UserFactory::createOne(['fullName' => 'Nome Antigo'])->_real();
        $pessoa->setFullName('Nome Novo');

        $this->servico->garantir($pessoa);

        $nome = $this->em->getConnection()->fetchOne('SELECT full_name FROM "user" WHERE id = ?', [$pessoa->getId()]);
        self::assertSame('Nome Antigo', $nome);
    }

    private function noBanco(User $pessoa): mixed
    {
        return $this->em->getConnection()->fetchOne('SELECT codigo_pessoal FROM "user" WHERE id = ?', [$pessoa->getId()]);
    }
}
