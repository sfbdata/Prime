<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Agenda\Entity\Evento;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\GravarNaAgendaFerramenta;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `gravar_na_agenda` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.6, PR 13): criar, mudar a situação e
 * avisar gravam na hora pelos UseCases da tela; editar, mover e excluir só gravam depois do `confirmar_acao`; o
 * compromisso de outra pessoa (mesmo de administrador) e o de outro escritório são "não encontrado"; participante de fora
 * da equipe, pasta que a pessoa não vê e falta do escopo de escrita recusam.
 */
#[CoversClass(GravarNaAgendaFerramenta::class)]
final class GravarNaAgendaFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const PERMISSOES = ['modules.agenda.view', 'modules.pastas.view', 'resources.pasta.view', 'resources.agenda.ver_prazos'];

    private Tenant $escritorio;
    private User $pessoa;
    private ConsentimentoDoConector $consentimento;
    private ClienteDoConector $app;
    private \DateTimeImmutable $amanha;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->amanha = new \DateTimeImmutable('tomorrow');
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::PERMISSOES);
        $doConector = new EscritorioDoConector($this->escritorio);
        $doConector->ligar($this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($doConector);
        $this->app = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'Claude', ['https://claude.ai/api/mcp/auth_callback'], new \DateTimeImmutable());
        $this->em()->persist($this->app);
        $this->consentimento = $this->consentimentoDe($this->pessoa);
        $this->ids = ['escritorio' => (int) $this->escritorio->getId(), 'pessoa' => (int) $this->pessoa->getId(), 'app' => (int) $this->app->getId(), 'consentimento' => (int) $this->consentimento->getId()];
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('salvar sem compromisso_id cria na hora, com tipo, horário, só eu, pasta, participante e lembrete, e avisa do Google')]
    public function testCriar(): void
    {
        $pasta = $this->pasta();
        $colega = $this->colega();

        $resposta = $this->executar([
            'acao' => 'salvar', 'titulo' => 'Reunião com o cliente', 'tipo' => 'reun', 'data' => $this->dia(), 'inicio' => '14:00', 'fim' => '15:30',
            'visibilidade' => 'equipe', 'pasta_id' => $pasta->getId(), 'participantes' => [$colega->getId()], 'lembrete_minutos' => 30, 'observacao' => 'Levar contrato',
        ]);

        self::assertSame('gravado', $resposta->dados['estado']);
        self::assertSame(GravarNaAgendaFerramenta::AVISO_DO_GOOGLE, $resposta->dados['google']);
        self::assertSame(1, $resposta->dados['avisados']);
        $evento = $this->evento((int) $resposta->dados['compromisso_id']);
        self::assertSame('Reunião com o cliente', $evento->getTitulo());
        self::assertSame('reun', $evento->getTipo()?->value);
        self::assertSame($this->dia() . ' 14:00', $evento->getDataInicio()?->format('Y-m-d H:i'));
        self::assertSame($this->dia() . ' 15:30', $evento->getDataFim()?->format('Y-m-d H:i'));
        self::assertFalse($evento->isDiaInteiro());
        self::assertSame($pasta->getId(), $evento->getPasta()?->getId());
        self::assertSame(30, $evento->getLembreteMinutos());
        self::assertSame('Levar contrato', $evento->getDescricao());
        self::assertSame($this->pessoa->getId(), $evento->getCriador()?->getId());
        self::assertSame($this->escritorio->getId(), $evento->getTenant()?->getId());
        self::assertSame([$colega->getId()], array_map(static fn (User $u) => $u->getId(), $evento->getParticipantes()->toArray()));
        self::assertSame(['compromisso' => [$evento->getId()]], $resposta->ids);
    }

    #[TestDox('salvar: sem horário é dia inteiro; "Só eu" não avisa ninguém')]
    public function testCriarDiaInteiroESoEu(): void
    {
        $resposta = $this->executar(['acao' => 'salvar', 'titulo' => 'Dia de estudo', 'data' => $this->dia(), 'visibilidade' => 'so_eu']);

        $evento = $this->evento((int) $resposta->dados['compromisso_id']);
        self::assertTrue($evento->isDiaInteiro());
        self::assertSame(Evento::VISIBILIDADE_SOMENTE_EU, $evento->getVisibilidade());
    }

    #[TestDox('editar é gravar por cima: vira proposta, não grava antes do confirmar e o campo omitido fica como está')]
    public function testEditarPorProposta(): void
    {
        $evento = $this->criar('Audiência antiga', 14, 60);

        $proposta = $this->executar(['acao' => 'salvar', 'compromisso_id' => $evento->getId(), 'titulo' => 'Audiência nova']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('"Audiência antiga"', $proposta->dados['proposta']['resumo']);
        self::assertStringContainsString('"Audiência nova"', $proposta->dados['proposta']['resumo']);
        self::assertSame('Audiência antiga', $this->evento((int) $evento->getId())->getTitulo(), 'a proposta não grava');

        $confirmada = $this->confirmar($proposta);

        self::assertSame('confirmada', $confirmada->dados['estado']);
        self::assertSame(GravarNaAgendaFerramenta::AVISO_DO_GOOGLE, $confirmada->dados['resultado']['google']);
        $gravado = $this->evento((int) $evento->getId());
        self::assertSame('Audiência nova', $gravado->getTitulo());
        self::assertSame($this->dia() . ' 14:00', $gravado->getDataInicio()?->format('Y-m-d H:i'), 'a hora não mudou');
        self::assertSame($this->dia() . ' 15:00', $gravado->getDataFim()?->format('Y-m-d H:i'), 'a duração se manteve');
    }

    #[TestDox('editar: o resumo cita cada campo que muda (de antes para depois) e quem entra e quem sai; participante trocado depois da proposta recusa')]
    public function testEditarCitaTudoEReconferePartipantes(): void
    {
        $ana = $this->colega('Ana Souza');
        $bia = $this->colega('Bia Lima');
        $cris = $this->colega('Cris Dias');
        $evento = $this->criar('Reunião', 14, 60);
        $this->em()->getConnection()->executeStatement('INSERT INTO evento_participante (evento_id, user_id) VALUES (?, ?)', [$evento->getId(), $ana->getId()]);
        $this->limpar();

        $proposta = $this->executar([
            'acao' => 'salvar', 'compromisso_id' => $evento->getId(), 'tipo' => 'aud', 'lembrete_minutos' => 60,
            'observacao' => 'Levar a procuração', 'participantes' => [$bia->getId()], 'visibilidade' => 'so_eu',
        ]);

        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertStringContainsString('tipo de compromisso para audiência', $resumo);
        self::assertStringContainsString('lembrete de 30 min antes para 60 min antes', $resumo);
        self::assertStringContainsString('observação de vazia para "Levar a procuração"', $resumo);
        self::assertStringContainsString('visibilidade de para a equipe para só para você', $resumo);
        self::assertStringContainsString('entram "Bia Lima"; saem "Ana Souza"', $resumo);
        self::assertStringNotContainsString('Cris Dias', $resumo);

        $this->em()->getConnection()->executeStatement('DELETE FROM evento_participante WHERE evento_id = ?', [$evento->getId()]);
        $this->em()->getConnection()->executeStatement('INSERT INTO evento_participante (evento_id, user_id) VALUES (?, ?)', [$evento->getId(), $cris->getId()]);
        $this->limpar();

        $this->assertConfirmaRecusa($proposta, GravarNaAgendaFerramenta::MOTIVO_MUDOU, GravarNaAgendaFerramenta::MUDOU);
        self::assertNull($this->evento((int) $evento->getId())->getDescricao(), 'a edição não gravou');
    }

    #[TestDox('editar: qualquer campo que a proposta sobrescreve e que mudou por fora (observação, tipo, lembrete, pasta) recusa, mesmo sem mexer no título')]
    public function testEditarCampoQueMudouPorFora(): void
    {
        foreach (["observacao = 'mexida'", "lembrete_minutos = 10", "tipo = 'urg'"] as $mudanca) {
            $evento = $this->criar('Campo ' . $mudanca, 14, 60);
            $proposta = $this->executar(['acao' => 'salvar', 'compromisso_id' => $evento->getId(), 'titulo' => 'Novo ' . $mudanca]);
            $this->em()->getConnection()->executeStatement('UPDATE evento SET ' . str_replace('observacao', 'descricao', $mudanca) . ' WHERE id = ?', [$evento->getId()]);
            $this->limpar();

            $this->assertConfirmaRecusa($proposta, GravarNaAgendaFerramenta::MOTIVO_MUDOU, GravarNaAgendaFerramenta::MUDOU);
        }
    }

    #[TestDox('id só como inteiro, como diz o esquema: o texto "1" é pedido inválido')]
    public function testIdEmTextoERecusado(): void
    {
        $evento = $this->criar('Meu', 14, 60);

        $this->assertMotivo(['acao' => 'salvar', 'compromisso_id' => (string) $evento->getId(), 'titulo' => 'X'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'status', 'compromisso_id' => (string) $evento->getId(), 'status' => 'concluido'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'avisar', 'compromisso_id' => $evento->getId(), 'colegas' => ['1']], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'salvar', 'titulo' => 'X', 'data' => $this->dia(), 'pasta_id' => '1'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        self::assertSame('agendado', $this->evento((int) $evento->getId())->getStatus());
    }

    #[TestDox('editar: trocar só a hora de início mantém a duração; compromisso que se repete continua se repetindo')]
    public function testEditarMantemDuracaoERepeticao(): void
    {
        $evento = $this->criar('Reunião semanal', 9, 90);
        $this->em()->getConnection()->executeStatement("UPDATE evento SET recorrente = true, tipo_recorrencia = 'semanal' WHERE id = ?", [$evento->getId()]);
        $this->limpar();

        $proposta = $this->executar(['acao' => 'salvar', 'compromisso_id' => $evento->getId(), 'inicio' => '10:00']);
        $this->confirmar($proposta);

        $gravado = $this->evento((int) $evento->getId());
        self::assertSame($this->dia() . ' 11:30', $gravado->getDataFim()?->format('Y-m-d H:i'));
        self::assertTrue($gravado->isRecorrente());
        self::assertSame('semanal', $gravado->getTipoRecorrencia());
    }

    #[TestDox('editar: o compromisso mudou depois da proposta, a confirmação é recusada e nada grava')]
    public function testEditarQueMudou(): void
    {
        $evento = $this->criar('Antes', 14, 60);
        $proposta = $this->executar(['acao' => 'salvar', 'compromisso_id' => $evento->getId(), 'titulo' => 'Depois']);

        $this->em()->getConnection()->executeStatement("UPDATE evento SET titulo = 'Mudou por fora' WHERE id = ?", [$evento->getId()]);
        $this->limpar();

        $this->assertConfirmaRecusa($proposta, GravarNaAgendaFerramenta::MOTIVO_MUDOU, GravarNaAgendaFerramenta::MUDOU);
        self::assertSame('Mudou por fora', $this->evento((int) $evento->getId())->getTitulo());
    }

    #[TestDox('mover: vira proposta, só grava depois do confirmar e mantém a duração; mudou ou se repete, recusa')]
    public function testMover(): void
    {
        $evento = $this->criar('Para mover', 14, 60);
        $novoDia = $this->amanha->modify('+3 days')->format('Y-m-d');

        $proposta = $this->executar(['acao' => 'mover', 'compromisso_id' => $evento->getId(), 'data' => $novoDia, 'hora' => '16:00']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertSame($this->dia() . ' 14:00', $this->evento((int) $evento->getId())->getDataInicio()?->format('Y-m-d H:i'), 'a proposta não grava');
        $confirmada = $this->confirmar($proposta);
        self::assertSame($novoDia, $confirmada->dados['resultado']['data']);
        $movido = $this->evento((int) $evento->getId());
        self::assertSame($novoDia . ' 16:00', $movido->getDataInicio()?->format('Y-m-d H:i'));
        self::assertSame($novoDia . ' 17:00', $movido->getDataFim()?->format('Y-m-d H:i'));

        $outra = $this->executar(['acao' => 'mover', 'compromisso_id' => $evento->getId(), 'data' => $this->dia()]);
        $this->em()->getConnection()->executeStatement("UPDATE evento SET titulo = 'Mudou por fora' WHERE id = ?", [$evento->getId()]);
        $this->limpar();
        $this->assertConfirmaRecusa($outra, GravarNaAgendaFerramenta::MOTIVO_MUDOU, GravarNaAgendaFerramenta::MUDOU);

        $this->em()->getConnection()->executeStatement("UPDATE evento SET recorrente = true WHERE id = ?", [$evento->getId()]);
        $this->limpar();
        $this->assertMotivo(['acao' => 'mover', 'compromisso_id' => $evento->getId(), 'data' => $this->dia()], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    #[TestDox('status: concluir, cancelar e reabrir gravam na hora; cancelar avisa o participante, como a tela')]
    public function testStatus(): void
    {
        $colega = $this->colega();
        $evento = $this->criar('Com participante', 14, 60);
        $this->em()->getConnection()->executeStatement('INSERT INTO evento_participante (evento_id, user_id) VALUES (?, ?)', [$evento->getId(), $colega->getId()]);
        $this->limpar();

        $concluido = $this->executar(['acao' => 'status', 'compromisso_id' => $evento->getId(), 'status' => 'concluido']);
        self::assertSame('gravado', $concluido->dados['estado']);
        self::assertTrue($concluido->dados['mudou']);
        self::assertSame(GravarNaAgendaFerramenta::AVISO_DO_GOOGLE, $concluido->dados['google']);
        self::assertSame('concluido', $this->evento((int) $evento->getId())->getStatus());

        $this->executar(['acao' => 'status', 'compromisso_id' => $evento->getId(), 'status' => 'agendado']);
        self::assertSame('agendado', $this->evento((int) $evento->getId())->getStatus());

        $this->executar(['acao' => 'status', 'compromisso_id' => $evento->getId(), 'status' => 'cancelado']);
        self::assertSame('cancelado', $this->evento((int) $evento->getId())->getStatus());
        self::assertSame(1, $this->em()->getRepository(Notificacao::class)->count(['usuario' => $colega->getId(), 'tipo' => Notificacao::TIPO_EVENTO_CANCELADO]));
    }

    #[TestDox('status excluido: vira proposta, o compromisso só some depois do confirmar')]
    public function testExcluir(): void
    {
        $evento = $this->criar('Para excluir', 14, 60);

        $proposta = $this->executar(['acao' => 'status', 'compromisso_id' => $evento->getId(), 'status' => 'excluido']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('Não há como desfazer', $proposta->dados['proposta']['resumo']);
        self::assertNotNull($this->evento((int) $evento->getId()), 'a proposta não exclui');

        $confirmada = $this->confirmar($proposta);

        self::assertSame('excluir', $confirmada->dados['resultado']['operacao']);
        $this->limpar();
        self::assertNull($this->em()->find(Evento::class, $evento->getId()));
    }

    #[TestDox('excluir: o compromisso mudou depois da proposta, a confirmação é recusada e ele continua')]
    public function testExcluirQueMudou(): void
    {
        $evento = $this->criar('Para excluir', 14, 60);
        $proposta = $this->executar(['acao' => 'status', 'compromisso_id' => $evento->getId(), 'status' => 'excluido']);
        $this->em()->getConnection()->executeStatement("UPDATE evento SET titulo = 'Mudou por fora' WHERE id = ?", [$evento->getId()]);
        $this->limpar();

        $this->assertConfirmaRecusa($proposta, GravarNaAgendaFerramenta::MOTIVO_MUDOU, GravarNaAgendaFerramenta::MUDOU);
        self::assertSame('Mudou por fora', $this->evento((int) $evento->getId())->getTitulo());
    }

    #[TestDox('avisar: grava o aviso do sino do colega, direto, e quem já foi avisado não recebe de novo')]
    public function testAvisarCompromisso(): void
    {
        $colega = $this->colega();
        $evento = $this->criar('Para avisar', 14, 60);

        $primeira = $this->executar(['acao' => 'avisar', 'compromisso_id' => $evento->getId(), 'colegas' => [$colega->getId()]]);
        $segunda = $this->executar(['acao' => 'avisar', 'compromisso_id' => $evento->getId(), 'colegas' => [$colega->getId()]]);

        self::assertSame(1, $primeira->dados['avisados']);
        self::assertSame(0, $segunda->dados['avisados']);
        self::assertSame(1, $this->em()->getRepository(Notificacao::class)->count(['usuario' => $colega->getId(), 'tipo' => Notificacao::TIPO_EVENTO_CRIADO]));
    }

    #[TestDox('avisar prazo: avisa o colega; prazo de pasta trancada ou de outro escritório é não encontrado')]
    public function testAvisarPrazo(): void
    {
        $colega = $this->colega();
        $pasta = $this->pasta();
        $prazo = $this->prazo($this->escritorio, $pasta);

        $resposta = $this->executar(['acao' => 'avisar', 'prazo_id' => $prazo->getId(), 'colegas' => [$colega->getId()]]);
        self::assertSame(1, $resposta->dados['avisados']);

        $alheio = $this->prazo($this->escritorio(), $this->pasta($this->escritorio()));
        $this->assertMotivo(['acao' => 'avisar', 'prazo_id' => $alheio->getId(), 'colegas' => [$colega->getId()]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);

        $trancada = $this->pasta(trancada: true);
        $doTrancada = $this->prazo($this->escritorio, $trancada);
        $this->assertMotivo(['acao' => 'avisar', 'prazo_id' => $doTrancada->getId(), 'colegas' => [$colega->getId()]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('teto: compromisso criado por outra pessoa é não encontrado em editar, mover, status e excluir, mesmo para o administrador')]
    public function testCompromissoDeOutraPessoa(): void
    {
        $admin = $this->pessoa();
        $this->vinculo($admin, $this->escritorio, sistema: true, permissoes: [...self::PERMISSOES, 'admin.users.manage']);
        $this->ligar($admin);
        $doColega = $this->criar('Do colega', 14, 60, $this->colega());
        $nao = ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO;

        foreach ([[$admin, 'o administrador'], [$this->pessoa, 'a criadora de outro compromisso']] as [$quem]) {
            $this->assertMotivo(['acao' => 'salvar', 'compromisso_id' => $doColega->getId(), 'titulo' => 'X'], $nao, $quem);
            $this->assertMotivo(['acao' => 'mover', 'compromisso_id' => $doColega->getId(), 'data' => $this->dia()], $nao, $quem);
            $this->assertMotivo(['acao' => 'status', 'compromisso_id' => $doColega->getId(), 'status' => 'concluido'], $nao, $quem);
            $this->assertMotivo(['acao' => 'status', 'compromisso_id' => $doColega->getId(), 'status' => 'excluido'], $nao, $quem);
        }
        $this->limpar();
        self::assertSame('Do colega', $this->evento((int) $doColega->getId())->getTitulo());
        self::assertSame('agendado', $this->evento((int) $doColega->getId())->getStatus());
    }

    #[TestDox('teto: a proposta de um compromisso cujo criador mudou não se confirma (não encontrado)')]
    public function testCriadorMudouDepoisDaProposta(): void
    {
        $evento = $this->criar('Meu', 14, 60);
        $proposta = $this->executar(['acao' => 'status', 'compromisso_id' => $evento->getId(), 'status' => 'excluido']);
        $this->em()->getConnection()->executeStatement('UPDATE evento SET criador_id = ? WHERE id = ?', [$this->colega()->getId(), $evento->getId()]);
        $this->limpar();

        $this->assertConfirmaRecusa($proposta, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, ErroDaFerramenta::NAO_ENCONTRADO);
        self::assertNotNull($this->evento((int) $evento->getId()));
    }

    #[TestDox('outro escritório: compromisso e prazo de outro escritório são não encontrado, e nada grava')]
    public function testOutroEscritorio(): void
    {
        $outro = $this->escritorio();
        $alheio = $this->criar('De outro escritório', 14, 60, $this->pessoa, $outro);

        $this->assertMotivo(['acao' => 'salvar', 'compromisso_id' => $alheio->getId(), 'titulo' => 'X'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'mover', 'compromisso_id' => $alheio->getId(), 'data' => $this->dia()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'status', 'compromisso_id' => $alheio->getId(), 'status' => 'cancelado'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'avisar', 'compromisso_id' => $alheio->getId(), 'colegas' => [$this->pessoa->getId()]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT titulo, status FROM evento WHERE id = ?', [$alheio->getId()]);
        self::assertSame(['titulo' => 'De outro escritório', 'status' => 'agendado'], $linha);
    }

    #[TestDox('participante e avisado de fora da equipe (outro escritório) são recusados, sem gravar nada')]
    public function testParticipanteForaDaEquipe(): void
    {
        $deFora = $this->pessoa();
        $this->vinculo($deFora, $this->escritorio());
        $evento = $this->criar('Meu', 14, 60);
        $antes = $this->em()->getRepository(Evento::class)->count([]);

        try {
            $this->executar(['acao' => 'salvar', 'titulo' => 'Novo', 'data' => $this->dia(), 'participantes' => [$deFora->getId()]]);
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $e->motivo);
            self::assertSame(GravarNaAgendaFerramenta::FORA_DA_EQUIPE, $e->getMessage());
        }
        self::assertSame($antes, $this->em()->getRepository(Evento::class)->count([]));
        $this->assertMotivo(['acao' => 'salvar', 'compromisso_id' => $evento->getId(), 'participantes' => [$deFora->getId()]], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'avisar', 'compromisso_id' => $evento->getId(), 'colegas' => [$deFora->getId()]], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        self::assertSame(0, $this->em()->getRepository(Notificacao::class)->count(['usuario' => $deFora->getId()]));
    }

    #[TestDox('pasta: a que a pessoa não vê (cadeado), a de outro escritório e a em segredo de justiça não se ligam ao compromisso')]
    public function testPastaQueNaoSai(): void
    {
        $base = ['acao' => 'salvar', 'titulo' => 'Com pasta', 'data' => $this->dia()];

        $this->assertMotivo($base + ['pasta_id' => $this->pasta(trancada: true)->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo($base + ['pasta_id' => $this->pasta($this->escritorio())->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo($base + ['pasta_id' => $this->pasta(nivelSigilo: 1)->getId()], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
        $this->assertMotivo($base + ['pasta_id' => 99999999], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertSame(0, $this->em()->getRepository(Evento::class)->count(['titulo' => 'Com pasta']));
    }

    #[TestDox('pasta: compromisso já ligado a pasta que ficou trancada para a pessoa é não encontrado em mover, status e avisar')]
    public function testCompromissoDePastaTrancada(): void
    {
        $pasta = $this->pasta(trancada: true);
        $evento = $this->criar('Na pasta trancada', 14, 60, pasta: $pasta);

        $this->assertMotivo(['acao' => 'mover', 'compromisso_id' => $evento->getId(), 'data' => $this->dia()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'status', 'compromisso_id' => $evento->getId(), 'status' => 'concluido'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'avisar', 'compromisso_id' => $evento->getId(), 'colegas' => [$this->colega()->getId()]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('pasta: sem o módulo Pastas, ligar pasta ao compromisso é recusado')]
    public function testSemOModuloPastas(): void
    {
        $sem = $this->pessoa();
        $this->vinculo($sem, $this->escritorio, permissoes: ['modules.agenda.view']);
        $this->ligar($sem);
        $pasta = $this->pasta();

        $this->assertMotivo(['acao' => 'salvar', 'titulo' => 'X', 'data' => $this->dia(), 'pasta_id' => $pasta->getId()], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $sem);
    }

    #[TestDox('sem o módulo agenda ou sem o escopo mcp:escrita a ferramenta recusa, mesmo na ação direta, e nada grava')]
    public function testSemPermissaoESemEscopo(): void
    {
        $sem = $this->pessoa();
        $this->vinculo($sem, $this->escritorio, permissoes: ['modules.pastas.view']);
        $this->ligar($sem);
        $this->assertMotivo(['acao' => 'salvar', 'titulo' => 'X', 'data' => $this->dia()], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $sem);

        try {
            static::getContainer()->get(GravarNaAgendaFerramenta::class)->executar(['acao' => 'salvar', 'titulo' => 'X', 'data' => $this->dia()], $this->chamada($this->pessoa, ['mcp:leitura']));
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $e->motivo);
        }
        self::assertSame(0, $this->em()->getRepository(Evento::class)->count(['titulo' => 'X']));
    }

    #[TestDox('esquema fechado: campo fora da ação, tipo errado, data inexistente, hora ruim e título vazio são pedido inválido')]
    public function testEntradaInvalida(): void
    {
        $invalido = ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA;
        $evento = $this->criar('Meu', 14, 60);

        $this->assertMotivo(['acao' => 'salvar', 'titulo' => 'X', 'data' => $this->dia(), 'de_inventado' => 1], $invalido);
        $this->assertMotivo(['acao' => 'salvar', 'titulo' => 'X'], $invalido);
        $this->assertMotivo(['acao' => 'salvar', 'titulo' => '  ', 'data' => $this->dia()], $invalido);
        $this->assertMotivo(['acao' => 'salvar', 'titulo' => str_repeat('a', 256), 'data' => $this->dia()], $invalido);
        $this->assertMotivo(['acao' => 'salvar', 'titulo' => 'X', 'data' => '2026-02-30'], $invalido);
        $this->assertMotivo(['acao' => 'salvar', 'titulo' => 'X', 'data' => $this->dia(), 'inicio' => '25:00'], $invalido);
        $this->assertMotivo(['acao' => 'salvar', 'titulo' => 'X', 'data' => $this->dia(), 'tipo' => 'inventado'], $invalido);
        $this->assertMotivo(['acao' => 'salvar', 'titulo' => 'X', 'data' => $this->dia(), 'lembrete_minutos' => 7], $invalido);
        $this->assertMotivo(['acao' => 'salvar', 'titulo' => 'X', 'data' => $this->dia(), 'participantes' => 'todos'], $invalido);
        $this->assertMotivo(['acao' => 'salvar', 'titulo' => 'X', 'data' => $this->dia(), 'inicio' => '10:00', 'fim' => '09:00'], $invalido);
        $this->assertMotivo(['acao' => 'status', 'compromisso_id' => $evento->getId(), 'status' => 'apagado'], $invalido);
        $this->assertMotivo(['acao' => 'avisar', 'colegas' => [1]], $invalido);
        $this->assertMotivo(['acao' => 'avisar', 'compromisso_id' => $evento->getId(), 'prazo_id' => 1, 'colegas' => [1]], $invalido);
        $this->assertMotivo(['acao' => 'excluir'], $invalido);
        self::assertSame(0, $this->em()->getRepository(Evento::class)->count(['titulo' => 'X']));
    }

    private function dia(): string
    {
        return $this->amanha->format('Y-m-d');
    }

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos, ?User $quem = null): RespostaDaFerramenta
    {
        return static::getContainer()->get(GravarNaAgendaFerramenta::class)->executar($argumentos, $this->chamada($quem ?? $this->pessoa));
    }

    private function confirmar(RespostaDaFerramenta $proposta): RespostaDaFerramenta
    {
        return static::getContainer()->get(ConfirmarAcaoFerramenta::class)->executar([
            'proposta_id' => $proposta->dados['proposta']['proposta_id'],
            'resumo' => $proposta->dados['proposta']['resumo'],
        ], $this->chamada($this->pessoa));
    }

    private function assertConfirmaRecusa(RespostaDaFerramenta $proposta, string $motivo, string $mensagem): void
    {
        try {
            $this->confirmar($proposta);
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);
            self::assertSame($mensagem, $e->getMessage());
        }
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo, ?User $quem = null): void
    {
        try {
            $this->executar($argumentos, $quem);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo, $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());

            return;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    /** @param list<string> $escopos */
    private function chamada(User $pessoa, array $escopos = ['mcp:leitura', 'mcp:escrita']): ChamadaDoConector
    {
        static::getContainer()->get('services_resetter')->reset();
        $this->reancorar();
        $contexto = $this->portao()->abrir((int) $pessoa->getId(), (int) $this->escritorio->getId());
        $consentimento = $this->consentimentoDe($pessoa);

        return new ChamadaDoConector($contexto, 41, (int) $consentimento->getId(), 'claude.ai', $escopos, new \DateTimeImmutable('+7 days'));
    }

    private function consentimentoDe(User $pessoa): ConsentimentoDoConector
    {
        $existente = $this->em()->getRepository(ConsentimentoDoConector::class)->findOneBy(['user' => $pessoa, 'tenant' => $this->escritorio]);
        if ($existente instanceof ConsentimentoDoConector) {
            return $existente;
        }
        $consentimento = new ConsentimentoDoConector($this->ref($pessoa), $this->escritorio, $this->app, ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable());
        $this->em()->persist($consentimento);
        $this->em()->flush();

        return $consentimento;
    }

    /** Liga o conector para a pessoa no escritório do cenário. */
    private function ligar(User $pessoa): void
    {
        $doConector = $this->em()->getRepository(EscritorioDoConector::class)->findOneBy(['tenant' => $this->escritorio]);
        self::assertInstanceOf(EscritorioDoConector::class, $doConector);
        $doConector->ligar($this->ref($pessoa), new \DateTimeImmutable());
        $this->em()->flush();
    }

    private function colega(string $nome = 'Pessoa do Conector'): User
    {
        $colega = $this->pessoa();
        $colega->setFullName($nome);
        $this->vinculo($colega, $this->escritorio, permissoes: self::PERMISSOES);

        return $colega;
    }

    /** Solta o que o EntityManager guarda e volta a apontar o cenário (escritório, pessoa, app) para o que está no banco. */
    private function limpar(): void
    {
        $this->em()->clear();
        $this->reancorar();
    }

    /** O reset dos serviços (a cada chamada) também limpa o EntityManager: o cenário volta como referência ao banco. */
    private function reancorar(): void
    {
        $this->escritorio = $this->em()->getReference(Tenant::class, $this->ids['escritorio']);
        $this->pessoa = $this->em()->getReference(User::class, $this->ids['pessoa']);
        $this->app = $this->em()->getReference(ClienteDoConector::class, $this->ids['app']);
        $this->consentimento = $this->em()->getReference(ConsentimentoDoConector::class, $this->ids['consentimento']);
    }

    /** @template T of object @param T $objeto @return T */
    private function ref(object $objeto): object
    {
        return $this->em()->getReference($objeto::class, $objeto->getId());
    }

    private function evento(int $id): Evento
    {
        $this->limpar();
        $evento = $this->em()->find(Evento::class, $id);
        self::assertInstanceOf(Evento::class, $evento);

        return $evento;
    }

    private function criar(string $titulo, int $hora, int $minutos, ?User $criador = null, ?Tenant $escritorio = null, ?Pasta $pasta = null): Evento
    {
        $inicio = $this->amanha->setTime($hora, 0);
        $evento = (new Evento())
            ->setTitulo($titulo)
            ->setDataInicio($inicio)
            ->setDataFim($inicio->modify('+' . $minutos . ' minutes'))
            ->setCriador($this->ref($criador ?? $this->pessoa))
            ->setTenant($this->ref($escritorio ?? $this->escritorio))
            ->setPasta($pasta === null ? null : $this->ref($pasta))
            ->setLembreteMinutos(30);
        $this->em()->persist($evento);
        $this->em()->flush();

        return $evento;
    }

    private function prazo(Tenant $escritorio, Pasta $pasta): PastaPrazo
    {
        $prazo = new PastaPrazo($this->ref($escritorio), $this->ref($pasta), null);
        $prazo->definir(PastaPrazo::TIPO_PRAZO, 'Contestar', $this->amanha, null, null);
        $this->em()->persist($prazo);
        $this->em()->flush();

        return $prazo;
    }

    private function pasta(?Tenant $escritorio = null, int $nivelSigilo = 0, bool $trancada = false): Pasta
    {
        $escritorio = $this->ref($escritorio ?? $this->escritorio);
        $pasta = (new Pasta())->setNup('AGW-' . uniqid());
        $pasta->setTenant($escritorio);
        $this->em()->persist($pasta);
        $processo = new Processo();
        $processo->setNumeroProcesso('07011345720258070001' . uniqid());
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($escritorio);
        $processo->setNivelSigilo($nivelSigilo);
        $this->em()->persist($processo);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        if ($trancada) {
            $this->em()->persist(new CadeadoDaPasta($escritorio, $pasta, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        }
        $this->em()->flush();

        return $pasta;
    }
}
