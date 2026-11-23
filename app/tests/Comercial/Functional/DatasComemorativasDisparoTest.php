<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Campanha\ChaveDasCampanhas;
use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\ExpansorDeCampanhas;
use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\Entity\ModeloDeCampanha;
use App\Comercial\Message\ExpandirCampanhas;
use App\Comercial\Message\VarrerAutomacoes;
use App\Comercial\MessageHandler\VarrerAutomacoesHandler;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\DataComemorativaRepository;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Comercial\Service\AvisoDeTrocaDoServicoDeEmail;
use App\Comercial\Service\DisparoDasDatasComemorativas;
use App\Comercial\Service\ModelosEfetivos;
use App\Comercial\Service\RecuperacaoDeEnvios;
use App\Comercial\Service\VarreduraDeAutomacoes;
use App\Tests\Comercial\Support\CriaCampanhas;
use App\Tests\Comercial\Support\LogEmMemoria;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * O passo `datas` da varredura (spec comercial-e-emails.md §13, F4) contra o banco semeado pela migration (as 17
 * ligadas, no dia, para Todos), com a hora de cada volta escolhida: das 9h em diante no dia de envio, uma campanha só
 * por data e ano, nada com a chave geral ou a data desligada, a antecedência atravessando o ano e o público da data
 * resolvido pelo expansor.
 */
#[CoversClass(DisparoDasDatasComemorativas::class)]
#[CoversClass(VarrerAutomacoesHandler::class)]
#[CoversClass(DataComemorativaRepository::class)]
final class DatasComemorativasDisparoTest extends KernelTestCase
{
    use CriaCampanhas;

    private Connection $conexao;
    private InMemoryTransport $fila;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
        $this->fila = static::getContainer()->get('messenger.transport.comercial');
        $this->fila->reset();
    }

    #[TestDox('Antes das 9h do dia de envio, nada; às 9h, a campanha do sistema na fila, com o modelo congelado e a trilha sem autor')]
    public function testNadaAntesDas9hEDepoisACampanha(): void
    {
        self::assertSame(0, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-25 08:59')));
        self::assertSame([], $this->chaves());

        self::assertSame(1, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-25 09:00')));

        $c = $this->conexao->fetchAssociative("SELECT * FROM comercial_campanha WHERE chave_idempotencia = 'data:natal:2026'");
        self::assertIsArray($c);
        self::assertSame(
            ['na_fila', null, 'data-natal', 'Natal', 'blocos', 'Feliz Natal! · BlueJus', 'NATAL', 'Feliz Natal, {nome}!', 'todos', 'unica', null],
            [$c['estado'], $c['criada_por_id'], $c['modelo_chave'], $c['modelo_nome'], $c['modo'], $c['assunto'], $c['selo'], $c['titulo'], $c['publico'], $c['frequencia'], $c['publico_parametros']],
        );
        self::assertStringContainsString('Abrir o BlueJus', (string) $c['conteudo']);
        $trilha = $this->conexao->fetchAssociative("SELECT changes, actor_user_id FROM audit_log WHERE action = 'camp_conf' AND entity_id = ?", [(string) $c['id']]);
        self::assertIsArray($trilha);
        self::assertSame(['publico' => 'todos', 'frequencia' => 'unica', 'total' => 0], json_decode((string) $trilha['changes'], true));
        self::assertNull($trilha['actor_user_id']);
        self::assertSame([], array_filter($this->fila->getSent(), static fn ($e): bool => $e->getMessage() instanceof ExpandirCampanhas), 'não acorda o expansor: ele roda a cada minuto pelo schedule cadeias');
    }

    #[TestDox('Duas voltas no mesmo dia (e uma no fim da noite) criam uma campanha só: data:<id>:<ano>')]
    public function testUmaSoEmDuasVoltas(): void
    {
        self::assertSame(1, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-25 09:05')));
        self::assertSame(0, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-25 09:20')));
        self::assertSame(0, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-25 23:59')));

        self::assertSame(['data:natal:2026'], $this->chaves());
    }

    #[TestDox('Com a chave geral desligada, nada é criado e nada vai ao log (a volta de 15 min repetiria), nem depois, noutro dia')]
    public function testChaveDesligada(): void
    {
        $log = new LogEmMemoria();

        self::assertSame(0, $this->disparo(false, $log)->disparar(new \DateTimeImmutable('2026-12-25 10:00')));

        self::assertSame([], $this->chaves());
        self::assertSame('', $log->tudo, 'sem log repetido a cada volta');
        self::assertSame(0, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-26 10:00')), 'não represa: no dia seguinte o Natal não sai');
        self::assertSame([], $this->chaves());
    }

    #[TestDox('Com a data desligada, nada')]
    public function testDataDesligada(): void
    {
        $this->conexao->executeStatement("UPDATE comercial_data_comemorativa SET ligada = false WHERE chave = 'natal'");

        self::assertSame(0, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-25 10:00')));
        self::assertSame([], $this->chaves());
    }

    #[TestDox('A data sem linha no banco vale o padrão (ligada, no dia, para Todos) e sai do mesmo jeito')]
    public function testDataSemLinha(): void
    {
        $this->conexao->executeStatement("DELETE FROM comercial_data_comemorativa WHERE chave = 'natal'");

        self::assertSame(1, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-25 10:00')));
        self::assertSame(['data:natal:2026'], $this->chaves());
        self::assertSame('todos', $this->conexao->fetchOne("SELECT publico FROM comercial_campanha WHERE chave_idempotencia = 'data:natal:2026'"));
    }

    #[TestDox('3 dias antes sai no dia 22 (e não no dia 25); 1 dia antes da Páscoa de 2027 sai no sábado')]
    public function testAntecedencia(): void
    {
        $this->conexao->executeStatement("UPDATE comercial_data_comemorativa SET antecedencia = 3 WHERE chave = 'natal'");
        $this->conexao->executeStatement("UPDATE comercial_data_comemorativa SET antecedencia = 1 WHERE chave = 'pascoa'");

        self::assertSame(0, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-21 10:00')));
        self::assertSame(1, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-22 10:00')));
        self::assertSame(0, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-25 10:00')));
        self::assertSame(1, $this->disparo()->disparar(new \DateTimeImmutable('2027-03-27 09:30')));

        self::assertSame(['data:natal:2026', 'data:pascoa:2027'], $this->chaves());
    }

    #[TestDox('No dia seguinte ao de envio nada é recuperado')]
    public function testNadaNoDiaSeguinte(): void
    {
        self::assertSame(0, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-26 10:00')));
        self::assertSame([], $this->chaves());
    }

    #[TestDox('O Ano Novo 1 dia antes sai em 31/12/2026 com o ano da data (2027), junto com o Réveillon de 2026')]
    public function testAnoDaDataNoAnoNovo(): void
    {
        $this->conexao->executeStatement("UPDATE comercial_data_comemorativa SET antecedencia = 1 WHERE chave = 'ano'");

        self::assertSame(2, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-31 09:00')));
        self::assertSame(0, $this->disparo()->disparar(new \DateTimeImmutable('2027-01-01 09:00')), 'no dia 1º, o Ano Novo de 2027 já saiu');

        self::assertSame(['data:ano:2027', 'data:reveillon:2026'], $this->chaves());
    }

    #[TestDox('O público da data vale na execução do expansor: Primários só alcança o primário')]
    public function testPublicoDaData(): void
    {
        $prim = $this->usuario('dt.prim');
        $this->vincular($prim, true);
        $sec = $this->cadastradoAtivo('dt.sec');
        $this->conexao->executeStatement("UPDATE comercial_data_comemorativa SET publico = 'prim' WHERE chave = 'natal'");
        $agora = new \DateTimeImmutable('2026-12-25 09:30');
        $this->disparo()->disparar($agora);
        $id = (int) $this->conexao->fetchOne("SELECT id FROM comercial_campanha WHERE chave_idempotencia = 'data:natal:2026'");

        self::assertSame(1, $this->expansor()->materializarDevidas($agora));

        $destinos = $this->conexao->fetchFirstColumn('SELECT email FROM comercial_campanha_destino WHERE campanha_id = ?', [$id]);
        self::assertContains((string) $prim->getEmail(), $destinos);
        self::assertNotContains((string) $sec->getEmail(), $destinos);
        self::assertSame('prim', $this->conexao->fetchOne('SELECT publico FROM comercial_campanha WHERE id = ?', [$id]));
    }

    #[TestDox('M5.12: o modelo escolhido para a data é o que sai (modelo_chave e conteúdo congelado); o padrão da data continua sendo data-<id>')]
    public function testModeloEscolhido(): void
    {
        $this->conexao->executeStatement("UPDATE comercial_data_comemorativa SET modelo_chave = 'obrigado' WHERE chave = 'natal'");

        self::assertSame(1, $this->disparo()->disparar(new \DateTimeImmutable('2026-12-25 09:00')));

        $c = $this->conexao->fetchAssociative("SELECT * FROM comercial_campanha WHERE chave_idempotencia = 'data:natal:2026'");
        self::assertIsArray($c);
        self::assertSame(['obrigado', 'Agradecimento · contribuição', 'simples', 'Obrigado por apoiar o BlueJus'], [$c['modelo_chave'], $c['modelo_nome'], $c['modo'], $c['assunto']]);
    }

    #[TestDox('M5.12: o padrão editado pelo Master vale na data (o modelo escolhido e o data-<id> editado)')]
    public function testFixoEditadoValeNaData(): void
    {
        $this->editarFixo('data-natal', ['modo' => 'blocos', 'assunto' => 'Natal do Master', 'selo' => 'NATAL', 'titulo' => 'Boas festas, {nome}', 'blocos' => [['t' => 'texto', 'a' => 'Texto editado do Natal']]]);
        $this->editarFixo('obrigado', ['modo' => 'simples', 'assunto' => 'Obrigado editado', 'selo' => 'OBRIGADO', 'titulo' => 'Valeu', 'corpo' => 'Corpo editado']);

        $this->disparo()->disparar(new \DateTimeImmutable('2026-12-25 09:00'));
        $c = $this->conexao->fetchAssociative("SELECT * FROM comercial_campanha WHERE chave_idempotencia = 'data:natal:2026'");
        self::assertIsArray($c);
        self::assertSame(['data-natal', 'Natal do Master'], [$c['modelo_chave'], $c['assunto']]);
        self::assertStringContainsString('Texto editado do Natal', (string) $c['conteudo']);

        $this->conexao->executeStatement("UPDATE comercial_data_comemorativa SET modelo_chave = 'obrigado' WHERE chave = 'pascoa'");
        $this->disparo()->disparar(new \DateTimeImmutable('2027-03-28 09:00'));
        $p = $this->conexao->fetchAssociative("SELECT * FROM comercial_campanha WHERE chave_idempotencia = 'data:pascoa:2027'");
        self::assertIsArray($p);
        self::assertSame(['obrigado', 'Obrigado editado'], [$p['modelo_chave'], $p['assunto']]);
        self::assertStringContainsString('Corpo editado', (string) $p['conteudo']);
    }

    #[TestDox('M5.12: o modelo próprio escolhido sai com o conteúdo dele; excluído, a data volta ao padrão dela e o log avisa, sem quebrar')]
    public function testProprioEscolhidoEExcluido(): void
    {
        $master = $this->usuario('dt.master');
        $modelos = static::getContainer()->get(ModeloDeCampanhaRepository::class);
        $proprio = new ModeloDeCampanha('Meu Natal', 'bi-gift', 'blocos', $master, new \DateTimeImmutable());
        $proprio->preencher('Assunto do meu Natal', 'NATAL', 'Meu título', (string) json_encode([['t' => 'texto', 'a' => 'Meu texto de Natal']]), new \DateTimeImmutable());
        $modelos->salvar($proprio, ModeloDeCampanhaRepository::NOVO, $master);
        $chaveDoProprio = 'cu' . $proprio->getId();
        $this->conexao->executeStatement('UPDATE comercial_data_comemorativa SET modelo_chave = ? WHERE chave = ?', [$chaveDoProprio, 'natal']);

        $this->disparo()->disparar(new \DateTimeImmutable('2026-12-25 09:00'));
        $c = $this->conexao->fetchAssociative("SELECT * FROM comercial_campanha WHERE chave_idempotencia = 'data:natal:2026'");
        self::assertIsArray($c);
        self::assertSame([$chaveDoProprio, 'Meu Natal', 'Assunto do meu Natal'], [$c['modelo_chave'], $c['modelo_nome'], $c['assunto']]);
        self::assertStringContainsString('Meu texto de Natal', (string) $c['conteudo']);

        $modelos->remover($proprio, $master);
        $this->conexao->executeStatement('UPDATE comercial_data_comemorativa SET modelo_chave = ? WHERE chave = ?', [$chaveDoProprio, 'pascoa']);
        $log = new LogEmMemoria();
        self::assertSame(1, $this->disparo(true, $log)->disparar(new \DateTimeImmutable('2027-03-28 09:00')));

        $p = $this->conexao->fetchAssociative("SELECT * FROM comercial_campanha WHERE chave_idempotencia = 'data:pascoa:2027'");
        self::assertIsArray($p);
        self::assertSame('data-pascoa', $p['modelo_chave'], 'voltou ao padrão da data');
        self::assertStringContainsString('não existe mais', $log->tudo);
    }

    #[TestDox('M5.12: o escolhido que não compõe campanha válida (vagas sem o botão) volta ao padrão da data, com log')]
    public function testEscolhidoInvalidoVoltaAoPadrao(): void
    {
        $this->editarFixo('vagas', ['modo' => 'simples', 'assunto' => 'Vagas', 'selo' => 'VAGAS', 'titulo' => 'Vagas', 'corpo' => 'Sem botão', 'cta' => '', 'link' => '']);
        $this->conexao->executeStatement("UPDATE comercial_data_comemorativa SET modelo_chave = 'vagas' WHERE chave = 'natal'");
        $log = new LogEmMemoria();

        self::assertSame(1, $this->disparo(true, $log)->disparar(new \DateTimeImmutable('2026-12-25 09:00')));

        self::assertSame('data-natal', $this->conexao->fetchOne("SELECT modelo_chave FROM comercial_campanha WHERE chave_idempotencia = 'data:natal:2026'"));
        self::assertStringContainsString('não compõe uma campanha', $log->tudo);
    }

    #[TestDox('M5.12: a versão editada do padrão da data que não compõe (bloco desconhecido) cai no texto do sistema; a data não fica sem envio')]
    public function testPadraoEditadoInvalidoVaiNoTextoDoSistema(): void
    {
        $this->editarFixo('data-natal', ['modo' => 'blocos', 'assunto' => 'Natal do Master', 'blocos' => [['t' => 'texto', 'a' => 'x']]]);
        $this->conexao->executeStatement("UPDATE comercial_modelo SET conteudo = '[{\"t\":\"desconhecido\"}]' WHERE fixo_chave = 'data-natal'");
        static::getContainer()->get(EntityManagerInterface::class)->clear(); // a versão em memória ainda tem o assunto
        $log = new LogEmMemoria();

        self::assertSame(1, $this->disparo(true, $log)->disparar(new \DateTimeImmutable('2026-12-25 09:00')));

        $c = $this->conexao->fetchAssociative("SELECT * FROM comercial_campanha WHERE chave_idempotencia = 'data:natal:2026'");
        self::assertIsArray($c);
        self::assertSame(['data-natal', (string) ((array) (new ModelosDeCampanha())->fixo('data-natal'))['assunto']], [$c['modelo_chave'], $c['assunto']]);
        self::assertStringContainsString('vale o texto do sistema', $log->tudo);

        // Assunto vazio também não compõe (a campanha não sairia): vale o texto do sistema.
        $this->editarFixo('data-pascoa', ['modo' => 'blocos', 'assunto' => 'Páscoa do Master', 'blocos' => [['t' => 'texto', 'a' => 'x']]]);
        $this->conexao->executeStatement("UPDATE comercial_modelo SET assunto = '' WHERE fixo_chave = 'data-pascoa'");
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(1, $this->disparo()->disparar(new \DateTimeImmutable('2027-03-28 09:00')));
        self::assertSame((string) ((array) (new ModelosDeCampanha())->fixo('data-pascoa'))['assunto'], $this->conexao->fetchOne("SELECT assunto FROM comercial_campanha WHERE chave_idempotencia = 'data:pascoa:2027'"));
    }

    /** @param array<string, mixed> $mensagem */
    private function editarFixo(string $chave, array $mensagem): void
    {
        $master = $this->usuario('dt.ed');
        $base = (array) (new ModelosDeCampanha())->fixo($chave);
        $modelo = ModeloDeCampanha::paraFixo($chave, (string) $base['nome'], (string) $base['icone'], (string) $mensagem['modo'], $master, new \DateTimeImmutable());
        $m = MensagemDaCampanha::deArray($mensagem);
        $modelo->preencher($m->assunto, $m->selo, $m->titulo, ModeloDaGrade::conteudoParaGravar($m), new \DateTimeImmutable());
        static::getContainer()->get(ModeloDeCampanhaRepository::class)->salvar($modelo, ModeloDeCampanhaRepository::ALTERADO, $master);
    }

    #[TestDox('O passo `datas` roda na varredura de 15 min, com o relógio dela')]
    public function testPassoNaVarredura(): void
    {
        $this->conexao->executeStatement('UPDATE comercial_varredura SET proxima_em = ? WHERE id = 1', ['2026-12-25 09:00:00']);
        $c = static::getContainer();
        $handler = new VarrerAutomacoesHandler(
            $this->conexao,
            $c->get(VarreduraDeAutomacoes::class),
            $c->get(RecuperacaoDeEnvios::class),
            $this->disparo(),
            $c->get(AvisoDeTrocaDoServicoDeEmail::class),
            new MockClock('2026-12-25 09:10', 'America/Sao_Paulo'),
            $log = new LogEmMemoria(),
        );

        $handler(new VarrerAutomacoes());
        self::assertSame(['data:natal:2026'], $this->chaves());
        self::assertSame('', $log->tudo, 'nenhum passo falhou');
        self::assertSame([], array_filter($this->fila->getSent(), static fn ($e): bool => $e->getMessage() instanceof VarrerAutomacoes), 'a varredura não se reagenda: o schedule cadeias é quem dispara');
    }

    private function disparo(bool $ligada = true, ?LogEmMemoria $log = null): DisparoDasDatasComemorativas
    {
        $c = static::getContainer();

        return new DisparoDasDatasComemorativas(
            $c->get(DataComemorativaRepository::class),
            $c->get(CampanhaDeEmailRepository::class),
            $c->get(ModelosEfetivos::class),
            $c->get(ComposicaoDaCampanha::class),
            new ChaveDasCampanhas($ligada),
            $log ?? new NullLogger(),
        );
    }

    private function expansor(): ExpansorDeCampanhas
    {
        return static::getContainer()->get(ExpansorDeCampanhas::class);
    }

    /** @return list<string> as chaves de idempotência das campanhas das datas, em ordem */
    private function chaves(): array
    {
        return array_map('strval', $this->conexao->fetchFirstColumn("SELECT chave_idempotencia FROM comercial_campanha WHERE chave_idempotencia LIKE 'data:%' ORDER BY chave_idempotencia"));
    }
}
