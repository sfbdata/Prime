<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Controller\CampanhaController;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\Campanha;
use App\Contribua\UseCase\ConfigurarCampanhaUseCase;
use App\Contribua\UseCase\InscreverNaCampanhaUseCase;
use App\Contribua\UseCase\MontarJanelaDaCampanhaUseCase;
use App\Contribua\UseCase\MontarPainelDaCampanhaUseCase;
use App\Contribua\UseCase\PedirDevolucaoDaCampanhaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Modo campanha "BlueJus Black": inscrição, confirmação do Master, devolução e configuração, pelas rotas reais.
 * Cada teste cria a SUA campanha (a de maior id é a vigente), com datas relativas a hoje, e o DAMA desfaz tudo.
 */
#[CoversClass(CampanhaController::class)]
#[CoversClass(InscreverNaCampanhaUseCase::class)]
#[CoversClass(PedirDevolucaoDaCampanhaUseCase::class)]
#[CoversClass(ConfigurarCampanhaUseCase::class)]
#[CoversClass(MontarJanelaDaCampanhaUseCase::class)]
#[CoversClass(MontarPainelDaCampanhaUseCase::class)]
final class CampanhaControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_contribua';
    private const CPF_A = '52998224725';
    private const CPF_B = '11144477735';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;
    private Tenant $escritorio;
    private Tenant $outro;
    private User $ana;
    private User $beto;
    private User $master;
    private Campanha $campanha;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = static::getContainer()->get(Connection::class);
        $this->escritorio = $this->tenant('A');
        $this->outro = $this->tenant('B');
        $this->ana = $this->pessoa('ana', $this->escritorio);
        $this->beto = $this->pessoa('beto', $this->outro);
        $this->master = $this->pessoa('master', $this->escritorio, ['ROLE_SUPER_ADMIN']);

        $this->campanha = Campanha::padrao();
        $this->configurarDatas(-1, 10);
    }

    #[TestDox('Inscrever: nasce aguardando pagamento, com txid BJB<id>, CPF só em hash e dois últimos dígitos')]
    public function testInscreverGrava(): void
    {
        $this->logar($this->ana, $this->escritorio);

        $r = $this->post('/contribua/campanha/inscrever', ['nome' => 'Ana Souza', 'cpf' => '529.982.247-25', 'oab' => ' 12345/df ', 'aceite' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'mensagem' => 'Inscrição registrada. Pague R$ 1,00 com o Pix para entrar no Desafio.'], $r);
        $linha = $this->db->fetchAssociative('SELECT * FROM contribua_campanha_participacao WHERE campanha_id = ?', [$this->campanha->getId()]);
        self::assertIsArray($linha);
        self::assertSame('aguardando_pagamento', $linha['situacao']);
        self::assertSame('BJB' . $linha['id'], $linha['txid']);
        self::assertSame('12345/DF', $linha['oab']);
        self::assertSame('25', $linha['cpf_final']);
        self::assertSame(64, \strlen((string) $linha['cpf_hash']));
        self::assertStringNotContainsString(self::CPF_A, implode('|', array_map('strval', $linha)));
        self::assertSame([100, 2, 30, 7], [$linha['valor_centavos'], $linha['multiplicador'], $linha['prazo_pedido_dias'], $linha['prazo_pagamento_dias_uteis']]);
        self::assertSame($this->escritorio->getId(), $linha['tenant_id']);
        self::assertNull($linha['pago_em']);
    }

    #[TestDox('Inscrever: recusas com a mensagem da spec e 422; sem CSRF é 403')]
    public function testInscreverRecusas(): void
    {
        $this->logar($this->ana, $this->escritorio);
        $ok = ['nome' => 'Ana', 'cpf' => self::CPF_A, 'oab' => '1/DF', 'aceite' => '1'];

        $this->recusa('/contribua/campanha/inscrever', ['aceite' => '0'] + $ok, 'Para entrar, marque que leu e aceita as regras.');
        $this->recusa('/contribua/campanha/inscrever', ['cpf' => '52998224726'] + $ok, 'Informe um CPF válido.');
        $this->recusa('/contribua/campanha/inscrever', ['oab' => '  '] + $ok, 'Informe sua inscrição na OAB.');
        self::assertSame(0, $this->contarParticipacoes());

        $this->client->request('POST', '/contribua/campanha/inscrever', ['_token' => 'errado'] + $ok);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contarParticipacoes());
    }

    #[TestDox('Inscrever: já inscrito, CPF ou OAB repetidos por outra pessoa (de outro escritório) são recusados')]
    public function testDuplicidades(): void
    {
        $this->logar($this->ana, $this->escritorio);
        $this->inscrever(self::CPF_A, '1/DF');
        $this->recusa('/contribua/campanha/inscrever', ['nome' => 'Ana', 'cpf' => self::CPF_B, 'oab' => '2/DF', 'aceite' => '1'], 'Você já está no Desafio.');

        $this->logar($this->beto, $this->outro);
        $this->recusa('/contribua/campanha/inscrever', ['nome' => 'Beto', 'cpf' => self::CPF_A, 'oab' => '9/DF', 'aceite' => '1'], 'Este CPF ou esta inscrição na OAB já está no Desafio.');
        $this->recusa('/contribua/campanha/inscrever', ['nome' => 'Beto', 'cpf' => self::CPF_B, 'oab' => '1/df', 'aceite' => '1'], 'Este CPF ou esta inscrição na OAB já está no Desafio.');
        self::assertSame(1, $this->contarParticipacoes());
    }

    #[TestDox('Inscrever: fora do período a campanha não está aberta; esgotada recusa com "Vagas esgotadas."')]
    public function testForaDoPeriodoEEsgotada(): void
    {
        $this->logar($this->ana, $this->escritorio);
        $this->configurarDatas(2, 10);
        $this->recusa('/contribua/campanha/inscrever', ['nome' => 'Ana', 'cpf' => self::CPF_A, 'oab' => '1/DF', 'aceite' => '1'], 'A campanha não está aberta para inscrições.');

        $this->configurarDatas(-1, 10);
        $this->campanha->aplicar('BlueJus Black', 'Black Friday BlueJus', 100, 2, 30, 7, $this->campanha->getInicio(), $this->campanha->getFim(), 1, false, true, $this->campanha->getBeneficios(), true, null, new \DateTimeImmutable());
        $this->em->flush();
        $this->inscrever(self::CPF_A, '1/DF');

        $this->logar($this->beto, $this->outro);
        $this->recusa('/contribua/campanha/inscrever', ['nome' => 'Beto', 'cpf' => self::CPF_B, 'oab' => '2/DF', 'aceite' => '1'], 'Vagas esgotadas.');
    }

    #[TestDox('Confirmar pagamento: só o Master; passa a ativa com pago_em; duas vezes é 422; inexistente é 404')]
    public function testConfirmar(): void
    {
        $id = $this->inscreverComo($this->ana, $this->escritorio, self::CPF_A, '1/DF');

        $this->logar($this->ana, $this->escritorio);
        $this->client->request('POST', "/contribua/campanha/participacoes/{$id}/confirmar", ['_token' => self::TOKEN]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('aguardando_pagamento', $this->situacao($id));

        $this->logar($this->master, $this->escritorio);
        $r = $this->post("/contribua/campanha/participacoes/{$id}/confirmar", []);
        self::assertSame(['ok' => true, 'mensagem' => 'Pagamento confirmado.'], $r);
        self::assertSame('ativa', $this->situacao($id));
        self::assertNotNull($this->db->fetchOne('SELECT pago_em FROM contribua_campanha_participacao WHERE id = ?', [$id]));
        self::assertSame($this->master->getId(), $this->db->fetchOne('SELECT pagamento_confirmado_por_id FROM contribua_campanha_participacao WHERE id = ?', [$id]));

        $this->post("/contribua/campanha/participacoes/{$id}/confirmar", []);
        self::assertResponseStatusCodeSame(422);
        $this->post('/contribua/campanha/participacoes/99999999/confirmar', []);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Devolução: a pessoa ativa pede, a chave Pix fica cifrada e o Master marca como devolvida')]
    public function testDevolucao(): void
    {
        $id = $this->inscreverComo($this->ana, $this->escritorio, self::CPF_A, '1/DF');
        $this->confirmar($id);

        $this->logar($this->ana, $this->escritorio);
        $r = $this->post('/contribua/campanha/devolucao', ['pix' => 'ana@exemplo.com', 'motivo' => 'Faltou integração']);

        self::assertSame(['ok' => true, 'mensagem' => 'Pedido de devolução registrado. Você recebe R$ 2,00 por Pix em até 7 dias úteis.'], $r);
        self::assertSame('devolucao_pedida', $this->situacao($id));
        $linha = $this->db->fetchAssociative('SELECT * FROM contribua_campanha_devolucao WHERE participacao_id = ?', [$id]);
        self::assertIsArray($linha);
        self::assertSame(200, $linha['valor_centavos']);
        self::assertSame('Faltou integração', $linha['motivo']);
        self::assertStringNotContainsString('ana@exemplo.com', (string) $linha['chave_pix_cifrada']);

        $this->post('/contribua/campanha/devolucao', ['pix' => 'ana@exemplo.com']);
        self::assertResponseStatusCodeSame(422);

        $painel = static::getContainer()->get(MontarPainelDaCampanhaUseCase::class)->executar();
        self::assertNotNull($painel);
        self::assertSame('1 pendente(s), 0 devolvido(s)', $painel->pedidosResumo);
        self::assertSame('ana@exemplo.com', $painel->devolucoes[0]->pix);
        self::assertTrue($painel->devolucoes[0]->pendente);

        $this->client->request('POST', "/contribua/campanha/devolucoes/{$linha['id']}/devolvida", ['_token' => self::TOKEN]);
        self::assertResponseStatusCodeSame(403);
        $this->logar($this->master, $this->escritorio);
        self::assertSame(['ok' => true, 'mensagem' => 'Devolução marcada como paga.'], $this->post("/contribua/campanha/devolucoes/{$linha['id']}/devolvida", []));
        self::assertSame('devolvida', $this->situacao($id));
        $this->post("/contribua/campanha/devolucoes/{$linha['id']}/devolvida", []);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Devolução: recusa aguardando pagamento, pessoa sem inscrição, sem chave Pix e fora do prazo (pago há mais de 30 dias)')]
    public function testDevolucaoRecusas(): void
    {
        $id = $this->inscreverComo($this->ana, $this->escritorio, self::CPF_A, '1/DF');
        $mensagemPrazo = 'Fora do prazo ou sem inscrição ativa.';

        $this->logar($this->ana, $this->escritorio);
        $this->recusa('/contribua/campanha/devolucao', ['pix' => 'x'], $mensagemPrazo);
        $this->confirmar($id);
        $this->logar($this->ana, $this->escritorio);
        $this->recusa('/contribua/campanha/devolucao', ['pix' => ' '], 'Informe a chave Pix para receber.');
        $this->recusa('/contribua/campanha/devolucao', ['pix' => str_repeat('x', 141)], 'A chave Pix pode ter até 140 caracteres.');

        // Outra pessoa (outro escritório) não mexe na inscrição da Ana: sem inscrição própria, é recusada.
        $this->logar($this->beto, $this->outro);
        $this->recusa('/contribua/campanha/devolucao', ['pix' => 'beto@exemplo.com'], $mensagemPrazo);
        self::assertSame('ativa', $this->situacao($id));

        $this->db->executeStatement("UPDATE contribua_campanha_participacao SET pago_em = NOW() - INTERVAL '31 days' WHERE id = ?", [$id]);
        $this->logar($this->ana, $this->escritorio);
        $this->recusa('/contribua/campanha/devolucao', ['pix' => 'ana@exemplo.com'], $mensagemPrazo);
        $this->db->executeStatement("UPDATE contribua_campanha_participacao SET pago_em = NOW() - INTERVAL '29 days' WHERE id = ?", [$id]);
        self::assertTrue($this->post('/contribua/campanha/devolucao', ['pix' => 'ana@exemplo.com'])['ok']);
    }

    #[TestDox('Devolução usa o valor congelado: mudar a campanha depois não muda o que a pessoa recebe')]
    public function testValorCongelado(): void
    {
        $id = $this->inscreverComo($this->ana, $this->escritorio, self::CPF_A, '1/DF');
        $this->confirmar($id);
        $this->logar($this->master, $this->escritorio);
        self::assertTrue($this->post('/contribua/campanha/configurar', ['multiplicador' => '3'])['ok']);

        $this->logar($this->ana, $this->escritorio);
        $r = $this->post('/contribua/campanha/devolucao', ['pix' => 'ana@exemplo.com']);

        self::assertSame('Pedido de devolução registrado. Você recebe R$ 2,00 por Pix em até 7 dias úteis.', $r['mensagem']);
    }

    #[TestDox('Configurar: só o Master; grava só os campos enviados, sobe a versão e registra a trilha')]
    public function testConfigurar(): void
    {
        $this->logar($this->ana, $this->escritorio);
        $this->client->request('POST', '/contribua/campanha/configurar', ['_token' => self::TOKEN, 'preco' => '5']);
        self::assertResponseStatusCodeSame(403);

        $this->logar($this->master, $this->escritorio);
        $this->configurarDatas(2, 10); // agendada: nada de regra 3 aqui
        $r = $this->post('/contribua/campanha/configurar', ['preco' => '2,50', 'vagas' => '40', 'multiplicador' => '3', 'beneficios' => "Um\n\n  Dois  \n", 'chamada' => '', 'ligada' => '1']);

        self::assertSame(['ok' => true, 'mensagem' => 'Campanha atualizada.'], $r);
        $linha = $this->db->fetchAssociative('SELECT * FROM contribua_campanha WHERE id = ?', [$this->campanha->getId()]);
        self::assertIsArray($linha);
        self::assertSame([250, 3, 40, 'Black Friday BlueJus', '["Um","Dois"]'], [$linha['preco_centavos'], $linha['multiplicador'], $linha['vagas'], $linha['chamada'], $linha['beneficios']]);
        self::assertSame($this->master->getId(), $linha['atualizada_por_id']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM contribua_campanha_alteracao WHERE campanha_id = ? AND usuario_id = ?', [$this->campanha->getId(), $this->master->getId()]));
        $trilha = $this->db->fetchAssociative('SELECT antes, depois FROM contribua_campanha_alteracao WHERE campanha_id = ?', [$this->campanha->getId()]);
        self::assertIsArray($trilha);
        self::assertSame(100, json_decode((string) $trilha['antes'], true)['precoCentavos']);
        self::assertSame(250, json_decode((string) $trilha['depois'], true)['precoCentavos']);
        $versao = (int) $linha['versao_regras'];

        // Sem mudança: não sobe a versão nem grava trilha.
        $this->post('/contribua/campanha/configurar', ['preco' => '2.50']);
        self::assertSame($versao, (int) $this->db->fetchOne('SELECT versao_regras FROM contribua_campanha WHERE id = ?', [$this->campanha->getId()]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM contribua_campanha_alteracao WHERE campanha_id = ?', [$this->campanha->getId()]));

        $this->post('/contribua/campanha/configurar', ['preco' => '0,50']);
        self::assertResponseStatusCodeSame(422);
        $this->post('/contribua/campanha/configurar', ['fim' => '2000-01-01']);
        self::assertResponseStatusCodeSame(422);
        $this->post('/contribua/campanha/configurar', ['inicio' => 'ontem']);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Configurar: com a campanha no ar não reduz multiplicador, preço, prazos nem benefícios; aumentar pode')]
    public function testRegraTres(): void
    {
        $this->logar($this->master, $this->escritorio);
        $mensagem = 'Com a campanha no ar, não dá para reduzir os benefícios. Mudanças valem só para novas inscrições.';

        foreach ([['multiplicador' => '3'], ['prazoPedido' => '45'], ['prazoPagto' => '10']] as $melhora) {
            self::assertTrue($this->post('/contribua/campanha/configurar', $melhora)['ok']);
        }
        $this->recusa('/contribua/campanha/configurar', ['multiplicador' => '2'], $mensagem);
        $this->recusa('/contribua/campanha/configurar', ['preco' => '2'], $mensagem);
        $this->recusa('/contribua/campanha/configurar', ['prazoPedido' => '40'], $mensagem);
        $this->recusa('/contribua/campanha/configurar', ['prazoPagto' => '5'], $mensagem);
        $this->recusa('/contribua/campanha/configurar', ['beneficios' => 'Só este'], $mensagem);
        $this->recusa('/contribua/campanha/padrao', [], $mensagem);

        $r = $this->post('/contribua/campanha/configurar', ['beneficios' => implode("\n", $this->campanha->getBeneficios()) . "\nUm novo", 'preco' => '1']);
        self::assertTrue($r['ok']);
    }

    #[TestDox('Restaurar padrão: volta tudo ao padrão e mantém ligada/desligada; só o Master')]
    public function testPadrao(): void
    {
        $this->logar($this->ana, $this->escritorio);
        $this->client->request('POST', '/contribua/campanha/padrao', ['_token' => self::TOKEN]);
        self::assertResponseStatusCodeSame(403);

        $this->logar($this->master, $this->escritorio);
        $this->configurarDatas(2, 10);
        $this->post('/contribua/campanha/configurar', ['nome' => 'Outra', 'preco' => '9', 'ligada' => '0']);
        self::assertTrue($this->post('/contribua/campanha/padrao', [])['ok']);

        $linha = $this->db->fetchAssociative('SELECT nome, preco_centavos, ligada, inicio, fim FROM contribua_campanha WHERE id = ?', [$this->campanha->getId()]);
        self::assertSame(['BlueJus Black', 100, false, '2026-11-19', '2026-11-30'], array_values((array) $linha));
    }

    #[TestDox('A janela traz a inscrição só da própria pessoa, o Pix só enquanto aguarda e o link de configurar só para o Master')]
    public function testJanela(): void
    {
        $id = $this->inscreverComo($this->ana, $this->escritorio, self::CPF_A, '1/DF');
        $janela = static::getContainer()->get(MontarJanelaDaCampanhaUseCase::class);

        $this->logar($this->ana, $this->escritorio);
        $deAna = $janela->executar(new Solicitante($this->ana, $this->escritorio, false, false));
        self::assertNotNull($deAna?->participacao);
        self::assertSame('aguardando_pagamento', $deAna->participacao->situacao);
        self::assertStringStartsWith('000201', (string) $deAna->participacao->pixCodigo);
        self::assertStringContainsString('BJB' . $id, (string) $deAna->participacao->pixCodigo);
        self::assertNull($deAna->urlConfigurar);
        self::assertSame('Minha participação', $deAna->campanha->botao);
        self::assertCount(10, $deAna->regras);
        self::assertNotSame('', $deAna->csrf);

        $deBeto = $janela->executar(new Solicitante($this->beto, $this->outro, false, false));
        self::assertNull($deBeto?->participacao);
        self::assertSame('Conhecer o Desafio', $deBeto->campanha->botao);
        self::assertNull($deBeto->inscritos);

        $doMaster = $janela->executar(new Solicitante($this->master, $this->escritorio, true, false));
        self::assertSame('/contribua#campanha', $doMaster?->urlConfigurar);

        $this->confirmar($id);
        $this->campanha = $this->em->find(Campanha::class, $this->campanha->getId());
        $this->campanha->aplicar('BlueJus Black', 'Black Friday BlueJus', 100, 2, 30, 7, $this->campanha->getInicio(), $this->campanha->getFim(), 0, true, true, $this->campanha->getBeneficios(), true, null, new \DateTimeImmutable());
        $this->em->flush();
        $ativa = $janela->executar(new Solicitante($this->ana, $this->escritorio, false, false));
        self::assertSame('ativa', $ativa?->participacao?->situacao);
        self::assertNull($ativa->participacao->pixCodigo);
        self::assertTrue($ativa->participacao->podeDevolver);
        self::assertSame(30, $ativa->participacao->diasRestantes);
        self::assertSame('R$ 2,00', $ativa->participacao->valorDevolucao);
        self::assertSame(1, $ativa->inscritos);
    }

    #[TestDox('GET /janela: 200 com o template da janela')]
    public function testRotaDaJanela(): void
    {
        $this->logar($this->ana, $this->escritorio);
        $this->client->request('GET', '/contribua/campanha/janela');

        self::assertResponseIsSuccessful();
    }

    #[TestDox('O Contribua do Master abre com o painel da campanha montado')]
    public function testIndexDoMaster(): void
    {
        $this->logar($this->master, $this->escritorio);
        $this->client->request('GET', '/contribua');

        self::assertResponseIsSuccessful();
    }

    private function configurarDatas(int $inicioEmDias, int $fimEmDias): void
    {
        $zona = new \DateTimeZone('America/Sao_Paulo');
        $hoje = new \DateTimeImmutable('today', $zona);
        $v = $this->campanha->valores();
        $this->campanha->aplicar(
            $v['nome'],
            $v['chamada'],
            $v['precoCentavos'],
            $v['multiplicador'],
            $v['prazoPedidoDias'],
            $v['prazoPagamentoDiasUteis'],
            $hoje->modify(sprintf('%+d days', $inicioEmDias)),
            $hoje->modify(sprintf('%+d days', $fimEmDias)),
            $v['vagas'],
            $v['mostrarContador'],
            $v['abaNoTopo'],
            $v['beneficios'],
            $v['ligada'],
            null,
            new \DateTimeImmutable(),
        );
        $this->em->persist($this->campanha);
        $this->em->flush();
    }

    private function inscrever(string $cpf, string $oab): int
    {
        $this->post('/contribua/campanha/inscrever', ['nome' => 'Pessoa', 'cpf' => $cpf, 'oab' => $oab, 'aceite' => '1']);
        self::assertResponseIsSuccessful();

        return (int) $this->db->fetchOne('SELECT MAX(id) FROM contribua_campanha_participacao WHERE campanha_id = ?', [$this->campanha->getId()]);
    }

    private function inscreverComo(User $quem, Tenant $escritorio, string $cpf, string $oab): int
    {
        $this->logar($quem, $escritorio);

        return $this->inscrever($cpf, $oab);
    }

    private function confirmar(int $id): void
    {
        $this->logar($this->master, $this->escritorio);
        self::assertTrue($this->post("/contribua/campanha/participacoes/{$id}/confirmar", [])['ok']);
    }

    private function situacao(int $id): string
    {
        return (string) $this->db->fetchOne('SELECT situacao FROM contribua_campanha_participacao WHERE id = ?', [$id]);
    }

    private function contarParticipacoes(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM contribua_campanha_participacao WHERE campanha_id = ?', [$this->campanha->getId()]);
    }

    /** @param array<string, string> $campos */
    private function recusa(string $url, array $campos, string $erro): void
    {
        $r = $this->post($url, $campos);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['ok' => false, 'erro' => $erro], $r);
    }

    /**
     * @param array<string, string> $campos
     *
     * @return array<string, mixed>
     */
    private function post(string $url, array $campos): array
    {
        $this->client->request('POST', $url, ['_token' => self::TOKEN] + $campos, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function logar(User $quem, Tenant $escritorio): void
    {
        $this->em->clear();
        $this->logarComTenant($this->client, $this->em->find(User::class, $quem->getId()), $this->em->find(Tenant::class, $escritorio->getId()));
        $this->campanha = $this->em->find(Campanha::class, $this->campanha->getId());
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório CAMPANHA ' . $nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $roles */
    private function pessoa(string $prefixo, Tenant $tenant, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . ucfirst($prefixo));
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName('Equipe ' . uniqid());
        $papel->setIsSystem(false);
        $this->em->persist($papel);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
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
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
