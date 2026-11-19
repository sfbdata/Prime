<?php

declare(strict_types=1);

namespace App\Tests\Cliente\Integration;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Exception\DocumentoDoClienteNaoEncontradoException;
use App\Cliente\UseCase\ExcluirDocumentoDoClienteUseCase;
use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(ExcluirDocumentoDoClienteUseCase::class)]
final class ExcluirDocumentoDoClienteUseCaseTest extends KernelTestCase
{
    use Factories;

    #[TestDox('documento próprio: a linha sai e a chave do arquivo volta para remover depois do COMMIT')]
    public function testProprioDevolveAChave(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant])->_real();
        $documento = $this->proprio($cliente);
        $id = (int) $documento->getId();
        $esperada = ChavesDeCliente::documento($documento);

        $chaves = static::getContainer()->get(ExcluirDocumentoDoClienteUseCase::class)->executar($documento, $this->autor(), $tenant);

        self::assertEquals([$esperada], $chaves);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente_documento WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente WHERE id = ?', [$cliente->getId()]));
    }

    #[TestDox('documento vinculado de pasta: sai só o vínculo, nenhuma chave, e o documento da pasta fica')]
    public function testVinculadoNaoDevolveChave(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant])->_real();
        $pasta = (new Pasta())->setNup('EXD-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $origem = (new PastaDocumento())->setPasta($pasta)->setTenant($tenant)->setCategoria(PastaDocumento::CATEGORIA_IDENTIFICACAO)->setTitulo('o')->setNomeOriginal('o.pdf')
            ->setCaminhoArquivo('p_' . uniqid() . '.pdf')->setMimeType('application/pdf')->setTamanhoBytes(1);
        $this->em()->persist($origem);
        $vinculado = ClienteDocumento::vinculadoA($origem, $cliente, $tenant);
        $cliente->addDocumento($vinculado);
        $this->em()->persist($vinculado);
        $this->em()->flush();
        [$id, $origemId] = [(int) $vinculado->getId(), (int) $origem->getId()];

        $chaves = static::getContainer()->get(ExcluirDocumentoDoClienteUseCase::class)->executar($vinculado, $this->autor(), $tenant);

        self::assertSame([], $chaves);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente_documento WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE id = ?', [$origemId]));
    }

    #[TestDox('documento de outro escritório: recusa e a linha continua')]
    public function testRecusaDocumentoDeOutroEscritorio(): void
    {
        self::bootKernel();
        $meu = TenantFactory::createOne()->_real();
        $cliente = ClientePFFactory::createOne(['tenant' => TenantFactory::createOne()])->_real();
        $alheio = $this->proprio($cliente);
        $id = (int) $alheio->getId();

        try {
            static::getContainer()->get(ExcluirDocumentoDoClienteUseCase::class)->executar($alheio, $this->autor(), $meu);
            self::fail('deveria recusar');
        } catch (DocumentoDoClienteNaoEncontradoException) {
        }

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente_documento WHERE id = ?', [$id]));
    }

    private function proprio(\App\Cliente\Entity\Cliente $cliente): ClienteDocumento
    {
        $documento = (new ClienteDocumento())->setCliente($cliente)->setTenant($cliente->getTenant() ?? self::fail('sem escritório'))
            ->setTitulo('rg')->setCategoria(ClienteDocumento::CATEGORIA_IDENTIFICACAO)->setCaminhoArquivo(bin2hex(random_bytes(8)) . '.pdf')
            ->setNomeOriginal('rg.pdf')->setMimeType('application/pdf')->setTamanhoBytes(10);
        $cliente->addDocumento($documento);
        $this->em()->persist($documento);
        $this->em()->flush();

        return $documento;
    }

    private function autor(): User
    {
        $autor = (new User())->setEmail('exd_' . uniqid() . '@teste.com')->setFullName('Autora da Exclusão')->setRoles(['ROLE_USER'])->setIsActive(true);
        $autor->setPassword('sem-uso');
        $this->em()->persist($autor);
        $this->em()->flush();

        return $autor;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
