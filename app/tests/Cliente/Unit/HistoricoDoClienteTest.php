<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClienteHistorico;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Repository\ClienteHistoricoRepository;
use App\Cliente\Service\CamposDoCadastro;
use App\Cliente\Service\HistoricoDoCliente;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Textos da aba Histórico do cadastro (desenho 1.2.3, `cadSalvar`; E12 3d): só rótulos, nunca valores; documento pelo
 * tipo, sem o nome do arquivo.
 */
#[CoversClass(HistoricoDoCliente::class)]
#[CoversClass(ClienteHistorico::class)]
#[CoversClass(CamposDoCadastro::class)]
final class HistoricoDoClienteTest extends TestCase
{
    /** @var list<ClienteHistorico> */
    private array $gravadas = [];

    #[TestDox('alterou: "<autor> alterou nome, CPF, estado civil", na ordem recebida e sem valores')]
    public function testAlterouSoComRotulos(): void
    {
        [$sut, $cliente, $tenant, $autor] = $this->montar();

        $sut->camposAlterados($cliente, $autor, $tenant, ['nome', 'CPF', 'estado civil']);

        self::assertCount(1, $this->gravadas);
        self::assertSame('Ana Souza alterou nome, CPF, estado civil', $this->gravadas[0]->getTexto());
        self::assertSame($autor, $this->gravadas[0]->getAutor());
        self::assertSame($tenant, $this->gravadas[0]->getTenant());
        self::assertSame($cliente, $this->gravadas[0]->getCliente());
    }

    #[TestDox('um valor no lugar do rótulo (CPF, nome) é descartado: o histórico nunca guarda dado do campo')]
    public function testValorNuncaEntra(): void
    {
        [$sut, $cliente, $tenant, $autor] = $this->montar();

        $sut->camposAlterados($cliente, $autor, $tenant, ['529.982.247-25', 'bairro', 'MARIA DAS DORES', 'bairro']);

        self::assertSame('Ana Souza alterou bairro', $this->gravadas[0]->getTexto());
        self::assertStringNotContainsString('529', $this->gravadas[0]->getTexto());
        self::assertStringNotContainsString('MARIA', $this->gravadas[0]->getTexto());
    }

    #[TestDox('nada (ou só desconhecido) alterado não grava linha')]
    public function testSemRotuloNaoGrava(): void
    {
        [$sut, $cliente, $tenant, $autor] = $this->montar();

        $sut->camposAlterados($cliente, $autor, $tenant, []);
        $sut->camposAlterados($cliente, $autor, $tenant, ['123.456.789-00']);

        self::assertSame([], $this->gravadas);
    }

    #[TestDox('criou: "<autor> criou o cadastro"; sem nome, "Usuário"')]
    public function testCriou(): void
    {
        [$sut, $cliente, $tenant, $autor] = $this->montar();
        $semNome = new User();
        $semNome->setFullName('  ');

        $sut->cadastroCriado($cliente, $autor, $tenant);
        $sut->cadastroCriado($cliente, $semNome, $tenant);

        self::assertSame(['Ana Souza criou o cadastro', 'Usuário criou o cadastro'], array_map(static fn (ClienteHistorico $h): string => $h->getTexto(), $this->gravadas));
    }

    #[TestDox('documento: "<autor> anexou documento de identificação" e "removeu comprovante de residência", sem o nome do arquivo')]
    public function testDocumentoPeloTipo(): void
    {
        [$sut, $cliente, $tenant, $autor] = $this->montar();

        $sut->documentoAnexado($this->documento($cliente, $tenant, ClienteDocumento::CATEGORIA_IDENTIFICACAO), $autor);
        $sut->documentoRemovido($this->documento($cliente, $tenant, ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA), $autor);
        $sut->documentoAnexado($this->documento($cliente, $tenant, 'OUTRA'), $autor);

        self::assertSame([
            'Ana Souza anexou documento de identificação',
            'Ana Souza removeu comprovante de residência',
            'Ana Souza anexou documento',
        ], array_map(static fn (ClienteHistorico $h): string => $h->getTexto(), $this->gravadas));
        foreach ($this->gravadas as $h) {
            self::assertStringNotContainsString('rg-maria', $h->getTexto());
        }
    }

    #[TestDox('documento alterado entra pelo tipo que tinha antes; rótulo de campo fora da janela (telefone fixo) é aceito')]
    public function testDocumentoAlteradoECampoForaDaJanela(): void
    {
        [$sut, $cliente, $tenant, $autor] = $this->montar();

        $sut->documentoAlterado($this->documento($cliente, $tenant, ClienteDocumento::CATEGORIA_PROCURACAO), $autor, ClienteDocumento::CATEGORIA_CONTRATO);
        $sut->camposAlterados($cliente, $autor, $tenant, ['telefone fixo', 'emissão do RG']);

        self::assertSame(['Ana Souza alterou contrato', 'Ana Souza alterou telefone fixo, emissão do RG'], array_map(static fn (ClienteHistorico $h): string => $h->getTexto(), $this->gravadas));
    }

    #[TestDox('rotulosAlterados: só os campos que mudaram, pelo rótulo, na ordem da fotografia')]
    public function testRotulosAlterados(): void
    {
        $antes = ['nome' => 'ANA', 'cpf' => '52998224725', 'bairro' => '', 'telefone_fixo' => ''];
        $depois = ['nome' => 'ANA', 'cpf' => '11144477735', 'bairro' => 'ASA SUL', 'telefone_fixo' => '(61) 3425-8844', 'desconhecido' => 'x'];

        self::assertSame(['CPF', 'bairro', 'telefone fixo'], CamposDoCadastro::rotulosAlterados($antes, $depois));
    }

    #[TestDox('cliente de outro escritório: recusa, nada gravado')]
    public function testOutroEscritorio(): void
    {
        [$sut, $cliente, , $autor] = $this->montar();

        try {
            $sut->camposAlterados($cliente, $autor, self::tenant(99), ['nome']);
            self::fail('deveria recusar');
        } catch (\DomainException) {
        }

        self::assertSame([], $this->gravadas);
    }

    #[TestDox('tela: q "dd/mm/aaaa hh:mm" e t; cliente sem id (novo) não consulta e devolve vazio')]
    public function testParaTela(): void
    {
        $repo = $this->createMock(ClienteHistoricoRepository::class);
        $tenant = self::tenant(1);
        $cliente = (new ClientePF())->setTenant($tenant);
        $sut = new HistoricoDoCliente($repo);
        $repo->expects(self::never())->method('ultimosDoCliente');

        self::assertSame([], $sut->paraTela($cliente, $tenant));
    }

    #[TestDox('tela: pede as 40 mais novas ao repositório com o escritório e formata a data')]
    public function testParaTelaFormata(): void
    {
        $repo = $this->createMock(ClienteHistoricoRepository::class);
        $tenant = self::tenant(1);
        $cliente = (new ClientePF())->setTenant($tenant);
        (new \ReflectionProperty(Cliente::class, 'id'))->setValue($cliente, 7);
        $linha = new ClienteHistorico($tenant, $cliente, null, 'Ana Souza alterou nome');
        (new \ReflectionProperty($linha, 'em'))->setValue($linha, new \DateTimeImmutable('2026-10-19 08:05:00'));
        $repo->expects(self::once())->method('ultimosDoCliente')->with($cliente, $tenant, 40)->willReturn([$linha]);

        self::assertSame([['q' => '19/10/2026 08:05', 't' => 'Ana Souza alterou nome']], (new HistoricoDoCliente($repo))->paraTela($cliente, $tenant));
    }

    #[TestDox('texto vazio não vira linha; texto longo é cortado no tamanho da coluna')]
    public function testEntidade(): void
    {
        $tenant = self::tenant(1);
        $cliente = (new ClientePF())->setTenant($tenant);

        self::assertSame(500, mb_strlen((new ClienteHistorico($tenant, $cliente, null, str_repeat('á', 600)))->getTexto()));
        $this->expectException(\DomainException::class);
        new ClienteHistorico($tenant, $cliente, null, '   ');
    }

    /** @return array{0: HistoricoDoCliente, 1: ClientePF, 2: Tenant, 3: User} */
    private function montar(): array
    {
        $repo = $this->createMock(ClienteHistoricoRepository::class);
        $repo->method('registrar')->willReturnCallback(function (ClienteHistorico $h): void {
            $this->gravadas[] = $h;
        });
        $tenant = self::tenant(1);
        $cliente = (new ClientePF())->setTenant($tenant);
        $autor = new User();
        $autor->setFullName('Ana Souza');

        return [new HistoricoDoCliente($repo), $cliente, $tenant, $autor];
    }

    private function documento(ClientePF $cliente, Tenant $tenant, string $categoria): ClienteDocumento
    {
        return (new ClienteDocumento())->setCliente($cliente)->setTenant($tenant)->setCategoria($categoria)
            ->setTitulo('rg-maria-529.pdf')->setNomeOriginal('rg-maria-529.pdf');
    }

    private static function tenant(int $id): Tenant
    {
        $t = new Tenant();
        (new \ReflectionProperty($t, 'id'))->setValue($t, $id);

        return $t;
    }
}
