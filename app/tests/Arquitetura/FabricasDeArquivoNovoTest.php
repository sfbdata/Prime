<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\Entity\ClientePF;
use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tenant\Tenant;
use App\Kanban\Armazenamento\ChavesDeKanban;
use App\Kanban\Entity\KanbanCard;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\PastaDocumento;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Ponto\Entity\JustificativaPonto;
use App\Profile\Armazenamento\ChavesDePerfil;
use App\ServiceDesk\Armazenamento\ChavesDeServiceDesk;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As fábricas de ARQUIVO NOVO dos domínios (E2.4A) — o par de escrita das fábricas de chave da
 * E2.2.
 *
 * O que está em jogo é R1: no disco local o escopo é ignorado em sete categorias, então um tenant
 * errado na gravação passaria verde aqui e viraria 404 (ou vazamento) no backend remoto. Por isso
 * cada fábrica é conferida contra a entidade dona, e as fábricas são DESCOBERTAS em
 * `src/<Dominio>/Armazenamento/ChavesDe*.php`: uma fábrica nova sem caso neste teste derruba a
 * suíte. E nada fora delas pode montar `NovoArquivo` — senão a rede toda é contornável.
 */
final class FabricasDeArquivoNovoTest extends TestCase
{

    /**
     * As únicas que recebem `Tenant`, cada uma com o porquê no docblock: não existe entidade dona
     * no momento da gravação.
     */
    private const RECEBEM_TENANT = [
        ChavesDePasta::class . '::novaImagemDoEditor',
        ChavesDePonto::class . '::novoAnexoDeLote',
    ];

    /**
     * @return iterable<string, array{string, \Closure(Tenant): object, CategoriaDeArquivo}>
     */
    public static function fabricas(): iterable
    {
        yield ChavesDePasta::class . '::novoDocumento' => [
            ChavesDePasta::class . '::novoDocumento',
            static fn (?Tenant $t): object => self::comTenant(new PastaDocumento(), $t),
            CategoriaDeArquivo::PASTA_DOCUMENTO,
        ];
        yield ChavesDePasta::class . '::novaImagemDoEditor' => [
            ChavesDePasta::class . '::novaImagemDoEditor',
            static fn (?Tenant $t): ?Tenant => $t,
            CategoriaDeArquivo::PASTA_IMAGEM_EDITOR,
        ];
        yield ChavesDeCliente::class . '::novoDocumento' => [
            ChavesDeCliente::class . '::novoDocumento',
            static fn (?Tenant $t): object => self::comTenant(new ClientePF(), $t),
            CategoriaDeArquivo::CLIENTE_DOCUMENTO,
        ];
        yield ChavesDeCobranca::class . '::novoDocumentoDeCaso' => [
            ChavesDeCobranca::class . '::novoDocumentoDeCaso',
            static fn (?Tenant $t): object => self::comTenant(new CasoCobranca(), $t),
            CategoriaDeArquivo::COBRANCA_DOCUMENTO,
        ];
        yield ChavesDeCobranca::class . '::novoDocumentoDeAcordo' => [
            ChavesDeCobranca::class . '::novoDocumentoDeAcordo',
            static fn (?Tenant $t): object => self::comTenant(new Acordo(), $t),
            CategoriaDeArquivo::COBRANCA_DOCUMENTO,
        ];
        yield ChavesDeCobranca::class . '::novoDocumentoDeCarteira' => [
            ChavesDeCobranca::class . '::novoDocumentoDeCarteira',
            static fn (?Tenant $t): object => self::comTenant(new Carteira(), $t),
            CategoriaDeArquivo::COBRANCA_DOCUMENTO,
        ];
        yield ChavesDeKanban::class . '::novoAnexo' => [
            ChavesDeKanban::class . '::novoAnexo',
            static fn (?Tenant $t): object => self::comTenant(
                (new \ReflectionClass(KanbanCard::class))->newInstanceWithoutConstructor(),
                $t,
            ),
            CategoriaDeArquivo::KANBAN_ANEXO,
        ];
        yield ChavesDeServiceDesk::class . '::novoAnexoDeChamado' => [
            ChavesDeServiceDesk::class . '::novoAnexoDeChamado',
            static fn (?Tenant $t): object => self::comTenant(new Chamado(), $t),
            CategoriaDeArquivo::CHAMADO_ANEXO,
        ];
        yield ChavesDePonto::class . '::novoAnexoDeJustificativa' => [
            ChavesDePonto::class . '::novoAnexoDeJustificativa',
            static fn (?Tenant $t): object => self::comTenant(new JustificativaPonto(), $t),
            CategoriaDeArquivo::JUSTIFICATIVA_ANEXO,
        ];
        yield ChavesDePonto::class . '::novoAnexoDeLote' => [
            ChavesDePonto::class . '::novoAnexoDeLote',
            static fn (?Tenant $t): ?Tenant => $t,
            CategoriaDeArquivo::JUSTIFICATIVA_ANEXO,
        ];
    }

    /**
     * @param \Closure(?Tenant): mixed $dona
     */
    #[DataProvider('fabricas')]
    #[TestDox('$metodo: categoria certa e escopo tirado da dona')]
    public function testEscopoECategoriaSaemDaDona(string $metodo, \Closure $dona, CategoriaDeArquivo $categoria): void
    {
        $novo = $metodo($dona(self::tenant(7)), 'pdf');

        self::assertInstanceOf(NovoArquivo::class, $novo);
        self::assertSame($categoria, $novo->categoria);
        self::assertSame(7, $novo->escopo->tenantIdOuNull());
        self::assertSame('pdf', $novo->extensao);
    }

    /**
     * @param \Closure(?Tenant): mixed $dona
     */
    #[DataProvider('fabricas')]
    #[TestDox('$metodo: dona sem escritório é recusada antes de qualquer gravação')]
    public function testDonaSemTenantEhRecusada(string $metodo, \Closure $dona, CategoriaDeArquivo $categoria): void
    {
        $semId = $dona(new Tenant());

        if ($semId instanceof Tenant) {
            $this->expectException(ChaveDeArquivoInvalida::class);
            $metodo($semId, 'pdf');

            return;
        }

        $this->expectException(ChaveDeArquivoInvalida::class);
        $metodo($dona(null), 'pdf');
    }

    #[TestDox('extensão inadequada vira bin, nunca exceção (D8)')]
    public function testExtensaoInadequadaViraBin(): void
    {
        $documento = (new PastaDocumento())->setTenant(self::tenant(7));

        self::assertSame('bin', ChavesDePasta::novoDocumento($documento, 'açaí - 02 junho 2025')->extensao);
        self::assertSame('bin', ChavesDePasta::novoDocumento($documento, '')->extensao);
        self::assertSame('pdf', ChavesDePasta::novoDocumento($documento, 'PDF')->extensao);
    }

    #[TestDox('foto nova: escopo global (D1)')]
    public function testFotoNovaEhGlobal(): void
    {
        $novo = ChavesDePerfil::novaFoto('png');

        self::assertSame(CategoriaDeArquivo::FOTO_PERFIL, $novo->categoria);
        self::assertTrue($novo->escopo->ehGlobal());
        self::assertSame('png', $novo->extensao);
    }

    #[TestDox('toda fábrica de arquivo novo tem caso neste teste, e só as documentadas recebem Tenant')]
    public function testInventarioDasFabricas(): void
    {
        $cobertas = array_keys(iterator_to_array(self::fabricas()));
        $cobertas[] = ChavesDePerfil::class . '::novaFoto';

        $encontradas = [];

        $fabricas = self::fabricasDescobertas();
        self::assertGreaterThanOrEqual(7, \count($fabricas), 'a descoberta das fábricas não achou nada');

        foreach ($fabricas as $classe) {
            foreach ((new \ReflectionClass($classe))->getMethods(\ReflectionMethod::IS_PUBLIC) as $metodo) {
                // Sem tipo de retorno, o filtro abaixo não enxergaria uma fábrica de arquivo novo.
                self::assertTrue($metodo->hasReturnType(), $classe . '::' . $metodo->getName() . ' sem tipo de retorno');

                if (!str_contains((string) $metodo->getReturnType(), NovoArquivo::class)) {
                    continue;
                }

                $nome          = $classe . '::' . $metodo->getName();
                $encontradas[] = $nome;

                foreach ($metodo->getParameters() as $parametro) {
                    // Pega `Tenant`, `?Tenant`, uniões e o id solto (`int $tenantId`).
                    $recebeTenant = str_contains((string) $parametro->getType(), Tenant::class)
                        || stripos($parametro->getName(), 'tenant') !== false;

                    if ($recebeTenant) {
                        self::assertContains($nome, self::RECEBEM_TENANT, $nome . ' recebe o escritório por fora sem estar documentada como exceção');
                    }
                }
            }
        }

        sort($cobertas);
        sort($encontradas);

        self::assertSame($cobertas, $encontradas);
    }

    /**
     * Quem monta `NovoArquivo` fora das fábricas contorna toda a prova de escopo acima: o
     * `ArmazenamentoLocal` ignora o escopo em sete categorias e a suíte não perceberia.
     */
    #[TestDox('só as fábricas dos domínios (e o próprio núcleo) montam NovoArquivo')]
    public function testSoAsFabricasMontamNovoArquivo(): void
    {
        $raiz    = \dirname(__DIR__, 2);
        $achados = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz . '/src', \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $arquivo */
        foreach ($iterador as $arquivo) {
            $relativo = str_replace($raiz . '/', '', $arquivo->getPathname());

            if ($arquivo->getExtension() !== 'php'
                || preg_match('#^src/[A-Za-z]+/Armazenamento/ChavesDe[A-Za-z]+\.php$#', $relativo) === 1
                || str_starts_with($relativo, 'src/Shared/Armazenamento/')
            ) {
                continue;
            }

            $codigo = (string) file_get_contents($arquivo->getPathname());

            // O nome curto, o qualificado (`new \App\Shared\Armazenamento\NovoArquivo(`) e o apelido
            // (`use ...\NovoArquivo as Outro;`), que tornaria o `new` invisível para a primeira regex.
            if (preg_match('/\bnew\s+[\\\\\w]*NovoArquivo\s*\(/', $codigo) === 1
                || preg_match('/NovoArquivo\s+as\s+\w+/', $codigo) === 1
            ) {
                $achados[] = $relativo;
            }
        }

        self::assertSame([], $achados);
    }

    /** @return list<class-string> */
    private static function fabricasDescobertas(): array
    {
        $raiz     = \dirname(__DIR__, 2);
        $fabricas = [];

        foreach (glob($raiz . '/src/*/Armazenamento/ChavesDe*.php') ?: [] as $arquivo) {
            $dominio    = basename(\dirname($arquivo, 2));
            $fabricas[] = 'App\\' . $dominio . '\\Armazenamento\\' . basename($arquivo, '.php');
        }

        sort($fabricas);

        return $fabricas;
    }

    private static function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private static function comTenant(object $entidade, ?Tenant $tenant): object
    {
        if ($tenant === null) {
            return $entidade;
        }

        // `tenant` pode ser privado de uma classe mãe (ClientePF herda de Cliente).
        $classe = new \ReflectionClass($entidade);
        while (!$classe->hasProperty('tenant') || $classe->getProperty('tenant')->getDeclaringClass()->getName() !== $classe->getName()) {
            $classe = $classe->getParentClass() ?: throw new \LogicException('Entidade sem propriedade tenant.');
        }

        $classe->getProperty('tenant')->setValue($entidade, $tenant);

        return $entidade;
    }
}
