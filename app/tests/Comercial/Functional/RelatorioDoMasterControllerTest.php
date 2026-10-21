<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Controller\RelatorioDoMasterController;
use App\Comercial\Repository\CampanhaMarcaRepository;
use App\Comercial\Repository\RelatorioDeCampanhasRepository;
use App\Comercial\UseCase\AbrirCampanhaDoRelatorioUseCase;
use App\Comercial\UseCase\EncerrarCampanhaUseCase;
use App\Comercial\UseCase\ExportarDestinatariosUseCase;
use App\Comercial\UseCase\ListarRelatorioDeCampanhasUseCase;
use App\Comercial\UseCase\MarcarCampanhasDoRelatorioUseCase;
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
 * O "Relatório como caixa de entrada" pela tela (F3d; desenho 05 VS 21.10.26, L300-305 e L502-514; spec
 * comercial-relatorio.md): só o Master, CSRF no POST, a lista com as abas e as métricas agregadas do envio, a busca e a
 * página no SQL, o visualizador com KPIs, o HTML remontado e os destinatários, e o "Cancelar envio" sobre o
 * EncerrarCampanhaUseCase. Da F3e (spec comercial-marcas.md): as marcas de cada Master (estrela, arquivo e remoção),
 * as abas Com estrela e Arquivadas e o "Exportar destinatários" em CSV.
 */
#[CoversClass(RelatorioDoMasterController::class)]
#[CoversClass(ListarRelatorioDeCampanhasUseCase::class)]
#[CoversClass(AbrirCampanhaDoRelatorioUseCase::class)]
#[CoversClass(RelatorioDeCampanhasRepository::class)]
#[CoversClass(EncerrarCampanhaUseCase::class)]
#[CoversClass(MarcarCampanhasDoRelatorioUseCase::class)]
#[CoversClass(CampanhaMarcaRepository::class)]
#[CoversClass(ExportarDestinatariosUseCase::class)]
final class RelatorioDoMasterControllerTest extends JusPrimeWebTestCase
{
    use CriaCampanhas;

    private User $master;

    #[TestDox('Quem não é Master não lista, não abre nem cancela: 403')]
    public function testSoMaster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório F3d ' . uniqid());
        $em->persist($tenant);
        $comum = $this->usuario('comum');
        $em->persist(new UserTenant($comum, $tenant));
        $em->flush();
        $id = $this->campanha(['a@exemplo.com']);
        $this->logarComTenant($client, $comum, $tenant);

        $client->request('GET', '/master/comercial/relatorio');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/master/comercial/campanhas/' . $id);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/master/comercial/campanhas/' . $id . '/cancelar', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('na_fila', $this->estado($id));
        $client->request('POST', '/master/comercial/relatorio/marcas', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['ids' => [$id], 'acao' => 'estrela']));
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/master/comercial/campanhas/' . $id . '/destinatarios.csv');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM comercial_campanha_marca WHERE campanha_id = ?', [$id]));
        self::assertSame(0, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'camp_csv' AND entity_id = ?", [(string) $id]));
    }

    #[TestDox('Cancelar sem o token CSRF: 403 e a campanha segue na fila')]
    public function testCsrf(): void
    {
        $client = $this->clienteMaster();
        $id = $this->campanha(['a@exemplo.com']);
        $client->request('POST', '/master/comercial/campanhas/' . $id . '/cancelar', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('na_fila', $this->estado($id));
    }

    #[TestDox('A lista: contagem das 3 abas (o "teste" cai em Enviadas), selo e métricas agregadas de comercial_envio')]
    public function testListaComContagens(): void
    {
        $client = $this->clienteMaster();
        $this->campanha(['a@exemplo.com'], ['estado' => 'agendada', 'agendada_para' => new \DateTimeImmutable('+2 days')]);
        $this->campanha(['a@exemplo.com'], ['estado' => 'recorrente', 'frequencia' => 'mensal']);
        $teste = $this->campanha(['a@exemplo.com'], ['estado' => 'teste']);
        $enviada = $this->campanha(['a@exemplo.com'], ['estado' => 'enviada', 'total' => 5]);
        $this->destino($enviada, 'lido@exemplo.com', 'enviado', aberto: true);
        $this->destino($enviada, 'entregue@exemplo.com', 'enviado');
        $this->destino($enviada, 'com.erro@exemplo.com', 'enviado', erro: 'aviso do SMTP');
        $this->destino($enviada, 'recusado@exemplo.com', 'falhou', erro: '550');
        $this->destino($enviada, 'fila@exemplo.com', null);

        $client->request('GET', '/master/comercial/relatorio');
        self::assertResponseIsSuccessful();
        $d = $this->json($client);

        self::assertSame(['env' => 2, 'ag' => 1, 'rec' => 1, 'est' => 0, 'arq' => 0], $d['abas']);
        self::assertSame(['env', '1 a 2 de 2'], [$d['aba'], $d['faixa']]);
        self::assertSame([$enviada, $teste], array_column($d['campanhas'], 'id'));
        self::assertSame(['Enviada', 'Teste'], array_column($d['campanhas'], 'rotulo'));
        $m = $d['campanhas'][0]['metricas'];
        self::assertSame(
            ['destinatarios' => 5, 'naFila' => 1, 'enviados' => 4, 'entregues' => 2, 'lidos' => 1, 'naoLidos' => 1, 'rejeitados' => 1, 'leitura' => 'lido', 'percentual' => '25%'],
            array_intersect_key($m, array_flip(['destinatarios', 'naFila', 'enviados', 'entregues', 'lidos', 'naoLidos', 'rejeitados', 'leitura', 'percentual'])),
        );
        self::assertStringStartsWith("Enviados: 4\nEntregues (recebidos): 2 (50%)\nAbertos e lidos: 1 (25%)\nNão lidos: 1\nRejeitados: 1", $m['dica']);
        self::assertSame(['Individual', 5, 'Convite'], [$d['campanhas'][0]['publico'], $d['campanhas'][0]['total'], $d['campanhas'][0]['sub']]);

        $client->request('GET', '/master/comercial/relatorio?aba=rec');
        self::assertSame(['Recorrente', 'Convite · mensal'], [$this->json($client)['campanhas'][0]['rotulo'], $this->json($client)['campanhas'][0]['sub']]);
    }

    #[TestDox('Busca por campanha, pelo rótulo do público ou por e-mail de destinatário (o % e o _ são literais) e paginação de 50 no SQL')]
    public function testBuscaEPaginacao(): void
    {
        $client = $this->clienteMaster();
        $natal = $this->campanha(['a@exemplo.com'], ['assunto' => 'Natal especial']);
        $primarios = $this->campanha(['a@exemplo.com'], ['publico' => 'prim']);
        $comDestino = $this->campanha(['a@exemplo.com']);
        $this->destino($comDestino, 'procurado.f3d@exemplo.com', 'enviado');
        for ($i = 0; $i < 48; ++$i) {
            $this->campanha(['a@exemplo.com']);
        }

        $client->request('GET', '/master/comercial/relatorio?q=NATAL');
        self::assertSame([$natal], array_column($this->json($client)['campanhas'], 'id'));
        $client->request('GET', '/master/comercial/relatorio?q=procurado.f3d');
        self::assertSame([$comDestino], array_column($this->json($client)['campanhas'], 'id'));
        $client->request('GET', '/master/comercial/relatorio?q=' . rawurlencode('primários'));
        self::assertSame([$primarios], array_column($this->json($client)['campanhas'], 'id'));
        $client->request('GET', '/master/comercial/relatorio?q=' . rawurlencode('_zz%'));
        self::assertSame(0, $this->json($client)['total']);

        $client->request('GET', '/master/comercial/relatorio');
        $p1 = $this->json($client);
        self::assertSame([51, 2, '1 a 50 de 51', 50], [$p1['total'], $p1['paginas'], $p1['faixa'], \count($p1['campanhas'])]);
        $client->request('GET', '/master/comercial/relatorio?p=9');
        $p2 = $this->json($client);
        self::assertSame([2, '51 a 51 de 51', [$natal]], [$p2['pagina'], $p2['faixa'], array_column($p2['campanhas'], 'id')]);
        self::assertSame(['env' => 51, 'ag' => 0, 'rec' => 0, 'est' => 0, 'arq' => 0], $p2['abas']);
    }

    #[TestDox('O visualizador: os 4 KPIs, o HTML remontado do conteúdo congelado e os destinatários com o estado; inexistente é 404')]
    public function testDetalhe(): void
    {
        $client = $this->clienteMaster();
        $id = $this->campanha(['a@exemplo.com'], ['estado' => 'enviada', 'publico' => 'prim', 'total' => 3]);
        $this->destino($id, 'lido@exemplo.com', 'enviado', aberto: true);
        $this->destino($id, 'entregue@exemplo.com', 'enviado');
        $this->destino($id, 'fila@exemplo.com', null);
        $this->destino($id, str_repeat('a', 64), 'enviado');

        $client->request('GET', '/master/comercial/campanhas/' . $id);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Security-Policy', 'sandbox');
        $c = $this->json($client);

        self::assertSame([
            ['rotulo' => 'Destinatários', 'valor' => '4', 'icone' => 'bi-people'],
            ['rotulo' => 'Enviados', 'valor' => '3', 'icone' => 'bi-check2'],
            ['rotulo' => 'Lidos', 'valor' => '1', 'icone' => 'bi-check2-all'],
            ['rotulo' => 'Taxa de leitura', 'valor' => '33%', 'icone' => 'bi-graph-up'],
        ], $c['kpis']);
        self::assertSame(['Seu escritório 100% digital, de graça', 'Enviada', 'Usuários primários', 'Convite', false, true], [$c['assunto'], $c['situacao'], $c['publico'], $c['modelo'], $c['cancelavel'], $c['iniciada']]);
        self::assertStringContainsString('Dra. Ana, conheça o escritório digital BlueJus', (string) $c['html']);
        self::assertStringContainsString('<html', (string) $c['html']);
        self::assertSame([
            ['email' => 'lido@exemplo.com', 'estado' => 'lido', 'lido' => true],
            ['email' => 'entregue@exemplo.com', 'estado' => 'enviado · aguardando leitura', 'lido' => false],
            ['email' => 'fila@exemplo.com', 'estado' => 'na fila', 'lido' => false],
            ['email' => 'endereço anonimizado', 'estado' => 'enviado · aguardando leitura', 'lido' => false],
        ], $c['destinatarios']);
        self::assertSame(0, $c['mais']);

        $client->request('GET', '/master/comercial/campanhas/999999999');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Cancelar envio: a agendada vira cancelada com "Cancelada pelo Master" e o Master na trilha; repetir é 422')]
    public function testCancelar(): void
    {
        $client = $this->clienteMaster();
        $id = $this->campanha(['a@exemplo.com'], ['estado' => 'agendada', 'agendada_para' => new \DateTimeImmutable('+2 days'), 'proxima' => new \DateTimeImmutable('+2 days')]);
        $client->request('GET', '/master/comercial/campanhas/' . $id);
        self::assertTrue($this->json($client)['cancelavel']);

        $this->cancelar($client, $id);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Campanha ' . $id . ' encerrada.', (string) $this->json($client)['mensagem']);
        self::assertSame(['cancelada', 'Cancelada pelo Master'], array_values((array) $this->conexao()->fetchAssociative('SELECT estado, motivo FROM comercial_campanha WHERE id = ?', [$id])));
        self::assertSame((string) $this->master->getId(), (string) $this->conexao()->fetchOne("SELECT actor_user_id FROM audit_log WHERE action = 'camp_fim' AND entity_id = ?", [(string) $id]));

        $this->cancelar($client, $id);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => EncerrarCampanhaUseCase::NAO_ATIVA], $this->json($client));
    }

    #[TestDox('Cancelar a enviada sem nada na fila: 422 (o EncerrarCampanha não alcança) e o botão não aparece')]
    public function testCancelarEnviadaSemFila(): void
    {
        $client = $this->clienteMaster();
        $id = $this->campanha(['a@exemplo.com'], ['estado' => 'enviada']);
        $client->request('GET', '/master/comercial/campanhas/' . $id);
        self::assertFalse($this->json($client)['cancelavel']);

        $this->cancelar($client, $id);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('enviada', $this->estado($id));
    }

    #[TestDox('Marcas sem o token CSRF: 403 e nada é gravado')]
    public function testCsrfDasMarcas(): void
    {
        $client = $this->clienteMaster();
        $id = $this->campanha(['a@exemplo.com']);
        $client->request('POST', '/master/comercial/relatorio/marcas', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'errado'], (string) json_encode(['ids' => [$id], 'acao' => 'estrela']));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM comercial_campanha_marca WHERE campanha_id = ?', [$id]));
    }

    #[TestDox('Abas Com estrela e Arquivadas: a arquivada só aparece em Arquivadas, a estrela arquivada não conta em Com estrela, e as contagens seguem cada regra')]
    public function testAbasDasMarcas(): void
    {
        $client = $this->clienteMaster();
        $env = $this->campanha(['a@exemplo.com']);
        $estrelada = $this->campanha(['a@exemplo.com'], ['estado' => 'agendada', 'agendada_para' => new \DateTimeImmutable('+2 days')]);
        $arquivada = $this->campanha(['a@exemplo.com'], ['estado' => 'recorrente', 'frequencia' => 'mensal']);

        $this->marcar($client, [$estrelada, $arquivada], 'estrela');
        self::assertResponseIsSuccessful();
        self::assertSame(['marcadas' => 2], $this->json($client));
        $this->marcar($client, [$arquivada], 'arquivar');

        self::assertSame(['env' => 1, 'ag' => 1, 'rec' => 0, 'est' => 1, 'arq' => 1], $this->aba($client, 'env')['abas']);
        self::assertSame([$env], array_column($this->aba($client, 'env')['campanhas'], 'id'));
        self::assertSame([[$estrelada, true]], array_map(static fn (array $c): array => [$c['id'], $c['estrela']], $this->aba($client, 'est')['campanhas']));
        self::assertSame([$arquivada], array_column($this->aba($client, 'arq')['campanhas'], 'id'));
        self::assertSame([], $this->aba($client, 'rec')['campanhas']);
        self::assertFalse($this->aba($client, 'env')['campanhas'][0]['estrela']);

        $this->marcar($client, [$arquivada], 'desarquivar');
        $this->marcar($client, [$estrelada], 'sem_estrela');
        self::assertSame(['env' => 1, 'ag' => 1, 'rec' => 1, 'est' => 1, 'arq' => 0], $this->aba($client, 'env')['abas']);
        self::assertSame([$arquivada], array_column($this->aba($client, 'est')['campanhas'], 'id'));
    }

    #[TestDox('Remover esconde a campanha de todas as abas e das contagens só para quem removeu; nada da campanha é apagado')]
    public function testRemoverSoParaQuemRemoveu(): void
    {
        $client = $this->clienteMaster();
        $removida = $this->campanha(['a@exemplo.com']);
        $fica = $this->campanha(['a@exemplo.com']);
        $this->marcar($client, [$removida], 'estrela');
        $this->marcar($client, [$removida], 'remover');

        $lista = $this->aba($client, 'env');
        self::assertSame([[$fica], 1, 0], [array_column($lista['campanhas'], 'id'), $lista['abas']['env'], $lista['abas']['est']]);
        self::assertSame(0, $this->aba($client, 'est')['total']);
        self::assertSame('na_fila', $this->estado($removida), 'a campanha continua lá');

        $outro = $this->usuario('master2');
        $outro->setRoles(['ROLE_SUPER_ADMIN']);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($outro);
        $this->marcarTermosAceitos($client);
        $lista = $this->aba($client, 'env');
        self::assertSame([$fica, $removida], array_column($lista['campanhas'], 'id'));
        self::assertSame([false, false], array_column($lista['campanhas'], 'estrela'), 'a estrela é de quem marcou');
        self::assertSame(0, $lista['abas']['est']);
    }

    #[TestDox('Marcas recusadas em 422 (ação inválida, mais de 50, corpo que não é JSON); id inexistente é ignorado')]
    public function testMarcasRecusadas(): void
    {
        $client = $this->clienteMaster();
        $id = $this->campanha(['a@exemplo.com']);

        $this->marcar($client, [$id], 'apagar');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Ação inválida.'], $this->json($client));
        $this->marcar($client, range(1, 51), 'estrela');
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', '/master/comercial/relatorio/marcas', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial'], 'não é json');
        self::assertResponseStatusCodeSame(422);

        $this->marcar($client, [999999999, $id], 'arquivar');
        self::assertResponseIsSuccessful();
        self::assertSame(['marcadas' => 1], $this->json($client));
    }

    #[TestDox('Exportar destinatários: o CSV completo e o só de e-mails, com BOM, todos os destinatários (sem o anonimizado) e a auditoria camp_csv sem e-mails')]
    public function testExportarDestinatarios(): void
    {
        $client = $this->clienteMaster();
        $id = $this->campanha(['a@exemplo.com'], ['estado' => 'enviada']);
        $this->destino($id, 'lido@exemplo.com', 'enviado', aberto: true);
        $this->destino($id, 'recusado@exemplo.com', 'falhou', erro: '550');
        $this->destino($id, 'fila@exemplo.com', null);
        $this->destino($id, str_repeat('b', 64), 'enviado');

        $client->request('GET', '/master/comercial/campanhas/' . $id . '/destinatarios.csv');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=utf-8');
        self::assertSame('attachment; filename=destinatarios-' . $id . '.csv', $client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(
            "\u{FEFF}E-mail;Enviado;Leitura\nlido@exemplo.com;sim;não rastreada\nrecusado@exemplo.com;sim;não rastreada\nfila@exemplo.com;não;não rastreada\n",
            $client->getResponse()->getContent(),
        );

        $client->request('GET', '/master/comercial/campanhas/' . $id . '/destinatarios.csv?formato=emails');
        self::assertSame("\u{FEFF}E-mail\nlido@exemplo.com\nrecusado@exemplo.com\nfila@exemplo.com\n", $client->getResponse()->getContent());

        $auditoria = $this->conexao()->fetchAllAssociative("SELECT changes, actor_user_id FROM audit_log WHERE action = 'camp_csv' AND entity_id = ? ORDER BY id", [(string) $id]);
        self::assertSame([['linhas' => 3, 'formato' => 'completo'], ['linhas' => 3, 'formato' => 'emails']], array_map(static fn (array $l): mixed => json_decode((string) $l['changes'], true), $auditoria));
        self::assertSame((string) $this->master->getId(), (string) $auditoria[0]['actor_user_id']);
        self::assertStringNotContainsString('@exemplo.com', (string) json_encode(array_column($auditoria, 'changes')));

        $client->request('GET', '/master/comercial/campanhas/999999999/destinatarios.csv');
        self::assertResponseStatusCodeSame(404);
    }

    /** @param list<int> $ids */
    private function marcar(KernelBrowser $client, array $ids, string $acao): void
    {
        $client->request('POST', '/master/comercial/relatorio/marcas', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial'], (string) json_encode(['ids' => $ids, 'acao' => $acao]));
    }

    /** @return array<string, mixed> */
    private function aba(KernelBrowser $client, string $aba): array
    {
        $client->request('GET', '/master/comercial/relatorio?aba=' . $aba);

        return $this->json($client);
    }

    private function cancelar(KernelBrowser $client, int $id): void
    {
        $client->request('POST', '/master/comercial/campanhas/' . $id . '/cancelar', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial'], '{}');
    }

    /** Um destinatário materializado; com situação, o envio dele em comercial_envio (sem situação: ainda não saiu). */
    private function destino(int $campanhaId, string $email, ?string $situacao, bool $aberto = false, ?string $erro = null): void
    {
        $envioId = null;
        if ($situacao !== null) {
            $envioId = (int) $this->conexao()->fetchOne(
                "INSERT INTO comercial_envio (token, destinatario, assunto, tipo, lista, origem, situacao, erro, criado_em, enviado_em, primeira_abertura_em, aberturas, cliques)
                 VALUES (:token, :para, 'Assunto', 'lista', 'comercial_campanhas', :origem, :situacao, :erro, NOW(), NOW(), :aberto, :aberturas, 0) RETURNING id",
                ['token' => bin2hex(random_bytes(16)), 'para' => $email, 'origem' => 'campanha:' . $campanhaId, 'situacao' => $situacao,
                    'erro' => $erro, 'aberto' => $aberto ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null, 'aberturas' => $aberto ? 1 : 0],
            );
        }
        $this->conexao()->executeStatement(
            'INSERT INTO comercial_campanha_destino (campanha_id, execucao, email, envio_id, criado_em) VALUES (?, 1, ?, ?, NOW())',
            [$campanhaId, $email, $envioId],
        );
        $this->conexao()->executeStatement('UPDATE comercial_campanha SET execucoes = 1 WHERE id = ?', [$campanhaId]);
    }

    private function estado(int $id): string
    {
        return (string) $this->conexao()->fetchOne('SELECT estado FROM comercial_campanha WHERE id = ?', [$id]);
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
