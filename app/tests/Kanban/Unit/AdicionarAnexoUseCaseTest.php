<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Unit;

use App\Entity\Auth\User;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Repository\KanbanAnexoRepository;
use App\Kanban\UseCase\AdicionarAnexoUseCase;
use App\Shared\Service\ArquivoStorageInterface;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(AdicionarAnexoUseCase::class)]
final class AdicionarAnexoUseCaseTest extends TestCase
{
    private const DIRETORIO = '/var/www/app/public/uploads/kanban';

    private ArquivoStorageInterface&MockObject $storage;
    private KanbanAnexoRepository&MockObject $repositorio;
    private AdicionarAnexoUseCase $useCase;

    protected function setUp(): void
    {
        $this->storage     = $this->createMock(ArquivoStorageInterface::class);
        $this->repositorio = $this->createMock(KanbanAnexoRepository::class);
        $this->useCase     = new AdicionarAnexoUseCase(
            $this->repositorio,
            $this->storage,
            self::DIRETORIO,
        );
    }

    #[TestDox('O diretório usado é o injetado pelo container — nunca um caminho relativo')]
    public function testUsaODiretorioInjetadoENaoUmCaminhoRelativo(): void
    {
        $this->storage
            ->expects(self::once())
            ->method('salvar')
            ->with(
                self::anything(),
                self::callback(static function (string $diretorio): bool {
                    // As duas asserções que importam: é EXATAMENTE o diretório do container, e é
                    // absoluto. O defeito corrigido na E1 passava o literal 'kanban', que o
                    // PHP-FPM resolveria contra /var/www/app — fora do volume persistido.
                    return $diretorio === self::DIRETORIO && str_starts_with($diretorio, '/');
                }),
            )
            ->willReturn('abc123.pdf');

        $this->repositorio->method('salvar')->willReturnCallback($this->simularPersistencia());

        $this->useCase->executar($this->arquivo(), $this->card(), $this->usuario());
    }

    #[TestDox('A coluna do banco guarda apenas o NOME devolvido pelo storage, sem diretório')]
    public function testColunaGuardaSomenteONome(): void
    {
        $this->storage->method('salvar')->willReturn('abc123.pdf');

        $capturado = null;
        $this->repositorio
            ->expects(self::once())
            ->method('salvar')
            ->willReturnCallback(function ($anexo) use (&$capturado): void {
                $capturado = $anexo;
                ($this->simularPersistencia())($anexo);
            });

        $this->useCase->executar($this->arquivo(), $this->card(), $this->usuario());

        self::assertNotNull($capturado);
        self::assertSame('abc123.pdf', $capturado->getCaminho());
        self::assertStringNotContainsString('/', $capturado->getCaminho());
    }

    /**
     * O `AnexoOutput::fromEntity` exige um id int, que só existe depois do flush. O repositório
     * real atribui o id ao persistir; aqui o mock faz o mesmo por reflexão, para o UseCase poder
     * ser exercitado sem banco.
     */
    private function simularPersistencia(): \Closure
    {
        return static function (object $anexo): void {
            $prop = new \ReflectionProperty($anexo, 'id');
            $prop->setValue($anexo, 1);
        };
    }

    private function arquivo(): UploadedFile&MockObject
    {
        $arquivo = $this->createMock(UploadedFile::class);
        $arquivo->method('getClientOriginalName')->willReturn('contrato.pdf');
        $arquivo->method('getSize')->willReturn(1024);
        $arquivo->method('getMimeType')->willReturn('application/pdf');

        return $arquivo;
    }

    private function card(): KanbanCard
    {
        $tenant = new Tenant();
        $tenant->setName('T');
        $usuario = $this->usuario();
        $board   = new KanbanBoard('B', $tenant, $usuario);
        $coluna  = new KanbanColuna('A Fazer', KanbanColuna::TIPO_A_FAZER, 0, $board);

        return new KanbanCard('Card', $coluna, $board, $usuario);
    }

    private function usuario(): User
    {
        $u = new User();
        $u->setEmail('u@test.com');
        $u->setFullName('U');

        return $u;
    }
}
