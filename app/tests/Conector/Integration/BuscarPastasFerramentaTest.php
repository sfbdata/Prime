<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\BuscarPastasFerramenta;
use App\Conector\Ferramenta\DespachoDasFerramentas;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Factory\Cliente\ClientePFFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `buscar_pastas` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.4 e decisões 1 a 3 do PR 6): a Lista do
 * Expediente para a pessoa e o escritório da chamada, com a pasta trancada só com número e cliente, a sem acesso fora e
 * contada, a em segredo fora e contada, o filtro inválido pelo nome do campo e a busca por número presa ao escopo.
 */
#[CoversClass(BuscarPastasFerramenta::class)]
final class BuscarPastasFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;
    use Factories;

    private const CPF = '529.982.247-25';
    private const LEITOR = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view'];

    /** Quem lê a lixeira do escritório precisa também editar a pasta (a mesma regra das rotas da lixeira). */
    private const LEITOR_DA_LIXEIRA = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private int $numero = 0;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('buscar: a pasta comum sai inteira; a trancada só com número, cliente e trancada, e conta no total; a em segredo sai e é contada; a de outro escritório não aparece')]
    public function testBuscarSegueALista(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: self::LEITOR);
        $comum = $this->pasta($escritorio);
        $comum->setNomeAcao('Revisão do CPF ' . self::CPF)->setResponsavel($pessoa);
        $trancada = $this->pasta($escritorio);
        $this->em()->persist(new CadeadoDaPasta($escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->pasta($escritorio, nivelSigilo: 1);
        $deOutro = $this->pasta($this->escritorio('Outro'));
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'buscar'], $vinculo);

        $porId = array_column($resposta->dados['pastas'], null, 'id');
        self::assertEqualsCanonicalizing([$comum->getId(), $trancada->getId()], array_keys($porId));
        self::assertSame(3, $resposta->dados['total'], 'a trancada e a em segredo contam no total, como na Lista');
        self::assertSame(1, $resposta->dados['omitidas_em_segredo']);
        self::assertSame(0, $resposta->dados['omitidas_sem_acesso']);
        self::assertNull($resposta->proximaPagina);
        self::assertSame(['id', 'numero', 'identificador', 'clientes', 'trancada'], array_keys($porId[$trancada->getId()]));
        self::assertTrue($porId[$trancada->getId()]['trancada']);

        $linha = $porId[$comum->getId()];
        self::assertFalse($linha['trancada']);
        self::assertFalse($linha['na_lixeira']);
        self::assertSame($pessoa->getId(), $linha['responsavel']['id']);
        self::assertStringContainsString('[CPF]', (string) $linha['acao']);
        self::assertStringNotContainsString(self::CPF, json_encode($resposta->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), 'no conector o CPF sai mascarado');
        self::assertStringNotContainsString((string) $deOutro->getNup(), json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
        self::assertSame(['pasta' => array_column($resposta->dados['pastas'], 'id')], $resposta->ids);
    }

    #[TestDox('buscar: a trancada que também está em segredo sai e conta como sem acesso, não como em segredo')]
    public function testTrancadaEmSegredoContaComoSemAcesso(): void
    {
        $escritorio = $this->escritorio();
        $vinculo = $this->vinculo($this->pessoa(), $escritorio, permissoes: self::LEITOR);
        $trancadaSecreta = $this->pasta($escritorio, nivelSigilo: 1);
        $this->em()->persist(new CadeadoDaPasta($escritorio, $trancadaSecreta, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'buscar', 'busca' => (string) $trancadaSecreta->getNup()], $vinculo);

        self::assertSame([], $resposta->dados['pastas']);
        self::assertSame(0, $resposta->dados['omitidas_em_segredo']);
        self::assertSame(1, $resposta->dados['omitidas_sem_acesso']);
    }

    #[TestDox('buscar: sem o recurso de ver a pasta, ela não aparece e a página conta quantas saíram')]
    public function testSemRecursoDeVer(): void
    {
        $escritorio = $this->escritorio();
        $vinculo = $this->vinculo($this->pessoa(), $escritorio, permissoes: ['modules.expediente.view']);
        $this->pasta($escritorio);
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'buscar'], $vinculo);

        self::assertSame([], $resposta->dados['pastas']);
        self::assertSame(1, $resposta->dados['omitidas_sem_acesso']);
    }

    #[TestDox('buscar: 51 pastas dão duas páginas, e proxima_pagina leva à segunda')]
    public function testPaginacao(): void
    {
        $escritorio = $this->escritorio();
        $vinculo = $this->vinculo($this->pessoa(), $escritorio, permissoes: self::LEITOR);
        for ($i = 0; $i < 51; ++$i) {
            $this->pasta($escritorio, comProcesso: false);
        }
        $this->em()->flush();

        $primeira = $this->executar(['acao' => 'buscar'], $vinculo);
        self::assertCount(50, $primeira->dados['pastas']);
        self::assertSame(51, $primeira->dados['total']);
        self::assertSame(2, $primeira->proximaPagina);

        static::getContainer()->get('services_resetter')->reset();
        $segunda = $this->executar(['acao' => 'buscar', 'pagina' => $primeira->proximaPagina], $vinculo);
        self::assertCount(1, $segunda->dados['pastas']);
        self::assertNull($segunda->proximaPagina);
        self::assertSame([], array_intersect(array_column($primeira->dados['pastas'], 'id'), array_column($segunda->dados['pastas'], 'id')));
    }

    #[TestDox('filtro inválido: a resposta cita o campo, nunca o valor')]
    public function testFiltroInvalido(): void
    {
        $vinculo = $this->vinculo($this->pessoa(), $this->escritorio(), permissoes: self::LEITOR);

        $this->assertFiltroInvalido(['acao' => 'buscar', 'desde' => '2026-13-45'], $vinculo, 'desde', '2026-13-45');
        $this->assertFiltroInvalido(['acao' => 'contar', 'situacao' => 'apagadas'], $vinculo, 'situacao', 'apagadas');
        $this->assertFiltroInvalido(['acao' => 'buscar', 'busca' => ['lista']], $vinculo, 'busca', 'lista');
        $this->assertFiltroInvalido(['acao' => 'buscar', 'desde' => '2026-05-10', 'ate' => '2026-05-01'], $vinculo, 'ate', '2026-05-01');
        $this->assertFiltroInvalido(['acao' => 'numero', 'numero' => str_repeat('9', 101)], $vinculo, 'numero', '9999');
    }

    #[TestDox('contar e opcoes: o total do filtro e as opções do escritório da chamada, sem nome de outro escritório')]
    public function testContarEOpcoes(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: self::LEITOR);
        $this->pasta($escritorio, comProcesso: false);
        $this->pasta($escritorio, comProcesso: false);
        $outro = $this->escritorio('Outro');
        $alheia = $this->pessoa();
        $alheia->setFullName('Pessoa de Outro Escritório');
        $this->vinculo($alheia, $outro, permissoes: self::LEITOR);
        // O outro escritório TEM pastas: sem o isolamento do escopo, elas entrariam na contagem.
        $this->pasta($outro, comProcesso: false);
        $this->pasta($outro, comProcesso: false);
        $this->pasta($outro, comProcesso: false);
        $this->em()->flush();

        $contar = $this->executar(['acao' => 'contar'], $vinculo);
        self::assertSame(['total' => 2], $contar->dados);

        static::getContainer()->get('services_resetter')->reset();
        $opcoes = $this->executar(['acao' => 'opcoes'], $vinculo);
        self::assertSame(['responsaveis', 'marcadores', 'situacoes', 'prioridades'], array_keys($opcoes->dados));
        self::assertNotSame([], $opcoes->dados['situacoes']);
        self::assertStringNotContainsString('Pessoa de Outro Escritório', json_encode($opcoes->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    #[TestDox('numero: acha a pasta pelo número; a da lixeira não sai; quem não tem a pasta no escopo não acha nada')]
    public function testNumero(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: self::LEITOR);
        $prefixo = 'NUM' . random_int(100000, 999999);
        $viva = $this->pasta($escritorio, comProcesso: false, nup: $prefixo . 'A');
        $naLixeira = $this->pasta($escritorio, comProcesso: false, nup: $prefixo . 'B');
        $naLixeira->marcarExcluida($pessoa, new \DateTimeImmutable());
        $semEscopo = $this->vinculo($this->pessoa(), $escritorio, permissoes: ['modules.pastas.view']);
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'numero', 'numero' => $prefixo], $vinculo);
        $ids = array_column($resposta->dados['resultados'], 'pasta_id');
        self::assertContains($viva->getId(), $ids);
        self::assertNotContains($naLixeira->getId(), $ids);
        self::assertSame(0, $resposta->dados['omitidas_em_segredo']);

        static::getContainer()->get('services_resetter')->reset();
        $fora = $this->executar(['acao' => 'numero', 'numero' => $prefixo], $semEscopo);
        self::assertSame([], $fora->dados['resultados']);
    }

    #[TestDox('numero: acha a pasta pelo nome do cliente, e o resultado não traz o CPF dele')]
    public function testNumeroPeloCliente(): void
    {
        $escritorio = $this->escritorio();
        $vinculo = $this->vinculo($this->pessoa(), $escritorio, permissoes: self::LEITOR);
        $nome = 'Cliente Busca ' . random_int(100000, 999999);
        $cliente = ClientePFFactory::createOne(['tenant' => $escritorio, 'nomeCompleto' => $nome, 'cpf' => self::CPF])->_real();
        $pasta = $this->pasta($escritorio, comProcesso: false);
        $pasta->addCliente($cliente);
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'numero', 'numero' => $nome], $vinculo);

        $doCliente = array_values(array_filter($resposta->dados['resultados'], static fn (array $r): bool => $r['grupo'] === 'cliente'));
        self::assertNotSame([], $doCliente, 'premissa: a busca rápida acha pelo cliente');
        self::assertSame($pasta->getId(), $doCliente[0]['pasta_id']);
        self::assertStringStartsWith('Pasta ', $doCliente[0]['subtitulo']);
        $json = json_encode($resposta->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString(self::CPF, $json);
        self::assertStringNotContainsString('52998224725', $json);
        self::assertStringNotContainsString('[CPF]', $json);
        self::assertStringNotContainsString('CPF', $json);
    }

    #[TestDox('lixeira: pasta em segredo sai da lista e conta em omitidas_em_segredo; a de outro escritório não aparece; excluido_por sai pelo rótulo, com o nome')]
    public function testLixeiraSegredoOutroEscritorioEExcluidoPor(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $pessoa->setFullName('Ana Excluiu');
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: self::LEITOR_DA_LIXEIRA);
        $comum = $this->pasta($escritorio, comProcesso: false);
        $secreta = $this->pasta($escritorio, nivelSigilo: 1);
        $outro = $this->escritorio('Outro');
        $deOutro = $this->pasta($outro, comProcesso: false);
        $this->naLixeira($comum, $escritorio, $pessoa, '2026-10-01 09:00:00');
        $this->naLixeira($secreta, $escritorio, $pessoa, '2026-10-02 09:00:00');
        $this->naLixeira($deOutro, $outro, $pessoa, '2026-10-03 09:00:00');

        $resposta = $this->executar(['acao' => 'lixeira'], $vinculo);

        self::assertSame([$comum->getId()], array_column($resposta->dados['pastas'], 'pasta_id'), 'premissa: a comum aparece, a em segredo e a de outro escritório não');
        self::assertSame(1, $resposta->dados['omitidas_em_segredo']);
        self::assertSame(['Ana Excluiu'], $resposta->dados['pastas'][0]['excluido_por']);
        $json = json_encode($resposta->dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString((string) $secreta->getNup(), $json);
        self::assertStringNotContainsString((string) $deOutro->getNup(), $json);
    }

    #[TestDox('lixeira: o cursor da primeira resposta traz a página seguinte, sem repetir pasta')]
    public function testLixeiraCursorValido(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: self::LEITOR_DA_LIXEIRA);
        for ($i = 0; $i < 51; ++$i) {
            $this->naLixeira($this->pasta($escritorio, comProcesso: false), $escritorio, $pessoa, sprintf('2026-10-01 09:%02d:00', $i));
        }

        $primeira = $this->executar(['acao' => 'lixeira'], $vinculo);
        self::assertCount(50, $primeira->dados['pastas']);
        self::assertNotNull($primeira->proximaPagina);

        static::getContainer()->get('services_resetter')->reset();
        $segunda = $this->executar(['acao' => 'lixeira', 'pagina' => $primeira->proximaPagina], $vinculo);

        self::assertCount(1, $segunda->dados['pastas']);
        self::assertNull($segunda->proximaPagina);
        self::assertSame([], array_intersect(array_column($primeira->dados['pastas'], 'pasta_id'), array_column($segunda->dados['pastas'], 'pasta_id')));
    }

    #[TestDox('numero: a pasta em segredo achada pelo número sai e conta em omitidas_em_segredo')]
    public function testNumeroEmSegredo(): void
    {
        $escritorio = $this->escritorio();
        $vinculo = $this->vinculo($this->pessoa(), $escritorio, permissoes: self::LEITOR);
        $prefixo = 'SEG' . random_int(100000, 999999);
        $comum = $this->pasta($escritorio, comProcesso: false, nup: $prefixo . 'A');
        $secreta = $this->pasta($escritorio, nivelSigilo: 1, nup: $prefixo . 'B');

        $resposta = $this->executar(['acao' => 'numero', 'numero' => $prefixo], $vinculo);

        self::assertSame([$comum->getId()], array_column($resposta->dados['resultados'], 'pasta_id'));
        self::assertSame(1, $resposta->dados['omitidas_em_segredo']);
        self::assertSame(0, $resposta->dados['omitidas_sem_acesso']);
        self::assertStringNotContainsString((string) $secreta->getNup(), json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
    }

    #[TestDox('numero: a pasta trancada que também está em segredo sai e conta em omitidas_sem_acesso, não em segredo')]
    public function testNumeroTrancadaEmSegredoContaComoSemAcesso(): void
    {
        $escritorio = $this->escritorio();
        $vinculo = $this->vinculo($this->pessoa(), $escritorio, permissoes: self::LEITOR);
        $nup = 'TRS' . random_int(100000, 999999);
        $trancadaSecreta = $this->pasta($escritorio, nivelSigilo: 1, nup: $nup);
        $this->em()->persist(new CadeadoDaPasta($escritorio, $trancadaSecreta, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'numero', 'numero' => $nup], $vinculo);

        self::assertSame([], $resposta->dados['resultados']);
        self::assertSame(0, $resposta->dados['omitidas_em_segredo']);
        self::assertSame(1, $resposta->dados['omitidas_sem_acesso']);
    }

    #[TestDox('minhas_demandas: só as pastas em que a pessoa é a responsável')]
    public function testMinhasDemandas(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: self::LEITOR);
        $minha = $this->pasta($escritorio, comProcesso: false);
        $minha->setResponsavel($pessoa);
        $this->pasta($escritorio, comProcesso: false);
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'minhas_demandas'], $vinculo);

        self::assertSame([$minha->getId()], array_column($resposta->dados['pastas'], 'id'));
        self::assertSame(1, $resposta->dados['total']);
    }

    #[TestDox('minhas_demandas: a trancada não aparece nem conta, mesmo sendo da pessoa, como na tela (spec pasta-cadeado D1)')]
    public function testMinhasDemandasComATrancada(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $vinculo = $this->vinculo($pessoa, $escritorio, permissoes: self::LEITOR);
        $trancada = $this->pasta($escritorio, comProcesso: false);
        $trancada->setResponsavel($pessoa);
        $this->em()->persist(new CadeadoDaPasta($escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'minhas_demandas'], $vinculo);

        self::assertSame([], $resposta->dados['pastas']);
        self::assertSame(0, $resposta->dados['total']);
        self::assertSame(0, $resposta->dados['omitidas_sem_acesso'], 'nada a contar: o ListarMinhasDemandasUseCase já não a traz');
    }

    #[TestDox('lixeira: sem nada excluído, a lista vem vazia; cursor fora do formato é filtro inválido')]
    public function testLixeira(): void
    {
        $vinculo = $this->vinculo($this->pessoa(), $this->escritorio(), permissoes: self::LEITOR);

        $resposta = $this->executar(['acao' => 'lixeira'], $vinculo);
        self::assertSame(['pastas' => [], 'omitidas_em_segredo' => 0], $resposta->dados);
        self::assertNull($resposta->proximaPagina);

        static::getContainer()->get('services_resetter')->reset();
        $this->assertFiltroInvalido(['acao' => 'lixeira', 'pagina' => 'abc'], $vinculo, 'pagina', 'abc');
    }

    #[TestDox('sem o módulo do Expediente, buscar é sem permissão; sem o de Pastas, numero também')]
    public function testSemModulo(): void
    {
        $escritorio = $this->escritorio();
        $soPastas = $this->vinculo($this->pessoa(), $escritorio, permissoes: ['modules.pastas.view']);
        $soExpediente = $this->vinculo($this->pessoa(), $escritorio, permissoes: ['modules.expediente.view']);

        $this->assertMotivo(['acao' => 'buscar'], $soPastas, ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        static::getContainer()->get('services_resetter')->reset();
        $this->assertMotivo(['acao' => 'numero', 'numero' => '123'], $soExpediente, ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
    }

    #[TestDox('pastas_paradas: sai a parada há mais de 30 dias; a trancada fica fora do relatório; a em segredo sai e é contada; a recente e a de outro escritório não entram')]
    public function testPastasParadas(): void
    {
        $escritorio = $this->escritorio();
        $vinculo = $this->vinculo($this->pessoa(), $escritorio, permissoes: self::LEITOR);
        $parada = $this->paradaHaDias($this->pasta($escritorio, comProcesso: false), 60);
        $recente = $this->paradaHaDias($this->pasta($escritorio, comProcesso: false), 2);
        $trancada = $this->paradaHaDias($this->pasta($escritorio, comProcesso: false), 70);
        $this->em()->persist(new CadeadoDaPasta($escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $secreta = $this->paradaHaDias($this->pasta($escritorio, nivelSigilo: 1), 80);
        $deOutro = $this->paradaHaDias($this->pasta($this->escritorio('Outro'), comProcesso: false), 90);
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'pastas_paradas'], $vinculo);

        $dados = $resposta->dados;
        self::assertSame([$parada->getId()], array_column($dados['pastas'], 'pasta_id'));
        self::assertSame(1, $dados['total']);
        self::assertSame(1, $dados['omitidas_em_segredo']);
        self::assertSame(0, $dados['omitidas_sem_acesso']);
        self::assertSame(1, $dados['resumo']['pastas_paradas']);
        self::assertSame(1, $dados['resumo']['sem_processo']);
        self::assertGreaterThanOrEqual(60, $dados['pastas'][0]['dias_parada']);
        self::assertNull($dados['pastas'][0]['processo']);
        self::assertNull($resposta->proximaPagina);
        self::assertSame(['pasta' => [(int) $parada->getId()]], $resposta->ids);
        $json = json_encode($dados, \JSON_THROW_ON_ERROR);
        foreach ([$recente, $trancada, $secreta, $deOutro] as $fora) {
            self::assertStringNotContainsString((string) $fora->getNup(), $json);
        }
    }

    #[TestDox('pastas_paradas: sem o recurso de ver a pasta, o relatório vem vazio, como a tela')]
    public function testPastasParadasSemRecursoDeVer(): void
    {
        $escritorio = $this->escritorio();
        $vinculo = $this->vinculo($this->pessoa(), $escritorio, permissoes: ['modules.expediente.view']);
        $this->paradaHaDias($this->pasta($escritorio, comProcesso: false), 60);
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'pastas_paradas'], $vinculo);

        self::assertSame([], $resposta->dados['pastas']);
        self::assertSame(0, $resposta->dados['total']);
    }

    #[TestDox('pastas_paradas: sem_contrato é do Financeiro; com a aba Financeiro escondida, vai nulo e fica fora do total')]
    public function testPastasParadasSemContratoSoParaQuemVeOFinanceiro(): void
    {
        $escritorio = $this->escritorio();
        $leitor = $this->pessoa();
        $vinculo = $this->vinculo($leitor, $escritorio, permissoes: self::LEITOR);
        $visivel = $this->paradaHaDias($this->pasta($escritorio, comProcesso: false), 60);
        $escondida = $this->paradaHaDias($this->pasta($escritorio, comProcesso: false), 61);
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($escritorio, $escondida, AbaDaPasta::Financeiro, $this->pessoa(), new \DateTimeImmutable()));
        $this->em()->flush();

        $dados = $this->executar(['acao' => 'pastas_paradas'], $vinculo)->dados;

        $porPasta = array_column($dados['pastas'], 'sem_contrato', 'pasta_id');
        self::assertTrue($porPasta[$visivel->getId()]);
        self::assertArrayHasKey((int) $escondida->getId(), $porPasta);
        self::assertNull($porPasta[$escondida->getId()]);
        self::assertSame(1, $dados['resumo']['sem_contrato']);
        self::assertSame(2, $dados['resumo']['pastas_paradas']);
    }

    #[TestDox('pastas_paradas: 21 pastas dão duas páginas de 20, a resposta cabe no teto e proxima_pagina leva à segunda')]
    public function testPastasParadasPaginadas(): void
    {
        $escritorio = $this->escritorio();
        $vinculo = $this->vinculo($this->pessoa(), $escritorio, permissoes: self::LEITOR);
        for ($i = 0; $i < 21; ++$i) {
            $this->paradaHaDias($this->pasta($escritorio, comProcesso: false), 40 + $i);
        }
        $this->em()->flush();

        $primeira = $this->executar(['acao' => 'pastas_paradas'], $vinculo);
        static::getContainer()->get('services_resetter')->reset();
        $segunda = $this->executar(['acao' => 'pastas_paradas', 'pagina' => $primeira->proximaPagina], $vinculo);

        self::assertCount(20, $primeira->dados['pastas']);
        self::assertSame(21, $primeira->dados['total']);
        self::assertSame(2, $primeira->proximaPagina);
        self::assertLessThan(DespachoDasFerramentas::TETO_DE_CARACTERES, mb_strlen(json_encode($primeira->dados, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)));
        self::assertCount(1, $segunda->dados['pastas']);
        self::assertNull($segunda->proximaPagina);
        self::assertSame(2, $segunda->dados['pagina']);
    }

    #[TestDox('pastas_sem_processo: sai a pasta sem processo e a com processo; a trancada fica fora; a em segredo sai e é contada; a de outro escritório não aparece; categoria filtra')]
    public function testPastasSemProcesso(): void
    {
        $escritorio = $this->escritorio();
        $vinculo = $this->vinculo($this->pessoa(), $escritorio, permissoes: self::LEITOR);
        $sem = $this->pasta($escritorio, comProcesso: false);
        $com = $this->pasta($escritorio);
        $trancada = $this->pasta($escritorio, comProcesso: false);
        $this->em()->persist(new CadeadoDaPasta($escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $secreta = $this->pasta($escritorio, nivelSigilo: 1);
        $deOutro = $this->pasta($this->escritorio('Outro'), comProcesso: false);
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'pastas_sem_processo'], $vinculo);

        $porId = array_column($resposta->dados['pastas'], null, 'pasta_id');
        self::assertEqualsCanonicalizing([$sem->getId(), $com->getId()], array_keys($porId));
        self::assertNull($porId[$sem->getId()]['processo']);
        self::assertNotNull($porId[$com->getId()]['processo']);
        self::assertSame(1, $resposta->dados['resumo']['sem_processo']);
        self::assertSame(1, $resposta->dados['omitidas_em_segredo']);
        self::assertSame(0, $resposta->dados['omitidas_sem_acesso']);
        $json = json_encode($resposta->dados, \JSON_THROW_ON_ERROR);
        foreach ([$trancada, $secreta, $deOutro] as $fora) {
            self::assertStringNotContainsString((string) $fora->getNup(), $json);
        }

        static::getContainer()->get('services_resetter')->reset();
        $categoria = $porId[$com->getId()]['categoria'];
        $filtrada = $this->executar(['acao' => 'pastas_sem_processo', 'categoria' => $categoria], $vinculo);
        self::assertContains($com->getId(), array_column($filtrada->dados['pastas'], 'pasta_id'));
        self::assertSame($filtrada->dados['total'], \count($filtrada->dados['pastas']));
    }

    #[TestDox('os relatórios recusam o que a ação não usa, página e categoria inválidas e pedem o módulo do Expediente')]
    public function testRelatoriosRecusas(): void
    {
        $escritorio = $this->escritorio();
        $vinculo = $this->vinculo($this->pessoa(), $escritorio, permissoes: self::LEITOR);
        $soPastas = $this->vinculo($this->pessoa(), $escritorio, permissoes: ['modules.pastas.view', 'resources.pasta.view']);

        $this->assertMotivo(['acao' => 'pastas_paradas', 'busca' => 'x'], $vinculo, ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        static::getContainer()->get('services_resetter')->reset();
        $this->assertMotivo(['acao' => 'pastas_paradas', 'categoria' => 'com'], $vinculo, ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        static::getContainer()->get('services_resetter')->reset();
        $this->assertFiltroInvalido(['acao' => 'pastas_paradas', 'pagina' => 12345678], $vinculo, 'pagina', '12345678');
        $this->assertFiltroInvalido(['acao' => 'pastas_sem_processo', 'pagina' => 'abc'], $vinculo, 'pagina', 'abc');
        $this->assertFiltroInvalido(['acao' => 'pastas_sem_processo', 'categoria' => 'inventada'], $vinculo, 'categoria', 'inventada');
        $this->assertMotivo(['acao' => 'pastas_paradas'], $soPastas, ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        static::getContainer()->get('services_resetter')->reset();
        $this->assertMotivo(['acao' => 'pastas_sem_processo'], $soPastas, ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
    }

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos, UserTenant $vinculo): RespostaDaFerramenta
    {
        return static::getContainer()->get(BuscarPastasFerramenta::class)->executar($argumentos, $this->abrir($vinculo));
    }

    /** A chamada como o `/mcp` monta: o portão abre (escritório fixado, filtro do Doctrine e modo do conector). */
    private function abrir(UserTenant $vinculo): ChamadaDoConector
    {
        $contexto = $this->portao()->abrir((int) $vinculo->getUser()->getId(), (int) $vinculo->getTenant()->getId());

        return new ChamadaDoConector($contexto, 41, 42, 'claude.ai', ['mcp:leitura'], new \DateTimeImmutable('+7 days'));
    }

    /** @param array<string, mixed> $argumentos */
    private function assertFiltroInvalido(array $argumentos, UserTenant $vinculo, string $campo, string $valor): void
    {
        $erro = $this->assertMotivo($argumentos, $vinculo, ErroDaFerramenta::MOTIVO_FILTRO_INVALIDO);
        self::assertStringContainsString($campo, $erro->getMessage());
        self::assertStringNotContainsString($valor, $erro->getMessage());
        static::getContainer()->get('services_resetter')->reset();
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, UserTenant $vinculo, string $motivo): ErroDaFerramenta
    {
        try {
            $this->executar($argumentos, $vinculo);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);

            return $e;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    /** Uma pasta do escritório, por padrão com um processo público (com `nivelSigilo` > 0, em segredo). */
    private function pasta(Tenant $escritorio, int $nivelSigilo = 0, bool $comProcesso = true, ?string $nup = null): Pasta
    {
        $pasta = (new Pasta())->setNup($nup ?? 'BUS-' . uniqid());
        $pasta->setTenant($escritorio);
        $this->em()->persist($pasta);
        if ($comProcesso) {
            $processo = new Processo();
            $processo->setNumeroProcesso(sprintf('0701134572025807%04d', ++$this->numero) . uniqid());
            $processo->setSiglaTribunal('TJDFT');
            $processo->setClasseProcessual('Procedimento Comum');
            $processo->setAssuntoProcessual('Assunto');
            $processo->setTenant($escritorio);
            $processo->setNivelSigilo($nivelSigilo);
            $this->em()->persist($processo);
            $vinculo = new PastaProcesso($pasta, $processo);
            $this->em()->persist($vinculo);
            $pasta->getPastaProcessos()->add($vinculo);
        }
        $this->em()->flush();

        return $pasta;
    }

    /** A pasta aberta há `$dias` e sem nenhuma movimentação: o relatório de pastas paradas conta a partir da abertura. */
    private function paradaHaDias(Pasta $pasta, int $dias): Pasta
    {
        $pasta->setDataAbertura(new \DateTimeImmutable('-' . $dias . ' days'));
        $this->em()->flush();

        return $pasta;
    }

    /** Um documento da pasta, excluído por `$por` na data dada: a pasta passa a ter algo na lixeira do escritório. */
    private function naLixeira(Pasta $pasta, Tenant $escritorio, User $por, string $quando): void
    {
        $documento = new PastaDocumento();
        $documento->setTenant($escritorio);
        $documento->setPasta($pasta);
        $documento->setTitulo('Documento excluído');
        $documento->setCategoria('OUTROS');
        $documento->setCaminhoArquivo('x/excluido.pdf');
        $documento->setNomeOriginal('excluido.pdf');
        $documento->setMimeType('application/pdf');
        $documento->setTamanhoBytes(10);
        $documento->marcarExcluido($por, new \DateTimeImmutable($quando));
        $this->em()->persist($documento);
        $this->em()->flush();
    }
}
