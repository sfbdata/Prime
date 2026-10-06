<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\Repository\PastaChecklistItemRepository;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Pasta\UseCase\CriarPastaUseCase;
use App\Pasta\UseCase\DuplicarPastaUseCase;
use App\Pasta\UseCase\GerarNumeroDePasta;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A cópia passa pelo `CriarPastaUseCase` de verdade (com o `GerarNumeroDePasta` de verdade):
 * é isso que garante "número novo pelo mesmo caminho da criação normal". Os dublês são só das
 * fronteiras — o EntityManager, a numeração do banco e o repositório do checklist.
 *
 * Setters de Pasta gravam em MAIÚSCULAS: as comparações usam o getter da origem, nunca o texto
 * digitado.
 */
#[CoversClass(DuplicarPastaUseCase::class)]
final class DuplicarPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private NumeracaoDePastaInterface&MockObject $numeracao;
    private PastaChecklistItemRepository&MockObject $checklistRepo;
    private DuplicarPastaUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;

    /** @var list<object> */
    private array $persistidos = [];

    protected function setUp(): void
    {
        $this->em            = $this->createMock(EntityManagerInterface::class);
        $this->numeracao     = $this->createMock(NumeracaoDePastaInterface::class);
        $this->checklistRepo = $this->createMock(PastaChecklistItemRepository::class);

        // Como o real: executa o callback e devolve o que ele devolve (aninha no CriarPasta).
        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn): mixed => $fn());
        $this->em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persistidos[] = $o;
        });

        $this->useCase = new DuplicarPastaUseCase(
            $this->em,
            new CriarPastaUseCase($this->em, new GerarNumeroDePasta($this->numeracao)),
            $this->checklistRepo,
        );

        $this->tenant  = new Tenant();
        $this->usuario = new User();
    }

    private function origem(): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('7');
        $pasta->setTenant($this->tenant);
        $pasta->setNomeCliente('Maria das Graças');
        $pasta->setNomeAcao('Ação de Cobrança');

        return $pasta;
    }

    private function cliente(?Tenant $tenant = null): ClientePF
    {
        $cliente = new ClientePF();
        $cliente->setTenant($tenant ?? $this->tenant);

        return $cliente;
    }

    /** @param array<string, bool> $itens título => concluído */
    private function checklistDaOrigem(array $itens): void
    {
        $lista = [];
        $ordem = 1;
        foreach ($itens as $titulo => $concluido) {
            $lista[] = (new PastaChecklistItem())
                ->setTitulo($titulo)
                ->setTenant($this->tenant)
                ->setOrdem($ordem++)
                ->setConcluido($concluido);
        }

        $this->checklistRepo->method('findByPasta')->willReturn($lista);
    }

    /** @return list<PastaChecklistItem> */
    private function itensPersistidos(): array
    {
        return array_values(array_filter(
            $this->persistidos,
            static fn (object $o): bool => $o instanceof PastaChecklistItem,
        ));
    }

    #[TestDox('a cópia nasce com número NOVO, gerado pelo GerarNumeroDePasta, no mesmo escritório e criada por quem duplicou')]
    public function testNumeroNovoPeloCaminhoDaCriacao(): void
    {
        $this->checklistDaOrigem([]);
        $this->numeracao->expects(self::once())->method('travar')->with($this->tenant);
        $this->numeracao->method('maiorNumero')->willReturn(41);

        $origem = $this->origem();
        $nova   = $this->useCase->executar($origem, $this->usuario, $this->tenant);

        self::assertNotSame($origem, $nova);
        self::assertSame('42', $nova->getNup());
        self::assertNotSame($origem->getNup(), $nova->getNup());
        self::assertSame($this->tenant, $nova->getTenant());
        self::assertSame($this->usuario, $nova->getCriadoPor());
        self::assertContains($nova, $this->persistidos, 'a pasta nova é persistida pelo CriarPastaUseCase');
    }

    #[TestDox('copia identificador, ação, responsável e prioridade da origem')]
    public function testCopiaCamposDoCaso(): void
    {
        $this->checklistDaOrigem([]);
        $this->numeracao->method('maiorNumero')->willReturn(10);

        $responsavel = new User();
        $origem      = $this->origem();
        $origem->setResponsavel($responsavel);
        $origem->setPrioridade(PrioridadePasta::Urgente);

        $nova = $this->useCase->executar($origem, $this->usuario, $this->tenant);

        self::assertSame($origem->getNomeCliente(), $nova->getNomeCliente());
        self::assertSame($origem->getNomeAcao(), $nova->getNomeAcao());
        self::assertSame($responsavel, $nova->getResponsavel());
        self::assertSame(PrioridadePasta::Urgente, $nova->getPrioridade());
    }

    #[TestDox('vincula os mesmos clientes e mantém o MESMO principal, mesmo quando ele não foi o primeiro vinculado')]
    public function testCopiaClientesComOMesmoPrincipal(): void
    {
        $this->checklistDaOrigem([]);
        $this->numeracao->method('maiorNumero')->willReturn(10);

        $primeiro = $this->cliente();
        $segundo  = $this->cliente();
        $origem   = $this->origem();
        $origem->addCliente($primeiro);
        $origem->addCliente($segundo);
        $origem->definirClientePrincipal($segundo);

        $nova = $this->useCase->executar($origem, $this->usuario, $this->tenant);

        self::assertCount(2, $nova->getClientes());
        self::assertTrue($nova->getClientes()->contains($primeiro));
        self::assertTrue($nova->getClientes()->contains($segundo));
        self::assertSame($segundo, $nova->getClientePrincipal());
    }

    #[TestDox('cliente de OUTRO escritório vinculado à origem não atravessa para a cópia')]
    public function testClienteDeOutroEscritorioNaoAtravessa(): void
    {
        $this->checklistDaOrigem([]);
        $this->numeracao->method('maiorNumero')->willReturn(10);

        $daCasa  = $this->cliente();
        $intruso = $this->cliente(new Tenant());
        $origem  = $this->origem();
        $origem->addCliente($intruso);
        $origem->addCliente($daCasa);

        $nova = $this->useCase->executar($origem, $this->usuario, $this->tenant);

        self::assertCount(1, $nova->getClientes());
        self::assertFalse($nova->getClientes()->contains($intruso));
        self::assertSame($daCasa, $nova->getClientePrincipal());
    }

    #[TestDox('copia os itens do checklist na mesma ordem, todos PENDENTES, ligados à pasta nova e ao escritório')]
    public function testCopiaChecklistDesmarcado(): void
    {
        $this->checklistDaOrigem(['PROCURAÇÃO' => true, 'RG' => false, 'COMPROVANTE DE RESIDÊNCIA' => true]);
        $this->numeracao->method('maiorNumero')->willReturn(10);
        $this->em->expects(self::atLeastOnce())->method('flush');

        $nova  = $this->useCase->executar($this->origem(), $this->usuario, $this->tenant);
        $itens = $this->itensPersistidos();

        self::assertSame(
            ['PROCURAÇÃO', 'RG', 'COMPROVANTE DE RESIDÊNCIA'],
            array_map(static fn (PastaChecklistItem $i): string => $i->getTitulo(), $itens),
        );
        self::assertSame([1, 2, 3], array_map(static fn (PastaChecklistItem $i): int => $i->getOrdem(), $itens));
        foreach ($itens as $item) {
            self::assertFalse($item->isConcluido(), 'conferência feita na origem não vale para a cópia');
            self::assertSame($nova, $item->getPasta());
            self::assertSame($this->tenant, $item->getTenant());
        }
    }

    #[TestDox('NÃO copia documentos, marcadores nem financeiro (valor da causa, situação do contrato, pro bono)')]
    public function testNaoCopiaDocumentosMarcadoresNemFinanceiro(): void
    {
        $this->checklistDaOrigem([]);
        $this->numeracao->method('maiorNumero')->willReturn(10);

        $origem = $this->origem();
        $origem->addDocumento(new PastaDocumento());
        $origem->addMarcador(new Marcador('URGENTE', $this->tenant, $this->usuario));
        $origem->setValorCausa('15000.00');
        $origem->setSituacaoContrato('ASSINADO');
        $origem->setProBono(true);

        $nova = $this->useCase->executar($origem, $this->usuario, $this->tenant);

        self::assertCount(0, $nova->getDocumentos());
        self::assertCount(0, $nova->getMarcadores());
        self::assertCount(0, $nova->getPastaProcessos());
        self::assertCount(0, $nova->getTarefas());
        self::assertCount(0, $nova->getPagamentos());
        self::assertCount(0, $nova->getMensagens());
        self::assertCount(0, $nova->getSecoes());
        self::assertCount(0, $nova->getObservacoesFinanceiras());
        self::assertCount(0, $nova->getObservacoesDetalhes());
        self::assertNull($nova->getValorCausa());
        self::assertSame('PENDENTE', $nova->getSituacaoContrato());
        self::assertFalse($nova->isProBono());
        self::assertNull($nova->getDriveFolderId(), 'a pasta do Drive da origem não é reaproveitada');

        foreach ($this->persistidos as $o) {
            self::assertTrue(
                $o instanceof Pasta || $o instanceof PastaChecklistItem,
                'só a pasta nova e os itens do checklist são gravados: ' . $o::class,
            );
        }
    }

    #[TestDox('pasta excluída (lápide) não é duplicada e nada é gravado')]
    public function testLapideRecusada(): void
    {
        $origem = $this->origem();
        $origem->marcarExcluida($this->usuario, new \DateTimeImmutable());

        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');
        $this->numeracao->expects(self::never())->method('travar');

        $this->expectException(\DomainException::class);
        $this->useCase->executar($origem, $this->usuario, $this->tenant);
    }

    #[TestDox('pasta de outro escritório é recusada antes de gerar número')]
    public function testOutroEscritorioRecusado(): void
    {
        $origem = $this->origem();
        $origem->setTenant(new Tenant());

        $this->em->expects(self::never())->method('persist');
        $this->numeracao->expects(self::never())->method('travar');

        $this->expectException(AccessDeniedException::class);
        $this->useCase->executar($origem, $this->usuario, $this->tenant);
    }
}
