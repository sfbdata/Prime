<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Integration;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\ClienteJaVinculadoAPastaException;
use App\Pasta\Exception\ClienteNaoVinculadoAPastaException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\UseCase\DesvincularClienteDaPastaUseCase;
use App\Pasta\UseCase\VincularClienteAPastaUseCase;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Os UseCases tirados do `PastaController` (`pasta_cliente_vincular` e `pasta_cliente_desvincular`), sem sessão: pessoa
 * e escritório por parâmetro, com o banco e as permissões de verdade. Guardas (outro escritório, sem editar a pasta,
 * cliente que a pessoa não vê, já vinculado, não vinculado) e o principal: o primeiro vínculo o grava; desvincular o
 * principal promove outro e devolve quem.
 */
#[CoversClass(VincularClienteAPastaUseCase::class)]
#[CoversClass(DesvincularClienteDaPastaUseCase::class)]
final class VincularEDesvincularClienteDaPastaUseCaseTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const EDITOR = ['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit', 'resources.cliente.view'];

    private Tenant $escritorio;
    private User $pessoa;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio('Escritório do Vínculo');
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::EDITOR);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('vincular: o primeiro cliente vira o principal; o segundo não muda o principal; de novo, recusa sem duplicar')]
    public function testVincular(): void
    {
        $pasta = $this->pasta();
        $primeiro = $this->cliente('Primeiro');
        $segundo = $this->cliente('Segundo');

        self::assertSame($primeiro, $this->vincular()->executar($pasta, (int) $primeiro->getId(), $this->pessoa, $this->escritorio));
        $this->vincular()->executar($pasta, (int) $segundo->getId(), $this->pessoa, $this->escritorio);

        self::assertSame(2, $this->vinculos($pasta));
        self::assertSame((int) $primeiro->getId(), $this->principal($pasta));
        $this->expectException(ClienteJaVinculadoAPastaException::class);
        $this->vincular()->executar($pasta, (int) $segundo->getId(), $this->pessoa, $this->escritorio);
    }

    #[TestDox('vincular: cliente de outro escritório, inexistente ou que a pessoa não vê dão ClienteNaoEncontrado; nada vinculado')]
    public function testVincularClienteQueNaoSeVe(): void
    {
        $pasta = $this->pasta();
        $alheio = $this->cliente('Alheio', $this->escritorio('Outro'));
        $meu = $this->cliente('Meu');
        $semVerCliente = $this->pessoa();
        $this->vinculo($semVerCliente, $this->escritorio, permissoes: ['resources.pasta.view', 'resources.pasta.edit']);

        foreach ([[(int) $alheio->getId(), $this->pessoa], [999_999_999, $this->pessoa], [(int) $meu->getId(), $semVerCliente]] as [$clienteId, $quem]) {
            try {
                $this->vincular()->executar($pasta, $clienteId, $quem, $this->escritorio);
                self::fail('deveria recusar');
            } catch (ClienteNaoEncontradoException) {
            }
        }
        self::assertSame(0, $this->vinculos($pasta));
    }

    #[TestDox('vincular e desvincular: pasta de outro escritório e pessoa que não edita a pasta são recusadas antes de tudo')]
    public function testPastaRecusada(): void
    {
        $outro = $this->escritorio('Outro');
        $pastaAlheia = $this->pasta($outro);
        $cliente = $this->cliente('Cliente');
        $pasta = $this->pasta();
        $pasta->addCliente($cliente);
        $this->em()->flush();
        $soVe = $this->pessoa();
        $this->vinculo($soVe, $this->escritorio, permissoes: ['resources.pasta.view', 'resources.cliente.view']);

        $recusas = [
            [fn () => $this->vincular()->executar($pastaAlheia, (int) $cliente->getId(), $this->pessoa, $this->escritorio), PastaDeOutroEscritorioException::class],
            [fn () => $this->desvincular()->executar($pastaAlheia, $cliente, $this->pessoa, $this->escritorio), PastaDeOutroEscritorioException::class],
            [fn () => $this->vincular()->executar($pasta, (int) $cliente->getId(), $soVe, $this->escritorio), SemPermissaoParaEditarPastaException::class],
            [fn () => $this->desvincular()->executar($pasta, $cliente, $soVe, $this->escritorio), SemPermissaoParaEditarPastaException::class],
        ];
        foreach ($recusas as [$chamar, $esperada]) {
            try {
                $chamar();
                self::fail('deveria recusar com ' . $esperada);
            } catch (\DomainException $e) {
                self::assertInstanceOf($esperada, $e);
            }
        }
        self::assertSame(1, $this->vinculos($pasta));
    }

    #[TestDox('desvincular o principal promove o de cadastro mais antigo e o devolve; o que não é principal devolve null')]
    public function testDesvincular(): void
    {
        $antigo = $this->cliente('Antigo');
        $meio = $this->cliente('Meio');
        $recente = $this->cliente('Recente');
        $pasta = $this->pasta();
        $pasta->addCliente($antigo);
        $pasta->addCliente($meio);
        $pasta->addCliente($recente);
        $this->em()->flush();

        self::assertNull($this->desvincular()->executar($pasta, $recente, $this->pessoa, $this->escritorio));
        self::assertSame((int) $antigo->getId(), $this->principal($pasta));

        $promovido = $this->desvincular()->executar($pasta, $antigo, $this->pessoa, $this->escritorio);
        self::assertSame($meio, $promovido);
        self::assertSame((int) $meio->getId(), $this->principal($pasta));
        self::assertSame(1, $this->vinculos($pasta));

        self::assertNull($this->desvincular()->executar($pasta, $meio, $this->pessoa, $this->escritorio), 'o último sai e ninguém é promovido');
        self::assertSame(0, $this->vinculos($pasta));
    }

    #[TestDox('desvincular cliente que não está na pasta, ou de outro escritório, recusa sem mexer em nada')]
    public function testDesvincularForaDaPasta(): void
    {
        $vinculado = $this->cliente('Vinculado');
        $pasta = $this->pasta();
        $pasta->addCliente($vinculado);
        $this->em()->flush();

        try {
            $this->desvincular()->executar($pasta, $this->cliente('Fora'), $this->pessoa, $this->escritorio);
            self::fail('deveria recusar');
        } catch (ClienteNaoVinculadoAPastaException $e) {
            self::assertSame(ClienteNaoVinculadoAPastaException::MENSAGEM, $e->getMessage());
        }
        try {
            $this->desvincular()->executar($pasta, $this->cliente('Alheio', $this->escritorio('Outro')), $this->pessoa, $this->escritorio);
            self::fail('deveria recusar');
        } catch (ClienteNaoEncontradoException) {
        }
        self::assertSame(1, $this->vinculos($pasta));
    }

    private function vincular(): VincularClienteAPastaUseCase
    {
        return static::getContainer()->get(VincularClienteAPastaUseCase::class);
    }

    private function desvincular(): DesvincularClienteDaPastaUseCase
    {
        return static::getContainer()->get(DesvincularClienteDaPastaUseCase::class);
    }

    private function vinculos(Pasta $pasta): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_cliente WHERE pasta_id = :p', ['p' => $pasta->getId()]);
    }

    private function principal(Pasta $pasta): ?int
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT cliente_principal_id FROM pasta WHERE id = :p', ['p' => $pasta->getId()]);

        return $id === null || $id === false ? null : (int) $id;
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('VDC-' . uniqid());
        $pasta->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function cliente(string $nome, ?Tenant $escritorio = null): ClientePF
    {
        $cliente = (new ClientePF())->setNomeCompleto($nome)->setCpf('')->setRg('')->setRgOrgaoExpedidor('');
        $cliente->setEmail('')->setCep('')->setEndereco('')->setCidade('')->setEstado('')->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }
}
