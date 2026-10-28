<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\ComporNomeDaPastaJudicial;
use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedorIdentificadorDaPasta::class)]
final class ResolvedorIdentificadorClientePrincipalTest extends TestCase
{
    #[Test]
    #[TestDox('Pasta comum: PJ principal mostra a fantasia, mesmo com razão social composta ("a - b")')]
    public function fantasiaVenceRazaoComposta(): void
    {
        $pasta = $this->pasta(1, (new ClientePJ())->setRazaoSocial('ACME LTDA - JOAO')->setNomeFantasia('ACME'));

        self::assertSame('ACME', $this->resolvedor()->para($pasta));
    }

    #[Test]
    #[TestDox('Pasta comum: PJ principal sem fantasia mostra a razão social')]
    public function semFantasiaMostraRazao(): void
    {
        $pasta = $this->pasta(2, (new ClientePJ())->setRazaoSocial('ACME LTDA')->setNomeFantasia('  '));

        self::assertSame('ACME LTDA', $this->resolvedor()->para($pasta));
    }

    #[Test]
    #[TestDox('Pasta comum: PF principal mostra o nome completo; o gravado composto fica só como reserva')]
    public function pessoaFisicaMostraNomeCompleto(): void
    {
        $cliente = (new ClientePF())->setNomeCompleto('MARIA SOUZA');
        $pasta = $this->pasta(3, $cliente);
        $pasta->setNomeCliente('MARIA SOUZA - OUTRO');

        self::assertSame('MARIA SOUZA', $this->resolvedor()->para($pasta));
    }

    #[Test]
    #[TestDox('Pasta sem cliente e sem caso mostra o nome gravado')]
    public function semClienteMostraGravado(): void
    {
        $pasta = $this->pasta(4, null);
        $pasta->setNomeCliente('TEXTO LIVRE');

        self::assertSame('TEXTO LIVRE', $this->resolvedor()->para($pasta));
    }

    private function resolvedor(): ResolvedorIdentificadorDaPasta
    {
        $casos = $this->createStub(CasoCobrancaRepository::class);
        $casos->method('clienteDaCarteiraPorPasta')->willReturn([]);

        return new ResolvedorIdentificadorDaPasta($casos, new ComporNomeDaPastaJudicial());
    }

    private function pasta(int $id, ClientePJ|ClientePF|null $principal): Pasta
    {
        $pasta = (new Pasta())->setTenant(new Tenant());
        (new \ReflectionProperty($pasta, 'id'))->setValue($pasta, $id);
        if ($principal !== null) {
            $pasta->addCliente($principal);
        }

        return $pasta;
    }
}
