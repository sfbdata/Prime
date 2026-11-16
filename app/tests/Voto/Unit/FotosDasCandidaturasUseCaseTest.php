<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Voto\Armazenamento\ChavesDoVoto;
use App\Voto\DTO\PautaInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Enum\TipoDaPauta;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OpcaoDaPautaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\FotosDasCandidaturas;
use App\Voto\Service\OpcoesDaPauta;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\UseCase\AdicionarPautaUseCase;
use App\Voto\UseCase\EditarPautaUseCase;
use App\Voto\UseCase\ExcluirRascunhoDaAssembleiaUseCase;
use App\Voto\UseCase\RemoverPautaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Foto 3x4 da candidatura (desenho 18, `.vFotoU`; decisão C7): enviar, trocar, manter e remover pelo "Salvar" da pauta.
 * O arquivo novo é gravado na chave do escritório (VOTO_FOTO_CANDIDATURA), o antigo só sai DEPOIS do COMMIT, o nome que
 * a tela devolve só vale se a própria pauta o tem, e depois de publicada nada muda. A `TransacaoComArquivoNovo` é a
 * REAL (ver `ComFotosDasCandidaturas`).
 */
#[CoversClass(FotosDasCandidaturas::class)]
#[CoversClass(EditarPautaUseCase::class)]
#[CoversClass(AdicionarPautaUseCase::class)]
#[CoversClass(RemoverPautaUseCase::class)]
#[CoversClass(ExcluirRascunhoDaAssembleiaUseCase::class)]
#[CoversClass(ChavesDoVoto::class)]
final class FotosDasCandidaturasUseCaseTest extends TestCase
{
    use ComFotosDasCandidaturas;

    private Tenant $tenant;
    private Assembleia $assembleia;
    private PautaDaAssembleia $pauta;
    private string $dirTemp;

    protected function setUp(): void
    {
        $this->tenant = self::tenant(7);
        $this->assembleia = new Assembleia($this->tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        $this->pauta = new PautaDaAssembleia($this->assembleia, 1, 'Síndico(a)', null, TipoDaPauta::EleicaoDeCargo);
        $this->dirTemp = sys_get_temp_dir() . '/voto-foto-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dirTemp);
    }

    #[TestDox('Enviar: grava no escopo do escritório (VOTO_FOTO_CANDIDATURA) e a opção aponta para o nome cunhado')]
    public function testEnviaAFoto(): void
    {
        $this->opcaoComFoto(1, 'Ana Souza', null);

        $this->editar([$this->linha('1', 'Ana Souza', arquivo: $this->upload($this->jpeg())), $this->linha('2', 'Caio Lima')]);

        $chave = $this->armazenamento->ultimaGravada();
        self::assertSame(CategoriaDeArquivo::VOTO_FOTO_CANDIDATURA, $chave->categoria);
        self::assertSame(7, $chave->escopo->tenantIdOuNull());
        self::assertMatchesRegularExpression(ChavesDoVoto::PADRAO_DO_NOME, $chave->nome);
        [$ana, $caio] = $this->pauta->getOpcoesDeVoto();
        self::assertSame($chave->nome, $ana->getFoto());
        self::assertNull($caio->getFoto());
        self::assertSame(['commit'], $this->ordem, 'só o COMMIT; nada a apagar');
    }

    #[TestDox('PNG e WebP também valem')]
    public function testPngEWebp(): void
    {
        $this->opcaoComFoto(1, 'Ana Souza', null);
        $this->editar([$this->linha('1', 'Ana Souza', arquivo: $this->upload($this->png(), 'foto.png'))]);
        self::assertStringEndsWith('.png', (string) $this->pauta->getOpcoesDeVoto()[0]->getFoto());
    }

    #[TestDox('Trocar: grava a nova, COMMIT, e só depois apaga a anterior (pelo nome que a opção tinha)')]
    public function testTrocaApagaAAnteriorDepoisDoCommit(): void
    {
        $antiga = $this->opcaoComFoto(1, 'Ana Souza', 'a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg');

        $this->editar([$this->linha('1', 'Ana Souza', foto: $antiga->nome, arquivo: $this->upload($this->jpeg()))]);

        $nova = $this->armazenamento->ultimaGravada();
        self::assertSame($nova->nome, $this->pauta->getOpcoesDeVoto()[0]->getFoto());
        self::assertNotSame($antiga->nome, $nova->nome);
        self::assertSame(['commit', 'excluir'], $this->ordem);
        self::assertFalse($this->armazenamento->existe($antiga));
        self::assertTrue($this->armazenamento->existe($nova));
    }

    #[TestDox('Manter: o nome devolvido pela tela, sem arquivo novo, fica na opção, mesmo que o nome ou o número mude de lugar')]
    public function testMantemAFoto(): void
    {
        $antiga = $this->opcaoComFoto(1, 'Ana Souza', 'a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg');
        $this->opcaoComFoto(2, 'Caio Lima', null);

        $this->editar([$this->linha('2', 'Ana Souza', foto: $antiga->nome), $this->linha('1', 'Caio Lima')]);

        [$caio, $ana] = $this->pauta->getOpcoesDeVoto();
        self::assertSame(['Caio Lima', null, 'Ana Souza', $antiga->nome], [$caio->getRotulo(), $caio->getFoto(), $ana->getRotulo(), $ana->getFoto()], 'a foto segue a linha');
        self::assertTrue($this->armazenamento->existe($antiga));
        self::assertSame([], $this->armazenamento->excluidas);
    }

    #[TestDox('Remover: nome vazio tira a foto e o arquivo sai só depois do COMMIT')]
    public function testRemoveAFoto(): void
    {
        $antiga = $this->opcaoComFoto(1, 'Ana Souza', 'a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg');

        $this->editar([$this->linha('1', 'Ana Souza', foto: '')]);

        self::assertNull($this->pauta->getOpcoesDeVoto()[0]->getFoto());
        self::assertFalse($this->armazenamento->existe($antiga));
        self::assertSame(['excluir'], $this->ordem, 'sem arquivo novo não há transação explícita; o flush é o COMMIT');
    }

    #[TestDox('Candidatura tirada da pauta leva a foto embora; a abstenção nunca tem foto')]
    public function testCandidaturaRemovidaLevaAFoto(): void
    {
        $antiga = $this->opcaoComFoto(1, 'Ana Souza', 'a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg');
        $this->opcaoComFoto(2, 'Caio Lima', null);

        $this->editar([$this->linha('2', 'Caio Lima')]);

        self::assertFalse($this->armazenamento->existe($antiga));
        self::assertCount(1, $this->pauta->getOpcoesDeVoto());
    }

    #[TestDox('Nome forjado: foto de outra pauta ou de outro escritório não vale e o arquivo alheio não é tocado')]
    public function testNomeForjadoNaoVale(): void
    {
        $this->opcaoComFoto(1, 'Ana Souza', null);
        $alheia = new ChaveDeArquivo(\App\Shared\Armazenamento\EscopoDeArquivo::deTenant(99), CategoriaDeArquivo::VOTO_FOTO_CANDIDATURA, 'ffffffffffffffffffffffffffffffff.jpg');
        $this->armazenamento ??= new \App\Tests\Shared\Doubles\ArmazenamentoEmMemoria();
        $this->armazenamento->semear($alheia, 'do vizinho');

        $this->editar([$this->linha('1', 'Ana Souza', foto: 'ffffffffffffffffffffffffffffffff.jpg')]);

        self::assertNull($this->pauta->getOpcoesDeVoto()[0]->getFoto());
        self::assertTrue($this->armazenamento->existe($alheia), 'nada do vizinho foi apagado');
        self::assertSame([], $this->armazenamento->excluidas);
    }

    #[TestDox('Pauta de deliberação não guarda foto: o arquivo enviado é ignorado e a que existia sai')]
    public function testDeliberacaoNaoTemFoto(): void
    {
        $pauta = new PautaDaAssembleia($this->assembleia, 2, 'Contas');
        $antiga = new OpcaoDaPauta($pauta, 1, 'Sim');
        $antiga->definirFoto('a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg');
        $this->fotosDasCandidaturas();
        $chave = ChavesDoVoto::fotoDaCandidatura($antiga);
        $this->armazenamento->semear($chave);

        $this->editar([$this->linha('1', 'Sim', foto: $antiga->getFoto() ?? '', arquivo: $this->upload($this->jpeg())), $this->linha('2', 'Não')], $pauta, TipoDaPauta::Deliberacao);

        self::assertSame([null, null], array_map(static fn (OpcaoDaPauta $o): ?string => $o->getFoto(), $pauta->getOpcoesDeVoto()));
        self::assertFalse($this->armazenamento->existe($chave));
        self::assertSame([], $this->armazenamento->gravadas, 'nada foi gravado');
    }

    /** @return iterable<string, array{string, string}> */
    public static function imagensRecusadas(): iterable
    {
        yield 'GIF' => ['gif', '/não permitido/i'];
        yield 'PDF' => ['pdf', '/não permitido/i'];
        yield 'JPEG de mentira (só o cabeçalho)' => ['jpegFalso', '/não permitido/i'];
        yield 'acima de 3 MB' => ['jpegGrande', '/3 MB/'];
    }

    #[DataProvider('imagensRecusadas')]
    #[TestDox('Imagem recusada ($_dataName): 422 antes de gravar qualquer coisa e a pauta não muda')]
    public function testImagemRecusada(string $tipo, string $mensagem): void
    {
        $antiga = $this->opcaoComFoto(1, 'Ana Souza', 'a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg');
        $conteudo = match ($tipo) {
            'gif' => "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;",
            'pdf' => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n",
            'jpegFalso' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9",
            'jpegGrande' => str_pad($this->jpeg(), FotosDasCandidaturas::TAMANHO_MAX + 1, "\0"),
        };

        try {
            $this->editar([$this->linha('1', 'Ana Souza', foto: $antiga->nome, arquivo: $this->upload($conteudo)), $this->linha('2', 'Caio Lima')]);
            self::fail('Esperava a recusa.');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertMatchesRegularExpression($mensagem, $e->getMessage());
            self::assertStringContainsString('Ana Souza', $e->getMessage());
        }

        self::assertSame([], $this->armazenamento->gravadas);
        self::assertTrue($this->armazenamento->existe($antiga));
        self::assertSame($antiga->nome, $this->pauta->getOpcoesDeVoto()[0]->getFoto(), 'a opção antiga segue intacta');
        self::assertSame([], $this->ordem);
    }

    #[TestDox('Depois de publicada a assembleia, enviar ou remover foto é recusado e nada é gravado nem apagado')]
    public function testTravaDepoisDePublicada(): void
    {
        $this->assembleia = new Assembleia($this->tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $this->pauta = new PautaDaAssembleia($this->assembleia, 1, 'Síndico(a)', null, TipoDaPauta::EleicaoDeCargo);
        $antiga = $this->opcaoComFoto(1, 'Ana Souza', 'a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg');
        $this->opcaoComFoto(2, 'Caio Lima', null);
        new UnidadeDaAssembleia($this->assembleia, '101', 'semente', 'hash');
        $this->assembleia->publicar();

        try {
            $this->editar([$this->linha('1', 'Ana Souza', arquivo: $this->upload($this->jpeg())), $this->linha('2', 'Caio Lima')]);
            self::fail('Publicada não muda.');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertSame('As pautas só mudam enquanto a assembleia está em rascunho.', $e->getMessage());
        }

        self::assertSame([], $this->armazenamento->gravadas);
        self::assertTrue($this->armazenamento->existe($antiga));
        self::assertSame($antiga->nome, $this->pauta->getOpcoesDeVoto()[0]->getFoto());
    }

    #[TestDox('Falha do banco antes do COMMIT: a foto nova não fica órfã e a antiga continua onde estava')]
    public function testFalhaDoBancoDesfazAFotoNova(): void
    {
        $antiga = $this->opcaoComFoto(1, 'Ana Souza', 'a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg');
        $recusa = new \RuntimeException('flush recusado');

        try {
            $this->editar([$this->linha('1', 'Ana Souza', foto: $antiga->nome, arquivo: $this->upload($this->jpeg()))], noFlush: $recusa);
            self::fail('A recusa do banco tinha de subir.');
        } catch (\RuntimeException $e) {
            self::assertSame($recusa, $e);
        }

        self::assertTrue($this->armazenamento->existe($antiga));
        self::assertFalse($this->armazenamento->existe($this->armazenamento->ultimaGravada()));
        self::assertNotContains($antiga, $this->armazenamento->excluidas);
    }

    #[TestDox('Disco falha ao apagar a anterior depois do COMMIT: a troca vale e o órfão vira registro')]
    public function testFalhaAoApagarAAnteriorViraRegistro(): void
    {
        $antiga = $this->opcaoComFoto(1, 'Ana Souza', 'a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg');
        $this->armazenamento->falhaAoExcluir = static fn (): \Throwable => new \RuntimeException('disco ilegível');

        $this->editar([$this->linha('1', 'Ana Souza', foto: $antiga->nome, arquivo: $this->upload($this->jpeg()))]);

        self::assertSame($this->armazenamento->ultimaGravada()->nome, $this->pauta->getOpcoesDeVoto()[0]->getFoto());
        self::assertTrue($this->armazenamento->existe($antiga));
        self::assertNotEmpty($this->logger->doNivel('error'));
    }

    #[TestDox('Pauta nova com foto: a opção nasce apontando para o arquivo gravado no escritório da assembleia')]
    public function testAdicionarPautaComFoto(): void
    {
        $assembleias = $this->createStub(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->willReturn($this->assembleia);
        $salva = null;
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->expects(self::once())->method('salvar')->willReturnCallback(static function (PautaDaAssembleia $p) use (&$salva): void {
            $salva = $p;
        });

        (new AdicionarPautaUseCase($assembleias, $pautas, new OpcoesDaPauta(), self::serializacao(), $this->fotosDasCandidaturas()))->executar($this->tenant, 7, new PautaInput(
            'Conselho', null,
            [$this->linha('1', 'Ana Souza', arquivo: $this->upload($this->jpeg())), $this->linha('2', 'Caio Lima')],
            null,
            TipoDaPauta::EleicaoDeCargo,
        ));

        self::assertInstanceOf(PautaDaAssembleia::class, $salva);
        $chave = $this->armazenamento->ultimaGravada();
        self::assertSame([7, CategoriaDeArquivo::VOTO_FOTO_CANDIDATURA], [$chave->escopo->tenantIdOuNull(), $chave->categoria]);
        [$ana, $caio] = $salva->getOpcoesDeVoto();
        self::assertSame([$chave->nome, null], [$ana->getFoto(), $caio->getFoto()]);
        self::assertNull($salva->getAbstencao()?->getFoto());
    }

    #[TestDox('Remover a pauta apaga as fotos das candidaturas depois do COMMIT')]
    public function testRemoverPautaApagaAsFotos(): void
    {
        $antiga = $this->opcaoComFoto(1, 'Ana Souza', 'a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg');
        $assembleias = $this->createStub(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->willReturn($this->assembleia);
        $pautas = $this->createMock(PautaDaAssembleiaRepository::class);
        $pautas->method('buscarDaAssembleia')->willReturn($this->pauta);
        $pautas->expects(self::once())->method('remover')->willReturnCallback(function (): void {
            self::assertSame([], $this->ordem, 'ainda não saiu nada do disco');
        });

        (new RemoverPautaUseCase($assembleias, $pautas, self::serializacao(), $this->fotosDasCandidaturas()))->executar($this->tenant, 7, 9);

        self::assertFalse($this->armazenamento->existe($antiga));
        self::assertSame(['excluir'], $this->ordem);
    }

    #[TestDox('Excluir o rascunho apaga as fotos de todas as pautas depois da remoção')]
    public function testExcluirRascunhoApagaAsFotos(): void
    {
        $antiga = $this->opcaoComFoto(1, 'Ana Souza', 'a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg');
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->willReturn($this->assembleia);
        $assembleias->expects(self::once())->method('remover');

        (new ExcluirRascunhoDaAssembleiaUseCase($assembleias, self::serializacao(), $this->fotosDasCandidaturas()))->executar($this->tenant, 7);

        self::assertFalse($this->armazenamento->existe($antiga));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Cria a opção com foto já guardada (semeada no armazenamento) ou sem foto. Devolve a chave (ou um marcador sem foto).
     */
    private function opcaoComFoto(int $numero, string $rotulo, ?string $nome): ChaveDeArquivo
    {
        $opcao = new OpcaoDaPauta($this->pauta, $numero, $rotulo);
        $this->fotosDasCandidaturas();
        if (null === $nome) {
            return new ChaveDeArquivo(\App\Shared\Armazenamento\EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::VOTO_FOTO_CANDIDATURA, 'semfoto.jpg');
        }
        $opcao->definirFoto($nome);
        $chave = ChavesDoVoto::fotoDaCandidatura($opcao);
        $this->armazenamento->semear($chave, 'foto antiga');

        return $chave;
    }

    /**
     * @param list<array<string, mixed>> $linhas
     */
    private function editar(array $linhas, ?PautaDaAssembleia $pauta = null, TipoDaPauta $tipo = TipoDaPauta::EleicaoDeCargo, ?\Throwable $noFlush = null): void
    {
        $pauta ??= $this->pauta;
        $assembleias = $this->createStub(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->willReturn($this->assembleia);
        $pautas = $this->createStub(PautaDaAssembleiaRepository::class);
        $pautas->method('buscarDaAssembleia')->willReturn($pauta);
        $caso = new EditarPautaUseCase($assembleias, $pautas, $this->createStub(OpcaoDaPautaRepository::class), new OpcoesDaPauta(), self::serializacao(), $this->fotosDasCandidaturas($noFlush));

        $caso->executar($this->tenant, 7, 9, new PautaInput($pauta->getTitulo(), null, $linhas, null, $tipo));
    }

    /** @return array<string, mixed> */
    private function linha(string $numero, string $rotulo, ?string $foto = null, ?UploadedFile $arquivo = null): array
    {
        return ['numero' => $numero, 'rotulo' => $rotulo, 'foto' => $foto ?? '', 'arquivo' => $arquivo];
    }

    private function upload(string $conteudo, string $nome = 'foto.jpg'): UploadedFile
    {
        $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    private function jpeg(): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(6, 8), null, 88);

        return (string) ob_get_clean();
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(6, 8));

        return (string) ob_get_clean();
    }

    private static function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private static function serializacao(): SerializacaoDaConfiguracao
    {
        return new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore()));
    }
}
