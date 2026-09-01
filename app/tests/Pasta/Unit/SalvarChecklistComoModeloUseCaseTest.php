<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Entity\PastaChecklistModeloItem;
use App\Pasta\Exception\ChecklistModeloJaExisteException;
use App\Pasta\Repository\PastaChecklistItemRepository;
use App\Pasta\Repository\PastaChecklistModeloRepository;
use App\Pasta\UseCase\SalvarChecklistComoModeloUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(SalvarChecklistComoModeloUseCase::class)]
final class SalvarChecklistComoModeloUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaChecklistItemRepository&MockObject $itemRepo;
    private PastaChecklistModeloRepository&MockObject $modeloRepo;
    private SalvarChecklistComoModeloUseCase $useCase;
    private Tenant $tenant;
    private Pasta $pasta;
    private User $autor;

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->itemRepo   = $this->createMock(PastaChecklistItemRepository::class);
        $this->modeloRepo = $this->createMock(PastaChecklistModeloRepository::class);
        $this->useCase    = new SalvarChecklistComoModeloUseCase($this->em, $this->itemRepo, $this->modeloRepo);

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');
        $this->pasta  = (new Pasta())->setTenant($this->tenant);
    }

    /** @param string[] $titulos */
    private function checklistDaPastaCom(array $titulos): void
    {
        $itens = [];
        foreach ($titulos as $titulo) {
            $itens[] = (new PastaChecklistItem())->setTitulo($titulo)->setTenant($this->tenant);
        }

        $this->itemRepo->method('findByPasta')->willReturn($itens);
    }

    #[TestDox('Salvar copia os títulos do checklist da pasta, na ordem, para um modelo novo')]
    public function testSalvaOsTitulosNaOrdem(): void
    {
        $this->checklistDaPastaCom(['Procuração', 'Comprovante de residência', 'RG']);
        $this->modeloRepo->method('buscarPorNome')->willReturn(null);
        $this->modeloRepo->expects($this->once())->method('salvar');
        $this->em->expects($this->once())->method('flush');

        $modelo = $this->useCase->executar($this->pasta, $this->autor, 'Trabalhista', $this->tenant);

        self::assertSame('TRABALHISTA', $modelo->getNome());
        self::assertSame($this->tenant, $modelo->getTenant());
        self::assertSame($this->autor, $modelo->getAutor());

        $linhas = $modelo->getItens()->toArray();
        self::assertCount(3, $linhas);
        self::assertSame(['PROCURAÇÃO', 'COMPROVANTE DE RESIDÊNCIA', 'RG'], array_map(
            static fn (PastaChecklistModeloItem $l): string => $l->getTitulo(),
            $linhas,
        ));
        self::assertSame([1, 2, 3], array_map(
            static fn (PastaChecklistModeloItem $l): int => $l->getOrdem(),
            $linhas,
        ));
    }

    #[TestDox('Todo item do modelo nasce carimbado com o escritório de quem salvou')]
    public function testItensDoModeloRecebemOTenant(): void
    {
        $this->checklistDaPastaCom(['Procuração']);
        $this->modeloRepo->method('buscarPorNome')->willReturn(null);

        $modelo = $this->useCase->executar($this->pasta, $this->autor, 'Cível', $this->tenant);

        foreach ($modelo->getItens() as $linha) {
            self::assertSame($this->tenant, $linha->getTenant());
        }
    }

    #[TestDox('Nome já usado pelo escritório é recusado enquanto ninguém pedir para substituir')]
    public function testNomeRepetidoSemSubstituirEhRecusado(): void
    {
        $this->checklistDaPastaCom(['Procuração']);
        $existente = (new PastaChecklistModelo())->setTenant($this->tenant)->setNome('Trabalhista');
        $this->modeloRepo->method('buscarPorNome')->willReturn($existente);

        $this->modeloRepo->expects($this->never())->method('salvar');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(ChecklistModeloJaExisteException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'trabalhista', $this->tenant);
    }

    #[TestDox('Substituir troca a lista inteira do modelo que já existia, sem criar outro')]
    public function testSubstituirTrocaOsItensDoModeloExistente(): void
    {
        $this->checklistDaPastaCom(['Petição inicial', 'Contrato']);

        $existente = (new PastaChecklistModelo())->setTenant($this->tenant)->setNome('Trabalhista');
        $existente->setAutor((new User())->setEmail('quem-criou@test.com'));
        $antigo = (new PastaChecklistModeloItem())->setTitulo('Item velho')->setOrdem(1);
        $existente->adicionarItem($antigo);

        $this->modeloRepo->method('buscarPorNome')->willReturn($existente);

        $modelo = $this->useCase->executar($this->pasta, $this->autor, 'Trabalhista', $this->tenant, substituir: true);

        self::assertSame($existente, $modelo, 'deve reaproveitar o modelo, não criar um segundo');
        self::assertSame(['PETIÇÃO INICIAL', 'CONTRATO'], array_map(
            static fn (PastaChecklistModeloItem $l): string => $l->getTitulo(),
            $modelo->getItens()->toArray(),
        ));
        self::assertSame('quem-criou@test.com', $modelo->getAutor()?->getEmail(), 'o autor original permanece');
    }

    #[TestDox('Pasta sem nenhum item no checklist não vira modelo vazio')]
    public function testChecklistVazioNaoViraModelo(): void
    {
        $this->checklistDaPastaCom([]);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'Trabalhista', $this->tenant);
    }

    #[TestDox('Nome vazio ou só espaços é recusado')]
    public function testNomeVazioEhRecusado(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, '   ', $this->tenant);
    }

    #[TestDox('Nome acima de 120 caracteres é recusado antes de tocar o banco')]
    public function testNomeLongoDemaisEhRecusado(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, str_repeat('a', 121), $this->tenant);
    }

    #[TestDox('Pasta de outro escritório não pode virar modelo do meu')]
    public function testPastaDeOutroEscritorioEhRecusada(): void
    {
        $this->pasta->setTenant(new Tenant());
        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'Trabalhista', $this->tenant);
    }
}
