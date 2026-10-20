<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\EncaminhamentoController;
use App\Central\Entity\Conversa;
use App\Central\Entity\Participante;
use App\Central\Service\AuditoriaDoDespachoNoAuditLog;
use App\Central\Service\GuardaDoEncaminhamento;
use App\Central\Service\LeituraDasMensagens;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * O "Encaminhar via Chat I.A" com o CADEADO da pasta (spec pasta-cadeado, §3: `AcessoAPasta::podeVer` passa pelo
 * `canAccessResource` com o cadeado como teto). Todas as pessoas daqui são colegas COMUNS (papel sem `isSystem` e
 * sem ROLE_SUPER_ADMIN), porque o Master e o primário sempre veem a pasta. O Master do escritório só tranca.
 *
 *  - pasta Oculta para quem ENVIA: destinos e envio dão 404 e nada é gravado;
 *  - pasta Oculta para quem RECEBE: ele some dos destinos, o envio para ele é 422 e o grupo com ele fica indisponível;
 *  - pasta trancada DEPOIS do despacho: quem ficou Oculto lê "Item de pasta sem acesso", os outros seguem vendo.
 */
#[CoversClass(EncaminhamentoController::class)]
#[CoversClass(GuardaDoEncaminhamento::class)]
#[CoversClass(LeituraDasMensagens::class)]
final class EncaminhamentoComCadeadoTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    use CentralHttpTrait;

    private const VE_PASTA = ['resources.pasta.view', 'resources.pasta.edit'];
    private const SEGREDO = 'SEGREDO-DO-CADEADO ligar para o cliente';

    #[TestDox('Pasta Oculta para quem envia (cadeado "Todos"): destinos e envio dão 404, nada gravado')]
    public function testRemetenteComCadeadoTodos(): void
    {
        $client = $this->cliente();
        [$master, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $pasta = $this->criarPasta($tenant);
        $registro = $this->criarAnotacao($tenant, $pasta, $ana, self::SEGREDO);
        $this->trancar($pasta, $master);

        $this->remetenteOcultoRecebe404($client, $tenant, $pasta, $ana, $bruno, (int) $registro->getId());
    }

    #[TestDox('Pasta Oculta para quem envia (cadeado por pessoa, sem ele na lista): destinos e envio dão 404, nada gravado')]
    public function testRemetenteForaDoCadeadoPorPessoa(): void
    {
        $client = $this->cliente();
        [$master, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $pasta = $this->criarPasta($tenant);
        $registro = $this->criarAnotacao($tenant, $pasta, $ana, self::SEGREDO);
        $this->trancar($pasta, $master, [[$bruno, NivelDeAcesso::Editar]]);

        $this->remetenteOcultoRecebe404($client, $tenant, $pasta, $ana, $bruno, (int) $registro->getId());
    }

    #[TestDox('Pasta Oculta para quem recebe e visível para quem envia (Visualizar ou Editar): ele some dos destinos, envio 422, grupo indisponível')]
    public function testDestinatarioOculto(): void
    {
        $client = $this->cliente();
        [$master, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $carla = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'carla');
        $pasta = $this->criarPasta($tenant);
        $grupo = $this->criarGrupo($tenant, 'Equipe com Carla', [$ana, $bruno, $carla]);
        $grupoSemCarla = $this->criarGrupo($tenant, 'Equipe sem Carla', [$ana, $bruno]);
        // Ana só visualiza, Bruno edita, Carla não está na lista (Oculto, padrão fechado).
        $this->trancar($pasta, $master, [[$ana, NivelDeAcesso::Visualizar], [$bruno, NivelDeAcesso::Editar]]);

        foreach ([$ana, $bruno] as $remetente) {
            $this->logarComTenant($client, $remetente, $tenant);
            $client->request('GET', '/central/encaminhar/destinos?pasta=' . $pasta->getId());
            self::assertResponseIsSuccessful('quem vê a pasta (Visualizar ou Editar) lista os destinos');
            $destinos = $this->json($client)['destinos'];

            $colegas = array_column(array_filter($destinos, static fn (array $d): bool => $d['tipo'] === 'colega'), 'usuarioId');
            self::assertNotContains($carla->getId(), $colegas, 'quem está Oculto no cadeado some dos destinos');
            $outro = $remetente === $ana ? $bruno : $ana;
            self::assertContains($outro->getId(), $colegas, 'controle: quem vê a pasta aparece');

            $comCarla = $this->doGrupo($destinos, $grupo);
            self::assertFalse($comCarla['disponivel']);
            self::assertSame('Nem todos deste grupo veem esta pasta.', $comCarla['motivo']);
            self::assertTrue($this->doGrupo($destinos, $grupoSemCarla)['disponivel'], 'controle: o grupo em que todos veem segue disponível');

            $this->encaminhar($client, $pasta, ['tipo' => 'pasta', 'usuarioId' => $carla->getId(), 'prioridade' => 'urgente']);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Este colega não vê esta pasta.', $this->json($client)['erro']);

            $this->encaminhar($client, $pasta, ['tipo' => 'pasta', 'conversaId' => $grupo->getId(), 'prioridade' => 'urgente']);
            self::assertResponseStatusCodeSame(422);
        }

        $this->nadaGravado($tenant, $pasta, [$carla, $bruno, $ana]);

        // Controle: o mesmo remetente envia ao colega que vê.
        $this->encaminhar($client, $pasta, ['tipo' => 'pasta', 'usuarioId' => $ana->getId()]);
        self::assertResponseStatusCodeSame(201);
    }

    #[TestDox('Pasta trancada depois do despacho: o membro que ficou Oculto lê "Item de pasta sem acesso"; os outros seguem vendo')]
    public function testLeituraDepoisDoCadeado(): void
    {
        $client = $this->cliente();
        [$master, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $carla = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'carla');
        $pasta = $this->criarPasta($tenant);
        $registro = $this->criarAnotacao($tenant, $pasta, $ana, '<p>' . self::SEGREDO . '</p>');
        $nup = (string) $pasta->getNup();
        $grupo = (int) $this->criarGrupo($tenant, 'Equipe', [$ana, $bruno, $carla])->getId();

        $this->logarComTenant($client, $ana, $tenant);
        $this->encaminhar($client, $pasta, ['tipo' => 'registro_dados', 'itemId' => $registro->getId(), 'conversaId' => $grupo]);
        self::assertResponseStatusCodeSame(201);

        // Controle: antes do cadeado, Carla lê o despacho.
        $this->logarComTenant($client, $carla, $tenant);
        $client->request('GET', "/central/conversas/$grupo/mensagens");
        $despachoId = $this->idDoDespacho($this->json($client)['mensagens']);
        self::assertStringContainsString(self::SEGREDO, $this->porId($this->json($client)['mensagens'])[$despachoId]['conteudo']);

        // O primário tranca: Ana edita, Bruno visualiza, Carla fica Oculta.
        $this->trancar($pasta, $master, [[$ana, NivelDeAcesso::Editar], [$bruno, NivelDeAcesso::Visualizar]]);

        $client->request('GET', "/central/conversas/$grupo/mensagens");
        self::assertResponseIsSuccessful();
        $corpo = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('SEGREDO-DO-CADEADO', $corpo);
        self::assertStringNotContainsString($nup, $corpo);
        self::assertStringNotContainsString('Despacho:', $corpo);
        $daCarla = $this->porId($this->json($client)['mensagens'])[$despachoId];
        self::assertSame('Item de pasta sem acesso', $daCarla['conteudo']);
        self::assertNull($daCarla['despacho']);
        self::assertTrue($daCarla['ocultoPorPermissao']);

        foreach ([$ana, $bruno] as $quem) {
            $this->logarComTenant($client, $quem, $tenant);
            $client->request('GET', "/central/conversas/$grupo/mensagens");
            $dele = $this->porId($this->json($client)['mensagens'])[$despachoId];
            self::assertStringContainsString(self::SEGREDO, $dele['conteudo']);
            self::assertStringContainsString("Pasta $nup", $dele['conteudo']);
            self::assertSame(['pastaId' => $pasta->getId(), 'nup' => $nup], $dele['despacho']);
            self::assertFalse($dele['ocultoPorPermissao']);
        }
    }

    private function remetenteOcultoRecebe404(KernelBrowser $client, Tenant $tenant, Pasta $pasta, User $remetente, User $destino, int $registroId): void
    {
        $grupo = $this->criarGrupo($tenant, 'Equipe', [$remetente, $destino]);
        $conversasAntes = $this->contar('SELECT COUNT(*) FROM central_conversa WHERE tenant_id = ?', [$tenant->getId()]);

        $this->logarComTenant($client, $remetente, $tenant);
        $client->request('GET', '/central/encaminhar/destinos?pasta=' . $pasta->getId());
        self::assertResponseStatusCodeSame(404);

        $this->encaminhar($client, $pasta, ['tipo' => 'pasta', 'usuarioId' => $destino->getId(), 'prioridade' => 'urgente']);
        self::assertResponseStatusCodeSame(404);
        $this->encaminhar($client, $pasta, ['tipo' => 'registro_dados', 'itemId' => $registroId, 'usuarioId' => $destino->getId(), 'prioridade' => 'urgente']);
        self::assertResponseStatusCodeSame(404);
        $this->encaminhar($client, $pasta, ['tipo' => 'pasta', 'conversaId' => $grupo->getId(), 'prioridade' => 'urgente']);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('SEGREDO-DO-CADEADO', (string) $client->getResponse()->getContent());

        self::assertSame($conversasAntes, $this->contar('SELECT COUNT(*) FROM central_conversa WHERE tenant_id = ?', [$tenant->getId()]), 'nenhuma conversa aberta');
        $this->nadaGravado($tenant, $pasta, [$destino, $remetente]);
    }

    /** @param list<User> $pessoas */
    private function nadaGravado(Tenant $tenant, Pasta $pasta, array $pessoas): void
    {
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM central_mensagem WHERE tenant_id = ?', [$tenant->getId()]), 'nenhuma mensagem');
        self::assertSame(0, $this->contar(
            'SELECT COUNT(*) FROM audit_log WHERE action = ? AND tenant_id = ? AND entity_id = ?',
            [AuditoriaDoDespachoNoAuditLog::ACAO, $tenant->getId(), (string) $pasta->getId()],
        ), 'nenhuma auditoria de despacho');
        foreach ($pessoas as $pessoa) {
            self::assertSame(0, $this->contar(
                'SELECT COUNT(*) FROM notificacao WHERE tipo = ? AND usuario_id = ?',
                [Notificacao::TIPO_CENTRAL_DESPACHO, $pessoa->getId()],
            ), 'nenhum aviso de despacho');
        }
    }

    /**
     * Tranca a pasta como o primário faria no modal. A pasta e as pessoas vêm de novo do banco: o EM é limpo
     * entre as requisições.
     *
     * @param list<array{0: User, 1: NivelDeAcesso}> $grade vazia = "Ocultar para todos"
     */
    private function trancar(Pasta $pasta, User $por, array $grade = []): void
    {
        $em = $this->em();
        $pasta = $em->find(Pasta::class, $pasta->getId()) ?? self::fail('pasta sumiu');
        $por = $em->find(User::class, $por->getId()) ?? self::fail('quem tranca sumiu');
        $agora = new \DateTimeImmutable();
        $cadeado = new CadeadoDaPasta($pasta->getTenant() ?? self::fail('pasta sem escritório'), $pasta, $grade === [] ? ModoDoCadeado::Todos : ModoDoCadeado::PorPessoa, $por, $agora);
        if ($grade !== []) {
            $cadeado->definirPorPessoa(
                array_map(static fn (array $linha): array => [$em->find(User::class, $linha[0]->getId()) ?? self::fail('pessoa sumiu'), $linha[1]], $grade),
                $por,
                $agora,
            );
        }
        $em->persist($cadeado);
        $em->flush();
    }

    /**
     * @param array<string, mixed> $corpo
     */
    private function encaminhar(KernelBrowser $client, Pasta $pasta, array $corpo): void
    {
        $this->post($client, '/central/encaminhar', ['pastaId' => $pasta->getId(), ...$corpo]);
    }

    /** @param list<User> $membros o primeiro cria e administra */
    private function criarGrupo(Tenant $tenant, string $nome, array $membros): Conversa
    {
        $em = $this->em();
        $agora = new \DateTimeImmutable();
        $tenant = $em->find(Tenant::class, $tenant->getId()) ?? self::fail('escritório sumiu');
        $membros = array_map(static fn (User $u): User => $em->find(User::class, $u->getId()) ?? self::fail('membro sumiu'), $membros);
        $grupo = Conversa::grupo($tenant, $membros[0], $nome, $agora);
        $em->persist($grupo);
        foreach ($membros as $membro) {
            $em->persist(new Participante($tenant, $grupo, $membro, $agora));
        }
        $em->flush();

        return $grupo;
    }

    /**
     * @param list<array<string, mixed>> $destinos
     *
     * @return array<string, mixed>
     */
    private function doGrupo(array $destinos, Conversa $grupo): array
    {
        $achados = array_values(array_filter($destinos, static fn (array $d): bool => $d['conversaId'] === $grupo->getId()));
        self::assertCount(1, $achados, 'o grupo aparece nos destinos');

        return $achados[0];
    }

    /** @param list<array<string, mixed>> $mensagens */
    private function idDoDespacho(array $mensagens): int
    {
        $despachos = array_values(array_filter($mensagens, static fn (array $m): bool => $m['despacho'] !== null));
        self::assertCount(1, $despachos);

        return (int) $despachos[0]['id'];
    }

    /**
     * @param list<array<string, mixed>> $mensagens
     *
     * @return array<int, array<string, mixed>>
     */
    private function porId(array $mensagens): array
    {
        return array_column($mensagens, null, 'id');
    }

    /** @param list<mixed> $parametros */
    private function contar(string $sql, array $parametros): int
    {
        return (int) $this->em()->getConnection()->fetchOne($sql, $parametros);
    }
}
