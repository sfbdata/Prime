<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Unit;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\Repository\ClienteRepository;
use App\Cliente\Repository\DocumentoVinculadoRepository;
use App\Cliente\Service\AlertasDaInteligenciaCadastral;
use App\Cliente\Service\DocumentosVisiveisDoCadastro;
use App\Cliente\Service\RegrasDosEncontrados;
use App\Cliente\UseCase\MontarInteligenciaCadastralUseCase;
use App\Cobranca\Repository\PessoaRepository;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Retrato do bloco "Inteligência cadastral" (F-A): só com CPF/CNPJ válido; "Encontrados" = documentos de identificação
 * e comprovante das pastas do cliente que o usuário vê, ainda não vinculados; o subtítulo conta documentos e pastas.
 */
#[CoversClass(MontarInteligenciaCadastralUseCase::class)]
final class MontarInteligenciaCadastralUseCaseTest extends TestCase
{
    #[TestDox('CPF inválido, vazio ou PJ com CNPJ inválido: o bloco não aparece e nada é consultado')]
    public function testDocumentoInvalidoDesliga(): void
    {
        $pastas = $this->createMock(PastaRepository::class);
        $pastas->expects(self::never())->method('findByCliente');
        $sut = $this->sut(pastas: $pastas);

        foreach (['', '111.111.111-11', '529.982.247-24'] as $cpf) {
            self::assertFalse($sut->executar(self::pf($cpf), new User(), self::tenant(1))->on, $cpf);
        }
        $pj = (new ClientePJ())->setRazaoSocial('ACME')->setCnpj('11222333000180');
        $pj->setTenant(self::tenant(1));
        self::assertFalse($sut->executar($pj, new User(), self::tenant(1))->on);
    }

    #[TestDox('cliente de outro escritório: ClienteNaoEncontradoException')]
    public function testOutroEscritorio(): void
    {
        $this->expectException(ClienteNaoEncontradoException::class);

        $this->sut()->executar(self::pf('529.982.247-25'), new User(), self::tenant(2));
    }

    #[TestDox('encontrados: só das pastas que o usuário vê e sem os já vinculados; subtítulo com documentos e pastas')]
    public function testEncontrados(): void
    {
        $vista = self::pasta(10, '1180');
        $outra = self::pasta(11, '1176');
        $escondida = self::pasta(12, '9999');
        $rg = self::documento(100, $vista, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG.pdf');
        $conta = self::documento(101, $outra, PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA, 'conta.pdf');
        $jaVinculado = self::documento(102, $vista, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'CNH.pdf');

        $repoPastas = $this->createStub(PastaRepository::class);
        $repoPastas->method('findByCliente')->willReturn([$vista, $outra, $escondida]);
        $docs = $this->createMock(PastaDocumentoRepository::class);
        $docs->expects(self::once())->method('identificacaoEComprovanteDasPastas')
            ->with([$vista, $outra], self::anything())->willReturn([$rg, $conta, $jaVinculado]);
        $vinculados = $this->createStub(DocumentoVinculadoRepository::class);
        $vinculados->method('idsDePastaDocumentoVinculados')->willReturn([102]);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessResource')->willReturnCallback(
            static fn (User $u, Tenant $t, string $tipo, int $id, string $acao): bool => $tipo === AccessRequest::RESOURCE_PASTA ? $id !== 12 : $acao === AccessRequest::ACTION_EDIT,
        );

        $saida = $this->sut($repoPastas, $docs, $vinculados, $permissoes)->executar(self::pf('529.982.247-25'), new User(), self::tenant(1));

        self::assertTrue($saida->on);
        self::assertSame('3 documento(s) deste cliente em 2 pasta(s)', $saida->subtitulo);
        self::assertSame([100, 101], array_map(static fn ($e): int => $e->id, $saida->encontrados));
        self::assertSame('RG.pdf', $saida->encontrados[0]->arquivo);
        self::assertSame('Documento de identificação', $saida->encontrados[0]->categoriaRotulo);
        self::assertSame('Pasta 1180', $saida->encontrados[0]->pasta);
        self::assertSame('Comprovante de residência', $saida->encontrados[1]->categoriaRotulo);
        self::assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4}$#', $saida->encontrados[0]->data);
        self::assertTrue($saida->encontrados[0]->podeVincular);
        self::assertStringNotContainsString('IA', $saida->nota);
    }

    #[TestDox('sem permissão de editar o cliente: os encontrados aparecem sem "Vincular ao cadastro"')]
    public function testSemEdicaoNaoVincula(): void
    {
        $pasta = self::pasta(10, '1180');
        $repoPastas = $this->createStub(PastaRepository::class);
        $repoPastas->method('findByCliente')->willReturn([$pasta]);
        $docs = $this->createStub(PastaDocumentoRepository::class);
        $docs->method('identificacaoEComprovanteDasPastas')->willReturn([self::documento(100, $pasta, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG.pdf')]);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessResource')->willReturnCallback(static fn (User $u, Tenant $t, string $tipo): bool => $tipo === AccessRequest::RESOURCE_PASTA);

        $saida = $this->sut($repoPastas, $docs, null, $permissoes)->executar(self::pf('529.982.247-25'), new User(), self::tenant(1));

        self::assertFalse($saida->encontrados[0]->podeVincular);
    }

    #[TestDox('categoria que o cadastro já tem (anexo próprio) não aparece nos encontrados; pasta na lápide fica fora até do subtítulo')]
    public function testCategoriaJaNoCadastroEPastaExcluida(): void
    {
        $viva = self::pasta(10, '1180');
        $lapide = self::pasta(11, '1176');
        $lapide->marcarExcluida(new User(), new \DateTimeImmutable());
        $repoPastas = $this->createStub(PastaRepository::class);
        $repoPastas->method('findByCliente')->willReturn([$viva, $lapide]);
        $docs = $this->createMock(PastaDocumentoRepository::class);
        $docs->expects(self::once())->method('identificacaoEComprovanteDasPastas')->with([$viva], self::anything())->willReturn([
            self::documento(100, $viva, PastaDocumento::CATEGORIA_IDENTIFICACAO, 'RG.pdf'),
            self::documento(101, $viva, PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA, 'conta.pdf'),
        ]);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessResource')->willReturn(true);
        $cliente = self::pf('529.982.247-25');
        $cliente->getDocumentos()->add((new ClienteDocumento())->setCliente($cliente)->setCategoria(ClienteDocumento::CATEGORIA_IDENTIFICACAO));

        $saida = $this->sut($repoPastas, $docs, null, $permissoes)->executar($cliente, new User(), self::tenant(1));

        self::assertSame([101], array_map(static fn ($e): int => $e->id, $saida->encontrados));
        self::assertSame('2 documento(s) deste cliente em 1 pasta(s)', $saida->subtitulo);
    }

    private function sut(?PastaRepository $pastas = null, ?PastaDocumentoRepository $docs = null, ?DocumentoVinculadoRepository $vinculados = null, ?PermissionChecker $permissoes = null): MontarInteligenciaCadastralUseCase
    {
        $permissoes ??= $this->createStub(PermissionChecker::class);
        $clientes = $this->createStub(ClienteRepository::class);
        $clientes->method('contarHomonimos')->willReturn(0);
        $clientes->method('comOMesmoDocumento')->willReturn([]);
        $alertas = new AlertasDaInteligenciaCadastral($clientes, $this->createStub(PessoaRepository::class), $permissoes);

        return new MontarInteligenciaCadastralUseCase(
            $alertas,
            $pastas ?? $this->createStub(PastaRepository::class),
            $docs ?? $this->createStub(PastaDocumentoRepository::class),
            $vinculados ?? $this->createStub(DocumentoVinculadoRepository::class),
            new RegrasDosEncontrados(new DocumentosVisiveisDoCadastro($permissoes)),
            $permissoes,
        );
    }

    private static function pf(string $cpf): Cliente
    {
        $c = (new ClientePF())->setNomeCompleto('José da Silva')->setCpf($cpf);
        $c->setTenant(self::tenant(1));
        (new \ReflectionProperty(Cliente::class, 'id'))->setValue($c, 1);

        return $c;
    }

    private static function pasta(int $id, string $nup): Pasta
    {
        $p = (new Pasta())->setNup($nup)->setTenant(self::tenant(1));
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($p, $id);

        return $p;
    }

    private static function documento(int $id, Pasta $pasta, string $categoria, string $nome): PastaDocumento
    {
        $d = (new PastaDocumento())->setPasta($pasta)->setTenant(self::tenant(1))->setCategoria($categoria)->setNomeOriginal($nome);
        (new \ReflectionProperty(PastaDocumento::class, 'id'))->setValue($d, $id);

        return $d;
    }

    private static function tenant(int $id): Tenant
    {
        static $tenants = [];
        if (!isset($tenants[$id])) {
            $tenants[$id] = new Tenant();
            (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenants[$id], $id);
        }

        return $tenants[$id];
    }
}
