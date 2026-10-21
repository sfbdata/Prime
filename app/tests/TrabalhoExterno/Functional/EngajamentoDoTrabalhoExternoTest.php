<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\EngajamentoController;
use App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository;
use App\TrabalhoExterno\Repository\DestinatariosDosAvisosRepository;
use App\TrabalhoExterno\UseCase\DefinirAlertaDeVagasUseCase;
use App\TrabalhoExterno\UseCase\MontarEngajamentoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * O engajamento e os avisos pela HTTP (spec VS 18.10.26 §4.7): o banner do interno e do externo, o
 * aviso de vaga nova (só externo, só com o sino ligado), as boas-vindas pelo link, a situação das
 * ofertas acompanhadas e os avisos no sino de cada evento, no escritório certo e sem texto pessoal.
 */
#[CoversClass(EngajamentoController::class)]
#[CoversClass(MontarEngajamentoUseCase::class)]
#[CoversClass(DefinirAlertaDeVagasUseCase::class)]
#[CoversClass(DestinatariosDosAvisosRepository::class)]
final class EngajamentoDoTrabalhoExternoTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    private const API = '/trabalho-externo/api';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->instalarCsrf($this->client);
    }

    #[TestDox('interno: pedidos para aprovar no selo e no chip só com talentos; sem alerta, lembrete nem boas-vindas; a prévia lê como externo')]
    public function testEngajamentoDoInterno(): void
    {
        [$sede, $a] = [$this->criarTenant('Sede'), $this->criarTenant('A')];
        $this->definirSede($sede);
        [$master, $equipe, $doA] = [$this->criarMaster($sede), $this->criarUsuario($sede, nome: 'Equipe'), $this->criarUsuario($a, nome: 'Dono')];
        $this->divulgar($doA, $a, 'Advogado cível');

        $this->logarComTenant($this->client, $master, $sede);
        $e = $this->engajamento();
        self::assertSame(['vagas' => 1, 'curriculos' => 0], $e['contadores']);
        self::assertMatchesRegularExpression('/^(Bom dia|Boa tarde|Boa noite), Master$/', $e['banner']['saudacao']);
        self::assertSame('Veja o que aconteceu na Central desde a sua última visita.', $e['banner']['subtitulo']);
        self::assertSame('1 pedido(s) de divulgação de vaga para aprovar', $e['banner']['chips'][0]['txt']);
        self::assertSame([['disponivel' => false, 'ligado' => false], null, null, true], [$e['alerta'], $e['lembrete'], $e['boasVindas'], $e['motiv']]);

        $previa = $this->engajamento('?como=solicitante');
        self::assertSame('Oportunidades para você na rede BlueJus.', $previa['banner']['subtitulo']);
        self::assertSame('cadastrar', $previa['lembrete']['estado']);

        $this->semTalentos();
        $this->logarComTenant($this->client, $equipe, $sede);
        $semTalentos = $this->engajamento();
        self::assertSame(0, $semTalentos['contadores']['vagas'], 'sem talentos o selo é o das abertas');
        self::assertNotContains('1 pedido(s) de divulgação de vaga para aprovar', array_column($semTalentos['banner']['chips'], 'txt'));
    }

    #[TestDox('externo: chips, lembrete e alerta; boas-vindas pelo link sem lembrete; ofertas só das publicadas; alerta liga e desliga com os toasts')]
    public function testEngajamentoDoExternoEAlerta(): void
    {
        [$sede, $a, $b] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('B')];
        $this->definirSede($sede);
        $master = $this->criarMaster($sede);
        [$doA, $doB] = [$this->criarUsuario($a, nome: 'Ana Souza'), $this->criarUsuario($b, nome: 'Bia')];
        $aberta = $this->publicar($master, $sede, self::vaga('Correspondente') + ['oferta' => true, 'duracaoDaOferta' => 15]);
        $comum = $this->publicar($master, $sede, self::vaga('Vaga comum'));
        $pendente = $this->divulgar($doB, $b, 'Pendente de B');

        $this->logarComTenant($this->client, $doA, $a);
        $e = $this->engajamento();
        self::assertSame(['vagas' => 2, 'curriculos' => 0], $e['contadores']);
        self::assertSame(['1 vaga(s) disputada(s) disponível(is) agora', 'Cadastre seu currículo para aceitar ofertas em 1 clique'], array_column($e['banner']['chips'], 'txt'));
        self::assertSame(['disponivel' => true, 'ligado' => false], $e['alerta']);
        self::assertSame(['estado' => 'cadastrar', 'pct' => 0, 'faltas' => [], 'dias' => 0, 'vagasAbertas' => 2], $e['lembrete']);
        self::assertNull($e['boasVindas']);
        self::assertSame([], $e['ofertas']);

        self::assertNull($this->engajamento('?vagas=' . $aberta . '&cadastro=1')['boasVindas'], 'sem origem=link não há boas-vindas');
        $link = $this->engajamento('?origem=link&vagas=VG-' . (int) substr($aberta, 3) . ',' . $pendente . ',lixo&cadastro=1&ofertas=' . $aberta . ',' . $comum . ',' . $pendente);
        self::assertNull($link['lembrete'], 'com as boas-vindas não há lembrete');
        self::assertSame(['Bem-vindo ao BlueJus!', ['rot' => 'Cadastrar currículo e me candidatar', 'acao' => 'cadastrarCv', 'codigo' => $aberta]], [$link['boasVindas']['titulo'], $link['boasVindas']['botao']]);
        self::assertSame([$aberta => 'aberta'], $link['ofertas'], 'só ofertas com tempo; a pendente de outro escritório não entra');
        $semVagas = $this->engajamento('?origem=link');
        self::assertSame(['Que bom ter você de volta!', 'As vagas que te indicaram já foram preenchidas, mas há outras abertas agora. Cadastre seu currículo e ative os avisos para não perder as próximas.'], [$semVagas['boasVindas']['titulo'], $semVagas['boasVindas']['texto']]);

        $ligou = $this->postarJson($this->client, self::API . '/vagas/alerta', ['ligado' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(['alerta' => ['disponivel' => true, 'ligado' => true], 'toast' => ['titulo' => 'Avisos ligados', 'texto' => 'Você recebe um aviso assim que sair vaga ou oferta na sua área.']], array_diff_key($ligou, ['ok' => 1]));
        self::assertTrue($this->engajamento()['alerta']['ligado']);
        self::assertSame((int) $a->getId(), json_decode((string) $this->em()->getConnection()->fetchOne("SELECT valor FROM trabalho_externo_preferencia WHERE chave = 'vagas.alerta'"), true)['escritorioId']);
        self::assertSame(['titulo' => 'Avisos desligados', 'texto' => ''], $this->postarJson($this->client, self::API . '/vagas/alerta', ['ligado' => false])['toast']);
        $this->postarJson($this->client, self::API . '/vagas/alerta', ['ligado' => 'sim']);
        self::assertResponseStatusCodeSame(422);
        $this->postarJson($this->client, self::API . '/vagas/alerta', ['ligado' => true], 'TOKEN_errado');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('alerta recusado ao interno, na prévia e com o sino desligado pelo Master')]
    public function testAlertaRecusado(): void
    {
        [$sede, $a] = [$this->criarTenant('Sede'), $this->criarTenant('A')];
        $this->definirSede($sede);
        [$master, $doA] = [$this->criarMaster($sede), $this->criarUsuario($a)];

        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, self::API . '/vagas/alerta', ['ligado' => true]);
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($this->client, self::API . '/vagas/alerta?como=solicitante', ['ligado' => true]);
        self::assertResponseStatusCodeSame(403, 'a escrita não lê a prévia: o Master continua interno');
        $this->postarJson($this->client, self::API . '/configuracao', ['vagas' => ['sino' => false]]);
        self::assertResponseIsSuccessful();

        $this->logarComTenant($this->client, $doA, $a);
        self::assertFalse($this->engajamento()['alerta']['disponivel']);
        $erro = $this->postarJson($this->client, self::API . '/vagas/alerta', ['ligado' => true]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Os avisos de vaga estão desligados pela Central.', $erro['mensagem']);
        self::assertSame(0, $this->contar("trabalho_externo_preferencia WHERE chave = 'vagas.alerta'"));
    }

    #[TestDox('avisos: pedido, aprovação, vaga na área, candidatura, envio, andamento e não aprovação, cada um no escritório certo e sem o nome do candidato')]
    public function testAvisosDosEventos(): void
    {
        [$sede, $a, $b, $c] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('B'), $this->criarTenant('C')];
        $this->definirSede($sede);
        [$master, $doA, $interessado] = [$this->criarMaster($sede), $this->criarUsuario($a, nome: 'Dono'), $this->criarUsuario($c, nome: 'Interessado')];
        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, self::API . '/configuracao', ['vagas' => ['dur' => 0]]);
        $this->logarComTenant($this->client, $interessado, $c);
        $this->postarJson($this->client, self::API . '/vagas/alerta', ['ligado' => true]);

        $codigo = $this->divulgar($doA, $a, 'Advogado trabalhista');
        self::assertSame([[(int) $master->getId(), (int) $sede->getId(), 'Novo pedido de divulgação de vaga: Advogado trabalhista', '/trabalho-externo#vaga=' . $codigo]], $this->daCentral($this->avisos('trabalho_externo_pedido_de_vaga'), $master, $sede));
        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, self::API . "/vagas/$codigo/acoes/aprovar");
        self::assertResponseIsSuccessful();
        self::assertSame([[(int) $doA->getId(), (int) $a->getId()]], $this->quem('trabalho_externo_vaga_aprovada'));
        self::assertSame([[(int) $interessado->getId(), (int) $c->getId()]], $this->quem('trabalho_externo_vaga_na_area'), 'quem ligou o aviso, sem currículo, no escritório dele');

        $candidato = $this->comCurriculo($b, 'Bia Lima');
        $this->postarJson($this->client, self::API . "/vagas/$codigo/candidatura");
        self::assertSame((int) $b->getId(), (int) $this->em()->getConnection()->fetchOne('SELECT escritorio_do_candidato FROM trabalho_externo_candidatura'));
        self::assertSame([[(int) $master->getId(), (int) $sede->getId()]], $this->daCentral($this->quem('trabalho_externo_candidatura'), $master, $sede));
        $this->postarJson($this->client, self::API . "/vagas/$codigo/candidatura");
        self::assertCount(1, $this->daCentral($this->quem('trabalho_externo_candidatura'), $master, $sede), 'a repetida não avisa de novo');

        $this->logarComTenant($this->client, $master, $sede);
        $id = (int) $this->em()->getConnection()->fetchOne('SELECT id FROM trabalho_externo_candidatura');
        $this->postarJson($this->client, self::API . "/vagas/$codigo/enviar", ['candidaturas' => [$id]]);
        self::assertSame([[(int) $doA->getId(), (int) $a->getId()]], $this->quem('trabalho_externo_candidatos_enviados'));
        self::assertSame([[(int) $candidato->getId(), (int) $b->getId()]], $this->quem('trabalho_externo_candidatura_status'), 'o envio avisa também o candidato');
        $this->em()->getConnection()->executeStatement("UPDATE notificacao SET lida = true WHERE tipo = 'trabalho_externo_candidatura_status'");
        $this->postarJson($this->client, self::API . "/candidaturas/$id/status", ['status' => 'entrevista']);
        self::assertSame([[(int) $candidato->getId(), (int) $b->getId()], [(int) $candidato->getId(), (int) $b->getId()]], $this->quem('trabalho_externo_candidatura_status'));

        $outra = $this->divulgar($doA, $a, 'Estagiário');
        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, self::API . "/vagas/$outra/acoes/reprovar", ['motivo' => 'Falta a remuneração']);
        self::assertSame('Motivo: Falta a remuneração', $this->em()->getConnection()->fetchOne("SELECT mensagem FROM notificacao WHERE tipo = 'trabalho_externo_vaga_reprovada'"));
        $todos = (string) json_encode($this->em()->getConnection()->fetchAllAssociative("SELECT titulo, mensagem FROM notificacao WHERE tipo LIKE 'trabalho_externo_%'"));
        self::assertStringNotContainsString('Bia', $todos);
        self::assertStringNotContainsString('cv@exemplo.test', $todos);

    }

    #[TestDox('sem sede definida, nenhum aviso da Central; a área diferente do currículo não recebe a vaga nova')]
    public function testSemSedeEAreaDiferente(): void
    {
        [$sede, $a, $c] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('C')];
        [$master, $doA] = [$this->criarMaster($sede), $this->criarUsuario($a)];
        $penal = $this->comCurriculo($c, 'Caio', ['Penal']);
        $this->postarJson($this->client, self::API . '/vagas/alerta', ['ligado' => true]);
        $this->divulgar($doA, $a, 'Sem sede');
        self::assertSame(0, $this->contar("notificacao WHERE tipo LIKE 'trabalho_externo_%'"));

        $this->publicar($master, $sede, self::vaga('Cível na Central'));
        self::assertSame([], $this->quem('trabalho_externo_vaga_na_area'), 'Penal não recebe vaga de Cível');
        $this->publicar($master, $sede, ['areas' => ['Penal']] + self::vaga('Penal na Central'));
        self::assertSame([[(int) $penal->getId(), (int) $c->getId()]], $this->quem('trabalho_externo_vaga_na_area'));
    }

    #[TestDox('"Solicitar informações" avisa quem solicitou, no escritório da demanda, com o link ?te=')]
    public function testPedidoDeInformacoes(): void
    {
        [$sede, $a] = [$this->criarTenant('Sede'), $this->criarTenant('A')];
        $this->definirSede($sede);
        [$master, $doA] = [$this->criarMaster($sede), $this->criarUsuario($a)];
        $this->logarComTenant($this->client, $doA, $a);
        $codigo = $this->solicitar($this->client, self::solicitacaoValida())['codigo'];

        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, self::API . "/demandas/$codigo/acoes/analise");
        $this->postarJson($this->client, self::API . "/demandas/$codigo/acoes/info", ['motivo' => 'Faltou a pauta']);
        self::assertResponseIsSuccessful();
        self::assertSame([[(int) $doA->getId(), (int) $a->getId(), 'Pedido de informações na demanda ' . $codigo, '/trabalho-externo?te=' . $codigo]], $this->avisos('trabalho_externo_demanda_informacoes'));
    }

    #[TestDox('"desde a sua última visita": a referência é a visita anterior; o aviso de visita depois de listar não zera o chip nem o NOVA')]
    public function testNovasDesdeAVisitaAnterior(): void
    {
        [$sede, $a] = [$this->criarTenant('Sede'), $this->criarTenant('A')];
        $master = $this->criarMaster($sede);
        $doA = $this->criarUsuario($a);
        $this->logarComTenant($this->client, $doA, $a);
        $this->postarJson($this->client, self::API . '/vagas/visita');
        self::assertSame([], array_column($this->engajamento()['banner']['chips'], 'txt', 'ic')['bi-stars'] ?? [], 'a primeira visita não tem anterior: nada é novo');
        $this->em()->getConnection()->executeStatement("UPDATE trabalho_externo_preferencia SET valor = ? WHERE chave = 'vagas.ultimaVisita'", [json_encode(['em' => (new \DateTimeImmutable('-2 hours'))->format(\DATE_ATOM), 'anterior' => null])]);
        $this->postarJson($this->client, self::API . '/vagas/visita');
        $codigo = $this->publicar($master, $sede, self::vaga('Nova depois da visita'));

        $this->logarComTenant($this->client, $doA, $a);
        $this->postarJson($this->client, self::API . '/vagas/visita');
        self::assertSame('1 vaga(s) nova(s) desde sua última visita', array_column($this->engajamento()['banner']['chips'], 'txt', 'ic')['bi-stars'] ?? null);
        $this->client->request('GET', self::API . '/vagas');
        $cartao = array_values(array_filter($this->json($this->client)['vagas']['vagas'], static fn (array $v): bool => $v['codigo'] === $codigo))[0];
        self::assertTrue($cartao['nova'], 'o NOVA continua depois do aviso de visita da mesma visita');
    }

    #[TestDox('banco de currículos: o atualizado mais recentemente primeiro')]
    public function testOrdemDoBancoPelaAtualizacao(): void
    {
        [$sede, $a, $b] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('B')];
        $master = $this->criarMaster($sede);
        $primeiro = $this->comCurriculo($a, 'Primeiro');
        $this->comCurriculo($b, 'Segundo');
        $this->em()->getConnection()->executeStatement("UPDATE trabalho_externo_curriculo SET recebido_em = recebido_em - INTERVAL '2 days', atualizado_em = atualizado_em - INTERVAL '2 days'");
        $this->logarComTenant($this->client, $primeiro, $a);
        $this->postarJson($this->client, self::API . '/curriculos/meu', self::curriculo('Primeiro'));

        $this->logarComTenant($this->client, $master, $sede);
        $this->client->request('GET', self::API . '/curriculos');
        self::assertSame(['Primeiro', 'Segundo'], array_column($this->json($this->client)['curriculos'], 'nome'));
    }

    /** @return array<string, mixed> */
    private function engajamento(string $query = ''): array
    {
        $this->client->request('GET', self::API . '/engajamento' . $query);
        self::assertResponseIsSuccessful();

        return $this->json($this->client);
    }

    private function divulgar(User $dono, Tenant $escritorio, string $titulo): string
    {
        $this->logarComTenant($this->client, $dono, $escritorio);
        $codigo = $this->postarJson($this->client, self::API . '/vagas', self::vaga($titulo))['codigo'];
        self::assertResponseIsSuccessful();

        return $codigo;
    }

    /** @param array<string, mixed> $vaga */
    private function publicar(User $master, Tenant $sede, array $vaga): string
    {
        $this->logarComTenant($this->client, $master, $sede);
        $codigo = $this->postarJson($this->client, self::API . '/vagas', $vaga)['codigo'];
        self::assertResponseIsSuccessful();

        return $codigo;
    }

    /** @param list<string> $areas */
    private function comCurriculo(Tenant $tenant, string $nome, array $areas = ['Cível']): User
    {
        $usuario = $this->criarUsuario($tenant, nome: $nome);
        $this->logarComTenant($this->client, $usuario, $tenant);
        $this->postarJson($this->client, self::API . '/curriculos/meu', ['areas' => $areas] + self::curriculo($nome));
        self::assertResponseIsSuccessful();

        return $usuario;
    }

    /** @return list<array{0: int, 1: int}> */
    private function quem(string $tipo): array
    {
        return array_map(static fn (array $l): array => [(int) $l['usuario_id'], (int) $l['tenant_id']], $this->em()->getConnection()->fetchAllAssociative('SELECT usuario_id, tenant_id FROM notificacao WHERE tipo = ? ORDER BY id', [$tipo]));
    }

    /** @return list<array{0: int, 1: int, 2: string, 3: string}> */
    private function avisos(string $tipo): array
    {
        return array_map(static fn (array $l): array => [(int) $l['usuario_id'], (int) $l['tenant_id'], (string) $l['titulo'], (string) $l['url']], $this->em()->getConnection()->fetchAllAssociative('SELECT usuario_id, tenant_id, titulo, url FROM notificacao WHERE tipo = ? ORDER BY id', [$tipo]));
    }

    /**
     * Os avisos da Central: todos na sede, e só os do Master deste teste (o banco pode ter outros Masters).
     *
     * @param list<list<int|string>> $linhas
     *
     * @return list<list<int|string>>
     */
    private function daCentral(array $linhas, User $master, Tenant $sede): array
    {
        foreach ($linhas as $l) {
            self::assertSame((int) $sede->getId(), $l[1], 'aviso da Central fora da sede');
        }

        return array_values(array_filter($linhas, static fn (array $l): bool => $l[0] === (int) $master->getId()));
    }

    private function contar(string $tabela): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabela);
    }

    private function semTalentos(): void
    {
        $repo = static::getContainer()->get(ConfiguracaoCentralRepository::class);
        $cfg = $repo->atual();
        $cfg->definirEquipe('talentos', false);
        $repo->salvar($cfg, flush: true);
    }

    /** @return array<string, mixed> */
    private static function vaga(string $titulo): array
    {
        return ['titulo' => $titulo, 'cargo' => 'Advogado(a)', 'contratacao' => 'CLT', 'cidade' => 'Brasília', 'uf' => 'DF', 'areas' => ['Cível'], 'ofertante' => ['nome' => 'Escritório que oferece']];
    }

    /** @return array<string, mixed> */
    private static function curriculo(string $nome): array
    {
        return [
            'nome' => $nome, 'email' => 'cv@exemplo.test', 'tel' => '(61) 99999-0000', 'situacao' => 'adv',
            'oabNum' => '12345', 'oabUf' => 'DF', 'cargo' => 'Advogado(a)', 'cidade' => 'Brasília', 'uf' => 'DF',
            'areas' => ['Cível'], 'resumo' => 'Atuação cível.', 'lgpd' => true,
        ];
    }
}
