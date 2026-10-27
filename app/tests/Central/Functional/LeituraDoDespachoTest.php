<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Service\LeituraDasMensagens;
use App\Central\UseCase\ListarConversasUseCase;
use App\Central\UseCase\ListarMensagensDaConversaUseCase;
use App\Central\UseCase\ObterNovidadesDaCentralUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * O despacho na LEITURA (regra 13 da Central; ressalva do dono na spec B: "o acesso ao histórico respeita as
 * permissões, inclusive para participantes novos [...] encaminhar herda essa regra").
 *
 * O cenário do bug: Ana encaminha para o grupo em que todos veem a pasta; depois adiciona Diego, que não vê. O
 * histórico e as novidades de Diego trazem "Item de pasta sem acesso", sem título, teor nem NUP; os outros seguem
 * vendo. Mais: a prévia da lista e a citação de uma resposta, o registro do Financeiro, a 1:1 sem ninguém que receba e
 * o colega de outro escritório.
 */
#[CoversClass(LeituraDasMensagens::class)]
#[CoversClass(ListarMensagensDaConversaUseCase::class)]
#[CoversClass(ObterNovidadesDaCentralUseCase::class)]
#[CoversClass(ListarConversasUseCase::class)]
final class LeituraDoDespachoTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    use CentralHttpTrait;

    private const VE_PASTA = ['resources.pasta.view'];
    private const SEGREDO = 'SEGREDO-DO-TEOR ligar para o cliente';

    #[TestDox('O bug: quem entra no grupo depois e não vê a pasta lê "Item de pasta sem acesso" no histórico, nas novidades e na citação; os outros seguem vendo')]
    public function testNovoMembroQueNaoVeAPasta(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $diego = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta = $this->criarPasta($tenant);
        $registro = $this->criarAnotacao($tenant, $pasta, $ana, '<p>' . self::SEGREDO . '</p>');
        $nup = (string) $pasta->getNup();

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, '/central/grupos', ['nome' => 'Equipe', 'participantes' => [$bruno->getId()]]);
        self::assertResponseIsSuccessful();
        $grupo = (int) $this->json($client)['grupo']['id'];
        $this->post($client, '/central/encaminhar', ['pastaId' => $pasta->getId(), 'tipo' => 'registro_dados', 'itemId' => $registro->getId(), 'conversaId' => $grupo, 'instrucao' => 'até sexta']);
        self::assertResponseStatusCodeSame(201);

        // Bruno responde citando o despacho; depois a Ana adiciona Diego, que não vê a pasta (o adicionar não olha a pasta).
        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', "/central/conversas/$grupo/mensagens");
        $despachos = array_values(array_filter($this->json($client)['mensagens'], static fn (array $m): bool => $m['despacho'] !== null));
        self::assertCount(1, $despachos);
        $despachoId = (int) $despachos[0]['id'];
        $this->post($client, "/central/conversas/$grupo/mensagens", ['conteudo' => 'pode deixar', 'respostaAId' => $despachoId]);
        self::assertResponseStatusCodeSame(201);
        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, "/central/conversas/$grupo/participantes", ['usuarioId' => $diego->getId()]);
        self::assertResponseIsSuccessful();

        // Diego: histórico.
        $this->logarComTenant($client, $diego, $tenant);
        $client->request('GET', "/central/conversas/$grupo/mensagens");
        self::assertResponseIsSuccessful();
        $this->semOTeor($client, $nup);
        $historico = $this->porId($this->json($client)['mensagens']);
        self::assertSame('Item de pasta sem acesso', $historico[$despachoId]['conteudo']);
        self::assertNull($historico[$despachoId]['despacho']);
        self::assertTrue($historico[$despachoId]['ocultoPorPermissao']);
        $resposta = $this->respostaA($historico, $despachoId);
        self::assertSame('pode deixar', $resposta['conteudo'], 'a resposta em si é legível');
        self::assertSame('Item de pasta sem acesso', $resposta['respostaA']['trecho']);
        self::assertTrue($resposta['respostaA']['ocultoPorPermissao']);

        // Diego: novidades (desde=0 traz as novas; as recém-criadas vêm também pela janela de 30 s).
        $client->request('GET', '/central/novidades?desde=0');
        self::assertResponseIsSuccessful();
        $this->semOTeor($client, $nup);
        $novidades = $this->json($client)['mensagens'];
        $doDespacho = array_values(array_filter($novidades, static fn (array $m): bool => $m['id'] === $despachoId));
        self::assertNotSame([], $doDespacho);
        foreach ($doDespacho as $m) {
            self::assertSame('Item de pasta sem acesso', $m['conteudo']);
            self::assertTrue($m['ocultoPorPermissao']);
        }
        $client->request('GET', '/central/novidades?desde=' . ($despachoId + 100));
        $this->semOTeor($client, $nup);
        $daJanela = array_values(array_filter($this->json($client)['mensagens'], static fn (array $m): bool => $m['id'] === $despachoId));
        self::assertCount(1, $daJanela, 'a janela de 30 s repete o despacho abaixo do cursor');
        self::assertSame('Item de pasta sem acesso', $daJanela[0]['conteudo']);

        // Os outros continuam vendo, com a referência.
        foreach ([$ana, $bruno] as $quem) {
            $this->logarComTenant($client, $quem, $tenant);
            $client->request('GET', "/central/conversas/$grupo/mensagens");
            $dele = $this->porId($this->json($client)['mensagens']);
            self::assertStringContainsString(self::SEGREDO, $dele[$despachoId]['conteudo']);
            self::assertStringContainsString("Pasta $nup", $dele[$despachoId]['conteudo']);
            self::assertSame(['pastaId' => $pasta->getId(), 'nup' => $nup], $dele[$despachoId]['despacho']);
            self::assertFalse($dele[$despachoId]['ocultoPorPermissao']);
            self::assertStringStartsWith('Despacho: Registro de', $this->respostaA($dele, $despachoId)['respostaA']['trecho']);
        }
    }

    #[TestDox('Lista de conversas: quem perdeu o acesso à pasta depois do despacho vê a prévia como "Item de pasta sem acesso"')]
    public function testPreviaDaListaParaQuemPerdeuOAcesso(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $pasta = $this->criarPasta($tenant);
        $registro = $this->criarAnotacao($tenant, $pasta, $ana, self::SEGREDO);

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, '/central/encaminhar', ['pastaId' => $pasta->getId(), 'tipo' => 'registro_dados', 'itemId' => $registro->getId(), 'usuarioId' => $bruno->getId()]);
        self::assertResponseStatusCodeSame(201);

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', '/central/conversas');
        $ultima = $this->conversasSemIa($client)[0]['ultima'];
        self::assertStringContainsString(self::SEGREDO, $ultima['conteudo'], 'controle: com acesso, a prévia é o despacho');
        self::assertSame($pasta->getId(), $ultima['despacho']['pastaId']);

        $this->tirarPermissoes($bruno, $tenant);
        $client->request('GET', '/central/conversas');
        $this->semOTeor($client, (string) $pasta->getNup());
        $ultima = $this->conversasSemIa($client)[0]['ultima'];
        self::assertSame('Item de pasta sem acesso', $ultima['conteudo']);
        self::assertNull($ultima['despacho']);
        self::assertTrue($ultima['ocultoPorPermissao']);

        // A pasta apagada (ON DELETE SET NULL) também esconde, inclusive de quem despachou.
        $this->em()->getConnection()->executeStatement('UPDATE central_mensagem SET despacho_pasta_id = NULL WHERE tenant_id = ?', [$tenant->getId()]);
        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/conversas');
        self::assertTrue($this->conversasSemIa($client)[0]['ultima']['ocultoPorPermissao']);
    }

    #[TestDox('Mensagem comum: despacho nulo e ocultoPorPermissao falso; as colunas do despacho ficam nulas no banco')]
    public function testMensagemComum(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');

        $this->logarComTenant($client, $ana, $tenant);
        $id = $this->enviar($client, $this->abrir($client, $bruno), 'bom dia');
        $mensagem = $this->json($client)['mensagem'];
        self::assertNull($mensagem['despacho']);
        self::assertFalse($mensagem['ocultoPorPermissao']);
        self::assertSame(
            ['despacho_pasta_id' => null, 'despacho_tipo' => null],
            $this->em()->getConnection()->fetchAssociative('SELECT despacho_pasta_id, despacho_tipo FROM central_mensagem WHERE id = ?', [$id]),
        );
    }

    #[TestDox('O despacho grava a referência (pasta e tipo) junto com a mensagem')]
    public function testDespachoGravaAReferencia(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $pasta = $this->criarPasta($tenant);

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, '/central/encaminhar', ['pastaId' => $pasta->getId(), 'tipo' => 'pasta', 'usuarioId' => $bruno->getId()]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame(
            [['despacho_pasta_id' => $pasta->getId(), 'despacho_tipo' => 'pasta']],
            $this->em()->getConnection()->fetchAllAssociative('SELECT despacho_pasta_id, despacho_tipo FROM central_mensagem WHERE tenant_id = ?', [$tenant->getId()]),
        );
    }

    #[TestDox('Registro do Financeiro: destinatário que vê a pasta mas não o financeiro é recusado; quem perde o financeiro depois lê "sem acesso"')]
    public function testRegistroDoFinanceiro(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $carla = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'carla');
        $pasta = $this->criarPasta($tenant);
        $registro = (new PastaObservacaoFinanceira())->setTenant($tenant)->setPasta($pasta)->setAutor($ana)->setConteudo(self::SEGREDO);
        $this->em()->persist($registro);
        $this->em()->flush();

        // Hoje o financeiro tem a mesma regra da pasta; o dublê aperta para separar as duas perguntas.
        $semFinanceiro = [(int) $bruno->getId()];
        $financeiro = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $financeiro->method('podeVer')->willReturnCallback(static function (User $u) use (&$semFinanceiro): bool {
            return !in_array((int) $u->getId(), $semFinanceiro, true);
        });
        static::getContainer()->set(VisibilidadeDoFinanceiroDaPasta::class, $financeiro);

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, '/central/encaminhar', ['pastaId' => $pasta->getId(), 'tipo' => 'registro_financeiro', 'itemId' => $registro->getId(), 'usuarioId' => $bruno->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Este colega não vê esta pasta.', $this->json($client)['erro']);

        $this->post($client, '/central/encaminhar', ['pastaId' => $pasta->getId(), 'tipo' => 'registro_financeiro', 'itemId' => $registro->getId(), 'usuarioId' => $carla->getId()]);
        self::assertResponseStatusCodeSame(201);
        $conversa = (int) $this->json($client)['conversaId'];

        $semFinanceiro[] = (int) $carla->getId();
        $this->logarComTenant($client, $carla, $tenant);
        $client->request('GET', "/central/conversas/$conversa/mensagens");
        $this->semOTeor($client, (string) $pasta->getNup());
        self::assertTrue($this->json($client)['mensagens'][0]['ocultoPorPermissao']);

        $semFinanceiro = [(int) $ana->getId()];
        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, '/central/encaminhar', ['pastaId' => $pasta->getId(), 'tipo' => 'registro_financeiro', 'itemId' => $registro->getId(), 'usuarioId' => $carla->getId()]);
        self::assertResponseStatusCodeSame(404, 'quem envia sem ver o financeiro: 404');
    }

    #[TestDox('1:1 pela conversa com colega que saiu do escritório: recusa, nada gravado')]
    public function testUmPraUmSemVinculoAtivo(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $pasta = $this->criarPasta($tenant);

        $this->logarComTenant($client, $ana, $tenant);
        $conversa = $this->abrir($client, $bruno);
        $this->sairDoEscritorio($bruno, $tenant);

        $this->post($client, '/central/encaminhar', ['pastaId' => $pasta->getId(), 'tipo' => 'pasta', 'conversaId' => $conversa]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Ninguém mais nesta conversa pode receber.', $this->json($client)['erro']);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM central_mensagem WHERE tenant_id = ?', [$tenant->getId()]));
    }

    #[TestDox('Colega de OUTRO escritório pelo usuarioId: a mesma recusa de um id que não existe, sem conversa aberta')]
    public function testColegaDeOutroEscritorio(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $deFora = $this->criarUsuarioComPermissoes($outro, self::VE_PASTA, 'fora');
        $pasta = $this->criarPasta($tenant);

        $this->logarComTenant($client, $ana, $tenant);
        $this->post($client, '/central/encaminhar', ['pastaId' => $pasta->getId(), 'tipo' => 'pasta', 'usuarioId' => $deFora->getId()]);
        $status = $client->getResponse()->getStatusCode();
        $corpo = $client->getResponse()->getContent();
        $this->post($client, '/central/encaminhar', ['pastaId' => $pasta->getId(), 'tipo' => 'pasta', 'usuarioId' => 999999999]);

        self::assertSame(422, $status);
        self::assertSame($client->getResponse()->getStatusCode(), $status, 'mesmo status de um id que não existe');
        self::assertSame($client->getResponse()->getContent(), $corpo, 'mesmo corpo: não revela que o colega existe');
        self::assertSame(['erro' => 'Colega não encontrado neste escritório.'], json_decode((string) $corpo, true));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM central_conversa WHERE tenant_id IN (?, ?)', [$tenant->getId(), $outro->getId()]));
    }

    /** Nada do item no corpo cru da resposta: nem o teor, nem o NUP, nem o título do despacho. */
    private function semOTeor(KernelBrowser $client, string $nup): void
    {
        $corpo = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('SEGREDO-DO-TEOR', $corpo);
        self::assertStringNotContainsString($nup, $corpo);
        self::assertStringNotContainsString('Despacho:', $corpo);
        self::assertStringNotContainsString('Registro de', $corpo);
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

    /**
     * @param array<int, array<string, mixed>> $mensagens
     *
     * @return array<string, mixed>
     */
    private function respostaA(array $mensagens, int $citada): array
    {
        foreach ($mensagens as $m) {
            if (($m['respostaA']['id'] ?? null) === $citada) {
                return $m;
            }
        }
        self::fail('a resposta ao despacho não veio');
    }

    /** Tira todas as permissões do papel da pessoa, como quem administra faria na tela de papéis. */
    private function tirarPermissoes(User $pessoa, Tenant $tenant): void
    {
        $this->em()->getConnection()->executeStatement(
            'DELETE FROM tenant_role_permission WHERE tenant_role_id = (SELECT tenant_role_id FROM user_tenant WHERE user_id = ? AND tenant_id = ?)',
            [$pessoa->getId(), $tenant->getId()],
        );
        $this->em()->clear();
    }
}
