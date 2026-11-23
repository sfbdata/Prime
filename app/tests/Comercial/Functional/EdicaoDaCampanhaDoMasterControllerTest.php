<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\Controller\EdicaoDaCampanhaDoMasterController;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\UseCase\ConsultarEdicaoDaCampanhaUseCase;
use App\Comercial\UseCase\EditarCampanhaUseCase;
use App\Comercial\UseCase\ExcluirCampanhaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Comercial\Support\CriaCampanhas;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Editar, reprogramar e excluir uma campanha da Central de envios (pedido do Farlei M5.3, 23/11/2026; decisões do
 * Coordenador; spec comercial-e-emails.md §8.1 item 6): só o Master, CSRF no POST, a edição só antes de começar a sair
 * (agendada, na fila que o expansor ainda não pegou, recorrente entre execuções), o estado coerente com a agenda, a trilha
 * `camp_edit` sem conteúdo nem e-mail, o público intacto e o "Excluir campanha" (cancela o ativo e esconde; o encerrado só esconde).
 */
#[CoversClass(EdicaoDaCampanhaDoMasterController::class)]
#[CoversClass(ConsultarEdicaoDaCampanhaUseCase::class)]
#[CoversClass(EditarCampanhaUseCase::class)]
#[CoversClass(ExcluirCampanhaUseCase::class)]
#[CoversClass(CampanhaDeEmailRepository::class)]
final class EdicaoDaCampanhaDoMasterControllerTest extends JusPrimeWebTestCase
{
    use CriaCampanhas;

    private User $master;

    #[TestDox('Quem não é Master não abre, não edita nem exclui: 403 e a campanha não muda')]
    public function testSoMaster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório M53 ' . uniqid());
        $em->persist($tenant);
        $comum = $this->usuario('comum');
        $em->persist(new UserTenant($comum, $tenant));
        $em->flush();
        $id = $this->agendada();
        $this->logarComTenant($client, $comum, $tenant);

        $client->request('GET', '/master/comercial/campanhas/' . $id . '/edicao');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/master/comercial/campanhas/' . $id . '/editar', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial'], (string) json_encode($this->corpo('Invasor')));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/master/comercial/campanhas/' . $id . '/excluir', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial'], '{}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('agendada', $this->campo($id, 'estado'));
        self::assertNotSame('Invasor', $this->campo($id, 'assunto'));
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM comercial_campanha_marca WHERE campanha_id = ?', [$id]));
    }

    #[TestDox('Sem o token CSRF: 403 em editar e excluir, e nada muda')]
    public function testCsrf(): void
    {
        $client = $this->clienteMaster();
        $id = $this->agendada();
        $client->request('POST', '/master/comercial/campanhas/' . $id . '/editar', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($this->corpo('Sem token')));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/master/comercial/campanhas/' . $id . '/excluir', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'errado'], '{}');
        self::assertResponseStatusCodeSame(403);
        self::assertNotSame('Sem token', $this->campo($id, 'assunto'));
        self::assertSame('agendada', $this->campo($id, 'estado'));
    }

    #[TestDox('Campanha inexistente: 404 na abertura, na edição e na exclusão')]
    public function testInexistente(): void
    {
        $client = $this->clienteMaster();
        $client->request('GET', '/master/comercial/campanhas/987654/edicao');
        self::assertResponseStatusCodeSame(404);
        $this->editar($client, 987654, $this->corpo('Nada'));
        self::assertResponseStatusCodeSame(404);
        $this->excluir($client, 987654);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Abrir para editar: a cópia congelada no formato do editor, a data futura no formato do datetime-local, a frequência e o estado')]
    public function testAbrir(): void
    {
        $client = $this->clienteMaster();
        $quando = new \DateTimeImmutable('+3 days 10:30');
        $id = $this->agendada($quando, 'semanal', 'recorrente');
        $client->request('GET', '/master/comercial/campanhas/' . $id . '/edicao');

        self::assertResponseIsSuccessful();
        $d = $this->json($client);
        self::assertSame($quando->format('Y-m-d\TH:i'), $d['agendar']);
        self::assertSame('semanal', $d['frequencia']);
        self::assertSame('recorrente', $d['estado']);
        self::assertSame('convite', $d['modelo']['chave']);
        self::assertSame('simples', $d['modelo']['modo']);
        self::assertSame($this->campo($id, 'assunto'), $d['modelo']['assunto']);
        self::assertSame($d['modelo']['assunto'], $d['nome']);
        foreach (['corpo', 'itens', 'cta', 'link', 'blocos', 'html', 'imagem', 'titulo', 'selo'] as $campo) {
            self::assertArrayHasKey($campo, $d['modelo']);
        }

        $semData = $this->campanha(['a@exemplo.com']);
        $client->request('GET', '/master/comercial/campanhas/' . $semData . '/edicao');
        self::assertSame('', $this->json($client)['agendar'], 'na fila, sem data');
    }

    #[TestDox('Abrir uma campanha que já começou a sair: 422 com o motivo')]
    public function testAbrirJaComecou(): void
    {
        $client = $this->clienteMaster();
        $id = $this->campanha(['a@exemplo.com'], ['estado' => 'enviada']);
        $client->request('GET', '/master/comercial/campanhas/' . $id . '/edicao');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => EditarCampanhaUseCase::JA_COMECOU], $this->json($client));
    }

    #[TestDox('Editar a agendada: regrava a cópia e a agenda (única com data continua agendada), com a trilha camp_edit só com {frequencia, agendada} e o público intacto')]
    public function testEditarAgendada(): void
    {
        $client = $this->clienteMaster();
        $id = $this->agendada();
        $antes = $this->conexao()->fetchAssociative('SELECT publico, publico_parametros, modelo_chave, total, criada_em FROM comercial_campanha WHERE id = ?', [$id]);
        $nova = new \DateTimeImmutable('+5 days 09:15');
        $this->editar($client, $id, $this->corpo('Assunto novo da campanha', $nova->format('Y-m-d\TH:i'), 'unica', ['titulo' => 'Título novo', 'selo' => 'AVISO']));

        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => 'Campanha atualizada.'], $this->json($client));
        $c = $this->conexao()->fetchAssociative('SELECT * FROM comercial_campanha WHERE id = ?', [$id]);
        self::assertSame('Assunto novo da campanha', $c['assunto']);
        self::assertSame('Assunto novo da campanha', $c['nome']);
        self::assertSame('Título novo', $c['titulo']);
        self::assertSame('AVISO', $c['selo']);
        self::assertStringContainsString('corpo', (string) $c['conteudo']);
        self::assertSame('agendada', $c['estado']);
        self::assertSame($nova->format('Y-m-d H:i:s'), (new \DateTimeImmutable((string) $c['agendada_para']))->format('Y-m-d H:i:s'));
        self::assertSame($nova->format('Y-m-d H:i:s'), (new \DateTimeImmutable((string) $c['proxima_execucao']))->format('Y-m-d H:i:s'));
        foreach (['publico', 'publico_parametros', 'modelo_chave', 'total', 'criada_em'] as $intacto) {
            self::assertEquals($antes[$intacto], $c[$intacto], $intacto . ' não muda na edição');
        }
        $trilha = $this->conexao()->fetchAssociative("SELECT changes, actor_user_id, route FROM audit_log WHERE action = 'camp_edit' AND entity_id = ?", [(string) $id]);
        self::assertSame(['frequencia' => 'unica', 'agendada' => $nova->format('Y-m-d H:i')], json_decode((string) $trilha['changes'], true));
        self::assertSame((string) $this->master->getId(), (string) $trilha['actor_user_id']);
        self::assertSame('master_comercial_campanha_editar', $trilha['route']);
        self::assertStringNotContainsString('Assunto novo', (string) $trilha['changes']);
        self::assertStringNotContainsString('@', (string) $trilha['changes']);
    }

    #[TestDox('Trocar a frequência deixa o estado coerente: outra frequência vira recorrente; única com data, agendada; única sem data, na fila agora')]
    public function testEstadoCoerente(): void
    {
        $client = $this->clienteMaster();
        $id = $this->agendada();
        $data = (new \DateTimeImmutable('+4 days 08:00'))->format('Y-m-d\TH:i');

        $this->editar($client, $id, $this->corpo('Mensal', $data, 'mensal'));
        self::assertResponseIsSuccessful();
        self::assertSame(['recorrente', 'mensal'], [$this->campo($id, 'estado'), $this->campo($id, 'frequencia')]);

        $this->editar($client, $id, $this->corpo('Semanal sem data', '', 'semanal'));
        self::assertResponseIsSuccessful();
        self::assertSame('recorrente', $this->campo($id, 'estado'));
        self::assertNotNull($this->campo($id, 'agendada_para'), 'a recorrente sem data começa agora');

        $this->editar($client, $id, $this->corpo('Única com data', $data, 'unica'));
        self::assertResponseIsSuccessful();
        self::assertSame(['agendada', 'unica'], [$this->campo($id, 'estado'), $this->campo($id, 'frequencia')]);

        $this->editar($client, $id, $this->corpo('Única sem data', '', 'unica'));
        self::assertResponseIsSuccessful();
        self::assertSame('na_fila', $this->campo($id, 'estado'));
        self::assertNull($this->campo($id, 'agendada_para'));
        self::assertLessThanOrEqual(new \DateTimeImmutable('+5 seconds'), new \DateTimeImmutable((string) $this->campo($id, 'proxima_execucao')));
    }

    #[TestDox('A na fila que o expansor ainda não pegou pode ser reprogramada para uma data')]
    public function testEditarNaFila(): void
    {
        $client = $this->clienteMaster();
        $id = $this->campanha(['a@exemplo.com']);
        $data = new \DateTimeImmutable('+2 days 14:00');
        $this->editar($client, $id, $this->corpo('Na fila reprogramada', $data->format('Y-m-d\TH:i')));

        self::assertResponseIsSuccessful();
        self::assertSame('agendada', $this->campo($id, 'estado'));
        self::assertSame('Na fila reprogramada', $this->campo($id, 'assunto'));
    }

    #[TestDox('A recorrente editada sem mudar a data (a do formulário é a próxima execução) mantém a âncora e a próxima: o calendário não anda')]
    public function testRecorrenteSemMudarDataMantemAncora(): void
    {
        $client = $this->clienteMaster();
        $ancora = new \DateTimeImmutable('-7 days 09:00');
        $proxima = new \DateTimeImmutable('+7 days 09:00');
        $id = $this->campanha(['a@exemplo.com'], ['estado' => 'recorrente', 'frequencia' => 'semanal', 'agendada_para' => $ancora, 'proxima' => $proxima]);
        $this->destino($id, 'a@exemplo.com', 'enviado');

        $this->editar($client, $id, $this->corpo('Só o texto mudou', $proxima->format('Y-m-d\TH:i'), 'semanal'));
        self::assertResponseIsSuccessful();
        self::assertSame('Só o texto mudou', $this->campo($id, 'assunto'));
        self::assertSame($ancora->format('Y-m-d H:i'), substr((string) $this->campo($id, 'agendada_para'), 0, 16));
        self::assertSame($proxima->format('Y-m-d H:i'), substr((string) $this->campo($id, 'proxima_execucao'), 0, 16));

        $nova = new \DateTimeImmutable('+10 days 08:00');
        $this->editar($client, $id, $this->corpo('Nova data', $nova->format('Y-m-d\TH:i'), 'semanal'));
        self::assertResponseIsSuccessful();
        self::assertSame($nova->format('Y-m-d H:i'), substr((string) $this->campo($id, 'agendada_para'), 0, 16), 'data nova reancora');
    }

    #[TestDox('A recorrente entre duas execuções (tudo da execução corrente já entregue ao Carteiro e enviado) pode ser editada')]
    public function testEditarRecorrenteEntreExecucoes(): void
    {
        $client = $this->clienteMaster();
        $id = $this->agendada(new \DateTimeImmutable('+7 days'), 'semanal', 'recorrente');
        $this->destino($id, 'a@exemplo.com', 'enviado');
        $this->editar($client, $id, $this->corpo('Recorrente editada', (new \DateTimeImmutable('+8 days 07:00'))->format('Y-m-d\TH:i'), 'quinzenal'));

        self::assertResponseIsSuccessful();
        self::assertSame(['recorrente', 'quinzenal', 'Recorrente editada'], [$this->campo($id, 'estado'), $this->campo($id, 'frequencia'), $this->campo($id, 'assunto')]);
    }

    #[TestDox('Depois de começar: recusa (422) e nada muda. Na fila já pega pelo expansor, enviando, enviada, cancelada, recorrente com lote por materializar ou com envio na fila')]
    public function testRecusaDepoisDeComecar(): void
    {
        $client = $this->clienteMaster();
        $casos = [];
        $pegaPeloExpansor = $this->campanha(['a@exemplo.com'], ['estado' => 'enviando']);
        $this->destino($pegaPeloExpansor, 'a@exemplo.com', null);
        $casos['enviando'] = $pegaPeloExpansor;
        $casos['execucao_sem_destino'] = $this->campanha(['a@exemplo.com']);
        $this->conexao()->executeStatement('UPDATE comercial_campanha SET execucoes = 1 WHERE id = ?', [$casos['execucao_sem_destino']]);
        $casos['enviada'] = $this->campanha(['a@exemplo.com'], ['estado' => 'enviada']);
        $casos['cancelada'] = $this->campanha(['a@exemplo.com'], ['estado' => 'cancelada']);
        $casos['recorrente_lote'] = $this->agendada(new \DateTimeImmutable('+7 days'), 'semanal', 'recorrente');
        $this->destino($casos['recorrente_lote'], 'a@exemplo.com', null);
        $casos['recorrente_na_fila'] = $this->agendada(new \DateTimeImmutable('+7 days'), 'semanal', 'recorrente');
        $this->destino($casos['recorrente_na_fila'], 'a@exemplo.com', 'enfileirado');
        $casos['recorrente_enviando'] = $this->agendada(new \DateTimeImmutable('+7 days'), 'semanal', 'recorrente');
        $this->destino($casos['recorrente_enviando'], 'a@exemplo.com', 'enviando');
        $casos['agendada_ja_pega'] = $this->agendada();
        $this->destino($casos['agendada_ja_pega'], 'a@exemplo.com', null);

        foreach ($casos as $nome => $id) {
            $antes = $this->conexao()->fetchAssociative('SELECT assunto, estado, frequencia, proxima_execucao FROM comercial_campanha WHERE id = ?', [$id]);
            $this->editar($client, $id, $this->corpo('Tarde demais ' . $nome));
            self::assertResponseStatusCodeSame(422, $nome);
            self::assertSame(['erro' => EditarCampanhaUseCase::JA_COMECOU], $this->json($client), $nome);
            self::assertSame($antes, $this->conexao()->fetchAssociative('SELECT assunto, estado, frequencia, proxima_execucao FROM comercial_campanha WHERE id = ?', [$id]), $nome);
            self::assertSame(0, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'camp_edit' AND entity_id = ?", [(string) $id]), $nome);
        }
    }

    #[TestDox('Data passada: "Escolha uma data futura."; data fora do formato e frequência inválida também são 422; nada muda')]
    public function testDataEFrequenciaInvalidas(): void
    {
        $client = $this->clienteMaster();
        $id = $this->agendada();
        $antes = $this->campo($id, 'assunto');

        $this->editar($client, $id, $this->corpo('Passada', (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i')));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Escolha uma data futura.'], $this->json($client));
        $this->editar($client, $id, $this->corpo('Lixo', 'ontem'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Data de agendamento inválida.'], $this->json($client));
        $this->editar($client, $id, $this->corpo('Freq', '', 'diaria'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Frequência inválida.'], $this->json($client));
        self::assertSame($antes, $this->campo($id, 'assunto'));
    }

    #[TestDox('O conteúdo é validado como na confirmação (assunto vazio: 422) e a chave do modelo do pedido não vale: o modelo da campanha não muda')]
    public function testConteudoEChaveDoModelo(): void
    {
        $client = $this->clienteMaster();
        $id = $this->agendada();
        $this->editar($client, $id, $this->corpo(''));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Informe o assunto e o conteúdo do e-mail.'], $this->json($client));

        $corpo = $this->corpo('Outro modelo');
        $corpo['modelo']['chave'] = 'vagas';
        $this->editar($client, $id, $corpo);
        self::assertResponseIsSuccessful();
        self::assertSame('convite', $this->campo($id, 'modelo_chave'));
        self::assertSame('Outro modelo', $this->campo($id, 'assunto'));

        $this->editar($client, $id, ['frequencia' => 'unica']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Pedido inválido.'], $this->json($client));
    }

    #[TestDox('A lista diz, em cada linha, se a campanha ainda é editável: agendada e na fila sim; enviada, cancelada e já pega pelo expansor não')]
    public function testListaTrazEditavel(): void
    {
        $client = $this->clienteMaster();
        $agendada = $this->agendada();
        $naFila = $this->campanha(['a@exemplo.com']);
        $enviada = $this->campanha(['a@exemplo.com'], ['estado' => 'enviada']);
        $pega = $this->campanha(['a@exemplo.com'], ['estado' => 'enviando']);
        $this->destino($pega, 'a@exemplo.com', null);
        $recorrenteComFila = $this->agendada(new \DateTimeImmutable('+7 days'), 'semanal', 'recorrente');
        $this->destino($recorrenteComFila, 'a@exemplo.com', 'enfileirado');

        $editaveis = [];
        foreach (['env', 'ag', 'rec'] as $aba) {
            $client->request('GET', '/master/comercial/relatorio?aba=' . $aba);
            foreach ((array) $this->json($client)['campanhas'] as $linha) {
                $editaveis[$linha['id']] = $linha['editavel'];
            }
        }
        self::assertEqualsCanonicalizing([$agendada => true, $naFila => true, $enviada => false, $pega => false, $recorrenteComFila => false], array_intersect_key($editaveis, array_flip([$agendada, $naFila, $enviada, $pega, $recorrenteComFila])));
    }

    #[TestDox('Excluir a ativa: cancela o envio (camp_fim com o Master), esconde do relatório e não apaga a linha')]
    public function testExcluirAtiva(): void
    {
        $client = $this->clienteMaster();
        $id = $this->agendada();
        $this->excluir($client, $id);

        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => ExcluirCampanhaUseCase::CANCELADA_E_ESCONDIDA], $this->json($client));
        self::assertSame(['cancelada', 'Cancelada pelo Master'], [$this->campo($id, 'estado'), $this->campo($id, 'motivo')]);
        self::assertSame(1, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'camp_fim' AND entity_id = ? AND actor_user_id = ?", [(string) $id, $this->master->getId()]));
        self::assertNotNull($this->conexao()->fetchOne('SELECT removida_em FROM comercial_campanha_marca WHERE campanha_id = ? AND user_id = ?', [$id, $this->master->getId()]));
        $client->request('GET', '/master/comercial/relatorio?aba=env');
        self::assertNotContains($id, array_column((array) $this->json($client)['campanhas'], 'id'));
    }

    #[TestDox('Excluir a enviada com envio ainda na fila também cancela; a enviada sem nada na fila só esconde (sem camp_fim)')]
    public function testExcluirEnviada(): void
    {
        $client = $this->clienteMaster();
        $comFila = $this->campanha(['a@exemplo.com'], ['estado' => 'enviada']);
        $this->destino($comFila, 'a@exemplo.com', 'enfileirado');
        $this->excluir($client, $comFila);
        self::assertResponseIsSuccessful();
        self::assertSame('cancelada', $this->campo($comFila, 'estado'));
        self::assertSame(1, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'camp_fim' AND entity_id = ?", [(string) $comFila]));

        $enviada = $this->campanha(['a@exemplo.com'], ['estado' => 'enviada']);
        $this->destino($enviada, 'a@exemplo.com', 'enviado');
        $this->excluir($client, $enviada);
        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => ExcluirCampanhaUseCase::ESCONDIDA], $this->json($client));
        self::assertSame('enviada', $this->campo($enviada, 'estado'), 'nada é apagado nem mudado');
        self::assertSame(0, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'camp_fim' AND entity_id = ?", [(string) $enviada]));
        self::assertNotNull($this->conexao()->fetchOne('SELECT removida_em FROM comercial_campanha_marca WHERE campanha_id = ? AND user_id = ?', [$enviada, $this->master->getId()]));
        self::assertSame(1, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM comercial_campanha WHERE id = ?', [$enviada]));
    }

    #[TestDox('Excluir a já cancelada só esconde, e repetir não dá erro')]
    public function testExcluirCancelada(): void
    {
        $client = $this->clienteMaster();
        $id = $this->campanha(['a@exemplo.com'], ['estado' => 'cancelada']);
        $this->excluir($client, $id);
        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => ExcluirCampanhaUseCase::ESCONDIDA], $this->json($client));
        $this->excluir($client, $id);
        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'camp_fim' AND entity_id = ?", [(string) $id]));
    }

    /** Uma campanha `agendada` (ou a dada) com a data futura. */
    private function agendada(?\DateTimeImmutable $quando = null, string $frequencia = 'unica', string $estado = 'agendada'): int
    {
        $quando ??= new \DateTimeImmutable('+2 days');

        return $this->campanha(['a@exemplo.com', 'b@exemplo.com'], ['estado' => $estado, 'frequencia' => $frequencia, 'agendada_para' => $quando, 'proxima' => $quando]);
    }

    /**
     * O corpo do "Salvar alterações": o modelo do convite com o assunto dado e o que mais vier por cima.
     *
     * @param array<string, mixed> $sobre
     *
     * @return array<string, mixed>
     */
    private function corpo(string $assunto, string $agendar = '', string $frequencia = 'unica', array $sobre = []): array
    {
        $modelo = (array) (new ModelosDeCampanha())->fixo('convite');

        return ['modelo' => array_merge($modelo, ['assunto' => $assunto], $sobre), 'agendar' => $agendar, 'frequencia' => $frequencia];
    }

    /** @param array<string, mixed> $corpo */
    private function editar(KernelBrowser $client, int $id, array $corpo): void
    {
        $client->request('POST', '/master/comercial/campanhas/' . $id . '/editar', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial'], (string) json_encode($corpo));
    }

    private function excluir(KernelBrowser $client, int $id): void
    {
        $client->request('POST', '/master/comercial/campanhas/' . $id . '/excluir', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial'], '{}');
    }

    /** Um destinatário materializado da execução 1; com situação, o envio dele em comercial_envio (sem situação: ainda não saiu). */
    private function destino(int $campanhaId, string $email, ?string $situacao): void
    {
        $envioId = null;
        if ($situacao !== null) {
            $envioId = (int) $this->conexao()->fetchOne(
                "INSERT INTO comercial_envio (token, destinatario, assunto, tipo, lista, origem, situacao, criado_em, aberturas, cliques)
                 VALUES (:token, :para, 'Assunto', 'lista', 'comercial_campanhas', :origem, :situacao, NOW(), 0, 0) RETURNING id",
                ['token' => bin2hex(random_bytes(16)), 'para' => $email, 'origem' => 'campanha:' . $campanhaId, 'situacao' => $situacao],
            );
        }
        $this->conexao()->executeStatement(
            'INSERT INTO comercial_campanha_destino (campanha_id, execucao, email, envio_id, criado_em) VALUES (?, 1, ?, ?, NOW())',
            [$campanhaId, $email, $envioId],
        );
        $this->conexao()->executeStatement('UPDATE comercial_campanha SET execucoes = 1 WHERE id = ?', [$campanhaId]);
    }

    private function campo(int $id, string $coluna): mixed
    {
        $valor = $this->conexao()->fetchOne('SELECT ' . $coluna . ' FROM comercial_campanha WHERE id = ?', [$id]);

        return $valor === false ? null : $valor;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function clienteMaster(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
        $this->master = $this->usuario('master');
        $this->master->setRoles(['ROLE_SUPER_ADMIN']);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($this->master);
        $this->marcarTermosAceitos($client);

        return $client;
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
