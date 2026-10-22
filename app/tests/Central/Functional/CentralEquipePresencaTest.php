<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\CentralController;
use App\Central\UseCase\ListarEquipeDaCentralUseCase;
use App\Central\UseCase\RegistrarPresencaUseCase;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Profile\Entity\UserProfile;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Painel "Equipe · em aberto" e presença (F2, `docs/specs/central-comunicacao-painel-presenca.md`): o batimento grava
 * só o vínculo de quem pede; a equipe lista os colegas ATIVOS com o que têm em aberto (Central + sino deste
 * escritório) e on-line pelo último batimento (60 s).
 */
#[CoversClass(CentralController::class)]
#[CoversClass(RegistrarPresencaUseCase::class)]
#[CoversClass(ListarEquipeDaCentralUseCase::class)]
final class CentralEquipePresencaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    /** @return array<string, mixed>|false */
    private function linhaDePresenca(int $usuario, int $tenant): array|false
    {
        return $this->em()->getConnection()->fetchAssociative(
            'SELECT status, ultima_atividade, status_desde FROM central_presenca WHERE usuario_id = ? AND tenant_id = ?',
            [$usuario, $tenant],
        );
    }

    #[TestDox('Batimento: cria a linha do vínculo, atualiza sem duplicar; status_desde só anda quando o status muda')]
    public function testBatimento(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->logarComTenant($client, $ana, $tenant);

        $this->post($client, '/central/presenca', ['status' => 'disponivel']);
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'disponivel', 'online' => [['id' => $ana->getId(), 'status' => 'disponivel', 'semAtividadeMin' => null]]], $this->json($client));
        self::assertSame('disponivel', $this->linhaDePresenca((int) $ana->getId(), (int) $tenant->getId())['status']);

        // Mesmo status: a linha é a mesma e o "desde" não anda; status novo: "desde" anda.
        $this->em()->getConnection()->executeStatement(
            "UPDATE central_presenca SET status_desde = '2026-01-01 08:00:00', ultima_atividade = '2026-01-01 08:00:00' WHERE usuario_id = ?",
            [$ana->getId()],
        );
        $this->post($client, '/central/presenca', ['status' => 'disponivel']);
        $linha = $this->linhaDePresenca((int) $ana->getId(), (int) $tenant->getId());
        self::assertSame('2026-01-01 08:00:00', $linha['status_desde']);
        self::assertNotSame('2026-01-01 08:00:00', $linha['ultima_atividade']);
        $this->post($client, '/central/presenca', ['status' => 'ocupado']);
        $linha = $this->linhaDePresenca((int) $ana->getId(), (int) $tenant->getId());
        self::assertSame('ocupado', $linha['status']);
        self::assertNotSame('2026-01-01 08:00:00', $linha['status_desde']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM central_presenca WHERE usuario_id = ?', [$ana->getId()]));
    }

    #[TestDox('Batimento: status fora da lista é 422, sem CSRF é 403 — e nada é gravado')]
    public function testBatimentoRecusado(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->logarComTenant($client, $ana, $tenant);

        foreach (['', 'offline', 'DISPONIVEL', 'invisivel'] as $status) {
            $this->post($client, '/central/presenca', ['status' => $status]);
            self::assertResponseStatusCodeSame(422, 'status ' . var_export($status, true));
        }
        $client->request('POST', '/central/presenca', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['status' => 'disponivel']));
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->linhaDePresenca((int) $ana->getId(), (int) $tenant->getId()));
    }

    #[TestDox('Equipe: só colegas ATIVOS (eu marcado), em aberto = Central + sino deste escritório, on-line pelo último batimento')]
    public function testEquipe(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');
        $caio = $this->colega($tenant, 'caio');
        $exColega = $this->colega($tenant, 'ex');

        // Bia manda 2 mensagens para Ana (não lidas da Ana) e Ana responde 1 (não lida da Bia).
        $this->logarComTenant($client, $bia, $tenant);
        $conversa = $this->abrir($client, $ana);
        $this->enviar($client, $conversa, 'primeira');
        $this->enviar($client, $conversa, 'segunda');
        $this->post($client, '/central/presenca', ['status' => 'ausente']);
        $this->logarComTenant($client, $ana, $tenant);
        $this->enviar($client, $conversa, 'resposta');

        // Sino: 2 não lidas da Ana neste escritório, 1 lida (não conta), 1 em OUTRO escritório (não conta) e 1 aviso de
        // menção da própria Central (não conta: a mensagem já conta como não lida).
        // (Referências: depois das requisições HTTP as entidades do teste não estão mais no EntityManager.)
        $anaRef = $this->em()->getReference(User::class, $ana->getId());
        foreach ([[$tenant, false, 'teste'], [$tenant, false, 'teste'], [$tenant, true, 'teste'], [$outro, false, 'teste'], [$tenant, false, Notificacao::TIPO_CENTRAL_MENCAO]] as [$t, $lida, $tipo]) {
            $this->em()->persist((new Notificacao())->setUsuario($anaRef)->setTenant($this->em()->getReference(Tenant::class, $t->getId()))
                ->setTipo($tipo)->setTitulo('Aviso')->setLida($lida));
        }
        $this->em()->flush();
        $this->sairDoEscritorio($exColega, $tenant);
        $this->em()->flush();

        // Caio bateu há 5 min: off-line, "visto por último".
        // Pelo relógio do PHP (o mesmo que grava e compara), não pelo NOW() do banco, que está em outro fuso.
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO central_presenca (tenant_id, usuario_id, ultima_atividade, status, status_desde) VALUES (?, ?, ?, 'disponivel', ?)",
            [$tenant->getId(), $caio->getId(), (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s'), (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s')],
        );
        $this->post($client, '/central/presenca', ['status' => 'disponivel']);

        $client->request('GET', '/central/equipe');
        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        $porNome = [];
        foreach ($dados['equipe'] as $m) {
            $porNome[$m['nome']] = $m;
        }

        self::assertArrayNotHasKey((string) $exColega->getFullName(), $porNome, 'ex-colega não aparece');
        self::assertArrayHasKey((string) $caio->getFullName(), $porNome);
        $eu = $porNome[(string) $ana->getFullName()];
        self::assertTrue($eu['eu']);
        self::assertSame(4, $eu['emAberto'], '2 da Central + 2 do sino deste escritório');
        self::assertTrue($eu['online']);
        self::assertSame('disponivel', $eu['status']);
        self::assertNotNull($eu['desde']);

        $b = $porNome[(string) $bia->getFullName()];
        self::assertFalse($b['eu']);
        self::assertSame(1, $b['emAberto']);
        self::assertSame('ausente', $b['status']);

        $c = $porNome[(string) $caio->getFullName()];
        self::assertSame(['offline', false, null], [$c['status'], $c['online'], $c['desde']]);
        self::assertNotNull($c['vistoEm']);
        self::assertSame(0, $c['emAberto']);

        // Ordem do desenho: mais em aberto primeiro; total e on-line somam a equipe.
        self::assertSame((string) $ana->getFullName(), $dados['equipe'][0]['nome']);
        self::assertSame(array_sum(array_column($dados['equipe'], 'emAberto')), $dados['total']);
        self::assertSame(2, $dados['online']);
        // Só números: nenhum texto de mensagem ou de notificação sai.
        $bruto = (string) $client->getResponse()->getContent();
        foreach (['primeira', 'segunda', 'resposta', 'Aviso'] as $texto) {
            self::assertStringNotContainsString($texto, $bruto);
        }
    }

    #[TestDox('Equipe do painel de presença: a URL da foto do perfil de quem tem foto (a do avatar do topo), null para quem não tem; quem é de outro escritório não aparece')]
    public function testFotoNaEquipe(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');
        $deFora = $this->colega($outro, 'fora');
        foreach ([[$ana, 'foto_ana.jpg'], [$deFora, 'foto_fora.jpg']] as [$u, $arquivo]) {
            $this->em()->persist((new UserProfile($this->em()->getReference(User::class, $u->getId())))->setFotoUrl($arquivo));
        }
        $this->em()->flush();

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/equipe');
        self::assertResponseIsSuccessful();
        $porNome = array_column($this->json($client)['equipe'], null, 'nome');

        self::assertSame(static::getContainer()->get('router')->generate('app_profile_foto_serve', ['nome' => 'foto_ana.jpg']), $porNome[(string) $ana->getFullName()]['foto'], 'a própria foto, como no avatar do topo');
        self::assertNull($porNome[(string) $bia->getFullName()]['foto'], 'sem foto: as iniciais ficam com a tela');
        self::assertArrayNotHasKey((string) $deFora->getFullName(), $porNome);
        self::assertStringNotContainsString('foto_fora.jpg', (string) $client->getResponse()->getContent(), 'foto de outro escritório não vaza');
    }

    #[TestDox('On-line é o batimento dos últimos 60 s: 50 s atrás está on-line, 70 s atrás está off-line')]
    public function testLimiteDeSessentaSegundos(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');
        $caio = $this->colega($tenant, 'caio');
        foreach ([[$bia, '-50 seconds'], [$caio, '-70 seconds']] as [$quem, $quando]) {
            $hora = (new \DateTimeImmutable($quando))->format('Y-m-d H:i:s');
            $this->em()->getConnection()->executeStatement(
                "INSERT INTO central_presenca (tenant_id, usuario_id, ultima_atividade, status, status_desde) VALUES (?, ?, ?, 'ocupado', ?)",
                [$tenant->getId(), $quem->getId(), $hora, $hora],
            );
        }
        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/equipe');
        $status = [];
        foreach ($this->json($client)['equipe'] as $m) {
            $status[$m['nome']] = [$m['status'], $m['online']];
        }
        self::assertSame(['ocupado', true], $status[(string) $bia->getFullName()]);
        self::assertSame(['offline', false], $status[(string) $caio->getFullName()]);
    }

    #[TestDox('Batimento responde quem está on-line NESTE escritório (só ids, status e minutos): ex-colega, desligado e outro escritório ficam fora')]
    public function testBatimentoTrazQuemEstaOnline(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');
        $caio = $this->colega($tenant, 'caio');
        $ex = $this->colega($tenant, 'ex');
        $deFora = $this->colega($outro, 'fora');
        $agora = (new \DateTimeImmutable('-10 seconds'))->format('Y-m-d H:i:s');
        $inativaDesde = (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s');
        $velho = (new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s');
        foreach ([[$tenant, $bia, 'inativo', $agora, $inativaDesde], [$tenant, $caio, 'ocupado', $velho, $velho], [$tenant, $ex, 'disponivel', $agora, $agora], [$outro, $deFora, 'disponivel', $agora, $agora]] as [$t, $quem, $st, $bat, $desde]) {
            $this->em()->getConnection()->executeStatement(
                'INSERT INTO central_presenca (tenant_id, usuario_id, ultima_atividade, status, status_desde) VALUES (?, ?, ?, ?, ?)',
                [$t->getId(), $quem->getId(), $bat, $st, $desde],
            );
        }
        $this->em()->getConnection()->executeStatement('UPDATE user_tenant SET is_active = false WHERE user_id = ?', [$ex->getId()]);
        $this->logarComTenant($client, $ana, $tenant);

        $this->post($client, '/central/presenca', ['status' => 'disponivel']);
        self::assertResponseIsSuccessful();
        $online = [];
        foreach ($this->json($client)['online'] as $p) {
            self::assertSame(['id', 'status', 'semAtividadeMin'], array_keys($p), 'nada de nome, texto, ponto ou horas');
            $online[$p['id']] = [$p['status'], $p['semAtividadeMin']];
        }
        ksort($online);
        self::assertSame([$ana->getId() => ['disponivel', null], $bia->getId() => ['inativo', 65]], $online);
    }

    #[TestDox('Batimento aceita o status inativo (ausente por falta de atividade)')]
    public function testBatimentoInativo(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->logarComTenant($client, $ana, $tenant);

        $this->post($client, '/central/presenca', ['status' => 'inativo']);
        self::assertResponseIsSuccessful();
        self::assertSame('inativo', $this->linhaDePresenca((int) $ana->getId(), (int) $tenant->getId())['status']);
        self::assertSame(60, $this->json($client)['online'][0]['semAtividadeMin']);
    }

    #[TestDox('Sem vínculo ativo no escritório: equipe e batimento recusados')]
    public function testSemVinculo(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->logarComTenant($client, $ana, $tenant);
        $this->em()->getConnection()->executeStatement('UPDATE user_tenant SET is_active = false WHERE user_id = ?', [$ana->getId()]);

        // A plataforma redireciona quem perdeu o vínculo (302) antes do controller; a Central responderia 403. Nunca 2xx/5xx.
        $client->request('GET', '/central/equipe');
        self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
        $this->post($client, '/central/presenca', ['status' => 'disponivel']);
        self::assertContains($client->getResponse()->getStatusCode(), [302, 403]);
        self::assertFalse($this->linhaDePresenca((int) $ana->getId(), (int) $tenant->getId()));
    }
}
