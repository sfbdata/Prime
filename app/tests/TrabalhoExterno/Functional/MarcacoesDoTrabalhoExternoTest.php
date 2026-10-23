<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Tenant\Support\DesligaEdicaoSemPrazo;
use App\TrabalhoExterno\Controller\DemandaExternaController;
use App\TrabalhoExterno\UseCase\ApagarMensagemParaTodosUseCase;
use App\TrabalhoExterno\UseCase\MarcarDemandasEmLoteUseCase;
use App\TrabalhoExterno\UseCase\MarcarMensagensEmLoteUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Marcações pessoais e chat avançado pela HTTP (spec VS 18.10.26 §3): valem só para quem marca,
 * não vazam demanda de outro escritório e ficam negadas na prévia do Master.
 */
#[CoversClass(DemandaExternaController::class)]
#[CoversClass(MarcarDemandasEmLoteUseCase::class)]
#[CoversClass(MarcarMensagensEmLoteUseCase::class)]
#[CoversClass(ApagarMensagemParaTodosUseCase::class)]
final class MarcacoesDoTrabalhoExternoTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    private const LOTE = '/trabalho-externo/api/demandas/marcacoes';

    #[TestDox('estrela, importante e lixeira valem só para quem marcou: o Master continua vendo a demanda normal')]
    public function testMarcacaoEhPessoal(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $master = $this->criarMaster($escritorio);
        $solicitante = $this->criarUsuario($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];

        $r = $this->postarJson($client, self::LOTE, ['codigos' => [$codigo], 'op' => 'estrela']);
        self::assertSame(['ok' => true, 'aplicados' => 1, 'texto' => '1 demanda(s) com estrela'], $r);
        $this->postarJson($client, self::LOTE, ['codigos' => [$codigo], 'op' => 'imp']);
        $this->postarJson($client, self::LOTE, ['codigos' => [$codigo], 'op' => 'lixeira']);

        $linha = $this->linha($client, $codigo);
        self::assertTrue($linha['estrela']);
        self::assertTrue($linha['lixeira']);
        self::assertSame(3, $linha['importancia']['nivel']);

        $this->logarComTenant($client, $master, $escritorio);
        $linha = $this->linha($client, $codigo);
        self::assertFalse($linha['estrela']);
        self::assertFalse($linha['lixeira']);
    }

    #[TestDox('excluir da lista tira a demanda só do painel de quem excluiu')]
    public function testDefinitivoTiraDaListaDeQuemExcluiu(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $master = $this->criarMaster($escritorio);
        $solicitante = $this->criarUsuario($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        $this->postarJson($client, self::LOTE, ['codigos' => [$codigo], 'op' => 'definitivo']);
        self::assertNull($this->linha($client, $codigo));

        $this->logarComTenant($client, $master, $escritorio);
        self::assertNotNull($this->linha($client, $codigo));
    }

    #[TestDox('não lida conta 1 com o chat em dia; lida, ou abrir o chat, desliga a marca')]
    public function testNaoLidaELida(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $solicitante = $this->criarUsuario($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        self::assertSame(0, $this->linha($client, $codigo)['naoLidas']);

        $this->postarJson($client, self::LOTE, ['codigos' => [$codigo], 'op' => 'naolida']);
        self::assertSame(1, $this->linha($client, $codigo)['naoLidas']);
        $this->postarJson($client, self::LOTE, ['codigos' => [$codigo], 'op' => 'lida']);
        self::assertSame(0, $this->linha($client, $codigo)['naoLidas']);
        $this->postarJson($client, self::LOTE, ['codigos' => [$codigo], 'op' => 'naolida']);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertSame(1, $this->json($client)['demanda']['naoLidas'], 'o painel lateral também conta a marca, para a tela avisar a leitura');
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/lida");
        self::assertSame(0, $this->linha($client, $codigo)['naoLidas'], 'abrir o chat desliga a marca manual');
    }

    #[TestDox('código de outro escritório é ignorado em silêncio, operação desconhecida é 422 e sem CSRF é 403')]
    public function testIsolamentoEEntrada(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $a = $this->criarTenant('A');
        $b = $this->criarTenant('B');
        $doA = $this->criarUsuario($a);
        $doB = $this->criarUsuario($b);

        $this->logarComTenant($client, $doA, $a);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];

        $this->logarComTenant($client, $doB, $b);
        self::assertSame(0, $this->postarJson($client, self::LOTE, ['codigos' => [$codigo], 'op' => 'estrela'])['aplicados']);
        $this->postarJson($client, self::LOTE, ['codigos' => [$codigo], 'op' => 'apagar-tudo']);
        self::assertResponseStatusCodeSame(422);
        $this->postarJson($client, self::LOTE, ['codigos' => [$codigo], 'op' => 'estrela'], 'errado');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('chat: lixeira e favorito são de quem marcou; excluir some só para ele; apagar para todos deixa a mensagem sem texto')]
    public function testChatAvancado(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $master = $this->criarMaster($escritorio);
        $solicitante = $this->criarUsuario($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'Primeira']);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'Segunda']);
        $ids = array_column($this->mensagens($client, $codigo), 'id', 'txt');

        $r = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens/marcacoes", ['ids' => [$ids['Primeira']], 'op' => 'lixeira']);
        self::assertSame('1 mensagem movida para a lixeira', $r['texto']);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens/marcacoes", ['ids' => [$ids['Segunda']], 'op' => 'fav']);
        $mensagens = array_column($this->mensagens($client, $codigo), null, 'txt');
        self::assertTrue($mensagens['Primeira']['lixeira']);
        self::assertTrue($mensagens['Segunda']['favorita']);

        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens/marcacoes", ['ids' => [$ids['Primeira']], 'op' => 'definitivo']);
        self::assertSame(['Segunda'], array_column($this->mensagens($client, $codigo), 'txt'));

        $this->logarComTenant($client, $master, $escritorio);
        self::assertSame(['Primeira', 'Segunda'], array_column(array_filter($this->mensagens($client, $codigo), static fn (array $m): bool => $m['estilo'] !== 'sistema'), 'txt'), 'para o Master nada mudou');
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens/{$ids['Segunda']}/apagar");
        self::assertResponseIsSuccessful('o Master pode apagar qualquer mensagem');

        $this->logarComTenant($client, $solicitante, $escritorio);
        $segunda = array_values(array_filter($this->mensagens($client, $codigo), static fn (array $m): bool => $m['id'] === $ids['Segunda']))[0];
        self::assertTrue($segunda['apagada']);
        self::assertSame('', $segunda['txt']);
        self::assertStringStartsWith('Mensagem apagada por Central BlueJus em ', $segunda['apagadaTxt'], 'para o Solicitante, quem atende aparece como Central');
        self::assertFalse($segunda['favorita']);
    }

    #[TestDox('apagar para todos: outro participante que não é o autor nem o Master recebe 403')]
    public function testSoOAutorApaga(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $solicitante = $this->criarUsuario($escritorio);
        $colega = $this->criarUsuario($escritorio);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'Minha']);
        $id = array_column($this->mensagens($client, $codigo), 'id', 'txt')['Minha'];

        $this->logarComTenant($client, $colega, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens/$id/apagar");
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Edição sem prazo: o primário do escritório dono apaga a mensagem alheia (opção ligada), auditado; desligada, 403')]
    public function testPrimarioDoDonoApaga(): void
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant();
        $solicitante = $this->criarUsuario($escritorio);
        $primario = $this->criarUsuario($escritorio, nome: 'Paula Primária');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(UserTenant::class)->findOneBy(['user' => $primario, 'tenant' => $escritorio])?->getTenantRole()?->setIsSystem(true);
        $em->flush();

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'Uma']);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens", ['texto' => 'Outra']);
        $ids = array_column($this->mensagens($client, $codigo), 'id', 'txt');

        $this->logarComTenant($client, $primario, $escritorio);
        $uma = array_values(array_filter($this->mensagens($client, $codigo), static fn (array $m): bool => $m['id'] === $ids['Uma']))[0];
        self::assertTrue($uma['podeApagar']);
        self::assertSame('EXCLUSIVO DO PRIMÁRIO', $uma['seloSemPrazo']);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens/{$ids['Uma']}/apagar");
        self::assertResponseIsSuccessful();
        $linha = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'sp_excluir', 'entityId' => (string) $ids['Uma']]);
        self::assertSame($primario->getId(), $linha?->getActorUserId());

        DesligaEdicaoSemPrazo::no($em, $em->find(Tenant::class, $escritorio->getId()) ?? $escritorio, static::getContainer());
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/mensagens/{$ids['Outra']}/apagar");
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array<string, mixed>|null */
    private function linha(KernelBrowser $client, string $codigo): ?array
    {
        $client->request('GET', '/trabalho-externo/api/painel');
        foreach ($this->json($client)['painel']['demandas'] as $linha) {
            if ($linha['codigo'] === $codigo) {
                return $linha;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function mensagens(KernelBrowser $client, string $codigo): array
    {
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");

        return $this->json($client)['demanda']['mensagens'];
    }
}
