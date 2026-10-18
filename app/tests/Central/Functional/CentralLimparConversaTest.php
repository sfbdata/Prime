<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\CentralController;
use App\Central\UseCase\LimparConversaUseCase;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Excluir" uma conversa pelo botão direito = limpar PARA MIM: some da minha lista e o histórico até ali fica oculto
 * só para quem limpou. O colega continua vendo tudo; mensagem nova traz a conversa de volta, só com as novas.
 */
#[CoversClass(CentralController::class)]
#[CoversClass(LimparConversaUseCase::class)]
final class CentralLimparConversaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    /** @return list<int> os ids das conversas da MINHA lista */
    private function lista(KernelBrowser $client): array
    {
        $client->request('GET', '/central/conversas');

        return array_column($this->json($client)['conversas'], 'id');
    }

    /** @return list<string> o conteúdo das mensagens que EU vejo */
    private function historico(KernelBrowser $client, int $conversa): array
    {
        $client->request('GET', "/central/conversas/$conversa/mensagens");
        self::assertResponseIsSuccessful();

        return array_column($this->json($client)['mensagens'], 'conteudo');
    }

    #[TestDox('Limpar: some da minha lista e do meu histórico, zera as não lidas; o colega segue vendo tudo; mensagem nova volta só com as novas')]
    public function testLimparParaMim(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');

        $this->logarComTenant($client, $bia, $tenant);
        $conversa = $this->abrir($client, $ana);
        $this->enviar($client, $conversa, 'um');
        $this->enviar($client, $conversa, 'dois');

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, "/central/conversas/$conversa/nao-lida", []);
        $this->post($client, "/central/conversas/$conversa/limpar", []);
        self::assertResponseIsSuccessful();
        self::assertNotContains($conversa, $this->lista($client));
        self::assertSame([], $this->historico($client, $conversa));
        $client->request('GET', '/central/novidades?desde=0');
        $d = $this->json($client);
        self::assertSame([[], 0], [$d['mensagens'], $d['naoLidas']], 'o polling não devolve o que foi limpo');

        $this->logarComTenant($client, $bia, $tenant);
        self::assertContains($conversa, $this->lista($client));
        self::assertSame(['um', 'dois'], $this->historico($client, $conversa), 'do lado da Bia nada some');
        $this->enviar($client, $conversa, 'três');

        $this->logarComTenant($client, $ana, $tenant);
        self::assertContains($conversa, $this->lista($client), 'mensagem nova traz a conversa de volta');
        self::assertSame(['três'], $this->historico($client, $conversa));
        $client->request('GET', '/central/novidades?desde=0');
        self::assertSame(['três'], array_column($this->json($client)['mensagens'], 'conteudo'));
    }

    #[TestDox('Limpar: o @aviso de menção da conversa vira lido; a janela de 30 s do polling não devolve o limpo; quem limpou e envia vê só a própria nova')]
    public function testMencaoJanelaEAutor(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');

        $this->logarComTenant($client, $bia, $tenant);
        $conversa = $this->abrir($client, $ana);
        $ultima = $this->enviar($client, $conversa, 'oi @[' . $ana->getFullName() . '](user:' . $ana->getId() . ')');
        $mencaoLida = fn (): bool => (bool) $this->em()->getConnection()->fetchOne(
            'SELECT lida FROM notificacao WHERE usuario_id = ? AND tipo = ?', [$ana->getId(), \App\Entity\Notificacao::TIPO_CENTRAL_MENCAO]
        );

        $this->logarComTenant($client, $ana, $tenant);
        self::assertFalse($mencaoLida());
        $this->post($client, "/central/conversas/$conversa/limpar", []);
        self::assertResponseIsSuccessful();
        self::assertTrue($mencaoLida(), 'a menção desta conversa vira lida junto');
        $client->request('GET', '/central/novidades?desde=' . $ultima);   // a janela traz as recentes abaixo do cursor
        self::assertSame([], $this->json($client)['mensagens']);

        $this->enviar($client, $conversa, 'minha nova');
        self::assertContains($conversa, $this->lista($client));
        self::assertSame(['minha nova'], $this->historico($client, $conversa));
    }

    #[TestDox('Limpar: quem saiu do grupo e quem é de outro escritório recebem 404')]
    public function testQuemSaiuEOutroEscritorio(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');
        $caio = $this->colega($tenant, 'caio');
        $deFora = $this->colega($outro, 'fora');

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, '/central/grupos', ['nome' => 'Equipe', 'participantes' => [$bia->getId(), $caio->getId()]]);
        self::assertResponseIsSuccessful();
        $grupo = (int) $this->json($client)['grupo']['id'];
        $this->enviar($client, $grupo, 'bom dia');

        $this->logarComTenant($client, $bia, $tenant);
        $this->post($client, "/central/conversas/$grupo/sair", []);
        self::assertResponseIsSuccessful();
        $this->post($client, "/central/conversas/$grupo/limpar", []);
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($client, $deFora, $outro);
        $this->post($client, "/central/conversas/$grupo/limpar", []);
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($client, $caio, $tenant);
        self::assertContains('bom dia', $this->historico($client, $grupo), 'nada foi limpo para quem ficou');
    }

    #[TestDox('Limpar: conversa alheia é 404, sem CSRF é 403, e nenhum dos dois esconde nada')]
    public function testGuardas(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bia = $this->colega($tenant, 'bia');
        $caio = $this->colega($tenant, 'caio');

        $this->logarComTenant($client, $bia, $tenant);
        $conversa = $this->abrir($client, $ana);
        $this->enviar($client, $conversa, 'oi');

        $this->logarComTenant($client, $caio, $tenant);
        $this->post($client, "/central/conversas/$conversa/limpar", []);
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('POST', "/central/conversas/$conversa/limpar", [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);
        self::assertContains($conversa, $this->lista($client));
        self::assertSame(['oi'], $this->historico($client, $conversa));
    }
}
