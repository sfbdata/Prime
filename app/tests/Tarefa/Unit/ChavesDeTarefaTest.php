<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Tarefa\Armazenamento\ChavesDeTarefa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A coluna `tarefa_mensagem.arquivo_anexo` guarda o caminho público `/uploads/tarefas/chat/<nome>`,
 * com barras — e `ChaveDeArquivo` recusa barra (D5 da E2). A fábrica traduz o valor numa chave só
 * com o `<nome>`, sem alterar o dado e sem relaxar a chave (E2.7, spec P2 §10.1).
 *
 * É aqui que mora a guarda contra travessia que antes era do `CaminhoDeAnexoDeTarefa`: o ataque é ao
 * VALOR, e um teste pela rota provaria outra barreira (o roteador normaliza `../` antes do controller).
 */
#[CoversClass(ChavesDeTarefa::class)]
final class ChavesDeTarefaTest extends TestCase
{
    #[TestDox('anexoDeMensagem(): categoria TAREFA_ANEXO, escopo do tenant da mensagem e nome sem o prefixo')]
    public function testAnexoDeMensagemTraduzOValorDaColuna(): void
    {
        $chave = ChavesDeTarefa::anexoDeMensagem(
            $this->mensagem($this->tenant(7), '/uploads/tarefas/chat/arquivo_6abab5669fac90.42347212.pdf'),
        );

        self::assertSame(CategoriaDeArquivo::TAREFA_ANEXO, $chave->categoria);
        self::assertSame(7, $chave->escopo->tenantIdOuNull());
        self::assertSame('arquivo_6abab5669fac90.42347212.pdf', $chave->nome);
    }

    #[TestDox('anexoDeMensagem(): nome cunhado pelo storage (hex de 128 bits) também é aceito')]
    public function testNomeCunhadoPeloStorageEhAceito(): void
    {
        $nome = bin2hex(random_bytes(16)) . '.png';

        self::assertSame($nome, ChavesDeTarefa::anexoDeMensagem($this->mensagem($this->tenant(1), '/uploads/tarefas/chat/' . $nome))->nome);
    }

    /** @return iterable<string, array{?string}> */
    public static function valoresRecusados(): iterable
    {
        yield 'nulo'                         => [null];
        yield 'vazio'                        => [''];
        yield 'só o prefixo'                 => ['/uploads/tarefas/chat/'];
        yield 'travessia ../'                => ['/uploads/tarefas/chat/../../../.env'];
        yield 'travessia codificada'         => ['/uploads/tarefas/chat/%2e%2e%2f.env'];
        yield 'subdiretório extra'           => ['/uploads/tarefas/chat/sub/x.pdf'];
        yield 'nome começando por ponto'     => ['/uploads/tarefas/chat/.htaccess'];
        yield 'só ponto ponto'               => ['/uploads/tarefas/chat/..'];
        yield 'ponto ponto no meio'          => ['/uploads/tarefas/chat/a..b.pdf'];
        yield 'byte nulo'                    => ["/uploads/tarefas/chat/x.pdf\0.php"];
        yield 'quebra de linha no fim'       => ["/uploads/tarefas/chat/x.pdf\n"];
        yield 'espaço'                       => ['/uploads/tarefas/chat/x y.pdf'];
        yield 'contrabarra'                  => ['/uploads/tarefas/chat/..\\x.pdf'];
        yield 'subdiretório admin (DP-9)'    => ['/uploads/tarefas/admin/x.pdf'];
        yield 'outro módulo'                 => ['/uploads/pastas/x.pdf'];
        yield 'sem barra inicial'            => ['uploads/tarefas/chat/x.pdf'];
        yield 'caminho absoluto do disco'    => ['/var/www/app/public/uploads/tarefas/chat/x.pdf'];
        yield 'prefixo parecido'             => ['/uploads/tarefas-outro/chat/x.pdf'];
        yield 'URL'                          => ['https://exemplo.com/uploads/tarefas/chat/x.pdf'];
    }

    #[DataProvider('valoresRecusados')]
    #[TestDox('anexoDeMensagem(): valor fora de /uploads/tarefas/chat/<nome> é recusado')]
    public function testValorForaDoFormatoEhRecusado(?string $valor): void
    {
        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDeTarefa::anexoDeMensagem($this->mensagem($this->tenant(7), $valor));
    }

    #[DataProvider('valoresRecusados')]
    #[TestDox('temAnexoEnderecavel(): falso para todo valor que anexoDeMensagem() recusa pelo formato')]
    public function testTemAnexoEnderecavelRecusaOMesmoFormato(?string $valor): void
    {
        self::assertFalse(ChavesDeTarefa::temAnexoEnderecavel($this->mensagem($this->tenant(7), $valor)));
    }

    #[TestDox('temAnexoEnderecavel(): só olha o formato — sem escritório continua verdadeiro, e a chave é que recusa (D10: dado corrompido propaga)')]
    public function testTemAnexoEnderecavelNaoDecideOEscritorio(): void
    {
        $mensagem = $this->mensagem(null, '/uploads/tarefas/chat/x.pdf');

        self::assertTrue(ChavesDeTarefa::temAnexoEnderecavel($mensagem));
        $this->expectException(ChaveDeArquivoInvalida::class);
        ChavesDeTarefa::anexoDeMensagem($mensagem);
    }

    #[TestDox('anexoDeMensagem(): sem tenant na mensagem, recusa — não há fallback para a sessão')]
    public function testMensagemSemTenantLanca(): void
    {
        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDeTarefa::anexoDeMensagem($this->mensagem(null, '/uploads/tarefas/chat/x.pdf'));
    }

    #[TestDox('anexoDeMensagem(): tenant sem id é recusado')]
    public function testTenantSemIdLanca(): void
    {
        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDeTarefa::anexoDeMensagem($this->mensagem(new Tenant(), '/uploads/tarefas/chat/x.pdf'));
    }

    #[TestDox('anexoDeMensagem() recebe só a entidade — o tenant não pode vir de fora (R1)')]
    public function testFabricaNaoAceitaTenantExterno(): void
    {
        $parametros = (new \ReflectionMethod(ChavesDeTarefa::class, 'anexoDeMensagem'))->getParameters();

        self::assertCount(1, $parametros);
        self::assertSame(TarefaMensagem::class, (string) $parametros[0]->getType());
    }

    #[TestDox('novoAnexo(): escopo da TAREFA, categoria TAREFA_ANEXO e a extensão informada')]
    public function testNovoAnexoUsaOEscritorioDaTarefa(): void
    {
        $novo = ChavesDeTarefa::novoAnexo($this->tarefa($this->tenant(9)), 'pdf');

        self::assertSame(CategoriaDeArquivo::TAREFA_ANEXO, $novo->categoria);
        self::assertSame(9, $novo->escopo->tenantIdOuNull());
        self::assertSame('pdf', $novo->extensao);
    }

    #[TestDox('novoAnexo(): tarefa sem tenant é recusada')]
    public function testNovoAnexoSemTenantLanca(): void
    {
        $this->expectException(ChaveDeArquivoInvalida::class);

        ChavesDeTarefa::novoAnexo($this->tarefa(null), 'pdf');
    }

    #[TestDox('valorDaColuna(): devolve o caminho público que a coluna sempre guardou, e a volta reconstrói a mesma chave')]
    public function testValorDaColunaFazIdaEVolta(): void
    {
        $chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::TAREFA_ANEXO, 'abc123.pdf');

        $valor = ChavesDeTarefa::valorDaColuna($chave);

        self::assertSame('/uploads/tarefas/chat/abc123.pdf', $valor);
        $volta = ChavesDeTarefa::anexoDeMensagem($this->mensagem($this->tenant(7), $valor));
        self::assertSame($chave->nome, $volta->nome);
        self::assertSame($chave->categoria, $volta->categoria);
        self::assertSame(7, $volta->escopo->tenantIdOuNull());
    }

    #[TestDox('valorDaColuna(): chave de outra categoria é recusada')]
    public function testValorDaColunaRecusaOutraCategoria(): void
    {
        $this->expectException(\LogicException::class);

        ChavesDeTarefa::valorDaColuna(new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::KANBAN_ANEXO, 'x.pdf'));
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function mensagem(?Tenant $tenant, ?string $valor): TarefaMensagem
    {
        $mensagem = $this->createStub(TarefaMensagem::class);
        $mensagem->method('getTenant')->willReturn($tenant);
        $mensagem->method('getArquivoAnexo')->willReturn($valor);

        return $mensagem;
    }

    private function tarefa(?Tenant $tenant): Tarefa
    {
        $tarefa = $this->createStub(Tarefa::class);
        $tarefa->method('getTenant')->willReturn($tenant);

        return $tarefa;
    }
}
