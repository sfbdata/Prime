<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\LeitorDeDocumentosDaPasta;
use App\Inteligencia\DTO\AnexoDeDocumento;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Exception\ArmazenamentoIndisponivelException;
use App\Inteligencia\Exception\DocumentoIlegivelException;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\RegraDaLeituraDeDocumentos;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Pasta\Service\ContadorDePaginasDePdf;
use App\Pasta\Service\EquipeQuePodeTerAbaOculta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tests\Inteligencia\Support\DefineId;
use App\Tests\Inteligencia\Support\FonteDeDadosDaPastaFalsa;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O leitor do worker no "Ler N documentos" (PR F): pega cada arquivo pela chave do armazenamento, só da pasta e do
 * escritório da análise, fora da lixeira e da aba oculta (gravada ou atual), para quem ainda acessa a pasta, com o
 * aceite novo, mede o tipo pelo conteúdo e soma as páginas. Toda
 * recusa é `DocumentoIlegivelException`, cuja mensagem leva o id e o motivo, nunca o nome nem o conteúdo.
 */
#[CoversClass(LeitorDeDocumentosDaPasta::class)]
final class LeitorDeDocumentosDaPastaTest extends TestCase
{
    private Tenant $tenant;
    private Pasta $pasta;
    private FonteDeDadosDaPastaFalsa $dados;
    private ArmazenamentoEmMemoria $armazenamento;
    private ConfiguracaoDeInteligencia $config;
    private User $solicitante;
    private bool $acessaAPasta = true;
    /** @var list<AbaOcultaDaPasta> */
    private array $regrasDeAba = [];
    /** @var array<string, ?int> páginas que o contador devolve, por caminho do arquivo */
    private array $contagem = [];
    private ?ArmazenamentoDeArquivos $armazenamentoQueFalha = null;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 42);
        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        DefineId::em($this->pasta, 7);
        $this->dados = new FonteDeDadosDaPastaFalsa();
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->config = new ConfiguracaoDeInteligencia($this->tenant);
        $this->solicitante = new User();
        DefineId::em($this->solicitante, 3);
        $this->config->atualizar(true, 10, 100, true, $this->solicitante);
    }

    private function leitor(): LeitorDeDocumentosDaPasta
    {
        $repo = $this->createStub(ConfiguracaoDeInteligenciaRepository::class);
        $repo->method('findDoTenant')->willReturn($this->config);

        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessResource')->willReturnCallback(fn (): bool => $this->acessaAPasta);
        $regras = $this->createStub(AbaOcultaDaPastaRepository::class);
        $regras->method('daPasta')->willReturnCallback(fn (): array => $this->regrasDeAba);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $primarios = new RegraDosPrimarios($vinculos);
        $contador = new class (fn (string $conteudo): ?int => $this->contagem[$conteudo] ?? null) implements ContadorDePaginasDePdf {
            public function __construct(private readonly \Closure $pelaContagem)
            {
            }

            public function contar(string $caminhoLocal): ?int
            {
                return ($this->pelaContagem)((string) file_get_contents($caminhoLocal));
            }
        };

        return new LeitorDeDocumentosDaPasta(
            $this->dados,
            $this->armazenamentoQueFalha ?? $this->armazenamento,
            $this->armazenamento,
            $contador,
            new RegraDaLeituraDeDocumentos($repo),
            $permissoes,
            new VisibilidadeDasAbasDaPasta($regras, $primarios, new EquipeQuePodeTerAbaOculta($vinculos, $primarios)),
        );
    }

    private function documento(int $id, ?string $conteudo, ?Tenant $tenant = null, ?Pasta $pasta = null, ?int $paginas = 1): PastaDocumento
    {
        $d = new PastaDocumento();
        $d->setTenant($tenant ?? $this->tenant);
        $d->setPasta($pasta ?? $this->pasta);
        $d->setTitulo('Documento ' . $id);
        $d->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $d->setCaminhoArquivo('doc-' . $id . '.bin');
        $d->setNomeOriginal('doc-' . $id . '.pdf');
        $d->setMimeType('application/pdf');
        $d->setTamanhoBytes(100);
        $d->setPaginas($paginas);
        DefineId::em($d, $id);
        if ($conteudo !== null) {
            $this->armazenamento->semear(ChavesDePasta::documento($d), $conteudo);
        }
        $this->dados->documentos[] = $d;

        return $d;
    }

    /**
     * @param list<int>    $ids
     * @param list<string> $abasOcultas
     */
    private function esperarIlegivel(array $ids, string $trecho, array $abasOcultas = []): void
    {
        try {
            $this->leitor()->anexos($this->tenant, $this->pasta, $ids, $abasOcultas, $this->solicitante);
            self::fail('deveria recusar');
        } catch (DocumentoIlegivelException $e) {
            self::assertStringContainsString($trecho, $e->getMessage());
            self::assertStringNotContainsString('%PDF', $e->getMessage(), 'nunca o conteúdo');
        }
    }

    #[TestDox('Lê na ordem pedida, com o tipo medido pelo conteúdo (não o declarado)')]
    public function testLeNaOrdemComTipoPeloConteudo(): void
    {
        $this->documento(1, '%PDF-1.7 um');
        $this->documento(2, "\x89PNG\r\n\x1A\ndois");

        $anexos = $this->leitor()->anexos($this->tenant, $this->pasta, [2, 1], [], $this->solicitante);

        self::assertSame([2, 1], array_map(static fn (AnexoDeDocumento $a): int => $a->documentoId, $anexos));
        self::assertSame(['image/png', AnexoDeDocumento::PDF], array_map(static fn (AnexoDeDocumento $a): string => $a->mediaType, $anexos));
        self::assertSame('%PDF-1.7 um', $anexos[1]->bytes);
    }

    #[TestDox('Documento de outro escritório ou de outra pasta (mesmo que a fonte o devolva) → recusa')]
    public function testOutroTenantOuOutraPasta(): void
    {
        $outro = new Tenant();
        DefineId::em($outro, 99);
        $this->documento(5, '%PDF-1.7 alheio', $outro);
        $this->esperarIlegivel([5], 'documento 5 não pode ser lido');

        $outraPasta = new Pasta();
        $outraPasta->setTenant($this->tenant);
        DefineId::em($outraPasta, 8);
        $this->documento(6, '%PDF-1.7 vizinho', null, $outraPasta);
        $this->esperarIlegivel([6], 'fora da pasta');
        $this->esperarIlegivel([404], 'fora da pasta');
    }

    #[TestDox('Lixeira, aba Documentos oculta, aceite antigo e seleção acima de 3 → recusa')]
    public function testRecusasDoPedido(): void
    {
        $this->documento(1, '%PDF-1.7 x')->marcarExcluido(new User(), new \DateTimeImmutable());
        $this->esperarIlegivel([1], 'na lixeira');

        $this->documento(2, '%PDF-1.7 y');
        $this->esperarIlegivel([2], 'aba Documentos oculta', [AbaDaPasta::Documentos->value]);
        $this->esperarIlegivel([2, 2, 2, 2], 'acima do limite');
        $this->esperarIlegivel([], 'seleção vazia');

        (new \ReflectionProperty($this->config, 'consentimentoVersao'))->setValue($this->config, 1);
        $this->esperarIlegivel([2], 'aceite');
    }

    #[TestDox('Arquivo ausente, vazio, com conteúdo que não é PDF nem imagem, ou imagem acima do teto → recusa')]
    public function testConteudoRuim(): void
    {
        $this->documento(1, null);
        $this->esperarIlegivel([1], 'arquivo ausente');

        $this->documento(2, '');
        $this->esperarIlegivel([2], 'arquivo vazio');

        $this->documento(3, "PK\x03\x04 docx disfarçado de pdf");
        $this->esperarIlegivel([3], 'não é PDF nem imagem');

        $this->documento(4, "\xFF\xD8\xFF" . str_repeat('x', RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_DA_IMAGEM));
        $this->esperarIlegivel([4], 'tamanho máximo');

        $this->documento(5, '%PDF-1.7' . str_repeat('x', RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_DO_PDF));
        $this->esperarIlegivel([5], 'tamanho máximo');
    }

    #[TestDox('PDF com lixo antes do cabeçalho (até o byte 1024) é PDF')]
    public function testCabecalhoDoPdfDepoisDoInicio(): void
    {
        $this->documento(1, str_repeat("\n", 500) . '%PDF-1.4 x');

        self::assertSame(AnexoDeDocumento::PDF, $this->leitor()->anexos($this->tenant, $this->pasta, [1], [], $this->solicitante)[0]->mediaType);
    }

    #[TestDox('Teto total: arquivos que juntos passam de 20 MB → recusa, pelo conteúdo')]
    public function testTetoTotalPeloConteudo(): void
    {
        $nove = RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_DO_PDF - 1;
        $this->documento(1, '%PDF-a' . str_repeat('a', $nove - 6));
        $this->documento(2, '%PDF-b' . str_repeat('b', $nove - 6));
        $this->documento(3, '%PDF-c' . str_repeat('c', $nove - 6));

        $this->esperarIlegivel([1, 2, 3], 'limite de tamanho');
    }

    #[TestDox('Páginas: somam até 100; sem contagem gravada, conta pelo arquivo; sem contagem possível, recusa')]
    public function testPaginas(): void
    {
        $this->documento(1, '%PDF-um', null, null, 60);
        $this->documento(2, '%PDF-dois', null, null, null);
        $this->contagem['%PDF-dois'] = 40;
        self::assertCount(2, $this->leitor()->anexos($this->tenant, $this->pasta, [1, 2], [], $this->solicitante), '60 + 40 contadas pelo arquivo');

        $this->contagem['%PDF-dois'] = 41;
        $this->esperarIlegivel([1, 2], 'limite de páginas');

        $this->documento(3, '%PDF-ilegivel', null, null, null);
        $this->esperarIlegivel([3], 'contar as páginas');
    }

    #[TestDox('Quem pediu perdeu o acesso à pasta, ou a aba Documentos ficou oculta DEPOIS do pedido → recusa')]
    public function testReconfereAcessoEAbaAgora(): void
    {
        $this->documento(1, '%PDF-1.7 x');

        $this->acessaAPasta = false;
        $this->esperarIlegivel([1], 'não acessa mais a pasta');
        $this->acessaAPasta = true;

        $this->regrasDeAba = [AbaOcultaDaPasta::paraTodaAEquipe($this->tenant, $this->pasta, AbaDaPasta::Documentos, new User(), new \DateTimeImmutable())];
        $this->esperarIlegivel([1], 'aba Documentos oculta');

        try {
            $this->leitor()->anexos($this->tenant, $this->pasta, [1], [], null);
            self::fail('sem quem pediu, nada é lido');
        } catch (DocumentoIlegivelException $e) {
            self::assertStringContainsString('não acessa mais a pasta', $e->getMessage());
        }
    }

    #[TestDox('Falha de I/O do armazenamento → ArmazenamentoIndisponivelException (transitória), sem nome nem chave na mensagem')]
    public function testFalhaDeArmazenamentoEhTransitoria(): void
    {
        $this->documento(1, '%PDF-1.7 x');
        $this->armazenamentoQueFalha = $this->createStub(ArmazenamentoDeArquivos::class);
        $this->armazenamentoQueFalha->method('ler')->willThrowException(new FalhaDeArmazenamento('disco /var/x/doc-1.bin'));

        try {
            $this->leitor()->anexos($this->tenant, $this->pasta, [1], [], $this->solicitante);
            self::fail('deveria ser transitória');
        } catch (ArmazenamentoIndisponivelException $e) {
            self::assertSame('documento 1: falha ao ler o armazenamento (FalhaDeArmazenamento)', $e->getMessage());
        }
    }
}
