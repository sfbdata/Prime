<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Contract\AuditoriaDoDespacho;
use App\Central\Controller\EncaminhamentoController;
use App\Central\DTO\EncaminharItemInput;
use App\Central\Enum\PrioridadeDoDespacho;
use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisoDeDespachoUrgente;
use App\Central\Service\GuardaDoEncaminhamento;
use App\Central\Service\ItensEncaminhaveisDaPasta;
use App\Central\UseCase\AbrirConversaUseCase;
use App\Central\UseCase\EncaminharItemDaPastaUseCase;
use App\Central\UseCase\EnviarMensagemUseCase;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Central\Entity\Conversa;
use App\Central\Entity\Participante;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Encaminhar via Chat I.A" (`docs/specs/encaminhar-via-chat-ia.md`) pela rota: o servidor relê o item e grava a
 * mensagem na 1:1; quem não vê a pasta não aparece nem recebe; item de outra pasta ou de outro escritório é 404;
 * "Urgente" avisa no sino sem o texto; a auditoria guarda só ids; escrita exige o CSRF `ajax`.
 */
#[CoversClass(EncaminhamentoController::class)]
final class EncaminhamentoTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    use CentralHttpTrait;

    private const VE_PASTA = ['resources.pasta.view'];

    #[TestDox('Registro de Dados para um colega: 201, a 1:1 recebe "Despacho: Registro de ..." com a instrução; a auditoria só com ids')]
    public function testEncaminharRegistroParaColega(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $pasta = $this->criarPasta($tenant);
        $registro = $this->criarAnotacao($tenant, $pasta, $ana, '<p>SEGREDO-DESPACHO ligar para o cliente</p>');

        // O registro é auditado ao nascer (é dele); o despacho não pode acrescentar nenhuma linha com o texto.
        $comOTextoAntes = $this->linhasDeAuditoriaCom('SEGREDO-DESPACHO');

        $this->logarComTenant($client, $ana, $tenant);
        $saida = $this->encaminhar($client, $pasta, ['tipo' => 'registro_dados', 'itemId' => $registro->getId(), 'usuarioId' => $bruno->getId(), 'instrucao' => 'até sexta']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Usuário bruno', $saida['destinoNome']);

        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', '/central/conversas/' . $saida['conversaId'] . '/mensagens');
        $mensagens = $this->json($client)['mensagens'];
        self::assertCount(1, $mensagens);
        self::assertStringStartsWith('Despacho: Registro de Usuário ana · ', $mensagens[0]['conteudo']);
        self::assertStringEndsWith("\nSEGREDO-DESPACHO ligar para o cliente\nPasta {$pasta->getNup()}\n\naté sexta", $mensagens[0]['conteudo']);

        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            "SELECT entity_class, changes FROM audit_log WHERE action = 'despacho' AND tenant_id = ? AND entity_id = ?",
            [$tenant->getId(), (string) $pasta->getId()],
        );
        self::assertCount(1, $linhas);
        self::assertSame(Pasta::class, $linhas[0]['entity_class']);
        self::assertSame(
            ['tipo' => 'registro_dados', 'item_id' => $registro->getId(), 'conversa_id' => $saida['conversaId'], 'prioridade' => 'normal'],
            json_decode((string) $linhas[0]['changes'], true),
        );
        self::assertSame($comOTextoAntes, $this->linhasDeAuditoriaCom('SEGREDO-DESPACHO'), 'o despacho não leva o texto do item ao audit_log');
        self::assertSame([], $this->despachosDe($bruno), 'normal não avisa no sino');
    }

    #[TestDox('Destinos: colega que não vê a pasta some; grupo com ele fica travado com o motivo; enviar para ele é 422')]
    public function testDestinosSoComQuemVe(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $carla = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta = $this->criarPasta($tenant);
        $grupo = $this->criarGrupo($tenant, 'Equipe com Carla', [$ana, $bruno, $carla]);

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/encaminhar/destinos?pasta=' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $destinos = $this->json($client)['destinos'];

        $colegas = array_column(array_filter($destinos, static fn (array $d): bool => $d['tipo'] === 'colega'), 'usuarioId');
        self::assertContains($bruno->getId(), $colegas);
        self::assertNotContains($carla->getId(), $colegas, 'quem não vê a pasta não aparece');
        $doGrupo = array_values(array_filter($destinos, static fn (array $d): bool => $d['conversaId'] === $grupo->getId()));
        self::assertCount(1, $doGrupo);
        self::assertFalse($doGrupo[0]['disponivel']);
        self::assertSame('Nem todos deste grupo veem esta pasta.', $doGrupo[0]['motivo']);

        $this->encaminhar($client, $pasta, ['tipo' => 'pasta', 'usuarioId' => $carla->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Este colega não vê esta pasta.', $this->json($client)['erro']);
        $this->encaminhar($client, $pasta, ['tipo' => 'pasta', 'conversaId' => $grupo->getId()]);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Item de outra pasta, pasta de outro escritório e pasta que eu não vejo: 404, sem mensagem gravada')]
    public function testItemDeForaEh404(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $semVer = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta = $this->criarPasta($tenant);
        $irma = $this->criarPasta($tenant);
        $registroDaIrma = $this->criarAnotacao($tenant, $irma, $ana, 'da outra pasta');
        $deFora = $this->criarPasta($outro);

        $this->logarComTenant($client, $ana, $tenant);
        $this->encaminhar($client, $pasta, ['tipo' => 'registro_dados', 'itemId' => $registroDaIrma->getId(), 'usuarioId' => $bruno->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->encaminhar($client, $deFora, ['tipo' => 'pasta', 'usuarioId' => $bruno->getId()]);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/central/encaminhar/destinos?pasta=' . $deFora->getId());
        self::assertResponseStatusCodeSame(404);

        // O EM é limpo entre requisições: a pasta e quem exclui vêm de novo do banco.
        $this->em()->find(Pasta::class, $irma->getId())?->marcarExcluida($this->em()->find(User::class, $ana->getId()), new \DateTimeImmutable());
        $this->em()->flush();
        $client->request('GET', '/central/encaminhar/destinos?pasta=' . $irma->getId());
        self::assertResponseStatusCodeSame(404, 'pasta excluída (lápide) não lista destinos');
        $this->encaminhar($client, $irma, ['tipo' => 'pasta', 'usuarioId' => $bruno->getId()]);
        self::assertResponseStatusCodeSame(404, 'nem recebe despacho');

        $this->logarComTenant($client, $semVer, $tenant);
        $client->request('GET', '/central/encaminhar/destinos?pasta=' . $pasta->getId());
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($client, $ana, $tenant);
        $client->request('GET', '/central/conversas');
        self::assertSame([], $this->conversasSemIa($client), 'nenhuma conversa aberta pelos pedidos recusados');
    }

    #[TestDox('Urgente: o colega ganha um aviso "Despacho urgente de NOME", com o link da conversa e sem o texto')]
    public function testUrgenteAvisaNoSino(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $pasta = $this->criarPasta($tenant);

        $this->logarComTenant($client, $ana, $tenant);
        $saida = $this->encaminhar($client, $pasta, ['tipo' => 'pasta', 'usuarioId' => $bruno->getId(), 'instrucao' => 'SEGREDO-URGENTE', 'prioridade' => 'urgente']);
        self::assertResponseStatusCodeSame(201);

        $avisos = $this->despachosDe($bruno);
        self::assertCount(1, $avisos);
        self::assertSame('Despacho urgente de Usuário', $avisos[0]['titulo']);
        self::assertNull($avisos[0]['mensagem']);
        self::assertSame('/central?conversa=' . $saida['conversaId'], $avisos[0]['url']);
        self::assertSame($tenant->getId(), $avisos[0]['tenant_id']);
        self::assertSame([], $this->despachosDe($ana), 'quem despacha não se avisa');
        self::assertSame(0, $this->linhasDeAuditoriaCom('SEGREDO-URGENTE'), 'a instrução não chega ao audit_log (nem pela notificação)');

        // Aparece na Caixa da Central (não está em FORA_DA_CAIXA), com o rótulo e a cor do "Para você".
        $this->logarComTenant($client, $bruno, $tenant);
        $client->request('GET', '/central/avisos');
        $naCaixa = array_values(array_filter($this->json($client)['avisos'], static fn (array $a): bool => $a['tipo'] === Notificacao::TIPO_CENTRAL_DESPACHO));
        self::assertCount(1, $naCaixa);
        self::assertSame(['atencao', 'Despacho', 'bi-person-check'], [$naCaixa[0]['grupo'], $naCaixa[0]['rotulo'], $naCaixa[0]['icone']]);
    }

    #[TestDox('Falhou a auditoria: nada fica gravado, nem a mensagem, nem a 1:1 aberta para o despacho, nem o aviso')]
    public function testFalhaDaAuditoriaDesfazTudo(): void
    {
        static::createClient();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $pasta = $this->criarPasta($tenant);
        $conexao = $this->em()->getConnection();
        $useCase = $this->despachoComAuditoriaQueFalha();

        try {
            $useCase->executar($ana, $tenant, new EncaminharItemInput((int) $pasta->getId(), TipoDeItemEncaminhado::Pasta, null, (int) $bruno->getId(), null, null, PrioridadeDoDespacho::Urgente));
            self::fail('a falha da auditoria devia subir');
        } catch (\RuntimeException $e) {
            self::assertSame('auditoria fora do ar', $e->getMessage());
        }

        self::assertSame(0, (int) $conexao->fetchOne('SELECT COUNT(*) FROM central_mensagem WHERE tenant_id = ?', [$tenant->getId()]));
        self::assertSame(0, (int) $conexao->fetchOne('SELECT COUNT(*) FROM central_conversa WHERE tenant_id = ?', [$tenant->getId()]));
        self::assertSame(0, (int) $conexao->fetchOne('SELECT COUNT(*) FROM notificacao WHERE tipo = ? AND usuario_id = ?', [Notificacao::TIPO_CENTRAL_DESPACHO, $bruno->getId()]));
    }

    #[TestDox('Encaminhar sem o token CSRF: 403 e nada gravado')]
    public function testCsrf(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'ana');
        $bruno = $this->criarUsuarioComPermissoes($tenant, self::VE_PASTA, 'bruno');
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('POST', '/central/encaminhar', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(
            ['pastaId' => $pasta->getId(), 'tipo' => 'pasta', 'usuarioId' => $bruno->getId()],
            JSON_THROW_ON_ERROR,
        ));
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/central/conversas');
        self::assertSame([], $this->conversasSemIa($client));
    }

    /** O despacho de verdade, com as peças do container, menos a auditoria, que falha. */
    private function despachoComAuditoriaQueFalha(): EncaminharItemDaPastaUseCase
    {
        $c = static::getContainer();

        return new EncaminharItemDaPastaUseCase(
            $c->get(AcessoACentral::class),
            $c->get(GuardaDoEncaminhamento::class),
            $c->get(ItensEncaminhaveisDaPasta::class),
            $c->get(UserRepository::class),
            $c->get(AbrirConversaUseCase::class),
            $c->get(EnviarMensagemUseCase::class),
            $c->get(AvisoDeDespachoUrgente::class),
            new class () implements AuditoriaDoDespacho {
                public function registrar(User $autor, Tenant $tenant, Pasta $pasta, TipoDeItemEncaminhado $tipo, ?int $itemId, int $conversaId, PrioridadeDoDespacho $prioridade): void
                {
                    throw new \RuntimeException('auditoria fora do ar');
                }
            },
            $c->get(EntityManagerInterface::class),
        );
    }

    /**
     * @param array<string, mixed> $corpo
     *
     * @return array<string, mixed>
     */
    private function encaminhar(KernelBrowser $client, Pasta $pasta, array $corpo): array
    {
        $this->post($client, '/central/encaminhar', ['pastaId' => $pasta->getId(), ...$corpo]);

        return $this->json($client);
    }

    /** @param list<User> $membros o primeiro cria e administra */
    private function criarGrupo(Tenant $tenant, string $nome, array $membros): Conversa
    {
        $agora = new \DateTimeImmutable();
        $grupo = Conversa::grupo($tenant, $membros[0], $nome, $agora);
        $this->em()->persist($grupo);
        foreach ($membros as $membro) {
            $this->em()->persist(new Participante($tenant, $grupo, $membro, $agora));
        }
        $this->em()->flush();

        return $grupo;
    }

    private function linhasDeAuditoriaCom(string $trecho): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE changes::text LIKE ?', ['%' . $trecho . '%']);
    }

    /** @return list<array<string, mixed>> */
    private function despachosDe(User $pessoa): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT titulo, mensagem, url, tenant_id FROM notificacao WHERE usuario_id = ? AND tipo = ? ORDER BY id',
            [$pessoa->getId(), Notificacao::TIPO_CENTRAL_DESPACHO],
        );
    }
}
