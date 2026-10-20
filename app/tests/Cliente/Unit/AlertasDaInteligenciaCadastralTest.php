<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\DTO\AlertaDaInteligenciaCadastral;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Service\AlertasDaInteligenciaCadastral;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Repository\PessoaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Alertas da Inteligência cadastral (F-A): homônimo (PF e PJ), mesmo documento com outro nome em outro cliente e na
 * Cobrança (esta só para quem acessa o módulo de cobranças). Nenhum CPF/CNPJ no texto.
 */
#[CoversClass(AlertasDaInteligenciaCadastral::class)]
final class AlertasDaInteligenciaCadastralTest extends TestCase
{
    private const CPF = '52998224725';

    #[TestDox('homônimo PF: conta os outros com o mesmo nome normalizado e CPF diferente; o mesmo CPF não é homônimo')]
    public function testHomonimoPf(): void
    {
        $cliente = self::pf('José da Silva', '529.982.247-25');
        $sut = $this->sut(nomes: [
            ['id' => 2, 'nome' => 'JOSE  DA SILVA.', 'documento' => '111.444.777-35'],
            ['id' => 3, 'nome' => 'José da Silva', 'documento' => '52998224725'],
            ['id' => 4, 'nome' => 'José da Silva', 'documento' => '390.533.447-05'],
        ]);

        $alertas = $sut->de($cliente, self::CPF, new User(), self::tenant());

        self::assertCount(1, $alertas);
        self::assertSame(AlertaDaInteligenciaCadastral::HOMONIMO, $alertas[0]->tipo);
        self::assertSame('bi bi-people', $alertas[0]->ic);
        self::assertSame('Possível homônimo.', $alertas[0]->negrito);
        self::assertStringStartsWith('2 outro(s) cadastro(s) com o mesmo nome e CPF diferente.', $alertas[0]->texto);
    }

    #[TestDox('homônimo PJ: "mesma razão social e CNPJ diferente"')]
    public function testHomonimoPj(): void
    {
        $cliente = (new ClientePJ())->setRazaoSocial('ACME Ltda')->setCnpj('11222333000181')->setTenant(self::tenant());
        $sut = $this->sut(nomes: [['id' => 2, 'nome' => 'Acme LTDA.', 'documento' => '11.444.777/0001-61']]);

        $alertas = $sut->de($cliente, '11222333000181', new User(), self::tenant());

        self::assertCount(1, $alertas);
        self::assertStringStartsWith('1 outro(s) cadastro(s) com a mesma razão social e CNPJ diferente.', $alertas[0]->texto);
    }

    #[TestDox('sem outro com o mesmo nome (ou só com o mesmo documento): nenhum alerta')]
    public function testSemAlerta(): void
    {
        $sut = $this->sut(nomes: [['id' => 3, 'nome' => 'José da Silva', 'documento' => '529.982.247-25']]);

        self::assertSame([], $sut->de(self::pf('José da Silva', '529.982.247-25'), self::CPF, new User(), self::tenant()));
    }

    #[TestDox('mesmo CPF com outro nome em outro cliente visível: nome, origem e o link; sem o CPF no texto')]
    public function testMesmoDocumentoOutroClienteVisivel(): void
    {
        $sut = $this->sut(mesmos: [
            ['id' => 8, 'nome' => 'JOSÉ DA SILVA', 'documento' => '52998224725'],
            ['id' => 9, 'nome' => 'J. da Silva', 'documento' => '52998224725'],
        ], vePastaOuCliente: true);

        $alertas = $sut->de(self::pf('José da Silva', '529.982.247-25'), self::CPF, new User(), self::tenant());

        self::assertCount(1, $alertas);
        self::assertSame(AlertaDaInteligenciaCadastral::MESMO_DOCUMENTO, $alertas[0]->tipo);
        self::assertSame('Mesmo CPF com outro nome.', $alertas[0]->negrito);
        self::assertSame('"J. da Silva" em outro cadastro de cliente. Pode ser abreviação ou cadastro duplicado; confira antes de consolidar.', $alertas[0]->texto);
        self::assertSame(9, $alertas[0]->abrirClienteId);
        self::assertStringNotContainsString('529', $alertas[0]->texto);
    }

    #[TestDox('outro cliente que o usuário não vê: o alerta fica, sem o nome dele e sem link')]
    public function testMesmoDocumentoOutroClienteInvisivel(): void
    {
        $sut = $this->sut(mesmos: [['id' => 9, 'nome' => 'J. da Silva', 'documento' => '52998224725']], vePastaOuCliente: false);

        $alertas = $sut->de(self::pf('José da Silva', '529.982.247-25'), self::CPF, new User(), self::tenant());

        self::assertCount(1, $alertas);
        self::assertNull($alertas[0]->abrirClienteId);
        self::assertStringNotContainsString('J. da Silva', $alertas[0]->texto);
        self::assertStringStartsWith('Há outro cadastro de cliente com outro nome.', $alertas[0]->texto);
    }

    #[TestDox('Cobrança: pessoa com o mesmo CPF e outro nome entra com a origem "Cobranças" só para quem acessa o módulo')]
    public function testCobrancaSoComModulo(): void
    {
        $pessoas = [self::pessoa('José da Silva'), self::pessoa('Gleisson B. Gabriel')];

        $com = $this->sut(pessoas: $pessoas, moduloCobrancas: true)->de(self::pf('José da Silva', '529.982.247-25'), self::CPF, new User(), self::tenant());
        $sem = $this->sut(pessoas: $pessoas, moduloCobrancas: false)->de(self::pf('José da Silva', '529.982.247-25'), self::CPF, new User(), self::tenant());

        self::assertCount(1, $com);
        self::assertSame('"Gleisson B. Gabriel" em Cobranças. Pode ser abreviação ou cadastro duplicado; confira antes de consolidar.', $com[0]->texto);
        self::assertNull($com[0]->abrirClienteId);
        self::assertSame([], $sem);
    }

    #[TestDox('Cobrança sem o módulo: o banco nem é consultado')]
    public function testCobrancaSemModuloNaoConsulta(): void
    {
        $pessoas = $this->createMock(PessoaRepository::class);
        $pessoas->expects(self::never())->method('buscarPossiveisDuplicadas');
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessModule')->willReturn(false);
        $clientes = $this->createStub(ClienteRepository::class);
        $clientes->method('comONomeNormalizado')->willReturn([]);
        $clientes->method('comOMesmoDocumento')->willReturn([]);

        self::assertSame([], (new AlertasDaInteligenciaCadastral($clientes, $pessoas, $permissoes))->de(self::pf('Ana', '529.982.247-25'), self::CPF, new User(), self::tenant()));
    }

    /**
     * @param list<array{id: int, nome: string, documento: string}> $nomes
     * @param list<array{id: int, nome: string, documento: string}> $mesmos
     * @param list<Pessoa>                                           $pessoas
     */
    private function sut(array $nomes = [], array $mesmos = [], array $pessoas = [], bool $vePastaOuCliente = true, bool $moduloCobrancas = false): AlertasDaInteligenciaCadastral
    {
        $clientes = $this->createStub(ClienteRepository::class);
        $clientes->method('comONomeNormalizado')->willReturn($nomes);
        $clientes->method('comOMesmoDocumento')->willReturn($mesmos);
        $repoPessoas = $this->createStub(PessoaRepository::class);
        $repoPessoas->method('buscarPossiveisDuplicadas')->willReturn($pessoas);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessResource')->willReturn($vePastaOuCliente);
        $permissoes->method('canAccessModule')->willReturn($moduloCobrancas);

        return new AlertasDaInteligenciaCadastral($clientes, $repoPessoas, $permissoes);
    }

    private static function pf(string $nome, string $cpf): Cliente
    {
        $c = (new ClientePF())->setNomeCompleto($nome)->setCpf($cpf);
        $c->setTenant(self::tenant());
        (new \ReflectionProperty(Cliente::class, 'id'))->setValue($c, 1);

        return $c;
    }

    private static function pessoa(string $nome): Pessoa
    {
        return (new Pessoa())->setNome($nome)->setCpf(self::CPF)->setTenant(self::tenant());
    }

    private static function tenant(): Tenant
    {
        static $t = null;
        if ($t === null) {
            $t = new Tenant();
            (new \ReflectionProperty($t, 'id'))->setValue($t, 1);
        }

        return $t;
    }
}
