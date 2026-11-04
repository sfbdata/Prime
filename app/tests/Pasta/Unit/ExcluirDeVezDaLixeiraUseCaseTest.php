<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ReferenciasDeArquivoDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\UseCase\ExcluirDeVezDaLixeiraUseCase;
use App\Pasta\UseCase\PurgarLixeiraUseCase;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * "Excluir de vez" (03/11/2026): a segunda barreira do UseCase. Seleção vazia, item de outro
 * escritório, de outra pasta ou fora da lixeira é recusado ANTES de a purga ser chamada (nenhum
 * toque no banco nem no disco). O caminho feliz (ordem INV-6, subárvore, referência
 * compartilhada) é provado com banco em `Functional/PurgarSelecaoDaLixeiraTest` e pela rota em
 * `Functional/PastaDocumentoLixeiraControllerTest`.
 */
#[CoversClass(ExcluirDeVezDaLixeiraUseCase::class)]
final class ExcluirDeVezDaLixeiraUseCaseTest extends TestCase
{
    private ExcluirDeVezDaLixeiraUseCase $useCase;
    private ArmazenamentoEmMemoria $memoria;
    private Tenant $tenant;
    private Pasta $pasta;
    private User $autor;

    protected function setUp(): void
    {
        // A purga é a de verdade, com dependências que gritam se forem tocadas: o EM não pode
        // receber nenhuma chamada (nem `getFilters`, nem `find`, nem `flush`).
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method(self::anything());

        $this->memoria = new ArmazenamentoEmMemoria();
        $purga         = new PurgarLixeiraUseCase(
            $em,
            $this->createStub(PastaDocumentoRepository::class),
            $this->createStub(PastaSecaoRepository::class),
            new AcessoALixeira($em),
            new RemocaoAposTransacao($this->memoria, new NullLogger()),
            $this->createStub(ReferenciasDeArquivoDaPasta::class),
            new MockClock('2026-11-03 12:00:00'),
            new NullLogger(),
        );

        $this->useCase = new ExcluirDeVezDaLixeiraUseCase($purga);
        $this->tenant  = new Tenant();
        $this->pasta   = (new Pasta())->setTenant($this->tenant);
        $this->autor   = (new User())->setEmail('autor@test.com');
    }

    #[TestDox('seleção vazia é recusada')]
    public function testSelecaoVazia(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, [], [], $this->tenant);
    }

    #[TestDox('documento de outro escritório é recusado (AccessDenied) e nada é tocado')]
    public function testDocumentoDeOutroEscritorio(): void
    {
        $doc = $this->documento(naLixeira: true, tenant: new Tenant());

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($this->pasta, [$doc], [], $this->tenant);
    }

    #[TestDox('subpasta de outro escritório é recusada (AccessDenied)')]
    public function testSecaoDeOutroEscritorio(): void
    {
        $secao = $this->secao(naLixeira: true, tenant: new Tenant());

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($this->pasta, [], [$secao], $this->tenant);
    }

    #[TestDox('item de outra pasta do mesmo escritório é recusado')]
    public function testItemDeOutraPasta(): void
    {
        $doc = $this->documento(naLixeira: true, pasta: (new Pasta())->setTenant($this->tenant));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Item de outra pasta.');

        $this->useCase->executar($this->pasta, [$doc], [], $this->tenant);
    }

    #[TestDox('item vivo (fora da lixeira) é recusado: só se exclui de vez o que está na lixeira')]
    public function testItemForaDaLixeira(): void
    {
        $naLixeira = $this->documento(naLixeira: true);
        $vivo      = $this->documento(naLixeira: false);

        try {
            $this->useCase->executar($this->pasta, [$naLixeira, $vivo], [], $this->tenant);
            self::fail('o item vivo devia ser recusado');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Item fora da lixeira.', $e->getMessage());
        }

        self::assertSame([], $this->memoria->excluidas, 'nenhum arquivo sai');
    }

    private function documento(bool $naLixeira, ?Tenant $tenant = null, ?Pasta $pasta = null): PastaDocumento
    {
        $d = new PastaDocumento();
        $d->setPasta($pasta ?? $this->pasta);
        $d->setTenant($tenant ?? $this->tenant);
        $d->setTitulo('doc.pdf');
        $d->setNomeOriginal('doc.pdf');
        $d->setCategoria('DEMAIS');
        $d->setCaminhoArquivo('doc.pdf');
        $d->setMimeType('application/pdf');
        $d->setTamanhoBytes(1);
        if ($naLixeira) {
            $d->marcarExcluido($this->autor, new \DateTimeImmutable('2026-11-01 10:00:00'));
        }

        return $d;
    }

    private function secao(bool $naLixeira, ?Tenant $tenant = null): PastaSecao
    {
        $s = (new PastaSecao())->setNome('SUB');
        $s->setPasta($this->pasta);
        $s->setTenant($tenant ?? $this->tenant);
        if ($naLixeira) {
            $s->marcarExcluido($this->autor, new \DateTimeImmutable('2026-11-01 10:00:00'));
        }

        return $s;
    }
}
