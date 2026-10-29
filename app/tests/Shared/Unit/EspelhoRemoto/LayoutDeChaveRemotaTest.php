<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A chave remota de cada arquivo (spec P2 §2, DP-1, DP-8).
 *
 * Três provas, cada uma fechando um buraco diferente:
 *  1. a tabela literal por categoria — o formato que o dono ratificou;
 *  2. a IGUALDADE com o caminho relativo do disco: para todas as categorias, o que vem depois de
 *     `uploads/` é exatamente o que o `ResolvedorDeCaminhoLocal` monta abaixo de `public/uploads/`
 *     com os parâmetros de produção LIDOS DO `config/services.yaml`. Se alguém mudar um diretório lá,
 *     ou o layout aqui, sozinho, cai — e o espelho deixaria de ser conferível contra a P0;
 *  3. os 326 nomes fora do padrão medidos na P1, byte a byte.
 */
#[CoversClass(LayoutDeChaveRemota::class)]
final class LayoutDeChaveRemotaTest extends TestCase
{
    private const RAIZ_DE_PRODUCAO = '/var/www/app/public/uploads';

    /** @return iterable<string, array{CategoriaDeArquivo, EscopoDeArquivo, string, string}> */
    public static function tabela(): iterable
    {
        $t7 = EscopoDeArquivo::deTenant(7);

        yield 'pasta_documento'     => [CategoriaDeArquivo::PASTA_DOCUMENTO, $t7, 'a1.pdf', 'core/t/7/uploads/pastas/a1.pdf'];
        yield 'pasta_imagem_editor' => [CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, $t7, 'a1.png', 'core/t/7/uploads/pastas/7/a1.png'];
        yield 'pasta_foto'          => [CategoriaDeArquivo::PASTA_FOTO, $t7, 'a1.jpg', 'core/t/7/uploads/pastas/fotos/a1.jpg'];
        yield 'cliente_documento'   => [CategoriaDeArquivo::CLIENTE_DOCUMENTO, $t7, 'a1.pdf', 'core/t/7/uploads/clientes/a1.pdf'];
        yield 'chat_ia_anexo'       => [CategoriaDeArquivo::CHAT_IA_ANEXO, $t7, 'a1.pdf', 'core/t/7/uploads/pastas/chat-ia/a1.pdf'];
        yield 'tenant_logo'         => [CategoriaDeArquivo::TENANT_LOGO, $t7, 'a1.png', 'core/t/7/uploads/pastas/logos/a1.png'];
        yield 'cliente_foto'        => [CategoriaDeArquivo::CLIENTE_FOTO, $t7, 'a1.jpg', 'core/t/7/uploads/clientes/fotos/a1.jpg'];
        yield 'chamado_anexo'       => [CategoriaDeArquivo::CHAMADO_ANEXO, $t7, 'a1.pdf', 'core/t/7/uploads/chamados/a1.pdf'];
        yield 'justificativa_anexo' => [CategoriaDeArquivo::JUSTIFICATIVA_ANEXO, $t7, 'a1.jpg', 'core/t/7/uploads/justificativas/a1.jpg'];
        yield 'cobranca_documento'  => [CategoriaDeArquivo::COBRANCA_DOCUMENTO, $t7, 'a1.pdf', 'core/t/7/uploads/cobrancas/7/a1.pdf'];
        yield 'kanban_anexo'        => [CategoriaDeArquivo::KANBAN_ANEXO, $t7, 'a1.pdf', 'core/t/7/uploads/kanban/a1.pdf'];
        yield 'tarefa_anexo'        => [CategoriaDeArquivo::TAREFA_ANEXO, $t7, 'arquivo_6abab5669fac90.42347212.pdf', 'core/t/7/uploads/tarefas/chat/arquivo_6abab5669fac90.42347212.pdf'];
        yield 'foto_perfil (global)' => [CategoriaDeArquivo::FOTO_PERFIL, EscopoDeArquivo::global(), 'a1.jpg', 'core/g/uploads/perfil/a1.jpg'];
        yield 'trabalho_externo_documento' => [CategoriaDeArquivo::TRABALHO_EXTERNO_DOCUMENTO, $t7, 'a1.pdf', 'core/t/7/uploads/trabalho-externo/a1.pdf'];
        yield 'trabalho_externo_curriculo (global)' => [CategoriaDeArquivo::TRABALHO_EXTERNO_CURRICULO, EscopoDeArquivo::global(), 'a1.pdf', 'core/g/uploads/trabalho-externo/curriculos/a1.pdf'];
        yield 'comercial_imagem (global)' => [CategoriaDeArquivo::COMERCIAL_IMAGEM, EscopoDeArquivo::global(), 'a1.jpg', 'core/g/uploads/comercial-imagens/a1.jpg'];
        yield 'inteligencia_documento_juridico (global)' => [CategoriaDeArquivo::INTELIGENCIA_DOCUMENTO_JURIDICO, EscopoDeArquivo::global(), 'a1.pdf', 'core/g/uploads/documentos-juridicos/a1.pdf'];
        yield 'recompensa_comprovante (global)' => [CategoriaDeArquivo::RECOMPENSA_COMPROVANTE, EscopoDeArquivo::global(), 'a1.pdf', 'core/g/uploads/recompensa-comprovantes/a1.pdf'];
    }

    #[DataProvider('tabela')]
    #[TestDox('$categoria: a chave remota é a do formato ratificado')]
    public function testTabelaRatificada(CategoriaDeArquivo $categoria, EscopoDeArquivo $escopo, string $nome, string $esperada): void
    {
        self::assertSame($esperada, LayoutDeChaveRemota::chaveDe(new ChaveDeArquivo($escopo, $categoria, $nome)));
    }

    #[TestDox('todas as categorias estão na tabela — categoria nova obriga a decidir a chave remota aqui')]
    public function testTodasAsCategoriasCobertas(): void
    {
        $cobertas = array_map(static fn (array $caso): CategoriaDeArquivo => $caso[0], iterator_to_array(self::tabela(), false));

        self::assertEqualsCanonicalizing(CategoriaDeArquivo::cases(), $cobertas);
    }

    #[DataProvider('tabela')]
    #[TestDox('$categoria: depois de uploads/ vem exatamente o caminho relativo do disco de produção')]
    public function testIgualAoCaminhoRelativoDoDisco(CategoriaDeArquivo $categoria, EscopoDeArquivo $escopo, string $nome): void
    {
        $chave   = new ChaveDeArquivo($escopo, $categoria, $nome);
        $noDisco = $this->resolvedorDeProducao()->caminhoDe($chave);
        self::assertStringStartsWith(self::RAIZ_DE_PRODUCAO . '/', $noDisco);

        $relativo = substr($noDisco, \strlen(self::RAIZ_DE_PRODUCAO) + 1);
        $prefixo  = $escopo->ehGlobal() ? 'core/g/uploads/' : 'core/t/' . $escopo->tenantIdObrigatorio() . '/uploads/';

        self::assertSame($prefixo . $relativo, LayoutDeChaveRemota::chaveDe($chave));
    }

    #[TestDox('o tenant da chave remota é o do escopo: dois escritórios, duas chaves — mesmo nas categorias que o disco não separa (R1)')]
    public function testTenantSaiDoEscopo(): void
    {
        $de1 = LayoutDeChaveRemota::chaveDe(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::CLIENTE_DOCUMENTO, 'x.pdf'));
        $de2 = LayoutDeChaveRemota::chaveDe(new ChaveDeArquivo(EscopoDeArquivo::deTenant(2), CategoriaDeArquivo::CLIENTE_DOCUMENTO, 'x.pdf'));

        self::assertSame('core/t/1/uploads/clientes/x.pdf', $de1);
        self::assertSame('core/t/2/uploads/clientes/x.pdf', $de2);
    }

    /** @return iterable<string, array{CategoriaDeArquivo, EscopoDeArquivo}> */
    public static function escoposQueNaoConcordam(): iterable
    {
        $globais = [CategoriaDeArquivo::FOTO_PERFIL, CategoriaDeArquivo::TRABALHO_EXTERNO_CURRICULO, CategoriaDeArquivo::COMERCIAL_IMAGEM, CategoriaDeArquivo::INTELIGENCIA_DOCUMENTO_JURIDICO, CategoriaDeArquivo::RECOMPENSA_COMPROVANTE];
        foreach ($globais as $categoria) {
            yield $categoria->value . ' com escritório' => [$categoria, EscopoDeArquivo::deTenant(1)];
        }
        foreach (CategoriaDeArquivo::cases() as $categoria) {
            if (!\in_array($categoria, $globais, true)) {
                yield $categoria->value . ' global' => [$categoria, EscopoDeArquivo::global()];
            }
        }
    }

    #[DataProvider('escoposQueNaoConcordam')]
    #[TestDox('escopo e categoria que não concordam são recusados — nunca viram chave em outro prefixo')]
    public function testEscopoQueNaoConcordaEhRecusado(CategoriaDeArquivo $categoria, EscopoDeArquivo $escopo): void
    {
        $this->expectException(ChaveDeArquivoInvalida::class);

        LayoutDeChaveRemota::chaveDe(new ChaveDeArquivo($escopo, $categoria, 'x.pdf'));
    }

    #[TestDox('teto do S3: chave de exatamente 1024 bytes passa; de 1025, é recusada')]
    public function testTetoDoS3(): void
    {
        $prefixo = \strlen('core/t/1/uploads/pastas/');
        $noTeto  = new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, str_repeat('a', 1024 - $prefixo));
        self::assertSame(1024, \strlen(LayoutDeChaveRemota::chaveDe($noTeto)));

        $this->expectException(ChaveDeArquivoInvalida::class);
        LayoutDeChaveRemota::chaveDe(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, str_repeat('a', 1025 - $prefixo)));
    }

    /** @return iterable<string, array{string}> */
    public static function legadosDaP1(): iterable
    {
        foreach (self::fixture()['nomes'] as $i => $nome) {
            yield sprintf('#%03d %s', $i, json_encode($nome, \JSON_UNESCAPED_UNICODE)) => [$nome];
        }
    }

    /**
     * Os 326 nomes de `pasta_documento.caminho_arquivo` fora do padrão hash, medidos na P1
     * (160 terminados em `.`, 147 com extensão maiúscula, 19 com texto livre — Unicode, espaço nas
     * bordas, `／`). O fixture é ANONIMIZADO na origem, na VPS (LGPD e D8: os nomes reais têm nome de
     * pessoa e os hashes reais são chaves de produção): mesma forma, mesmo comprimento em caracteres
     * e em bytes, mesmas classes de caractere; ver o campo `anonimizacao` do JSON.
     */
    #[DataProvider('legadosDaP1')]
    #[TestDox('legado da P1: o nome entra na chave remota byte a byte, sem ‛ nem normalização')]
    public function testLegadoDaP1PreservadoByteAByte(string $nome): void
    {
        $chave = LayoutDeChaveRemota::chaveDe(new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome));

        self::assertSame('core/t/1/uploads/pastas/' . $nome, $chave);
        self::assertSame(\strlen('core/t/1/uploads/pastas/') + \strlen($nome), \strlen($chave), 'nenhum byte entra nem sai');
        self::assertStringNotContainsString("\u{201B}", $chave, 'o ‛ é o escape do rclone, não do layout');
        self::assertTrue(mb_check_encoding($chave, 'UTF-8'));
    }

    #[TestDox('o fixture tem os 326 da P1, nas três classes medidas, com os casos que importam')]
    public function testFixtureEhOdaP1(): void
    {
        $fixture = self::fixture();
        $nomes   = $fixture['nomes'];

        self::assertSame(326, $fixture['total']);
        self::assertCount(326, $nomes);
        self::assertSame(['sem extensão' => 160, 'extensão maiúscula' => 147, 'texto livre' => 19], $fixture['classes']);
        self::assertCount(2, array_filter($nomes, static fn (string $n): bool => str_contains($n, '／')), 'os dois com barra de largura total');
        self::assertCount(6, array_filter($nomes, static fn (string $n): bool => preg_match('/[^\x00-\x7F]/', $n) === 1), 'os seis com Unicode');
        self::assertCount(3, array_filter($nomes, static fn (string $n): bool => $n !== trim($n)), 'os três com espaço na borda');
        self::assertSame(67, max(array_map('strlen', $nomes)), 'o maior nome medido na P1, em bytes — longe do teto de 1024');
    }

    /** @return array{total: int, classes: array<string, int>, nomes: list<string>} */
    private static function fixture(): array
    {
        /** @var array{total: int, classes: array<string, int>, nomes: list<string>} $dados */
        $dados = json_decode((string) file_get_contents(__DIR__ . '/legados-p1-anonimizados.json'), true, 512, \JSON_THROW_ON_ERROR);

        return $dados;
    }

    /** O resolvedor com os diretórios de PRODUÇÃO, lidos do `services.yaml` (não do `when@test`). */
    private function resolvedorDeProducao(): ResolvedorDeCaminhoLocal
    {
        /** @var array{parameters: array<string, string>} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 4) . '/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        $p      = static fn (string $nome): string => str_replace('%kernel.project_dir%', '/var/www/app', $config['parameters'][$nome]);

        return new ResolvedorDeCaminhoLocal(
            uploadsDir: $p('uploads_dir'),
            clientesUploadsDir: $p('clientes_uploads_dir'),
            chamadosUploadsDir: $p('chamados_uploads_dir'),
            justificativasUploadsDir: $p('justificativas_uploads_dir'),
            fotosPerfilDir: $p('fotos_perfil_dir'),
            cobrancasUploadsDir: $p('cobrancas_uploads_dir'),
            kanbanUploadsDir: $p('kanban_uploads_dir'),
            tarefasUploadsDir: $p('tarefas_uploads_dir'),
            trabalhoExternoUploadsDir: $p('trabalho_externo_uploads_dir'),
            comercialImagensDir: $p('comercial_imagens_dir'),
            documentosJuridicosDir: $p('documentos_juridicos_dir'),
            recompensaComprovantesDir: $p('recompensa_comprovantes_dir'),
        );
    }
}
