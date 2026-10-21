<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Campanha\ChaveDasCampanhas;
use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\Controller\CampanhaDoMasterController;
use App\Comercial\Message\ExpandirCampanhas;
use App\Comercial\Repository\CadastradosRepository;
use App\Comercial\UseCase\ConfirmarCampanhaUseCase;
use App\Comercial\UseCase\ContarPublicosUseCase;
use App\Comercial\UseCase\ListarCampanhasUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Profile\Enum\Genero;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tests\Comercial\Support\CriaCampanhas;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A 2b pela tela (spec comercial-e-emails.md §8.1, §8.4 a §8.7): a contagem dos públicos, o "Enviar campanha" (chave
 * desligada recusa, idempotência, data passada, sem destinatário, B1 e descadastro, a trilha manual sem o assunto) e a
 * lista de campanhas. Só o Master, CSRF no header, 422 com {erro}. Nada sai no confirmar: a campanha nasce e o expansor
 * é acordado.
 */
#[CoversClass(CampanhaDoMasterController::class)]
#[CoversClass(ContarPublicosUseCase::class)]
#[CoversClass(ConfirmarCampanhaUseCase::class)]
#[CoversClass(ListarCampanhasUseCase::class)]
#[CoversClass(CadastradosRepository::class)]
final class CampanhasDoMasterControllerTest extends JusPrimeWebTestCase
{
    use CriaCampanhas;

    private User $master;

    #[TestDox('Quem não é Master não confirma nem lista campanhas: 403')]
    public function testSoMaster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório 2b ' . uniqid());
        $em->persist($tenant);
        $comum = $this->usuario('comum');
        $em->persist(new UserTenant($comum, $tenant));
        $em->flush();
        $this->logarComTenant($client, $comum, $tenant);
        $client->request('GET', '/master/comercial/campanhas');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/master/comercial/campanhas', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contar('comercial_campanha'));
    }

    #[TestDox('Sem o token CSRF: 403 na contagem e no confirmar')]
    public function testCsrf(): void
    {
        $master = $this->clienteMaster();
        foreach (['/master/comercial/publicos/contagem', '/master/comercial/campanhas'] as $url) {
            $master->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
            self::assertResponseStatusCodeSame(403, $url);
        }
        self::assertSame(0, $this->contar('comercial_campanha'));
    }

    #[TestDox('Chave da instalação desligada (padrão): "Enviar campanha" é recusado com a mensagem da spec e nada é gravado')]
    public function testChaveDesligadaRecusa(): void
    {
        $client = $this->clienteMaster(ligada: false);
        $this->postar($client, '/master/comercial/campanhas', $this->pedido([(string) $this->cadastradoAtivo()->getEmail()]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('O envio de campanhas está desligado nesta instalação.', $this->json($client)['erro']);
        self::assertSame(0, $this->contar('comercial_campanha'));
    }

    #[TestDox('Confirmar: nasce na fila com a cópia do conteúdo, audita camp_conf só com {publico, frequencia, total} e acorda o expansor; nada sai')]
    public function testConfirmar(): void
    {
        $client = $this->clienteMaster();
        $ana = $this->cadastradoAtivo('ana');
        $this->postar($client, '/master/comercial/campanhas', $this->pedido([(string) $ana->getEmail()]));

        self::assertResponseIsSuccessful();
        $saida = $this->json($client);
        self::assertSame('Campanha enfileirada: 1 e-mail(s). O servidor envia em lotes e registra as entregas.', $saida['mensagem']);
        $campanha = $this->conexao()->fetchAssociative('SELECT * FROM comercial_campanha WHERE id = ?', [$saida['id']]);
        self::assertIsArray($campanha);
        self::assertSame(['na_fila', 'indiv', 'unica', 1, 'Convite · novos escritórios', 'convite', null], [$campanha['estado'], $campanha['publico'], $campanha['frequencia'], $campanha['total'], $campanha['modelo_nome'], $campanha['modelo_chave'], $campanha['agendada_para']]);
        self::assertSame([['email' => $ana->getEmail(), 'nome' => '']], json_decode((string) $campanha['publico_parametros'], true));
        $trilha = (string) $this->conexao()->fetchOne("SELECT changes FROM audit_log WHERE action = 'camp_conf' AND entity_id = ?", [(string) $saida['id']]);
        self::assertSame(['publico' => 'indiv', 'frequencia' => 'unica', 'total' => 1], json_decode($trilha, true));
        self::assertStringNotContainsString((string) $campanha['assunto'], $trilha);
        self::assertSame(0, $this->contar('comercial_envio'), 'nada sai no confirmar');
        $fila = static::getContainer()->get('messenger.transport.comercial');
        \assert($fila instanceof InMemoryTransport);
        self::assertCount(1, array_filter($fila->getSent(), static fn ($e): bool => $e->getMessage() instanceof ExpandirCampanhas));
    }

    #[TestDox('Contagem com filtro (§9): "N de M atendem", K sem a informação; o selo conta os campos; a grade não muda')]
    public function testContagemComFiltro(): void
    {
        $client = $this->clienteMaster();
        [$ana, $beto, $caio] = $this->tresComPerfil();
        $emails = [(string) $ana->getEmail(), (string) $beto->getEmail(), (string) $caio->getEmail()];

        $this->postar($client, '/master/comercial/publicos/contagem', ['publico' => 'indiv', 'individual' => implode(', ', $emails), 'filtro' => ['gen' => 'F']]);

        self::assertResponseIsSuccessful();
        $saida = $this->json($client);
        self::assertSame(['ativo' => true, 'quantos' => 1, 'base' => 3, 'atendem' => 1, 'semInformacao' => 1], $saida['filtro']);
        self::assertSame(1, $saida['destinatarios']);
        self::assertSame(3, $saida['publicos']['indiv'], 'a grade conta a lista sem o filtro (dc L640)');
    }

    #[TestDox('Confirmar com filtro: o total é o filtrado, e a campanha guarda a lista inteira mais o filtro para reaplicar')]
    public function testConfirmarCongelaOFiltro(): void
    {
        $client = $this->clienteMaster();
        [$ana, $beto, $caio] = $this->tresComPerfil();
        $emails = [(string) $ana->getEmail(), (string) $beto->getEmail(), (string) $caio->getEmail()];

        $this->postar($client, '/master/comercial/campanhas', $this->pedido($emails, ['filtro' => ['gen' => 'F', 'q' => 'ana']]));

        self::assertResponseIsSuccessful();
        $campanha = $this->conexao()->fetchAssociative('SELECT total, publico_parametros FROM comercial_campanha WHERE id = ?', [$this->json($client)['id']]);
        self::assertIsArray($campanha);
        self::assertSame(1, $campanha['total']);
        $parametros = json_decode((string) $campanha['publico_parametros'], true);
        self::assertSame(['q' => 'ana', 'gen' => 'F'], $parametros['filtro'] ?? null);
        self::assertSame($emails, array_column($parametros['informados'], 'email'));
    }

    #[TestDox('Filtro com valor fora do desenho é recusado (422)')]
    public function testFiltroInvalido(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/publicos/contagem', ['publico' => 'prim', 'filtro' => ['gen' => 'X']]);

        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Idempotência: a mesma chave duas vezes (clique duplo) devolve a mesma campanha; uma linha e uma trilha só')]
    public function testIdempotencia(): void
    {
        $client = $this->clienteMaster();
        $pedido = $this->pedido([(string) $this->cadastradoAtivo()->getEmail()]);
        $this->postar($client, '/master/comercial/campanhas', $pedido);
        $primeira = $this->json($client);
        $this->postar($client, '/master/comercial/campanhas', $pedido);

        self::assertResponseIsSuccessful();
        self::assertSame($primeira, $this->json($client));
        self::assertSame(1, $this->contar('comercial_campanha'));
        self::assertSame(1, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'camp_conf'"));
    }

    #[TestDox('Recusas: data no passado ("Escolha uma data futura."), nenhum destinatário, assunto vazio')]
    public function testRecusas(): void
    {
        $client = $this->clienteMaster();
        $email = (string) $this->cadastradoAtivo()->getEmail();
        $this->postar($client, '/master/comercial/campanhas', $this->pedido([$email], ['agendar' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i')]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Escolha uma data futura.', $this->json($client)['erro']);

        $this->postar($client, '/master/comercial/campanhas', $this->pedido(['ninguem_' . uniqid() . '@exemplo.com']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Nenhum destinatário nesta lista.', $this->json($client)['erro']);

        $pedido = $this->pedido([$email]);
        $pedido['modelo']['assunto'] = ' ';
        $this->postar($client, '/master/comercial/campanhas', $pedido);
        self::assertSame('Informe o assunto e o conteúdo do e-mail.', $this->json($client)['erro']);
        self::assertSame(0, $this->contar('comercial_campanha'));
    }

    #[TestDox('Agendada e recorrente: com data, "agendada"; recorrente sem data começa agora e guarda a âncora')]
    public function testAgendadaERecorrente(): void
    {
        $client = $this->clienteMaster();
        $email = (string) $this->cadastradoAtivo()->getEmail();
        $data = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
        $this->postar($client, '/master/comercial/campanhas', $this->pedido([$email], ['agendar' => $data]));
        $agendada = $this->json($client);
        $this->postar($client, '/master/comercial/campanhas', $this->pedido([$email], ['frequencia' => 'mensal']));
        $recorrente = $this->json($client);

        self::assertStringStartsWith('Campanha agendada: 1 e-mail(s).', $agendada['mensagem']);
        self::assertSame(['agendada', str_replace('T', ' ', $data) . ':00'], array_values((array) $this->conexao()->fetchAssociative('SELECT estado, proxima_execucao FROM comercial_campanha WHERE id = ?', [$agendada['id']])));
        $linha = (array) $this->conexao()->fetchAssociative('SELECT estado, agendada_para, proxima_execucao FROM comercial_campanha WHERE id = ?', [$recorrente['id']]);
        self::assertSame('recorrente', $linha['estado']);
        self::assertNotNull($linha['agendada_para']);
        self::assertSame($linha['agendada_para'], $linha['proxima_execucao']);
        self::assertStringStartsWith('Campanha enfileirada:', $recorrente['mensagem']);
    }

    #[TestDox('Contagem: K desta lista sem repetidos; descadastrados e sem autorização (B1) fora; o primeiro destinatário volta como exemplo')]
    public function testContagemComB1EDescadastro(): void
    {
        $client = $this->clienteMaster();
        $ana = $this->cadastradoAtivo('ana', nome: 'Ana Maria Souza');
        $bia = $this->cadastradoAtivo('bia');
        static::getContainer()->get(DescadastroDeEmailInterface::class)->descadastrar(null, (string) $bia->getEmail(), ComposicaoDaCampanha::LISTA, 'teste');
        $texto = $ana->getEmail() . ', ' . strtoupper((string) $ana->getEmail()) . '; ' . $bia->getEmail() . ' estranho_' . uniqid() . '@exemplo.com';
        $this->postar($client, '/master/comercial/publicos/contagem', ['publico' => 'indiv', 'individual' => $texto]);

        self::assertResponseIsSuccessful();
        $c = $this->json($client);
        self::assertSame([1, 1, 1], [$c['destinatarios'], $c['descadastrados'], $c['semAutorizacao']]);
        self::assertSame(4, $c['publicos']['indiv'], 'a grade conta o que foi lido, como o dc');
        self::assertSame(['email' => $ana->getEmail(), 'nome' => ''], $c['exemplo']);

        $this->postar($client, '/master/comercial/campanhas', $this->pedido([], ['individual' => $texto]));
        $id = $this->json($client)['id'];
        self::assertSame([['email' => $ana->getEmail(), 'nome' => '']], json_decode((string) $this->conexao()->fetchOne('SELECT publico_parametros FROM comercial_campanha WHERE id = ?', [$id]), true), 'os recusados não são gravados');
    }

    #[TestDox('Contagem do CSV: lido no servidor (dc L.545); o arquivo de mais de 1 MB é recusado')]
    public function testContagemDoCsv(): void
    {
        $client = $this->clienteMaster();
        $ana = $this->cadastradoAtivo('ana');
        $this->postar($client, '/master/comercial/publicos/contagem', ['publico' => 'csv', 'csv' => "nome;email\nAna Lima;" . $ana->getEmail() . "\nFulano;fulano_" . uniqid() . "@exemplo.com\n"]);
        $c = $this->json($client);
        self::assertSame([2, 1, 1], [$c['publicos']['csv'], $c['destinatarios'], $c['semAutorizacao']]);
        self::assertSame('Ana Lima', $c['exemplo']['nome']);

        $this->postar($client, '/master/comercial/publicos/contagem', ['publico' => 'csv', 'csv' => str_repeat('x', 1048577)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('O arquivo passa de 1 MB.', $this->json($client)['erro']);
    }

    #[TestDox('Públicos do sistema: vínculo ativo, usuário ativo e escritório válido; primário pelo papel de sistema; secundário pelo vínculo sem ele (§8.5)')]
    public function testPrimariosESecundarios(): void
    {
        $this->clienteMaster();
        $prim = $this->usuario('prim');
        $tenant = $this->vincular($prim, true);
        $sec = $this->usuario('sec');
        $this->vincular($sec, false, $tenant);
        $osDois = $this->usuario('dois');
        $this->vincular($osDois, true);
        $this->vincular($osDois, false, $tenant);
        $inativo = $this->usuario('inativo', ativo: false);
        $this->vincular($inativo, true, $tenant);
        $semVinculo = $this->usuario('desligado');
        $this->vincular($semVinculo, false, $tenant, vinculoAtivo: false);
        $suspenso = $this->usuario('suspenso');
        $escritorioSuspenso = $this->vincular($suspenso, true);
        $excluido = $this->usuario('excluido');
        $escritorioExcluido = $this->vincular($excluido, true);
        $this->conexao()->executeStatement('UPDATE tenant SET is_active = false WHERE id = ?', [$escritorioSuspenso->getId()]);
        $this->conexao()->executeStatement('UPDATE tenant SET excluido_em = ? WHERE id = ?', [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $escritorioExcluido->getId()]);
        $cadastrados = static::getContainer()->get(CadastradosRepository::class);

        $primarios = array_column($cadastrados->primarios(), 'email');
        $secundarios = array_column($cadastrados->secundarios(), 'email');

        self::assertContains($prim->getEmail(), $primarios);
        self::assertContains($osDois->getEmail(), $primarios);
        self::assertContains($sec->getEmail(), $secundarios);
        self::assertContains($osDois->getEmail(), $secundarios, 'secundário noutro escritório conta como secundário (letra da §8.5)');
        foreach ([$inativo, $semVinculo, $suspenso, $excluido] as $fora) {
            self::assertNotContains($fora->getEmail(), [...$primarios, ...$secundarios]);
        }
    }

    #[TestDox('Todos = primários ∪ secundários sem repetidos: quem é as duas coisas conta uma vez na grade e na lista')]
    public function testTodosSemRepetidos(): void
    {
        $client = $this->clienteMaster();
        $osDois = $this->usuario('dois');
        $this->vincular($osDois, true);
        $this->vincular($osDois, false);
        $this->postar($client, '/master/comercial/publicos/contagem', ['publico' => 'todos']);

        $c = $this->json($client);
        self::assertLessThanOrEqual($c['publicos']['todos'], $c['destinatarios'] + $c['descadastrados'], 'a lista não passa da união');
        self::assertLessThan($c['publicos']['prim'] + $c['publicos']['sec'], $c['publicos']['todos']);
    }

    #[TestDox('B1 com o vínculo válido: usuário ativo sem vínculo, só em escritório excluído (quarentena) ou suspenso fica fora do Individual')]
    public function testB1ExigeVinculoValido(): void
    {
        $client = $this->clienteMaster();
        $valido = $this->cadastradoAtivo('valido');
        $semVinculo = $this->usuario('semvinculo');
        $quarentena = $this->usuario('quarentena');
        $excluido = $this->vincular($quarentena, true);
        $suspensoUser = $this->usuario('suspenso');
        $suspenso = $this->vincular($suspensoUser, false);
        $desligado = $this->usuario('desligado');
        $this->vincular($desligado, false, vinculoAtivo: false);
        $this->conexao()->executeStatement('UPDATE tenant SET is_active = false, excluido_em = ? WHERE id = ?', [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $excluido->getId()]);
        $this->conexao()->executeStatement('UPDATE tenant SET is_active = false WHERE id = ?', [$suspenso->getId()]);
        $texto = implode(', ', array_map(static fn (User $u): string => (string) $u->getEmail(), [$valido, $semVinculo, $quarentena, $suspensoUser, $desligado]));
        $this->postar($client, '/master/comercial/publicos/contagem', ['publico' => 'indiv', 'individual' => $texto]);

        $c = $this->json($client);
        self::assertSame([1, 4], [$c['destinatarios'], $c['semAutorizacao']]);
        self::assertSame($valido->getEmail(), $c['exemplo']['email']);
    }

    #[TestDox('Confirmado gravado com maiúsculas: a Base de contatos o devolve em minúsculas e o B1 do Individual o aceita')]
    public function testConfirmadoComMaiusculas(): void
    {
        $client = $this->clienteMaster();
        $email = 'Lead.Misto_' . uniqid() . '@Exemplo.com';
        $this->conexao()->executeStatement(
            "INSERT INTO comercial_confirmacao_endereco (lista, email, pedido_em, confirmado_em) VALUES ('comercial_campanhas', ?, ?, ?)",
            [$email, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
        $this->postar($client, '/master/comercial/publicos/contagem', ['publico' => 'indiv', 'individual' => strtolower($email)]);
        $indiv = $this->json($client);
        $this->postar($client, '/master/comercial/publicos/contagem', ['publico' => 'leads']);
        $leads = $this->json($client);

        self::assertSame([1, 0], [$indiv['destinatarios'], $indiv['semAutorizacao']]);
        self::assertContains(strtolower($email), [$leads['exemplo']['email'] ?? null]);
    }

    #[TestDox('Lista de campanhas: as 12 últimas, da mais nova; sub "modelo · público[ · frequência]", data, total e o rótulo do estado')]
    public function testListaDeCampanhas(): void
    {
        $client = $this->clienteMaster();
        for ($i = 0; $i < 12; ++$i) {
            $this->campanha(['a@exemplo.com']);
        }
        $ultima = $this->campanha(['a@exemplo.com', 'b@exemplo.com'], ['estado' => 'recorrente', 'frequencia' => 'quinzenal', 'publico' => 'prim', 'agendada_para' => new \DateTimeImmutable('2031-04-05 08:30'), 'proxima' => new \DateTimeImmutable('2031-04-05 08:30'), 'total' => 2]);
        $client->request('GET', '/master/comercial/campanhas');

        $campanhas = $this->json($client)['campanhas'];
        self::assertCount(12, $campanhas);
        self::assertSame([
            'id' => $ultima, 'nome' => 'Seu escritório 100% digital, de graça', 'sub' => 'Convite · Usuários primários · quinzenal',
            'em' => '05/04/2031 08:30', 'total' => 2, 'estado' => 'recorrente', 'rotulo' => 'Recorrente',
        ], $campanhas[0]);
        self::assertSame(['Convite · Individual', 'na_fila', 'Na fila'], [$campanhas[1]['sub'], $campanhas[1]['estado'], $campanhas[1]['rotulo']]);
    }

    #[TestDox('Prévia com o primeiro destinatário (dc L.537): o primeiro nome dele; sem nome, "Dra. Ana"')]
    public function testPreviaComExemplo(): void
    {
        $client = $this->clienteMaster();
        $convite = (array) (new ModelosDeCampanha())->fixo('convite');
        $this->postar($client, '/master/comercial/previa-campanha', $convite + ['exemplo' => ['email' => 'maria@exemplo.com', 'nome' => 'Maria Souza']]);
        self::assertStringContainsString('Maria, conheça o escritório digital BlueJus', (string) $this->json($client)['html']);

        $this->postar($client, '/master/comercial/previa-campanha', $convite + ['exemplo' => ['email' => 'lead@exemplo.com', 'nome' => '']]);
        self::assertStringContainsString('Dra. Ana, conheça o escritório digital BlueJus', (string) $this->json($client)['html']);
    }

    /**
     * @param list<string>         $emails
     * @param array<string, mixed> $troca
     *
     * @return array<string, mixed>
     */
    private function pedido(array $emails, array $troca = []): array
    {
        return array_merge([
            'chave' => bin2hex(random_bytes(16)),
            'modelo' => (array) (new ModelosDeCampanha())->fixo('convite'),
            'publico' => 'indiv', 'individual' => implode(', ', $emails), 'frequencia' => 'unica', 'agendar' => '',
        ], $troca);
    }

    /** @return array{0: User, 1: User, 2: User} Ana (feminino), Beto (masculino) e Caio (sem perfil), todos ativos */
    private function tresComPerfil(): array
    {
        $ana = $this->cadastradoAtivo('ana', 'Ana Filtro');
        $this->perfil($ana, Genero::Feminino);
        $beto = $this->cadastradoAtivo('beto', 'Beto Filtro');
        $this->perfil($beto, Genero::Masculino);

        return [$ana, $beto, $this->cadastradoAtivo('caio', 'Caio Filtro')];
    }

    private function contar(string $tabela): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM ' . $tabela);
    }

    /** @param array<string, mixed> $corpo */
    private function postar(KernelBrowser $client, string $url, array $corpo): void
    {
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial'], (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function clienteMaster(bool $ligada = true): KernelBrowser
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
        static::getContainer()->set(ChaveDasCampanhas::class, new ChaveDasCampanhas($ligada));
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
