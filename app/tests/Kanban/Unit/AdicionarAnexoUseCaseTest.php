<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Repository\KanbanAnexoRepository;
use App\Kanban\UseCase\AdicionarAnexoUseCase;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * O upload é um arquivo REAL em diretório temporário próprio (E2.4A): a ponte HTTP lê a extensão
 * do conteúdo e move o arquivo — exatamente o movimento que, lido fora de ordem, derrubava o anexo
 * do Kanban em produção. Um mock de `UploadedFile` esconderia esse defeito.
 */
#[CoversClass(AdicionarAnexoUseCase::class)]
final class AdicionarAnexoUseCaseTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    private const TENANT_ID = 31;

    private ArmazenamentoEmMemoria $armazenamento;
    private KanbanAnexoRepository&MockObject $repositorio;
    private AdicionarAnexoUseCase $useCase;
    private string $dirTemp;

    protected function setUp(): void
    {
        $this->dirTemp = sys_get_temp_dir() . '/kanban-anexo-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);

        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->repositorio   = $this->createMock(KanbanAnexoRepository::class);
        $this->useCase       = new AdicionarAnexoUseCase($this->repositorio, $this->armazenamento);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }

        @rmdir($this->dirTemp);
    }

    #[TestDox('A coluna do banco guarda apenas o NOME cunhado pelo storage, e os metadados vêm do upload')]
    public function testColunaGuardaSomenteONomeCunhado(): void
    {
        $capturado = null;
        $this->repositorio
            ->expects(self::once())
            ->method('salvar')
            ->willReturnCallback(function (KanbanAnexo $anexo) use (&$capturado): void {
                $capturado = $anexo;
                ($this->simularPersistencia())($anexo);
            });

        $saida = $this->useCase->executar($this->upload(self::PDF, 'contrato.pdf'), $this->card(), $this->usuario());

        $chave = $this->armazenamento->ultimaGravada();

        self::assertNotNull($capturado);
        self::assertCount(1, $this->armazenamento->gravadas);
        self::assertSame($chave->nome, $capturado->getCaminho());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $capturado->getCaminho());
        self::assertStringNotContainsString('/', $capturado->getCaminho());
        self::assertSame(self::PDF, $this->armazenamento->ler($chave));
        // Metadados lidos do upload ANTES da gravação — depois dela o arquivo de origem não existe.
        self::assertSame('contrato.pdf', $capturado->getNomeOriginal());
        self::assertSame(\strlen(self::PDF), $capturado->getTamanho());
        self::assertSame('application/pdf', $capturado->getMimeType());
        self::assertSame(1, $saida->id);
    }

    #[TestDox('A chave gravada tem o escopo do escritório do CARD e a categoria KANBAN_ANEXO (R1)')]
    public function testEscopoSaiDoCard(): void
    {
        $this->repositorio->method('salvar')->willReturnCallback($this->simularPersistencia());

        $card = $this->card();
        $this->useCase->executar($this->upload(self::PDF, 'contrato.pdf'), $card, $this->usuario());

        // No disco local o escopo é ignorado (diretório plano); o dublê o materializa.
        $chave = $this->armazenamento->ultimaGravada();
        self::assertSame(self::TENANT_ID, $card->getTenant()?->getId());
        self::assertSame(self::TENANT_ID, $chave->escopo->tenantIdOuNull());
        self::assertSame(CategoriaDeArquivo::KANBAN_ANEXO, $chave->categoria);
    }

    #[TestDox('Falha do storage propaga e nenhum anexo é salvo')]
    public function testFalhaDoArmazenamentoNaoSalvaAnexo(): void
    {
        $this->armazenamento->falhaAoGravar = new FalhaDeArmazenamento('disco indisponível');

        // Sem arquivo não há registro: nada de linha apontando para o vazio (INV-6).
        $this->repositorio->expects(self::never())->method('salvar');

        $this->expectException(FalhaDeArmazenamento::class);

        $this->useCase->executar($this->upload(self::PDF, 'contrato.pdf'), $this->card(), $this->usuario());
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

    /** Upload real em modo de teste (pula `is_uploaded_file`), sobre um arquivo com conteúdo real. */
    private function upload(string $conteudo, string $nomeOriginal): UploadedFile
    {
        $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nomeOriginal, null, null, true);
    }

    private function card(): KanbanCard
    {
        $tenant = new Tenant();
        $tenant->setName('T');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, self::TENANT_ID);
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
