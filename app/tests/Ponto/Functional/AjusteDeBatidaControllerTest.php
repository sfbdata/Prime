<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\AjusteDeBatidaController;
use App\Ponto\DTO\DesconsiderarBatidaInput;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Exception\AjusteDeBatidaRecusadoException;
use App\Ponto\UseCase\DesconsiderarBatidaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * As quatro portas do ajuste de batida na ficha do colaborador (E10, plano-e10-ponto §3 e §5): incluir, ajustar,
 * desconsiderar e restaurar. Cobre o CSRF de cada uma, a guarda P43 (o gestor pelo perfil não corrige a própria
 * batida: 403), a batida de outro escritório (404), o registro original que continua no banco, o ajuste gravado com o
 * evento na trilha, os avisos de cada desfecho e a linha "Desconsideradas" com o Restaurar (sem ele na desconsideração
 * da análise, decisão A).
 *
 * As batidas ficam em outubro de 2026, um mês já passado; a ficha é aberta nessa competência.
 */
#[Group('ponto')]
#[CoversClass(AjusteDeBatidaController::class)]
final class AjusteDeBatidaControllerTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const COMPETENCIA = '2026-10';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $primario;
    private User $colaborador;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();

        $this->escritorio = $this->escritorio('Escritório do ajuste');
        $this->primario = $this->pessoa('primario_ajuste');
        $this->vincularPrimario($this->primario, $this->escritorio);
        $this->colaborador = $this->pessoa('colaborador_ajuste');
        $this->vincularColaborador($this->colaborador, $this->escritorio);
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
    }

    // ------------------------------------------------------------------------------------------------- Incluir

    #[TestDox('Incluir: a batida entra como inclusão, com o motivo e o evento na trilha; volta à aba Ponto da competência')]
    public function testIncluirGravaAInclusao(): void
    {
        $this->incluir($this->colaborador, ['data' => '2026-10-21', 'hora' => '18:00:00', 'tipo' => 'saida', 'motivo' => 'Esqueceu de bater a saída.']);

        $this->assertVoltouParaAFicha($this->colaborador);
        self::assertSame(['Batida incluída na folha.'], $this->avisos('success'));
        self::assertSame([['tipo' => 'inclusao', 'motivo' => 'Esqueceu de bater a saída.', 'data_hora_nova' => '2026-10-21 18:00:00']], $this->ajustes($this->colaborador));
        self::assertSame('batida_incluida', $this->ultimaAcaoNaTrilha());
    }

    #[TestDox('Incluir sem motivo: o aviso pede o motivo e nada é gravado')]
    public function testIncluirSemMotivo(): void
    {
        $this->incluir($this->colaborador, ['data' => '2026-10-21', 'hora' => '18:00:00', 'tipo' => 'saida', 'motivo' => '']);

        self::assertResponseRedirects();
        self::assertSame([AjusteDeBatidaRecusadoException::MENSAGEM_SEM_MOTIVO], $this->avisos('danger'));
        self::assertSame([], $this->ajustes($this->colaborador));
    }

    // ------------------------------------------------------------------------------------------------- Ajustar

    #[TestDox('A página de ajuste mostra a batida atual no formulário, com a cabeça vazia (nunca ajustada)')]
    public function testPaginaDeAjuste(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');

        $crawler = $this->client->request('GET', $this->ficha($this->colaborador) . "/ponto/b{$id}/ajustar?competencia=" . self::COMPETENCIA);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ajustar batida');
        self::assertSame('2026-10-21', $crawler->filter('#registro_ponto_manual_data')->attr('value'));
        self::assertSame('', $crawler->filter('input[name="cabeca"]')->attr('value'));
        self::assertCount(1, $crawler->filter('textarea[name="registro_ponto_manual[motivo]"][required]'));
    }

    #[TestDox('Ajustar: a alteração entra com o evento na trilha, o registro original continua no banco e o aviso confirma')]
    public function testAjustarGravaAAlteracao(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');

        $this->ajustar($this->colaborador, "b{$id}", '', '18:30:00');

        $this->assertVoltouParaAFicha($this->colaborador);
        self::assertSame(['Batida ajustada. O registro original fica guardado.'], $this->avisos('success'));
        self::assertSame([['tipo' => 'alteracao', 'motivo' => 'Bateu atrasado.', 'data_hora_nova' => '2026-10-21 18:30:00']], $this->ajustes($this->colaborador));
        self::assertSame('2026-10-21 18:00:00', $this->registroOriginal($id));
        self::assertSame('batida_ajustada', $this->ultimaAcaoNaTrilha());
    }

    #[TestDox('Ajustar com a cabeça velha (outro ajuste entrou antes): volta à ficha com o aviso "mudou" e nada é gravado')]
    public function testAjustarComACabecaVelha(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');
        $this->ajustar($this->colaborador, "b{$id}", '', '18:30:00');

        $this->ajustar($this->colaborador, "b{$id}", '', '19:00:00');

        $this->assertVoltouParaAFicha($this->colaborador);
        self::assertSame([AjusteDeBatidaRecusadoException::MENSAGEM_MUDOU], $this->avisos('danger'));
        self::assertCount(1, $this->ajustes($this->colaborador));
    }

    #[TestDox('C5: a batida de trabalho externo em análise volta à ficha com o aviso ao ajustar e ao desconsiderar, e nada é gravado')]
    public function testBatidaComJustificativaEmAnalise(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');
        $justificativa = $this->justificativa($this->colaborador, $this->escritorio, new \DateTimeImmutable('2026-10-21'), 'pendente', ['tipo' => 'trabalho_externo']);
        $justificativa->setRegistroPonto($this->em()->getReference(RegistroPonto::class, $id));
        $this->em()->flush();

        $this->ajustar($this->colaborador, "b{$id}", '', '18:30:00');
        $this->assertVoltouParaAFicha($this->colaborador);
        self::assertSame([AjusteDeBatidaRecusadoException::MENSAGEM_EM_ANALISE], $this->avisos('danger'));

        $this->desconsiderar($this->colaborador, "b{$id}", 'Batida em dobro.');
        $this->assertVoltouParaAFicha($this->colaborador);
        // O redirect não é seguido: o aviso do ajuste ainda está na sessão, e o do desconsiderar entra depois dele.
        self::assertSame(array_fill(0, 2, AjusteDeBatidaRecusadoException::MENSAGEM_EM_ANALISE), $this->avisos('danger'));
        self::assertSame([], $this->ajustes($this->colaborador));
    }

    #[TestDox('C5: a página "Ajustar batida" nem abre para a batida com a justificativa em análise: volta à ficha com o aviso')]
    public function testPaginaDoAjusteNaoAbreComJustificativaEmAnalise(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');
        $justificativa = $this->justificativa($this->colaborador, $this->escritorio, new \DateTimeImmutable('2026-10-21'), 'pendente', ['tipo' => 'trabalho_externo']);
        $justificativa->setRegistroPonto($this->em()->getReference(RegistroPonto::class, $id));
        $this->em()->flush();

        $this->client->request('GET', $this->ficha($this->colaborador) . "/ponto/b{$id}/ajustar");

        $this->assertVoltouParaAFicha($this->colaborador);
        self::assertSame([AjusteDeBatidaRecusadoException::MENSAGEM_EM_ANALISE], $this->avisos('danger'));
    }

    #[TestDox('Ajustar sem motivo: a página volta com o erro no campo e nada é gravado')]
    public function testAjustarSemMotivo(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');

        $this->ajustar($this->colaborador, "b{$id}", '', '18:30:00', motivo: '');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', AjusteDeBatidaRecusadoException::MENSAGEM_SEM_MOTIVO);
        self::assertSame([], $this->ajustes($this->colaborador));
    }

    #[TestDox('A página que volta com erro mantém a cabeça que a tela mandou: com outro ajuste no meio, o envio seguinte é "mudou"')]
    public function testErroDoFormularioMantemACabecaDaTela(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');
        $this->ajustar($this->colaborador, "b{$id}", '', '18:30:00');   // outro ajuste entra depois que a tela abriu

        $crawler = $this->client->request('POST', $this->ficha($this->colaborador) . "/ponto/b{$id}/ajustar", [
            'registro_ponto_manual' => ['data' => '2026-10-21', 'hora' => '19:00:00', 'tipo' => 'saida', 'motivo' => ''],
            'cabeca' => '',
            'competencia' => self::COMPETENCIA,
            '_token' => 'TOKEN_ponto_ajustar',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('', $crawler->filter('input[name="cabeca"]')->attr('value'), 'a cabeça da tela, não a lida agora');

        $this->ajustar($this->colaborador, "b{$id}", (string) $crawler->filter('input[name="cabeca"]')->attr('value'), '19:00:00');

        self::assertSame([AjusteDeBatidaRecusadoException::MENSAGEM_MUDOU], $this->avisos('danger'));
        self::assertCount(1, $this->ajustes($this->colaborador));
    }

    #[TestDox('Ajustar a inclusão de outro escritório da mesma pessoa: 404 e nada é gravado')]
    public function testAjustarInclusaoDeOutroEscritorio(): void
    {
        $outro = $this->escritorio('Outro escritório do ajuste');
        $this->vincularPrimario($this->primario, $outro);
        $this->vincularColaborador($this->colaborador, $outro);
        $this->logarComTenant($this->client, $this->primario, $outro);
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/ponto/add', $outro->getId(), $this->colaborador->getId()), [
            'registro_ponto_manual' => ['data' => '2026-10-21', 'hora' => '18:00:00', 'tipo' => 'saida', 'motivo' => 'No outro escritório.'],
            '_token' => 'TOKEN_ponto_manual_add',
        ]);
        $inclusao = (int) $this->banco()->fetchOne('SELECT id FROM ponto_ajuste WHERE tenant_id = ?', [$outro->getId()]);
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $this->client->request('GET', $this->ficha($this->colaborador) . "/ponto/a{$inclusao}/ajustar");
        self::assertResponseStatusCodeSame(404);
        $this->ajustar($this->colaborador, "a{$inclusao}", '', '18:30:00');
        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->ajustes($this->colaborador), 'só a inclusão do outro escritório');
    }

    // ------------------------------------------------------------------------------------------- Desconsiderar

    #[TestDox('Desconsiderar sem token CSRF: 403 e nada é gravado')]
    public function testDesconsiderarSemToken(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');

        $this->client->request('POST', $this->ficha($this->colaborador) . "/ponto/b{$id}/desconsiderar", ['motivo' => 'Batida em dobro.']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->ajustes($this->colaborador));
    }

    #[TestDox('Desconsiderar: a desconsideração entra com o evento na trilha, o registro original fica e o aviso confirma')]
    public function testDesconsiderarGravaADesconsideracao(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');

        $this->desconsiderar($this->colaborador, "b{$id}", 'Batida em dobro.');

        $this->assertVoltouParaAFicha($this->colaborador);
        self::assertSame(['Batida desconsiderada. O registro original fica guardado.'], $this->avisos('success'));
        self::assertSame([['tipo' => 'desconsideracao', 'motivo' => 'Batida em dobro.', 'data_hora_nova' => null]], $this->ajustes($this->colaborador));
        self::assertSame('2026-10-21 18:00:00', $this->registroOriginal($id));
        self::assertSame('batida_desconsiderada', $this->ultimaAcaoNaTrilha());
    }

    #[TestDox('Desconsiderar sem motivo: o aviso pede o motivo e nada é gravado')]
    public function testDesconsiderarSemMotivo(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');

        $this->desconsiderar($this->colaborador, "b{$id}", '   ');

        self::assertSame([AjusteDeBatidaRecusadoException::MENSAGEM_SEM_MOTIVO], $this->avisos('danger'));
        self::assertSame([], $this->ajustes($this->colaborador));
    }

    #[TestDox('Fora da conta: o "x" da inclusão (a{id}) e o da batida ajustada mandam a cabeça da linhagem e desconsideram de verdade')]
    public function testDesconsiderarPeloForaDaConta(): void
    {
        $this->batida($this->colaborador, 'entrada', '2026-10-21 08:00:00');
        $segunda = $this->batida($this->colaborador, 'entrada', '2026-10-21 10:00:00');
        $this->ajustar($this->colaborador, "b{$segunda}", '', '10:30:00', tipo: 'entrada');
        $this->incluir($this->colaborador, ['data' => '2026-10-21', 'hora' => '11:00:00', 'tipo' => 'entrada', 'motivo' => 'Entrada lançada em dobro.']);
        $alteracao = (int) $this->banco()->fetchOne("SELECT id FROM ponto_ajuste WHERE tipo = 'alteracao' AND user_id = ?", [$this->colaborador->getId()]);
        $inclusao = (int) $this->banco()->fetchOne("SELECT id FROM ponto_ajuste WHERE tipo = 'inclusao' AND user_id = ?", [$this->colaborador->getId()]);

        $botoes = $this->linhaDoDia('2026-10-21')->filter('.batidas-desconsideradas button.pe-mais--excluir');
        self::assertSame(
            [["b{$segunda}", (string) $alteracao], ["a{$inclusao}", (string) $inclusao]],
            $botoes->each(static fn (Crawler $b): array => [basename(\dirname((string) $b->attr('data-acao'))), (string) $b->attr('data-cabeca')]),
            'cada "x" do Fora da conta leva a cabeça da própria linhagem',
        );

        foreach ($botoes->each(static fn (Crawler $b): array => [(string) $b->attr('data-acao'), (string) $b->attr('data-token'), (string) $b->attr('data-cabeca')]) as [$acao, $token, $cabeca]) {
            $this->client->request('POST', $acao, ['_token' => $token, 'cabeca' => $cabeca, 'motivo' => 'Entrada em dobro.', 'competencia' => self::COMPETENCIA]);
            $this->assertVoltouParaAFicha($this->colaborador);
        }

        self::assertSame([], $this->avisos('danger'), 'nenhum "mudou"');
        self::assertSame(array_fill(0, 2, 'Batida desconsiderada. O registro original fica guardado.'), $this->avisos('success'));
        self::assertSame(['alteracao', 'inclusao', 'desconsideracao', 'desconsideracao'], array_column($this->ajustes($this->colaborador), 'tipo'));
        self::assertCount(0, $this->linhaDoDia('2026-10-21')->filter('.batidas-desconsideradas'), 'as duas saíram do Fora da conta');
    }

    // ----------------------------------------------------------------------------------------------- Restaurar

    #[TestDox('Restaurar pela linha "Desconsideradas" da ficha: a batida volta, o aviso confirma e a linha some')]
    public function testRestaurarPelaLinhaDaFicha(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');
        $this->desconsiderar($this->colaborador, "b{$id}", 'Batida em dobro.');
        $botao = $this->linhaDoDia('2026-10-21')->filter('.batidas-fora-por-ajuste .batida-fora-por-ajuste button.pe-mais--restaurar');
        self::assertCount(1, $botao, 'a desconsideração da tela tem o Restaurar');

        $this->client->request('POST', (string) $botao->attr('data-acao'), ['_token' => (string) $botao->attr('data-token'), 'motivo' => 'Era válida.', 'competencia' => self::COMPETENCIA]);

        $this->assertVoltouParaAFicha($this->colaborador);
        self::assertSame(['Batida restaurada.'], $this->avisos('success'));
        self::assertSame(['desconsideracao', 'restauracao'], array_column($this->ajustes($this->colaborador), 'tipo'));
        self::assertSame('ajuste_desfeito', $this->ultimaAcaoNaTrilha());
        self::assertCount(0, $this->linhaDoDia('2026-10-21')->filter('.batidas-fora-por-ajuste'), 'a batida voltou para a conta');
    }

    #[TestDox('O mês que só tem a batida desconsiderada entra na lista da ficha e abre com a linha do dia e o Restaurar')]
    public function testMesSoComDesconsideracaoContinuaNaFicha(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');
        $this->desconsiderar($this->colaborador, "b{$id}", 'Batida em dobro.');

        $crawler = $this->client->request('GET', $this->ficha($this->colaborador) . '/edit-role?tab=ponto');

        self::assertResponseIsSuccessful();
        self::assertSame(self::COMPETENCIA, $crawler->filter('#competencia-ponto-user option[selected]')->attr('value'), 'a ficha abre no mês da desconsideração');
        $linha = $crawler->filter('tr[data-chave-dia="2026-10-21"]');
        self::assertCount(1, $linha, 'a linha do dia existe sem nenhuma batida efetiva');
        self::assertCount(1, $linha->filter('.batidas-fora-por-ajuste .batida-fora-por-ajuste button.pe-mais--restaurar'));
    }

    #[TestDox('Decisão A: a desconsideração da análise aparece sem o Restaurar, com o porquê na dica, e a rota recusa com o aviso')]
    public function testDesconsideracaoDaAnaliseNaoSeRestaura(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');
        $justificativa = $this->justificativa($this->colaborador, $this->escritorio, new \DateTimeImmutable('2026-10-21'), 'rejeitado', ['tipo' => 'trabalho_externo']);
        // A E12 desconsidera assim ao recusar a justificativa (antes de qualquer requisição: as entidades seguem geridas).
        $desconsideracao = static::getContainer()->get(DesconsiderarBatidaUseCase::class)->executar(DesconsiderarBatidaInput::pelaAnalise(
            $this->escritorio,
            $this->colaborador,
            "b{$id}",
            'Trabalho externo recusado.',
            $this->primario,
            $justificativa,
        ));

        $fora = $this->linhaDoDia('2026-10-21')->filter('.batidas-fora-por-ajuste .batida-fora-por-ajuste');
        self::assertCount(1, $fora);
        self::assertCount(0, $fora->filter('button'), 'sem o Restaurar');
        self::assertSame(AjusteDeBatidaRecusadoException::MENSAGEM_LIGADA_A_JUSTIFICATIVA, $fora->attr('title'));

        $this->client->request('POST', $this->ficha($this->colaborador) . "/ponto/ajuste/{$desconsideracao->getId()}/restaurar", ['_token' => "TOKEN_ponto_restaurar_{$desconsideracao->getId()}", 'motivo' => 'Volta.']);

        self::assertSame([AjusteDeBatidaRecusadoException::MENSAGEM_LIGADA_A_JUSTIFICATIVA], $this->avisos('danger'));
        self::assertSame(['desconsideracao'], array_column($this->ajustes($this->colaborador), 'tipo'));
    }

    #[TestDox('Restaurar sem token CSRF: 403; restaurar pela ficha de outro escritório: 404; nada é gravado')]
    public function testRestaurarSemTokenOuDeOutroEscritorio(): void
    {
        $outro = $this->escritorio('Outro escritório do ajuste');
        $this->vincularPrimario($this->primario, $outro);
        $this->vincularColaborador($this->colaborador, $outro);
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');
        $this->desconsiderar($this->colaborador, "b{$id}", 'Batida em dobro.');
        $ajusteId = (int) $this->banco()->fetchOne("SELECT id FROM ponto_ajuste WHERE tipo = 'desconsideracao' AND user_id = ?", [$this->colaborador->getId()]);

        $this->client->request('POST', $this->ficha($this->colaborador) . "/ponto/ajuste/{$ajusteId}/restaurar", ['motivo' => 'Volta.']);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $this->primario, $outro);
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/ponto/ajuste/%d/restaurar', $outro->getId(), $this->colaborador->getId(), $ajusteId), ['_token' => "TOKEN_ponto_restaurar_{$ajusteId}", 'motivo' => 'Volta.']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(['desconsideracao'], array_column($this->ajustes($this->colaborador), 'tipo'));
    }

    // --------------------------------------------------------------------------------------------------- P43

    #[TestDox('P43: o gestor pelo perfil (não primário) recebe 403 ao corrigir a própria batida, nas quatro portas (e na página de ajuste), e nada é gravado')]
    public function testGestorNaoCorrigeAPropriaBatida(): void
    {
        $gestor = $this->pessoa('gestor_ajuste');
        $this->vincularGestor($gestor, $this->escritorio);
        $propria = $this->batida($gestor, 'saida', '2026-10-21 18:00:00');
        // A batida que o primário tirou da conta: o gestor também não a restaura pela própria ficha.
        $this->desconsiderar($gestor, 'b' . $this->batida($gestor, 'entrada', '2026-10-21 08:00:00'), 'Batida em dobro.');
        $desconsideracao = (int) $this->banco()->fetchOne("SELECT id FROM ponto_ajuste WHERE tipo = 'desconsideracao' AND user_id = ?", [$gestor->getId()]);
        $this->logarComTenant($this->client, $gestor, $this->escritorio);

        $tela = $this->client->request('GET', $this->ficha($gestor) . '/edit-role?tab=ponto&competencia=' . self::COMPETENCIA);
        self::assertResponseIsSuccessful();
        self::assertSame(
            AjusteDeBatidaRecusadoException::MENSAGEM_DA_PROPRIA_BATIDA,
            trim($tela->filter('#tab-ponto section.pe-folha > p[data-folha-sem-correcao]')->text()),
            'a aba diz por que os botões somem',
        );
        self::assertCount(0, $tela->filter('#tab-ponto tr[data-chave-dia="2026-10-21"] a, #tab-ponto tr[data-chave-dia="2026-10-21"] button'));

        $this->incluir($gestor, ['data' => '2026-10-21', 'hora' => '08:00:00', 'tipo' => 'entrada', 'motivo' => 'Esqueci.']);
        self::assertResponseStatusCodeSame(403, 'incluir');
        $this->client->request('GET', $this->ficha($gestor) . "/ponto/b{$propria}/ajustar");
        self::assertResponseStatusCodeSame(403, 'abrir o ajuste');
        $this->ajustar($gestor, "b{$propria}", '', '18:30:00');
        self::assertResponseStatusCodeSame(403, 'ajustar');
        $this->desconsiderar($gestor, "b{$propria}", 'Em dobro.');
        self::assertResponseStatusCodeSame(403, 'desconsiderar');
        $this->client->request('POST', $this->ficha($gestor) . "/ponto/ajuste/{$desconsideracao}/restaurar", ['_token' => "TOKEN_ponto_restaurar_{$desconsideracao}", 'motivo' => 'Era minha.', 'competencia' => self::COMPETENCIA]);
        self::assertResponseStatusCodeSame(403, 'restaurar');

        self::assertSame(['desconsideracao'], array_column($this->ajustes($gestor), 'tipo'), 'só a desconsideração do primário');
    }

    #[TestDox('P43: o mesmo gestor corrige a batida do colaborador (a guarda é só da própria)')]
    public function testGestorCorrigeABatidaDoColaborador(): void
    {
        $gestor = $this->pessoa('gestor_ajuste');
        $this->vincularGestor($gestor, $this->escritorio);
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');
        $this->logarComTenant($this->client, $gestor, $this->escritorio);

        $tela = $this->client->request('GET', $this->ficha($this->colaborador) . '/edit-role?tab=ponto&competencia=' . self::COMPETENCIA);
        self::assertCount(0, $tela->filter('[data-folha-sem-correcao]'), 'na ficha do colaborador não há linha do P43');
        self::assertSame('Incluir batida', trim($tela->filter('#modalAddBatidaLabel')->text()));
        self::assertSame('Incluir', trim($tela->filter('#modalAddBatida button[type="submit"]')->text()));

        $this->ajustar($this->colaborador, "b{$id}", '', '18:30:00');

        $this->assertVoltouParaAFicha($this->colaborador);
        self::assertSame(['alteracao'], array_column($this->ajustes($this->colaborador), 'tipo'));
    }

    // ------------------------------------------------------------------------------------- marca "aj" (E10)

    /**
     * Ponta a ponta do desenho (L154, dica L749): depende do bloco A da E10 (a folha monta o dia com os ajustes
     * aplicados e preenche `{tipo}Ajuste`). Até ele entrar, a linha ainda mostra a batida gravada sem a marca.
     */
    #[TestDox('Depois do ajuste, a hora leva a marca "aj" com quem ajustou e quando, na ficha e na tela do colaborador')]
    public function testMarcaAjDepoisDoAjuste(): void
    {
        $id = $this->batida($this->colaborador, 'saida', '2026-10-21 18:00:00');
        $this->ajustar($this->colaborador, "b{$id}", '', '18:30:00');
        $dica = sprintf('Ajustado por %s em %s. Registro original preservado.', $this->primario->getFullName(), (new \DateTimeImmutable('today'))->format('d/m/Y'));

        $naFicha = $this->linhaDoDia('2026-10-21')->filter('td[data-r="Saída"] > span.pe-folha-celula > span.pe-pl.pe-aj');
        self::assertCount(1, $naFicha, 'a marca na ficha, dentro da célula da hora');
        self::assertSame([$dica, $dica], [$naFicha->attr('title'), $naFicha->attr('aria-label')]);

        $this->logarComTenant($this->client, $this->colaborador, $this->escritorio);
        $crawler = $this->client->request('GET', '/ponto/?competencia=' . self::COMPETENCIA);
        $naTela = $crawler->filter('tr[data-chave-dia="2026-10-21"] > td[data-r="Saída"] > span.pe-pl.pe-aj');
        self::assertCount(1, $naTela, 'a marca na tela do colaborador, filha direta da célula');
        self::assertStringContainsString('18:30:00', $naTela->closest('td')?->text() ?? '');
    }

    // ------------------------------------------------------------------------------------------------- apoio

    private function ficha(User $pessoa): string
    {
        return sprintf('/tenant/%d/user/%d', $this->escritorio->getId(), $pessoa->getId());
    }

    /** @param array<string, string> $campos */
    private function incluir(User $pessoa, array $campos): void
    {
        $this->client->request('POST', $this->ficha($pessoa) . '/ponto/add', [
            'registro_ponto_manual' => $campos,
            'competencia' => self::COMPETENCIA,
            '_token' => 'TOKEN_ponto_manual_add',
        ]);
    }

    private function ajustar(User $pessoa, string $alvo, string $cabeca, string $hora, string $motivo = 'Bateu atrasado.', string $tipo = 'saida'): void
    {
        $this->client->request('POST', $this->ficha($pessoa) . "/ponto/{$alvo}/ajustar", [
            'registro_ponto_manual' => ['data' => '2026-10-21', 'hora' => $hora, 'tipo' => $tipo, 'motivo' => $motivo],
            'cabeca' => $cabeca,
            'competencia' => self::COMPETENCIA,
            '_token' => 'TOKEN_ponto_ajustar',
        ]);
    }

    private function desconsiderar(User $pessoa, string $alvo, string $motivo): void
    {
        $this->client->request('POST', $this->ficha($pessoa) . "/ponto/{$alvo}/desconsiderar", [
            'motivo' => $motivo,
            'competencia' => self::COMPETENCIA,
            '_token' => 'TOKEN_ponto_desconsiderar_' . $alvo,
        ]);
    }

    private function assertVoltouParaAFicha(User $pessoa): void
    {
        self::assertResponseRedirects(sprintf('%s/edit-role?competencia=%s&tab=ponto', $this->ficha($pessoa), self::COMPETENCIA));
    }

    /** @return list<string> */
    private function avisos(string $tipo): array
    {
        $sessao = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $sessao);

        return $sessao->getFlashBag()->peek($tipo);
    }

    private function linhaDoDia(string $dia): Crawler
    {
        $crawler = $this->client->request('GET', $this->ficha($this->colaborador) . '/edit-role?tab=ponto&competencia=' . self::COMPETENCIA);
        self::assertResponseIsSuccessful();
        $linha = $crawler->filter(sprintf('tr[data-chave-dia="%s"]', $dia));
        self::assertCount(1, $linha, 'a linha do dia deveria existir na folha');

        return $linha;
    }

    /** @return list<array{tipo: string, motivo: string, data_hora_nova: ?string}> os ajustes da pessoa, na ordem */
    private function ajustes(User $pessoa): array
    {
        /** @var list<array{tipo: string, motivo: string, data_hora_nova: ?string}> */
        return $this->banco()->fetchAllAssociative(
            'SELECT tipo, motivo, data_hora_nova FROM ponto_ajuste WHERE user_id = ? ORDER BY id',
            [$pessoa->getId()],
        );
    }

    private function registroOriginal(int $id): string
    {
        return (string) $this->banco()->fetchOne('SELECT data_hora FROM registro_ponto WHERE id = ?', [$id]);
    }

    private function ultimaAcaoNaTrilha(): string
    {
        return (string) $this->banco()->fetchOne(
            'SELECT acao FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia DESC LIMIT 1',
            [$this->escritorio->getId()],
        );
    }

    /**
     * As leituras vão direto ao banco: o EntityManager é limpo a cada requisição (services_resetter) e as
     * entidades do setUp deixam de ser geridas.
     */
    private function banco(): Connection
    {
        return $this->em()->getConnection();
    }

    private function batida(User $pessoa, string $tipo, string $quando): int
    {
        $em = $this->em();
        $batida = (new RegistroPonto())
            ->setTenant($em->getReference(Tenant::class, $this->escritorio->getId()))
            ->setUser($em->getReference(User::class, $pessoa->getId()))
            ->setTipo($tipo)
            ->setDataHora(new \DateTime($quando));
        $em->persist($batida);
        $em->flush();

        return (int) $batida->getId();
    }
}
