<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
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

/** Quem tem conversa IA e individual: os UseCases da tela devolvem só a individual e nenhum lança (PR 1 da F3). */
#[CoversClass(ListarConversasUseCase::class)]
#[CoversClass(ListarEquipeDaCentralUseCase::class)]
#[CoversClass(ListarDestinosDoEncaminhamentoUseCase::class)]
#[CoversClass(MarcarTudoComoLidoUseCase::class)]
#[CoversClass(ObterNovidadesDaCentralUseCase::class)]
final class ConversaComAIaNaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    #[TestDox('Lista, equipe, destinos, ler tudo e novidades ignoram a conversa IA e não falham')]
    public function testTelaIgnoraAIa(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view'], 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view'], 'bruno');
        $pasta = $this->criarPasta($tenant);
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
        self::assertResponseIsSuccessful();
        self::assertSame([$individual], array_column($this->json($client)['conversas'], 'id'));

        $client->request('GET', '/central/equipe');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/central/encaminhar/destinos?pasta=' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertSame([$individual], array_values(array_filter(array_column($this->json($client)['destinos'], 'conversaId'))));

        $client->request('GET', '/central/novidades?desde=0');
        self::assertResponseIsSuccessful();
        $novidades = $this->json($client);
        self::assertStringNotContainsString('RESPOSTA-DA-IA', json_encode($novidades, JSON_THROW_ON_ERROR));
        self::assertSame(1, $novidades['naoLidas'] ?? 1);

        $this->post($client, '/central/ler-tudo', []);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/central/conversas/' . $ia->getId() . '/mensagens');
        self::assertResponseStatusCodeSame(404);
    }
}
