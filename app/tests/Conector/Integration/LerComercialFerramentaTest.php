<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\LerComercialFerramenta;
use App\Conector\Ferramenta\TabelaDeAbaEChave;
use App\Conector\Service\HtmlParaTextoDoConector;
use App\Entity\Auth\User;
use App\Tests\Comercial\Support\CriaCampanhas;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Conector\Support\DublesDoMcpTrait;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ler_comercial` com o banco (spec mcp-remoto-oauth 6.9 e 12.30), chamada como o despacho chama. Só o Master passa (a
 * segunda porta, depois do handler que esconde a ferramenta); cada ação devolve só números, texto e e-mail mascarado;
 * nenhuma resposta traz e-mail sem máscara nem nome de destinatário; leitura não grava nada.
 */
#[CoversClass(LerComercialFerramenta::class)]
#[CoversClass(HtmlParaTextoDoConector::class)]
final class LerComercialFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;
    use CriaCampanhas;
    use DublesDoMcpTrait;

    /** Qualquer e-mail inteiro (a máscara deixa `*` antes do `@`, que não casa). */
    private const EMAIL_SEM_MASCARA = '/[\w.+-]+@[\w-]+\.[\w.-]+/u';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /** @return array<string, array{string}> */
    public static function acoes(): array
    {
        return array_combine(LerComercialFerramenta::ACOES, array_map(static fn (string $a): array => [$a], LerComercialFerramenta::ACOES));
    }

    #[DataProvider('acoes')]
    #[TestDox('segunda porta: quem não é Master é recusado em toda ação, com a mensagem neutra e o motivo so_do_master, antes de ler qualquer coisa')]
    public function testNaoMasterRecusado(string $acao): void
    {
        $pessoa = $this->pessoa(['ROLE_USER', 'ROLE_ADMIN']);
        $antes = $this->contagemDeGravacao();

        try {
            $this->executar($pessoa, ['acao' => $acao, 'campanha_id' => 1, 'publico' => 'leads', 'modelo' => 'convite']);
            self::fail('devia recusar');
        } catch (ErroDaFerramenta $erro) {
            self::assertSame(LerComercialFerramenta::MOTIVO_SO_DO_MASTER, $erro->motivo);
            self::assertSame('Ferramenta não disponível.', $erro->getMessage());
        }
        self::assertSame($antes, $this->contagemDeGravacao());
    }

    #[TestDox('a tabela de aba e chave tem uma linha por ação, sem módulo nem chave')]
    public function testTabela(): void
    {
        self::assertSame(LerComercialFerramenta::ACOES, TabelaDeAbaEChave::acoes(LerComercialFerramenta::NOME));
        foreach (LerComercialFerramenta::ACOES as $acao) {
            $linha = TabelaDeAbaEChave::linha(LerComercialFerramenta::NOME, $acao);
            self::assertNotNull($linha, $acao);
            self::assertSame([], $linha->modulos);
            self::assertSame([], $linha->chaves);
        }
    }

    #[TestDox('entrada: sem ação, ação fora da lista ou de outro tipo é entrada inválida')]
    public function testEntradaInvalida(): void
    {
        foreach ([[], ['acao' => 'excluir'], ['acao' => 3], ['acao' => ['resumo']]] as $argumentos) {
            try {
                $this->executar($this->master(), $argumentos);
                self::fail('devia recusar');
            } catch (ErroDaFerramenta $erro) {
                self::assertSame(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $erro->motivo);
            }
        }
    }

    #[TestDox('resumo: só números e booleanos, com o envio ligado e a cota; sem e-mail')]
    public function testResumo(): void
    {
        $this->campanha(['a@exemplo.com'], ['estado' => 'enviada']);
        $dados = $this->executar($this->master(), ['acao' => 'resumo'])->dados;

        foreach (['campanhas', 'na_fila', 'base', 'descadastrados', 'testes'] as $campo) {
            self::assertIsInt($dados[$campo], $campo);
        }
        self::assertGreaterThanOrEqual(1, $dados['campanhas']);
        self::assertIsBool($dados['envio_de_campanhas']['ligado']);
        self::assertContains($dados['envio_de_campanhas']['origem'], ['painel', 'ambiente']);
        self::assertIsInt($dados['envio_de_campanhas']['esperando_o_envio']);
        self::assertSame(['enviados_24h', 'teto', 'aviso_a_partir_de_percentual', 'perto_do_teto', 'campanhas_pausadas', 'libera_em'], array_keys($dados['cota_de_envio']));
        self::assertIsBool($dados['cota_de_envio']['campanhas_pausadas']);
        self::assertSemEmail($dados);
    }

    #[TestDox('modelos: nome, chave, id, modo e se é próprio; fixos e próprios; nunca o HTML nem o corpo')]
    public function testModelos(): void
    {
        $this->conexao()->executeStatement(
            "INSERT INTO comercial_modelo (nome, icone, modo, assunto, selo, titulo, conteudo, criado_em, alterado_em)
             VALUES ('Meu modelo HTML', 'bi-star', 'html', 'Assunto do meu', 'selo', 'Título', '<p>CORPO-SECRETO-DO-HTML</p>', NOW(), NOW())",
        );
        $dados = $this->executar($this->master(), ['acao' => 'modelos']);
        $modelos = $dados->dados['modelos'];

        self::assertSame(\count($modelos), $dados->dados['total']);
        $porChave = array_column($modelos, null, 'chave');
        self::assertFalse($porChave['convite']['proprio']);
        self::assertNull($porChave['convite']['id']);
        $proprios = array_values(array_filter($modelos, static fn (array $m): bool => $m['proprio']));
        self::assertNotSame([], $proprios);
        self::assertContains('Meu modelo HTML', array_column($proprios, 'nome'));
        foreach ($modelos as $m) {
            self::assertSame(['chave', 'id', 'nome', 'modo', 'assunto', 'proprio'], array_keys($m));
        }
        self::assertStringNotContainsString('CORPO-SECRETO-DO-HTML', (string) json_encode($dados->dados));
        self::assertStringNotContainsString('<p', (string) json_encode($dados->dados));
    }

    #[TestDox('automações: estado das automações e das datas; o último disparo sai com o e-mail mascarado, domínio também')]
    public function testAutomacoes(): void
    {
        $this->conexao()->executeStatement(
            "INSERT INTO comercial_envio (token, destinatario, assunto, tipo, lista, origem, situacao, criado_em, aberturas, cliques)
             VALUES (?, 'maria.silva@escritoriosilva.adv.br', 'Bem-vinda', 'automacao', 'automacoes', 'automacao:cad', 'enviado', NOW(), 0, 0)",
            [bin2hex(random_bytes(16))],
        );
        $dados = $this->executar($this->master(), ['acao' => 'automacoes'])->dados;

        self::assertNotSame([], $dados['automacoes']);
        self::assertSame(['chave', 'titulo', 'descricao', 'ligada', 'atraso', 'ligada_em'], array_keys($dados['automacoes'][0]));
        self::assertNotSame([], $dados['datas_comemorativas']);
        self::assertIsInt($dados['datas_ligadas']);
        $destinatarios = array_column($dados['ultimos_disparos'], 'destinatario');
        self::assertSame([], array_column($dados['ultimos_disparos'], 'assunto'), 'o assunto do envio não sai: pode levar o nome de quem recebeu (6.9)');
        self::assertContains('m***@e***.adv.br', $destinatarios);
        $json = (string) json_encode($dados, \JSON_UNESCAPED_UNICODE);
        foreach (['maria.silva', 'escritoriosilva', 'gmail.com'] as $proibido) {
            self::assertStringNotContainsString($proibido, $json);
        }
        self::assertSemEmail($dados);
    }

    #[TestDox('campanhas: estado, totais e métricas por aba, com a próxima página; nenhum e-mail dos destinatários')]
    public function testCampanhas(): void
    {
        $id = $this->campanha(['lido.secreto@exemplo.com'], ['estado' => 'enviada', 'total' => 3]);
        $this->destino($id, 'lido.secreto@exemplo.com', 'enviado', aberto: true);
        $this->destino($id, 'rejeitado.secreto@exemplo.com', 'falhou');

        $resposta = $this->executar($this->master(), ['acao' => 'campanhas']);
        $dados = $resposta->dados;

        self::assertSame('env', $dados['aba']);
        self::assertArrayHasKey('env', $dados['campanhas_por_aba']);
        $linha = array_column($dados['campanhas'], null, 'id')[$id];
        self::assertSame('enviada', $linha['estado']);
        self::assertSame(3, $linha['total']);
        self::assertSame(['destinatarios', 'na_fila', 'enviados', 'entregues', 'lidos', 'nao_lidos', 'rejeitados', 'clicaram', 'taxa_de_leitura', 'rastreia_leitura'], array_keys($linha['metricas']));
        self::assertSame(1, $linha['metricas']['rejeitados']);
        self::assertSame(['campanha' => array_column($dados['campanhas'], 'id')], $resposta->ids);
        $json = (string) json_encode($dados, \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('secreto', $json);
        self::assertSemEmail($dados);

        $vazia = $this->executar($this->master(), ['acao' => 'campanhas', 'aba' => 'arq', 'busca' => 'nada-casa-com-isto'])->dados;
        self::assertSame([], $vazia['campanhas']);
    }

    #[TestDox('campanhas: aba, busca e página fora do esperado são filtro inválido; a página seguinte vem em proxima_pagina')]
    public function testCampanhasFiltros(): void
    {
        foreach ([['aba' => 'tudo'], ['aba' => 3], ['busca' => ['x']], ['pagina' => 0], ['pagina' => '2']] as $filtro) {
            try {
                $this->executar($this->master(), ['acao' => 'campanhas'] + $filtro);
                self::fail('devia recusar ' . json_encode($filtro));
            } catch (ErroDaFerramenta $erro) {
                self::assertSame(ErroDaFerramenta::MOTIVO_FILTRO_INVALIDO, $erro->motivo);
            }
        }
        for ($i = 0; $i < 51; ++$i) {
            $this->campanha(['a@exemplo.com'], ['estado' => 'enviada']);
        }
        $primeira = $this->executar($this->master(), ['acao' => 'campanhas']);
        self::assertSame(2, $primeira->proximaPagina);
        self::assertCount(50, $primeira->dados['campanhas']);
        self::assertNull($this->executar($this->master(), ['acao' => 'campanhas', 'pagina' => 2])->proximaPagina);
    }

    #[TestDox('campanha: indicadores, estado e contagem por situação; no máximo 10 rejeitados, mascarados; nunca a lista, os nomes nem o HTML')]
    public function testCampanha(): void
    {
        $id = $this->campanha(['lido.secreto@exemplo.com'], ['estado' => 'enviada', 'total' => 20]);
        $this->destino($id, 'lido.secreto@exemplo.com', 'enviado', aberto: true);
        $this->destino($id, 'entregue.secreto@exemplo.com', 'enviado');
        $this->destino($id, 'fila.secreto@exemplo.com', null);
        for ($i = 1; $i <= 12; ++$i) {
            $this->destino($id, 'rejeitado' . $i . '.secreto@dominio' . $i . '.com.br', 'falhou');
        }
        $resposta = $this->executar($this->master(), ['acao' => 'campanha', 'campanha_id' => $id]);
        $dados = $resposta->dados;

        self::assertSame($id, $dados['id']);
        self::assertSame('enviada', $dados['estado']);
        self::assertSame(4, \count($dados['kpis']));
        self::assertSame(['campanha' => [$id]], $resposta->ids);
        self::assertSame(12, $dados['destinatarios_por_situacao']['rejeitado']);
        self::assertSame(1, $dados['destinatarios_por_situacao']['lido']);
        self::assertSame(15, array_sum($dados['destinatarios_por_situacao']));
        self::assertCount(LerComercialFerramenta::MAXIMO_DE_REJEITADOS, $dados['rejeitados_mascarados']);
        foreach ($dados['rejeitados_mascarados'] as $mascarado) {
            self::assertMatchesRegularExpression('/^r\*\*\*@d\*\*\*\.com\.br$/', $mascarado);
        }
        $json = (string) json_encode($dados, \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('secreto', $json);
        self::assertStringNotContainsString('Nome ', $json, 'nenhum nome de destinatário');
        self::assertArrayNotHasKey('html', $dados);
        self::assertArrayNotHasKey('destinatarios', $dados);
        self::assertStringNotContainsString('<', $json);
        self::assertSemEmail($dados);
    }

    #[TestDox('campanha: id que não existe é não encontrado; id fora do formato é filtro inválido')]
    public function testCampanhaInexistente(): void
    {
        try {
            $this->executar($this->master(), ['acao' => 'campanha', 'campanha_id' => 2_000_000_000]);
            self::fail('devia recusar');
        } catch (ErroDaFerramenta $erro) {
            self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo);
        }
        foreach ([[], ['campanha_id' => 0], ['campanha_id' => '5'], ['campanha_id' => 1.5]] as $extra) {
            try {
                $this->executar($this->master(), ['acao' => 'campanha'] + $extra);
                self::fail('devia recusar');
            } catch (ErroDaFerramenta $erro) {
                self::assertSame(ErroDaFerramenta::MOTIVO_FILTRO_INVALIDO, $erro->motivo);
            }
        }
    }

    #[TestDox('contar_publico: contagens e o exemplo só com o e-mail mascarado, sem nome; csv e indiv são recusados')]
    public function testContarPublico(): void
    {
        $primario = $this->usuario('pub', nome: 'Fulana Secretíssima');
        $this->vincular($primario, true);

        $dados = $this->executar($this->master(), ['acao' => 'contar_publico', 'publico' => 'prim'])->dados;

        self::assertGreaterThanOrEqual(1, $dados['destinatarios']);
        self::assertSame(['leads', 'prim', 'sec', 'todos'], array_keys($dados['tamanho_dos_publicos']));
        self::assertIsInt($dados['descadastrados']);
        self::assertIsInt($dados['sem_autorizacao']);
        self::assertIsString($dados['exemplo_mascarado']);
        self::assertMatchesRegularExpression('/^.\*\*\*@.\*\*\*\..+$/u', $dados['exemplo_mascarado']);
        $json = (string) json_encode($dados, \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('Secretíssima', $json);
        self::assertStringNotContainsString((string) $primario->getEmail(), $json);
        self::assertArrayNotHasKey('exemplo', $dados);
        self::assertSemEmail($dados);

        foreach (['csv', 'indiv', 'tudo', 3, null] as $invalido) {
            try {
                $this->executar($this->master(), ['acao' => 'contar_publico', 'publico' => $invalido]);
                self::fail('devia recusar');
            } catch (ErroDaFerramenta $erro) {
                self::assertSame(ErroDaFerramenta::MOTIVO_FILTRO_INVALIDO, $erro->motivo);
            }
        }
    }

    #[TestDox('prévia: assunto e corpo do modelo salvo em texto, sem marcação; id, chave do próprio e chave de fixo valem')]
    public function testPrevia(): void
    {
        $this->conexao()->executeStatement(
            "INSERT INTO comercial_modelo (nome, icone, modo, assunto, selo, titulo, conteudo, criado_em, alterado_em)
             VALUES ('Modelo da prévia', 'bi-star', 'html', 'Assunto do html', 'selo', 'Título', '<h1>Olá</h1><p>Primeira <b>linha</b> &amp; fim</p><script>alert(1)</script><a href=\"https://exemplo.com/x\">Abrir</a>', NOW(), NOW())",
        );
        $id = (int) $this->conexao()->fetchOne("SELECT id FROM comercial_modelo WHERE nome = 'Modelo da prévia' ORDER BY id DESC LIMIT 1");

        $fixo = $this->executar($this->master(), ['acao' => 'previa', 'modelo' => 'convite'])->dados;
        self::assertNotSame('', $fixo['assunto']);
        self::assertNotSame('', $fixo['corpo_em_texto']);
        self::assertStringNotContainsString('<', $fixo['corpo_em_texto']);
        self::assertFalse($fixo['cortado']);
        self::assertSame('convite', $fixo['modelo']['chave']);
        // LGPD (6.9): o rodapé leva o link assinado do descadastro e o e-mail de exemplo; nenhum dos dois sai.
        self::assertStringNotContainsString('http', $fixo['corpo_em_texto'], 'nenhum endereço de link, nem o do descadastro');
        self::assertDoesNotMatchRegularExpression('/[^\s*@]+@[^\s*@]+\.[a-z]/i', str_replace('***', '', $fixo['corpo_em_texto']), 'nenhum e-mail sem máscara');

        foreach ([(string) $id, 'cu' . $id] as $referencia) {
            $proprio = $this->executar($this->master(), ['acao' => 'previa', 'modelo' => $referencia])->dados;
            self::assertSame('Assunto do html', $proprio['assunto']);
            self::assertSame('html', $proprio['modelo']['modo']);
            self::assertStringContainsString('Primeira linha & fim', $proprio['corpo_em_texto']);
            self::assertStringContainsString('Abrir', $proprio['corpo_em_texto']);
            self::assertStringNotContainsString('https://exemplo.com/x', $proprio['corpo_em_texto']);
            self::assertStringNotContainsString('alert(1)', $proprio['corpo_em_texto']);
            self::assertStringNotContainsString('<', $proprio['corpo_em_texto']);
        }
    }

    #[TestDox('prévia: modelo que não existe é não encontrado; texto fora do formato de id ou chave é filtro inválido; a mensagem nunca vem do pedido')]
    public function testPreviaRecusas(): void
    {
        foreach (['nao-existe', '2000000000', 'cu2000000000'] as $modelo) {
            try {
                $this->executar($this->master(), ['acao' => 'previa', 'modelo' => $modelo]);
                self::fail('devia recusar');
            } catch (ErroDaFerramenta $erro) {
                self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo, $modelo);
            }
        }
        foreach ([[], ['modelo' => ''], ['modelo' => 7], ['modelo' => '<b>x</b>'], ['modelo' => 'Convite Maiúsculo']] as $extra) {
            try {
                $this->executar($this->master(), ['acao' => 'previa'] + $extra);
                self::fail('devia recusar');
            } catch (ErroDaFerramenta $erro) {
                self::assertSame(ErroDaFerramenta::MOTIVO_FILTRO_INVALIDO, $erro->motivo);
            }
        }
    }

    #[TestDox('a prévia em texto fica dentro do teto: acima de 30 mil caracteres, é cortada e avisa')]
    public function testPreviaCortada(): void
    {
        $this->conexao()->executeStatement(
            "INSERT INTO comercial_modelo (nome, icone, modo, assunto, selo, titulo, conteudo, criado_em, alterado_em)
             VALUES ('Modelo enorme', 'bi-star', 'html', 'Grande', 'selo', 'Título', ?, NOW(), NOW())",
            ['<p>' . str_repeat('palavra ', 6000) . '</p>'],
        );
        $id = (int) $this->conexao()->fetchOne("SELECT id FROM comercial_modelo WHERE nome = 'Modelo enorme' ORDER BY id DESC LIMIT 1");

        $dados = $this->executar($this->master(), ['acao' => 'previa', 'modelo' => (string) $id])->dados;

        self::assertTrue($dados['cortado']);
        self::assertLessThanOrEqual(LerComercialFerramenta::MAXIMO_DA_PREVIA + mb_strlen(LerComercialFerramenta::CORTADO), mb_strlen($dados['corpo_em_texto']));
        self::assertStringEndsWith(LerComercialFerramenta::CORTADO, $dados['corpo_em_texto']);
    }

    #[TestDox('leitura não grava: nenhuma ação muda campanha, modelo, automação nem audit_log')]
    public function testNaoGrava(): void
    {
        $this->campanha(['a@exemplo.com'], ['estado' => 'enviada']);
        $id = (int) $this->conexao()->fetchOne('SELECT MAX(id) FROM comercial_campanha');
        $antes = $this->contagemDeGravacao();

        foreach ([['acao' => 'resumo'], ['acao' => 'modelos'], ['acao' => 'automacoes'], ['acao' => 'campanhas'], ['acao' => 'campanha', 'campanha_id' => $id], ['acao' => 'contar_publico', 'publico' => 'todos'], ['acao' => 'previa', 'modelo' => 'convite']] as $argumentos) {
            $this->executar($this->master(), $argumentos);
        }

        self::assertSame($antes, $this->contagemDeGravacao());
    }

    #[TestDox('o conversor de HTML para texto: tira cabeça, estilo e script, quebra nos blocos, mantém o endereço do link e decodifica as entidades')]
    public function testConversor(): void
    {
        $texto = HtmlParaTextoDoConector::converter('<html><head><title>T</title><style>p{color:red}</style></head><body><!-- x --><p>Olá&nbsp;mundo</p><p>Dois &lt;3</p><ul><li>a</li><li>b</li></ul><a href="https://x.com/a">link</a></body></html>');

        self::assertSame("Olá mundo\nDois <3\na\nb\n\nlink", $texto, 'o link sai só com o texto, sem o endereço');
    }

    /** @return array<string, int> o que uma leitura não pode mexer */
    private function contagemDeGravacao(): array
    {
        $contagens = [];
        foreach (['comercial_campanha', 'comercial_modelo', 'comercial_automacao', 'comercial_envio'] as $tabela) {
            $contagens[$tabela] = (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM ' . $tabela);
        }
        // O audit_log só do Comercial e do conector: o cenário do próprio teste grava pessoa e escritório.
        $contagens['audit_log'] = (int) $this->conexao()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE entity_class LIKE '%Comercial%' OR route = 'conector_mcp' OR action LIKE 'camp\_%' OR action LIKE 'modelo\_%'",
        );

        return $contagens;
    }

    /** @param array<string, mixed> $dados */
    private static function assertSemEmail(array $dados): void
    {
        $json = (string) json_encode($dados, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        self::assertDoesNotMatchRegularExpression(self::EMAIL_SEM_MASCARA, $json, 'a resposta traz e-mail sem máscara');
    }

    private function master(): User
    {
        return $this->pessoa(['ROLE_USER', 'ROLE_SUPER_ADMIN']);
    }

    /** @param array<string, mixed> $argumentos */
    private function executar(User $pessoa, array $argumentos): RespostaDaFerramenta
    {
        $escritorio = $this->escritorio();
        $vinculo = $this->vinculo($pessoa, $escritorio);

        return static::getContainer()->get(LerComercialFerramenta::class)
            ->executar($argumentos, self::chamada(['mcp:leitura'], $pessoa, $escritorio, $vinculo));
    }

    private function destino(int $campanhaId, string $email, ?string $situacao, bool $aberto = false): void
    {
        $envioId = null;
        if ($situacao !== null) {
            $envioId = (int) $this->conexao()->fetchOne(
                "INSERT INTO comercial_envio (token, destinatario, assunto, tipo, lista, origem, situacao, erro, criado_em, enviado_em, primeira_abertura_em, aberturas, cliques)
                 VALUES (:token, :para, 'Assunto', 'lista', 'comercial_campanhas', :origem, :situacao, NULL, NOW(), NOW(), :aberto, :aberturas, 0) RETURNING id",
                ['token' => bin2hex(random_bytes(16)), 'para' => $email, 'origem' => 'campanha:' . $campanhaId, 'situacao' => $situacao,
                    'aberto' => $aberto ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null, 'aberturas' => $aberto ? 1 : 0],
            );
        }
        $this->conexao()->executeStatement(
            'INSERT INTO comercial_campanha_destino (campanha_id, execucao, email, envio_id, criado_em) VALUES (?, 1, ?, ?, NOW())',
            [$campanhaId, $email, $envioId],
        );
        $this->conexao()->executeStatement('UPDATE comercial_campanha SET execucoes = 1 WHERE id = ?', [$campanhaId]);
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
