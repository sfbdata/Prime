<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Integration;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Exception\ClienteNaoEncontradoException;
use App\Cliente\UseCase\ExcluirClienteUseCase;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(ExcluirClienteUseCase::class)]
final class ExcluirClienteUseCaseTest extends KernelTestCase
{
    use Factories;

    #[TestDox('apaga o cliente e os documentos, tira dele as pastas, solta o principal e devolve só as chaves dos arquivos próprios')]
    public function testApagaEDevolveAsChaves(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant])->_real();
        $pasta = (new Pasta())->setNup('EXC-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->addCliente($cliente);
        $this->em()->persist($pasta);
        $proprio = $this->documento($cliente, 'proprio.pdf');
        $origem = (new PastaDocumento())->setPasta($pasta)->setTenant($tenant)->setCategoria(PastaDocumento::CATEGORIA_IDENTIFICACAO)->setTitulo('o')->setNomeOriginal('o.pdf')
            ->setCaminhoArquivo('p_' . uniqid() . '.pdf')->setMimeType('application/pdf')->setTamanhoBytes(1);
        $this->em()->persist($origem);
        $vinculado = ClienteDocumento::vinculadoA($origem, $cliente, $tenant);
        $cliente->addDocumento($vinculado);
        $this->em()->persist($vinculado);
        $this->em()->flush();
        [$id, $pastaId, $origemId] = [(int) $cliente->getId(), (int) $pasta->getId(), (int) $origem->getId()];
        $chaveEsperada = ChavesDeCliente::documento($proprio);

        $chaves = static::getContainer()->get(ExcluirClienteUseCase::class)->executar($id, $tenant);

        self::assertEquals([$chaveEsperada], $chaves, 'só o arquivo próprio; o vinculado não tem arquivo seu');
        $conexao = $this->em()->getConnection();
        self::assertSame(0, (int) $conexao->fetchOne('SELECT COUNT(*) FROM cliente WHERE id = ?', [$id]));
        self::assertSame(0, (int) $conexao->fetchOne('SELECT COUNT(*) FROM cliente_documento WHERE cliente_id = ?', [$id]));
        self::assertSame(0, (int) $conexao->fetchOne('SELECT COUNT(*) FROM pasta_cliente WHERE cliente_id = ?', [$id]));
        self::assertNull($conexao->fetchOne('SELECT cliente_principal_id FROM pasta WHERE id = ?', [$pastaId]));
        self::assertSame(1, (int) $conexao->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE id = ?', [$origemId]), 'o documento da pasta fica');
    }

    #[TestDox('cliente de outro escritório: recusa e nada é apagado')]
    public function testRecusaClienteDeOutroEscritorio(): void
    {
        self::bootKernel();
        $meu = TenantFactory::createOne()->_real();
        $alheio = ClientePFFactory::createOne(['tenant' => TenantFactory::createOne()])->_real();
        $id = (int) $alheio->getId();

        try {
            static::getContainer()->get(ExcluirClienteUseCase::class)->executar($id, $meu);
            self::fail('deveria recusar');
        } catch (ClienteNaoEncontradoException) {
        }

        self::assertNotNull($this->em()->find(Cliente::class, $id));
    }

    #[TestDox('temCarteiraDeCobranca: verdadeiro só com carteira do cliente no escritório; sem ela, falso')]
    public function testTemCarteiraDeCobranca(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $com = ClientePFFactory::createOne(['tenant' => $tenant])->_real();
        $sem = ClientePFFactory::createOne(['tenant' => $tenant])->_real();
        CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $com]);
        $usecase = static::getContainer()->get(ExcluirClienteUseCase::class);

        self::assertTrue($usecase->temCarteiraDeCobranca($com, $tenant));
        self::assertFalse($usecase->temCarteiraDeCobranca($sem, $tenant));
    }

    private function documento(Cliente $cliente, string $nome): ClienteDocumento
    {
        $documento = (new ClienteDocumento())->setCliente($cliente)->setTenant($cliente->getTenant() ?? self::fail('sem escritório'))
            ->setTitulo($nome)->setCategoria(ClienteDocumento::CATEGORIA_IDENTIFICACAO)->setCaminhoArquivo(bin2hex(random_bytes(8)) . '.pdf')
            ->setNomeOriginal($nome)->setMimeType('application/pdf')->setTamanhoBytes(10);
        $cliente->addDocumento($documento);
        $this->em()->persist($documento);

        return $documento;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
