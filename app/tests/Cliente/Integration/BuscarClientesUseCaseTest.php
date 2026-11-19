<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Integration;

use App\Cliente\Entity\ClientePF;
use App\Cliente\UseCase\BuscarClientesUseCase;
use App\Entity\Tenant\Tenant;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A busca de clientes sem sessão: ordem (documento inteiro antes do nome), o escritório e o termo curto. O escopo do
 * acesso restrito está provado pela tela (`AcessoRestritoClienteEProcessoTest`) e pela `ler_clientes`
 * (`LerClientesFerramentaTest`), que usam este UseCase.
 */
#[CoversClass(BuscarClientesUseCase::class)]
final class BuscarClientesUseCaseTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const CPF = '11144477735';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    #[TestDox('o CPF inteiro casa primeiro; o nome que contém o termo vem depois, mesmo cadastrado antes')]
    public function testDocumentoAntesDoNome(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $escritorio);
        $peloNome = $this->cliente($escritorio, 'Ana 111.444.777-35', '');
        $peloCpf = $this->cliente($escritorio, 'Bruno', self::CPF);

        $achados = $this->buscar()->executar('111.444.777-35', $pessoa, $escritorio);

        self::assertSame([$peloCpf->getId(), $peloNome->getId()], array_map(static fn ($a) => $a->cliente->getId(), $achados));
        self::assertTrue($achados[0]->documentoInteiro);
        self::assertSame(self::CPF, $achados[0]->documentoDigitos);
        self::assertFalse($achados[1]->documentoInteiro);
    }

    #[TestDox('só o escritório de quem busca; termo com menos de 2 caracteres não busca')]
    public function testEscritorioETermoCurto(): void
    {
        $escritorio = $this->escritorio();
        $outro = $this->escritorio('Outro');
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $escritorio);
        $meu = $this->cliente($escritorio, 'Carla Daqui', '');
        $this->cliente($outro, 'Carla Dali', '');

        self::assertSame([$meu->getId()], array_map(static fn ($a) => $a->cliente->getId(), $this->buscar()->executar('carla', $pessoa, $escritorio)));
        self::assertSame([], $this->buscar()->executar(' c ', $pessoa, $escritorio));
    }

    private function buscar(): BuscarClientesUseCase
    {
        return static::getContainer()->get(BuscarClientesUseCase::class);
    }

    private function cliente(Tenant $escritorio, string $nome, string $cpf): ClientePF
    {
        $cliente = (new ClientePF())->setNomeCompleto($nome)->setCpf($cpf)->setRg('')->setRgOrgaoExpedidor('')->setNacionalidade('brasileira');
        $cliente->setEmail('')->setCep('')->setEndereco('')->setCidade('Brasília')->setEstado('DF')->setTenant($escritorio);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }
}
