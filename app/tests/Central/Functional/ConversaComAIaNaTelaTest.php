<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\CentralController;
use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
use App\Central\UseCase\EnviarMensagemUseCase;
use App\Central\UseCase\ListarConversasUseCase;
use App\Central\UseCase\ListarDestinosDoEncaminhamentoUseCase;
use App\Central\UseCase\ListarEquipeDaCentralUseCase;
use App\Central\UseCase\MarcarTudoComoLidoUseCase;
use App\Central\UseCase\ObterNovidadesDaCentralUseCase;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A conversa com o BlueJus IA na janela da Central (F3, PR 3a): só o dono a vê (lista, histórico, novidades, ler tudo),
 * a lista a traz sempre, o envio comum é recusado e nada dela vaza para a Equipe nem para os destinos do Encaminhar.
 */
#[CoversClass(CentralController::class)]
#[CoversClass(ListarConversasUseCase::class)]
#[CoversClass(EnviarMensagemUseCase::class)]
#[CoversClass(ListarEquipeDaCentralUseCase::class)]
#[CoversClass(ListarDestinosDoEncaminhamentoUseCase::class)]
#[CoversClass(MarcarTudoComoLidoUseCase::class)]
#[CoversClass(ObterNovidadesDaCentralUseCase::class)]
final class ConversaComAIaNaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    #[TestDox('A lista traz a conversa IA mesmo sem conversa prévia (cria uma só, idempotente); a outra pessoa tem a dela')]
    public function testListaTrazAIaSemConversaPrevia(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/conversas');
        self::assertResponseIsSuccessful();
        $lista = $this->json($client)['conversas'];
        self::assertCount(1, $lista);
        self::assertSame('ia', $lista[0]['tipo']);
        self::assertTrue($lista[0]['ia']);
        self::assertNull($lista[0]['outro']);
        self::assertSame('BlueJus IA', $lista[0]['nome']);
        $iaDaAna = $lista[0]['id'];

        $client->request('GET', '/central/conversas');
        self::assertSame([$iaDaAna], array_column($this->json($client)['conversas'], 'id'), 'segunda chamada: a mesma, sem criar outra');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT count(*) FROM central_conversa WHERE tipo = 'ia' AND usuario_menor_id = ?", [$ana->getId()]));

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', '/central/conversas');
        $daBruno = $this->json($client)['conversas'];
        self::assertCount(1, $daBruno);
        self::assertNotSame($iaDaAna, $daBruno[0]['id'], 'uma IA por pessoa');
    }

    #[TestDox('Só o dono abre a conversa IA (histórico 200, com a fala marcada deIa); a outra pessoa recebe 404; envio comum nela é 403 e nada é gravado')]
    public function testSoODonoAbreEEnvioComumERecusado(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $agora = new \DateTimeImmutable();
        $ia = Conversa::comAIa($tenant, $ana, $agora);
        $this->em()->persist($ia);
        $this->em()->persist(new Participante($tenant, $ia, $ana, $agora));
        $this->em()->persist(new Mensagem($tenant, $ia, $ana, 'PERGUNTA-DA-ANA', $agora));
        $this->em()->persist(Mensagem::daIA($tenant, $ia, $ana, 'RESPOSTA-DA-IA', $agora));
        $this->em()->flush();
        $id = (int) $ia->getId();

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', "/central/conversas/$id/mensagens");
        self::assertResponseIsSuccessful();
        $mensagens = $this->json($client)['mensagens'];
        self::assertSame(['PERGUNTA-DA-ANA', 'RESPOSTA-DA-IA'], array_column($mensagens, 'conteudo'));
        self::assertSame([false, true], array_column($mensagens, 'deIa'));
        self::assertSame([true, false], array_column($mensagens, 'minha'), 'a fala da IA não é "minha", embora o autor gravado seja o dono');
        self::assertSame('BlueJus IA', $mensagens[1]['autorNome']);

        $antes = (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM central_mensagem');
        $this->post($client, "/central/conversas/$id/mensagens", ['conteudo' => 'oi IA']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('conversa_ia', $this->json($client)['codigo']);
        self::assertSame($antes, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM central_mensagem'));

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', "/central/conversas/$id/mensagens");
        self::assertResponseStatusCodeSame(404);
        $this->post($client, "/central/conversas/$id/mensagens", ['conteudo' => 'bisbilhotando']);
        self::assertResponseStatusCodeSame(404);
        $this->post($client, "/central/conversas/$id/lida", []);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Novidades e não lidas do dono contam a resposta da IA; a Equipe e os destinos do Encaminhar não veem a IA de ninguém; ler tudo a marca como lida')]
    public function testNovidadesEquipeEDestinos(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view'], 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view'], 'bruno');
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $agora = new \DateTimeImmutable();
        $ia = Conversa::comAIa($tenant, $ana, $agora);
        $this->em()->persist($ia);
        $this->em()->persist(new Participante($tenant, $ia, $ana, $agora));
        $this->em()->persist(Mensagem::daIA($tenant, $ia, $ana, 'RESPOSTA-DA-IA', $agora));
        $this->em()->flush();

        $this->logarComTenant($client, $ana, $tenant);
        $individual = $this->abrir($client, $bruno);
        $this->logarComTenant($client, $bruno, $tenant);
        $this->enviar($client, $individual, 'oi Ana');
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/central/conversas');
        $lista = $this->json($client)['conversas'];
        self::assertEqualsCanonicalizing([$individual, (int) $ia->getId()], array_column($lista, 'id'));
        $daIa = array_values(array_filter($lista, static fn (array $c): bool => $c['ia']))[0];
        self::assertSame(1, $daIa['naoLidas']);
        self::assertTrue($daIa['ultima']['deIa']);

        $client->request('GET', '/central/novidades?desde=0');
        $novidades = $this->json($client);
        self::assertSame(2, $novidades['naoLidas'], 'a individual e a resposta da IA');
        self::assertContains('RESPOSTA-DA-IA', array_column($novidades['mensagens'], 'conteudo'));

        $client->request('GET', '/central/encaminhar/destinos?pasta=' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $destinos = $this->json($client)['destinos'];
        self::assertSame([$individual], array_values(array_filter(array_column($destinos, 'conversaId'))), 'a IA nunca é destino');

        // Equipe: o "em aberto" que o Bruno enxerga da Ana não inclui a resposta da IA (só a individual, que é dela).
        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', '/central/equipe');
        self::assertResponseIsSuccessful();
        $equipe = $this->json($client);
        $daAna = array_values(array_filter($equipe['equipe'], static fn (array $m): bool => $m['id'] === $ana->getId()));
        self::assertSame(1, $daAna[0]['emAberto'] ?? null);

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, '/central/ler-tudo', []);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/central/novidades?desde=0');
        self::assertSame(0, $this->json($client)['naoLidas']);
    }
}
