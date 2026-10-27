<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A prova de INV-1: **nenhum arquivo se move na E2**.
 *
 * Para cada categoria, o caminho que a chave resolve é comparado com o caminho LITERAL que o
 * código de hoje monta. Se alguém mudar o resolvedor, este teste cai — e cair aqui é infinitamente
 * mais barato que descobrir em produção que 22.650 documentos ficaram inalcançáveis.
 *
 * ## Por que os parâmetros são fixados à mão, e não lidos do container
 *
 * Em `APP_ENV=test` os oito parâmetros apontam para `var/uploads-test/*`
 * (`config/services.yaml:161-169`). Um teste que lesse o container provaria concatenação de
 * strings, não o layout de PRODUÇÃO — que é o que INV-1 promete. Por isso aqui os valores de
 * produção entram literais, e um segundo teste confere que o `services.yaml` ainda os declara
 * assim. Os dois juntos fecham o circuito; qualquer um sozinho é ilusão de cobertura.
 */
#[CoversClass(ResolvedorDeCaminhoLocal::class)]
final class MapaDeChaveParaCaminhoLocalTest extends TestCase
{
    private const PROJECT_DIR = '/var/www/app';

    private function resolvedorDeProducao(): ResolvedorDeCaminhoLocal
    {
        $raiz = self::PROJECT_DIR . '/public/uploads';

        return new ResolvedorDeCaminhoLocal(
            uploadsDir: $raiz . '/pastas',
            clientesUploadsDir: $raiz . '/clientes',
            chamadosUploadsDir: $raiz . '/chamados',
            justificativasUploadsDir: $raiz . '/justificativas',
            fotosPerfilDir: $raiz . '/perfil',
            cobrancasUploadsDir: $raiz . '/cobrancas',
            kanbanUploadsDir: $raiz . '/kanban',
            tarefasUploadsDir: $raiz . '/tarefas',
            trabalhoExternoUploadsDir: $raiz . '/trabalho-externo',
            comercialImagensDir: $raiz . '/comercial-imagens',
            documentosJuridicosDir: $raiz . '/documentos-juridicos',
            recompensaComprovantesDir: $raiz . '/recompensa-comprovantes',
        );
    }

    /** @return iterable<string, array{CategoriaDeArquivo, EscopoDeArquivo, string, string}> */
    public static function categoriasResolviveis(): iterable
    {
        $tenant = EscopoDeArquivo::deTenant(7);
        $raiz   = self::PROJECT_DIR . '/public/uploads';

        yield 'pasta_documento (plano)' => [
            CategoriaDeArquivo::PASTA_DOCUMENTO, $tenant, 'a1b2.pdf',
            $raiz . '/pastas/a1b2.pdf',
        ];
        yield 'pasta_foto (plano, subpasta própria)' => [
            CategoriaDeArquivo::PASTA_FOTO, $tenant, 'a1b2.jpg',
            $raiz . '/pastas/fotos/a1b2.jpg',
        ];
        yield 'pasta_imagem_editor (subpasta do tenant)' => [
            CategoriaDeArquivo::PASTA_IMAGEM_EDITOR, $tenant, 'a1b2.png',
            $raiz . '/pastas/7/a1b2.png',
        ];
        yield 'cliente_documento (plano)' => [
            CategoriaDeArquivo::CLIENTE_DOCUMENTO, $tenant, 'a1b2.pdf',
            $raiz . '/clientes/a1b2.pdf',
        ];
        yield 'cliente_foto (plano, subpasta própria)' => [
            CategoriaDeArquivo::CLIENTE_FOTO, $tenant, 'a1b2.jpg',
            $raiz . '/clientes/fotos/a1b2.jpg',
        ];
        yield 'chamado_anexo (plano)' => [
            CategoriaDeArquivo::CHAMADO_ANEXO, $tenant, 'a1b2.pdf',
            $raiz . '/chamados/a1b2.pdf',
        ];
        yield 'justificativa_anexo (plano)' => [
            CategoriaDeArquivo::JUSTIFICATIVA_ANEXO, $tenant, 'a1b2.pdf',
            $raiz . '/justificativas/a1b2.pdf',
        ];
        yield 'foto_perfil (plano, escopo global)' => [
            CategoriaDeArquivo::FOTO_PERFIL, EscopoDeArquivo::global(), 'a1b2.jpg',
            $raiz . '/perfil/a1b2.jpg',
        ];
        yield 'cobranca_documento (subpasta do tenant)' => [
            CategoriaDeArquivo::COBRANCA_DOCUMENTO, $tenant, 'a1b2.pdf',
            $raiz . '/cobrancas/7/a1b2.pdf',
        ];
        yield 'kanban_anexo (plano)' => [
            CategoriaDeArquivo::KANBAN_ANEXO, $tenant, 'a1b2.pdf',
            $raiz . '/kanban/a1b2.pdf',
        ];
        // E2.7: o caminho que `TarefaController::uploadFiles()` montava — `kernel.project_dir` +
        // `/public/uploads/tarefas/chat/` + nome — e que `CaminhoDeAnexoDeTarefa` resolvia.
        yield 'tarefa_anexo (plano, subpasta chat)' => [
            CategoriaDeArquivo::TAREFA_ANEXO, $tenant, 'arquivo_6abab5669fac90.42347212.pdf',
            $raiz . '/tarefas/chat/arquivo_6abab5669fac90.42347212.pdf',
        ];
        yield 'trabalho_externo_documento (plano)' => [
            CategoriaDeArquivo::TRABALHO_EXTERNO_DOCUMENTO, $tenant, 'a1b2.pdf',
            $raiz . '/trabalho-externo/a1b2.pdf',
        ];
        yield 'trabalho_externo_curriculo (plano, escopo global, subpasta própria)' => [
            CategoriaDeArquivo::TRABALHO_EXTERNO_CURRICULO, EscopoDeArquivo::global(), 'a1b2.pdf',
            $raiz . '/trabalho-externo/curriculos/a1b2.pdf',
        ];
        yield 'comercial_imagem (plano, escopo global)' => [
            CategoriaDeArquivo::COMERCIAL_IMAGEM, EscopoDeArquivo::global(), 'a1b2.jpg',
            $raiz . '/comercial-imagens/a1b2.jpg',
        ];
        yield 'inteligencia_documento_juridico (plano, escopo global)' => [
            CategoriaDeArquivo::INTELIGENCIA_DOCUMENTO_JURIDICO, EscopoDeArquivo::global(), 'a1b2.docx',
            $raiz . '/documentos-juridicos/a1b2.docx',
        ];
        yield 'recompensa_comprovante (plano, escopo global)' => [
            CategoriaDeArquivo::RECOMPENSA_COMPROVANTE, EscopoDeArquivo::global(), 'a1b2.pdf',
            $raiz . '/recompensa-comprovantes/a1b2.pdf',
        ];
    }

    #[DataProvider('categoriasResolviveis')]
    #[TestDox('o caminho resolvido é idêntico ao que o código de hoje monta')]
    public function testCaminhoIdenticoAoAtual(
        CategoriaDeArquivo $categoria,
        EscopoDeArquivo $escopo,
        string $nome,
        string $esperado,
    ): void {
        $chave = new ChaveDeArquivo($escopo, $categoria, $nome);

        self::assertSame($esperado, $this->resolvedorDeProducao()->caminhoDe($chave));
    }

    /**
     * O escopo é ignorado nas sete categorias planas — é o risco R1, e está aqui documentado
     * como comportamento esperado para que ninguém o "conserte" sem migrar arquivo junto.
     */
    #[TestDox('nas categorias planas, tenants diferentes resolvem para o MESMO caminho (R1)')]
    public function testEscopoNaoAfetaCategoriaPlana(): void
    {
        $resolvedor = $this->resolvedorDeProducao();

        $doTenant1 = new ChaveDeArquivo(
            EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::CLIENTE_DOCUMENTO, 'x.pdf',
        );
        $doTenant2 = new ChaveDeArquivo(
            EscopoDeArquivo::deTenant(2), CategoriaDeArquivo::CLIENTE_DOCUMENTO, 'x.pdf',
        );

        self::assertSame(
            $resolvedor->caminhoDe($doTenant1),
            $resolvedor->caminhoDe($doTenant2),
            'o disco atual é plano aqui; provar isolamento pelo caminho seria enganar-se (R1)',
        );
    }

    #[TestDox('categoria com isolamento físico e escopo global falha alto, em vez de montar caminho torto')]
    public function testIsolamentoFisicoExigeTenant(): void
    {
        $chave = new ChaveDeArquivo(
            EscopoDeArquivo::global(),
            CategoriaDeArquivo::COBRANCA_DOCUMENTO,
            'x.pdf',
        );

        $this->expectExceptionMessage('escopo informado é global');
        $this->resolvedorDeProducao()->caminhoDe($chave);
    }

    #[TestDox('todas as categorias do enum estão cobertas e todas resolvem')]
    public function testTodasAsCategoriasEstaoCobertas(): void
    {
        $cobertas = [];
        foreach (self::categoriasResolviveis() as [$categoria, , , ]) {
            $cobertas[] = $categoria;
        }

        self::assertEqualsCanonicalizing(
            CategoriaDeArquivo::cases(),
            $cobertas,
            'categoria nova obriga a decidir o caminho dela AQUI, não em produção',
        );
    }

    /**
     * O outro lado do circuito: os valores literais usados acima ainda são os que o
     * `services.yaml` declara para produção. Sem isto, mudar o `services.yaml` deixaria este
     * arquivo verde e errado.
     */
    #[TestDox('os oito parâmetros de produção do services.yaml continuam sendo os assumidos aqui')]
    public function testParametrosDeProducaoNaoMudaram(): void
    {
        $yaml = (string) file_get_contents(\dirname(__DIR__, 3) . '/config/services.yaml');

        $esperados = [
            'uploads_dir'                => '%kernel.project_dir%/public/uploads/pastas',
            'justificativas_uploads_dir' => '%kernel.project_dir%/public/uploads/justificativas',
            'chamados_uploads_dir'       => '%kernel.project_dir%/public/uploads/chamados',
            'clientes_uploads_dir'       => '%kernel.project_dir%/public/uploads/clientes',
            'fotos_perfil_dir'           => '%kernel.project_dir%/public/uploads/perfil',
            'cobrancas_uploads_dir'      => '%kernel.project_dir%/public/uploads/cobrancas',
            'kanban_uploads_dir'         => '%kernel.project_dir%/public/uploads/kanban',
            'tarefas_uploads_dir'        => '%kernel.project_dir%/public/uploads/tarefas',
            'trabalho_externo_uploads_dir' => '%kernel.project_dir%/public/uploads/trabalho-externo',
            'comercial_imagens_dir'      => '%kernel.project_dir%/public/uploads/comercial-imagens',
            'documentos_juridicos_dir'   => '%kernel.project_dir%/public/uploads/documentos-juridicos',
            'recompensa_comprovantes_dir' => '%kernel.project_dir%/public/uploads/recompensa-comprovantes',
        ];

        foreach ($esperados as $parametro => $valor) {
            self::assertStringContainsString(
                sprintf("    %s: '%s'", $parametro, $valor),
                $yaml,
                sprintf(
                    'O parâmetro %s mudou no services.yaml. Se o diretório de produção mudou, '
                    . 'ARQUIVOS PRECISAM SER MOVIDOS — INV-1 não é mais verdade.',
                    $parametro,
                ),
            );
        }
    }
}
