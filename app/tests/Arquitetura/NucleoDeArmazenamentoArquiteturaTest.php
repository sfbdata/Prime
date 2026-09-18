<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Shared\Armazenamento\ArmazenamentoComPrefixo;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoEmprestado;
use App\Shared\Armazenamento\ArquivoTemporarioPossuido;
use App\Shared\Armazenamento\CategoriaComIsolamentoFisico;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As decisões D7, D8 e D9 são **estruturais** — e um teste que só exercitasse comportamento não
 * provaria nada sobre elas.
 *
 * O que se afirma aqui não é "o código funciona": é "o código **errado não pode ser escrito**".
 * São propriedades da forma da API, e é por isso que este arquivo usa reflexão em vez de
 * chamadas. Se alguém abrir a porta de novo — aceitar categoria plana no prefixo, unificar os
 * dois tipos de ownership, importar HttpFoundation no núcleo — a suíte cai aqui, na revisão, e
 * não em produção.
 */
final class NucleoDeArmazenamentoArquiteturaTest extends TestCase
{
    private function diretorioDoNucleo(): string
    {
        return \dirname(__DIR__, 2) . '/src/Shared/Armazenamento';
    }

    // =================================================================== D7

    /**
     * A barreira de D7 é o TIPO do parâmetro. Aceitar `CategoriaDeArquivo` aqui reabriria a
     * possibilidade de um `excluirPrefixo(tenant 5, CLIENTE_DOCUMENTO)` varrer
     * `uploads/clientes`, que é compartilhado por todos os escritórios.
     */
    #[TestDox('D7: nenhuma operação de prefixo aceita CategoriaDeArquivo')]
    public function testPrefixoSoAceitaCategoriaComIsolamentoFisico(): void
    {
        $interface = new \ReflectionClass(ArmazenamentoComPrefixo::class);

        self::assertNotSame([], $interface->getMethods(), 'a interface de prefixo não pode estar vazia');

        foreach ($interface->getMethods() as $metodo) {
            $tipos = array_map(
                static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                $metodo->getParameters(),
            );

            self::assertNotContains(
                CategoriaDeArquivo::class,
                $tipos,
                sprintf(
                    'ArmazenamentoComPrefixo::%s aceita CategoriaDeArquivo. Isso reabre D7: '
                    . 'categoria plana mora em diretório compartilhado entre escritórios, e '
                    . 'apagar o "prefixo do tenant" ali destrói o acervo alheio.',
                    $metodo->getName(),
                ),
            );

            self::assertContains(
                CategoriaComIsolamentoFisico::class,
                $tipos,
                sprintf('ArmazenamentoComPrefixo::%s deveria exigir o tipo restrito.', $metodo->getName()),
            );
        }
    }

    #[TestDox('D7: o enum restrito tem exatamente as duas categorias que isolam em disco')]
    public function testEnumRestritoTemSomenteAsDuasSeguras(): void
    {
        $restritas = array_map(
            static fn (CategoriaComIsolamentoFisico $c): CategoriaDeArquivo => $c->paraCategoria(),
            CategoriaComIsolamentoFisico::cases(),
        );

        self::assertEqualsCanonicalizing(
            [CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, CategoriaDeArquivo::COBRANCA_DOCUMENTO],
            $restritas,
        );
    }

    /**
     * A premissa de D7 verificada contra o resolvedor real, não contra a documentação: categoria
     * com isolamento resolve para caminhos DIFERENTES por tenant; categoria plana, para o MESMO.
     *
     * Se alguém acrescentar um caso ao enum restrito sem dar subpasta de tenant à categoria, este
     * teste cai — que é exatamente o acidente que D7 existe para impedir.
     */
    #[TestDox('D7: toda categoria do enum restrito realmente separa tenants no disco')]
    public function testCategoriaRestritaRealmenteIsolaNoDisco(): void
    {
        $resolvedor = $this->resolvedor();

        foreach (CategoriaComIsolamentoFisico::cases() as $restrita) {
            $categoria = $restrita->paraCategoria();

            self::assertNotSame(
                $resolvedor->caminhoDe($this->chave(EscopoDeArquivo::deTenant(1), $categoria)),
                $resolvedor->caminhoDe($this->chave(EscopoDeArquivo::deTenant(2), $categoria)),
                sprintf(
                    '%s está no enum restrito mas resolve para o MESMO diretório em dois '
                    . 'tenants — apagar por prefixo ali apagaria o acervo de outro escritório.',
                    $categoria->value,
                ),
            );
        }
    }

    /** @return iterable<string, array{CategoriaDeArquivo}> */
    public static function categoriasPlanas(): iterable
    {
        foreach (CategoriaDeArquivo::cases() as $categoria) {
            if (CategoriaComIsolamentoFisico::deCategoriaOuNull($categoria) !== null) {
                continue;
            }
            if ($categoria === CategoriaDeArquivo::TAREFA_ANEXO) {
                continue; // não resolvível até a E2.7 (D5)
            }

            yield $categoria->value => [$categoria];
        }
    }

    #[DataProvider('categoriasPlanas')]
    #[TestDox('D7: categoria plana compartilha diretório entre tenants — por isso está fora do prefixo')]
    public function testCategoriaPlanaCompartilhaDiretorio(CategoriaDeArquivo $categoria): void
    {
        $resolvedor = $this->resolvedor();

        self::assertSame(
            $resolvedor->caminhoDe($this->chave(EscopoDeArquivo::deTenant(1), $categoria)),
            $resolvedor->caminhoDe($this->chave(EscopoDeArquivo::deTenant(2), $categoria)),
            'se esta categoria passou a isolar por tenant, mova-a para o enum restrito',
        );
    }

    // =================================================================== D9

    /**
     * Ownership como TIPO, não como flag. O que se prova: a classe emprestada não tem sequer o
     * método que apagaria.
     */
    #[TestDox('D9: ArquivoEmprestado não tem liberar() nem destrutor')]
    public function testEmprestadoNaoSabeApagar(): void
    {
        $classe = new \ReflectionClass(ArquivoEmprestado::class);

        self::assertFalse($classe->hasMethod('liberar'), 'emprestado não libera: o arquivo não é dele');
        self::assertFalse(
            $classe->hasMethod('__destruct'),
            'um destrutor aqui apagaria o arquivo de PRODUÇÃO — é o INV-9',
        );
    }

    #[TestDox('D9: ArquivoTemporarioPossuido tem ciclo de vida explícito')]
    public function testPossuidoTemCicloDeVida(): void
    {
        $classe = new \ReflectionClass(ArquivoTemporarioPossuido::class);

        self::assertTrue($classe->hasMethod('liberar'));
        self::assertTrue($classe->hasMethod('__destruct'));
    }

    /**
     * O construtor público é a porta pela qual alguém "possuiria" um arquivo de produção. Ela não
     * existe: só se possui o que se criou.
     */
    #[TestDox('D9: não há construtor público que assuma posse de um caminho arbitrário')]
    public function testPossuidoSoPossuiOQueCriou(): void
    {
        $construtor = (new \ReflectionClass(ArquivoTemporarioPossuido::class))->getConstructor();

        self::assertNotNull($construtor);
        self::assertTrue(
            $construtor->isPrivate(),
            'construtor público deixaria alguém envolver o caminho de um arquivo persistido '
            . 'num objeto cujo destrutor apaga',
        );
    }

    /**
     * Sem supertipo comum, ninguém consegue escrever uma função que aceite os dois e decida em
     * runtime se apaga — que é exatamente a ambiguidade que D9 proíbe. Quem quiser aceitar os
     * dois precisa escrever a união na assinatura, e isso aparece na revisão.
     */
    #[TestDox('D9: os dois tipos de materialização não compartilham interface nem herança')]
    public function testOwnershipNaoEhConfundivel(): void
    {
        $emprestado = new \ReflectionClass(ArquivoEmprestado::class);
        $possuido   = new \ReflectionClass(ArquivoTemporarioPossuido::class);

        self::assertSame([], $emprestado->getInterfaceNames());
        self::assertSame([], $possuido->getInterfaceNames());
        self::assertFalse($emprestado->getParentClass());
        self::assertFalse($possuido->getParentClass());
        self::assertFalse($emprestado->isSubclassOf(ArquivoTemporarioPossuido::class));
        self::assertFalse($possuido->isSubclassOf(ArquivoEmprestado::class));
    }

    // ====================================================== núcleo sem framework

    /**
     * INV-8, mais AWS e Cloudflare. O núcleo tem de continuar podendo ser usado por comando de
     * CLI e por teste unitário sem kernel — e tem de continuar ignorando qual é o backend remoto.
     */
    #[TestDox('o núcleo não importa Symfony, AWS, Cloudflare nem Google')]
    public function testNucleoNaoDependeDeFramework(): void
    {
        $proibidos = ['Symfony\\', 'Aws\\', 'Cloudflare', 'Google\\', 'GuzzleHttp\\', 'Doctrine\\'];
        $violacoes = [];

        foreach ($this->arquivosDoNucleo() as $arquivo) {
            // Sem comentários: os docblocks CITAM Symfony e BinaryFileResponse de propósito, para
            // explicar o que o núcleo evita. O guarda olha código.
            $codigo = $this->semComentarios((string) file_get_contents($arquivo));

            foreach ($proibidos as $proibido) {
                // Duas formas, porque `use` sozinho deixa passar o FQCN inline
                // (`new \Symfony\...`, type-hint qualificado, `\Doctrine\...::class`) — que é
                // exatamente o atalho que alguém tomaria na E2.4 ao precisar de UploadedFile.
                $padrao = '/(?:^use\s+|\\\\)' . preg_quote($proibido, '/') . '/m';

                if (preg_match($padrao, $codigo) === 1) {
                    $violacoes[] = basename($arquivo) . ' referencia ' . $proibido;
                }
            }
        }

        self::assertSame([], $violacoes, implode("\n", $violacoes));
    }

    #[TestDox('o núcleo não menciona bucket, S3, R2 nem endpoint remoto')]
    public function testNucleoNaoConheceOBackendRemoto(): void
    {
        $violacoes = [];

        foreach ($this->arquivosDoNucleo() as $arquivo) {
            $codigo = $this->semComentarios((string) file_get_contents($arquivo));

            if (preg_match('/\b(bucket|SigV4|presigned|accessKey|secretKey)\b/i', $codigo) === 1) {
                $violacoes[] = basename($arquivo);
            }
        }

        self::assertSame(
            [],
            $violacoes,
            "Detalhe de backend remoto vazou para o núcleo: " . implode(', ', $violacoes),
        );
    }

    /**
     * Guarda contra God Interface. O número não é arbitrário: são os seis verbos que um backend
     * remoto responde de forma diferente. Crescer aqui exige justificar na spec.
     */
    #[TestDox('o núcleo continua com exatamente seis métodos')]
    public function testNucleoNaoIncha(): void
    {
        $metodos = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(ArmazenamentoDeArquivos::class))->getMethods(),
        );
        sort($metodos);

        self::assertSame(
            ['abrir', 'excluir', 'existe', 'gravar', 'ler', 'metadados'],
            $metodos,
            'método novo no núcleo precisa passar pela spec — ou pertence a uma interface segregada',
        );
    }

    #[TestDox('o núcleo não decide HTTP: nada de Response, Content-Disposition ou inline')]
    public function testNucleoNaoDecideHttp(): void
    {
        $violacoes = [];

        foreach ($this->arquivosDoNucleo() as $arquivo) {
            $codigo = $this->semComentarios((string) file_get_contents($arquivo));

            if (preg_match('/\b(Response|Content-Disposition|BinaryFile|inline)\b/', $codigo) === 1) {
                $violacoes[] = basename($arquivo);
            }
        }

        self::assertSame([], $violacoes, 'HTTP é da camada de cima: ' . implode(', ', $violacoes));
    }

    // ------------------------------------------------------------- apoio

    /**
     * Mesmo nome de arquivo em escopos diferentes — é a comparação que revela se a categoria
     * separa tenants no disco. Passou a ser por `caminhoDe()` porque `diretorioDe()` virou
     * privado: método público que devolve diretório compartilhado a partir de categoria plana
     * era, ele mesmo, a porta que D7 fecha.
     */
    private function chave(EscopoDeArquivo $escopo, CategoriaDeArquivo $categoria): ChaveDeArquivo
    {
        return new ChaveDeArquivo($escopo, $categoria, 'mesmo-nome.pdf');
    }

    private function resolvedor(): ResolvedorDeCaminhoLocal
    {
        return new ResolvedorDeCaminhoLocal(
            uploadsDir: '/raiz/pastas',
            clientesUploadsDir: '/raiz/clientes',
            chamadosUploadsDir: '/raiz/chamados',
            justificativasUploadsDir: '/raiz/justificativas',
            fotosPerfilDir: '/raiz/perfil',
            cobrancasUploadsDir: '/raiz/cobrancas',
            kanbanUploadsDir: '/raiz/kanban',
        );
    }

    /** @return list<string> */
    private function arquivosDoNucleo(): array
    {
        $arquivos = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->diretorioDoNucleo(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterador as $arquivo) {
            if ($arquivo instanceof \SplFileInfo && $arquivo->getExtension() === 'php') {
                $arquivos[] = $arquivo->getPathname();
            }
        }

        self::assertNotSame([], $arquivos, 'o núcleo não pode estar vazio — caminho errado?');

        return $arquivos;
    }

    /** Comentários explicam o porquê e citam o que é proibido; o guarda olha só o código. */
    private function semComentarios(string $php): string
    {
        $codigo = '';

        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $codigo .= is_array($token) ? $token[1] : $token;
        }

        return $codigo;
    }
}
