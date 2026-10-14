<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\CentralController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Central A2 (`docs/specs/central-comunicacao-a2.md`): @menção com aviso no sino, responder, marcar como não lida.
 *
 * O que se prova: a menção só avisa participante ativo — nunca terceiro do mesmo escritório, de outro, nem quem
 * está nos dois sem participar; o aviso não carrega o texto (nem no `audit_log`); não há enxurrada; ler a conversa
 * lê a menção; a resposta só cita mensagem da mesma conversa; a marca de não lida é só do participante.
 */
#[CoversClass(CentralController::class)]
final class CentralA2ControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    /**
     * @param array<string, mixed> $corpo
     *
     * @return array<string, mixed>
     */
    private function enviarCom(KernelBrowser $client, int $conversa, array $corpo): array
    {
        $this->post($client, "/central/conversas/$conversa/mensagens", $corpo);

        return $this->json($client);
    }

    /**
     * As notificações de menção da Central de uma pessoa, direto no banco (sem o TenantFilter de quem está logado).
     *
     * @return list<array<string, mixed>>
     */
    private function mencoesDe(User $pessoa): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT tipo, titulo, mensagem, url, lida, tenant_id FROM notificacao WHERE usuario_id = ? AND tipo = ? ORDER BY id',
            [$pessoa->getId(), Notificacao::TIPO_CENTRAL_MENCAO],
        );
    }

    private static function token(User $pessoa, string $rotulo): string
    {
        return sprintf('@[%s](user:%d)', $rotulo, $pessoa->getId());
    }

    #[TestDox('Menção ao participante: um aviso no sino, sem o texto da conversa — nem na notificação, nem no audit_log')]
    public function testMencaoAvisaSemTexto(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');

        $this->logarComTenant($client, $ana, $tenant);
        $conversa = $this->abrir($client, $bruno);
        $mensagem = $this->enviarCom($client, $conversa, ['conteudo' => 'SEGREDO-A2 ' . self::token($bruno, 'Diretor')])['mensagem'];
        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString(sprintf('(user:%d)', $bruno->getId()), $mensagem['conteudo']);
        self::assertStringNotContainsString('[Diretor]', $mensagem['conteudo'], 'o rótulo é o nome do banco, não o digitado');

        $avisos = $this->mencoesDe($bruno);
        self::assertCount(1, $avisos);
        self::assertNull($avisos[0]['mensagem']);
        self::assertStringNotContainsString('SEGREDO', $avisos[0]['titulo']);
        self::assertStringContainsString('mencionou você na Central de Comunicação', $avisos[0]['titulo']);
        self::assertSame('/central?conversa=' . $conversa, $avisos[0]['url']);
        self::assertSame($tenant->getId(), $avisos[0]['tenant_id']);
        self::assertSame([], $this->mencoesDe($ana), 'quem escreve não se avisa');

        $vazou = $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE changes::text LIKE '%SEGREDO-A2%'");
        self::assertSame(0, (int) $vazou, 'o texto da conversa não pode chegar ao audit_log');
    }

    #[TestDox('Sem enxurrada: enquanto a menção não for lida, não chega outra; ler a conversa lê a menção, e a próxima avisa')]
    public function testSemEnxurradaELeitura(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');

        $this->logarComTenant($client, $ana, $tenant);
        $conversa = $this->abrir($client, $bruno);
        $this->enviarCom($client, $conversa, ['conteudo' => 'um ' . self::token($bruno, 'B')]);
        $ultima = $this->enviarCom($client, $conversa, ['conteudo' => 'dois ' . self::token($bruno, 'B')])['mensagem']['id'];
        self::assertCount(1, $this->mencoesDe($bruno));

        $this->logarComTenant($client, $bruno, $tenant);
        $this->post($client, "/central/conversas/$conversa/lida", ['ateId' => $ultima]);
        self::assertResponseIsSuccessful();
        self::assertTrue((bool) $this->mencoesDe($bruno)[0]['lida']);

        $this->logarComTenant($client, $ana, $tenant);
        $this->enviarCom($client, $conversa, ['conteudo' => 'três ' . self::token($bruno, 'B')]);
        self::assertCount(2, $this->mencoesDe($bruno));
    }

    #[TestDox('Menção a quem não participa — colega do escritório, gente de outro escritório, quem está nos dois — vira texto e não avisa')]
    public function testMencaoAQuemNaoParticipa(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $carla = $this->colega($tenant, 'carla');
        $estranho = $this->colega($outro, 'estranho');
        $dupla = $this->colega($outro, 'dupla');
        $this->em()->persist(new UserTenant($dupla, $tenant));
        $this->em()->flush();

        // Carla participa de OUTRA conversa no mesmo escritório — participar de alguma não a torna mencionável nesta.
        $this->logarComTenant($client, $carla, $tenant);
        $this->abrir($client, $bruno);

        $this->logarComTenant($client, $ana, $tenant);
        $conversa = $this->abrir($client, $bruno);
        $texto = implode(' ', [self::token($carla, 'Carla'), self::token($estranho, 'Estranho'), self::token($dupla, 'Dupla'), self::token($ana, 'Eu')]);
        $mensagem = $this->enviarCom($client, $conversa, ['conteudo' => $texto])['mensagem'];

        self::assertSame('@Carla @Estranho @Dupla @Eu', $mensagem['conteudo']);
        foreach ([$carla, $estranho, $dupla, $ana, $bruno] as $pessoa) {
            self::assertSame([], $this->mencoesDe($pessoa));
        }
    }

    #[TestDox('Quem está em dois escritórios recebe o aviso no escritório da conversa, não no outro')]
    public function testAvisoNoEscritorioDaConversa(): void
    {
        $client = $this->cliente();
        [, $escritorioA] = $this->criarAdmin();
        [, $escritorioB] = $this->criarAdmin();
        $ana = $this->colega($escritorioA, 'ana');
        $this->em()->persist(new UserTenant($ana, $escritorioB));
        $this->em()->flush();
        $dora = $this->colega($escritorioB, 'dora');

        $this->logarComTenant($client, $dora, $escritorioB);
        $conversa = $this->abrir($client, $ana);
        $this->enviarCom($client, $conversa, ['conteudo' => 'oi ' . self::token($ana, 'Ana')]);

        $avisos = $this->mencoesDe($ana);
        self::assertCount(1, $avisos);
        self::assertSame($escritorioB->getId(), $avisos[0]['tenant_id']);
    }

    #[TestDox('Colega que saiu não é mencionável: some do autocomplete e não recebe aviso')]
    public function testMencionaveis(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $this->colega($tenant, 'carla');

        $this->logarComTenant($client, $ana, $tenant);
        $conversa = $this->abrir($client, $bruno);
        $client->request('GET', "/central/conversas/$conversa/mensagens");
        self::assertSame([$bruno->getId()], array_column($this->json($client)['mencionaveis'], 'id'), 'só o participante, nunca eu nem terceiro');

        $this->sairDoEscritorio($bruno, $tenant);
        $client->request('GET', "/central/conversas/$conversa/mensagens");
        self::assertSame([], $this->json($client)['mencionaveis']);
    }

    #[TestDox('Responder: cita a original da mesma conversa; de outra conversa ou de outro escritório é 422 e nada é gravado')]
    public function testResponder(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $carla = $this->colega($tenant, 'carla');
        $estranho = $this->colega($outro, 'estranho');
        $vizinho = $this->colega($outro, 'vizinho');

        $this->logarComTenant($client, $estranho, $outro);
        $deFora = $this->enviar($client, $this->abrir($client, $vizinho), 'SEGREDO-DE-FORA');

        $this->logarComTenant($client, $carla, $tenant);
        $daOutraConversa = $this->enviar($client, $this->abrir($client, $bruno), 'SEGREDO-DA-CARLA');

        $this->logarComTenant($client, $bruno, $tenant);
        $conversa = $this->abrir($client, $ana);
        $original = $this->enviar($client, $conversa, 'pergunta com ' . self::token($ana, 'Ana'));

        $this->logarComTenant($client, $ana, $tenant);
        $resposta = $this->enviarCom($client, $conversa, ['conteudo' => 'resposta', 'respostaAId' => $original])['mensagem'];
        self::assertResponseStatusCodeSame(201);
        self::assertSame($original, $resposta['respostaA']['id']);
        self::assertStringStartsWith('pergunta com @', $resposta['respostaA']['trecho']);
        self::assertStringNotContainsString('(user:', $resposta['respostaA']['trecho'], 'a citação vem como texto');

        foreach ([$daOutraConversa, $deFora, 0, 'abc'] as $invalida) {
            $corpo = $this->enviarCom($client, $conversa, ['conteudo' => 'NAO-GRAVAR', 'respostaAId' => $invalida]);
            self::assertResponseStatusCodeSame(422);
            self::assertStringNotContainsString('SEGREDO', json_encode($corpo, JSON_THROW_ON_ERROR));
        }

        $client->request('GET', "/central/conversas/$conversa/mensagens");
        $mensagens = $this->json($client)['mensagens'];
        self::assertSame([null, $original], array_map(static fn (array $m): ?int => $m['respostaA']['id'] ?? null, $mensagens));
        self::assertStringNotContainsString('NAO-GRAVAR', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Marcar como não lida: só o meu lado; ler desliga; conversa alheia é 404, vazia é 422, sem CSRF é 403')]
    public function testMarcarNaoLida(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $bruno = $this->colega($tenant, 'bruno');
        $carla = $this->colega($tenant, 'carla');

        $this->logarComTenant($client, $ana, $tenant);
        $vazia = $this->abrir($client, $carla);
        $this->post($client, "/central/conversas/$vazia/nao-lida", []);
        self::assertResponseStatusCodeSame(422);

        $conversa = $this->abrir($client, $bruno);
        $ultima = $this->enviar($client, $conversa, 'oi');
        $this->post($client, "/central/conversas/$conversa/nao-lida", []);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/central/conversas');
        $linha = array_values(array_filter($this->json($client)['conversas'], static fn (array $c): bool => $c['id'] === $conversa))[0];
        self::assertTrue($linha['marcadaNaoLida']);
        self::assertSame(0, $linha['naoLidas'], 'a marca não inventa mensagem');

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', '/central/conversas');
        self::assertFalse($this->json($client)['conversas'][0]['marcadaNaoLida'], 'a marca é só de quem marcou');

        foreach ([$carla, $admin] as $intruso) {
            $this->logarComTenant($client, $intruso, $tenant);
            $this->post($client, "/central/conversas/$conversa/nao-lida", []);
            self::assertResponseStatusCodeSame(404);
        }

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('POST', "/central/conversas/$conversa/nao-lida", [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);

        $this->post($client, "/central/conversas/$conversa/lida", ['ateId' => $ultima]);
        $client->request('GET', '/central/conversas');
        $linha = array_values(array_filter($this->json($client)['conversas'], static fn (array $c): bool => $c['id'] === $conversa))[0];
        self::assertFalse($linha['marcadaNaoLida'], 'ler desliga a marca, mesmo sem o cursor andar');
    }

    #[TestDox('A página leva o ?conversa= só quando é um id bem formado; o título é "Central de Comunicação"')]
    public function testPaginaAbreConversa(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/central?conversa=42');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-central][data-abrir-conversa="42"]');
        self::assertSelectorTextContains('[data-central-cab-titulo]', 'Central de Comunicação');

        foreach (['abc', '0', '99999999999', '<x>'] as $invalido) {
            $client->request('GET', '/central?conversa=' . urlencode($invalido));
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('[data-abrir-conversa]');
        }
    }
}
