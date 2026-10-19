<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClienteEmail;
use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Pasta\Controller\PastaPushClienteController;
use App\Pasta\DTO\AvisarClienteOutput;
use App\Pasta\Entity\EnvioAoCliente;
use App\Pasta\Entity\Pasta;
use App\Pasta\Enum\TipoDeEnvioAoCliente;
use App\Pasta\Service\EnvioAoCliente\EnvioDeEmailAoClientePeloMailer;
use App\Pasta\UseCase\CadastrarEmailDoClienteUseCase;
use App\Pasta\UseCase\DefinirAvisosDoEmailDoClienteUseCase;
use App\Pasta\UseCase\EnviarAnaliseAoClienteUseCase;
use App\Pasta\UseCase\MontarAvisarClienteUseCase;
use App\Pasta\UseCase\RemoverEmailDoClienteUseCase;
use App\Pasta\UseCase\TornarEmailDoClientePrincipalUseCase;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Avisar cliente" e o envio manual da Análise IA (spec push-avisar-cliente §2, §3 e §5): a tela (filho
 * direto da coluna, textos do desenho, item virtual do cadastro, descadastrado travado), o CRUD dos
 * e-mails (CSRF, EDITAR, outro escritório, id alheio) e o envio (histórico, e-mail com cópia,
 * Responder para e descadastro, checkbox obrigatório, análise interna, aviso ao primário sem
 * Controladoria).
 */
#[CoversClass(PastaPushClienteController::class)]
#[CoversClass(CadastrarEmailDoClienteUseCase::class)]
#[CoversClass(TornarEmailDoClientePrincipalUseCase::class)]
#[CoversClass(DefinirAvisosDoEmailDoClienteUseCase::class)]
#[CoversClass(RemoverEmailDoClienteUseCase::class)]
#[CoversClass(MontarAvisarClienteUseCase::class)]
#[CoversClass(EnviarAnaliseAoClienteUseCase::class)]
#[CoversClass(EnvioDeEmailAoClientePeloMailer::class)]
#[Group('pasta')]
final class PastaPushClienteTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const COLUNA = '#push > .ps-push-grade > aside.ps-push-coluna';
    private const CARTAO = self::COLUNA . ' > .ps-push-cliente';

    private function instalarCsrfStorage(): void
    {
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
    }

    /** @return array{0: KernelBrowser, 1: User, 2: Tenant, 3: Pasta, 4: ClientePF} */
    private function cenario(string $emailDaFicha = 'maria.ficha@cliente.com.br'): array
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $cliente = $this->criarClientePF($tenant, $pasta, 'Maria da Silva Souza');
        $cliente->setEmail($emailDaFicha);
        $this->em()->flush();
        $this->instalarCsrfStorage();

        return [$client, $user, $tenant, $pasta, $cliente];
    }

    /** @param array<string, mixed> $dados */
    private function postar(KernelBrowser $client, Pasta $pasta, string $caminho, array $dados = [], ?string $token = null): void
    {
        $client->request('POST', '/pasta/' . $pasta->getId() . '/push/cliente' . $caminho, $dados + [
            '_token' => $token ?? 'TOKEN_pasta_push_cliente_' . $pasta->getId(),
        ]);
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return list<ClienteEmail> */
    private function linhas(ClientePF $cliente): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(ClienteEmail::class)->findBy(['cliente' => $cliente->getId()], ['id' => 'ASC']);
    }

    /** @return list<EnvioAoCliente> */
    private function envios(Pasta $pasta): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(EnvioAoCliente::class)->findBy(['pasta' => $pasta->getId()], ['id' => 'ASC']);
    }

    private function analiseConcluida(Tenant $tenant, User $user, Pasta $pasta, bool $interna = false): AnaliseDeInteligencia
    {
        $this->ligarIaNoTenant($tenant, $user);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->concluirAnalise($analise, 'A ré contestou.', [['tipo' => 'prazo', 'texto' => 'Réplica em 15 dias.']]);
        if (!$interna) {
            $analise->alternarInterna();
            $this->em()->flush();
        }

        return $analise;
    }

    /**
     * Depois de uma requisição o EntityManager foi reiniciado (kernel.reset): a entidade do teste está
     * solta e precisa ser lida de novo antes de servir de relação.
     *
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private function deNovo(object $entidade): object
    {
        $id = $entidade->getId(); // @phpstan-ignore method.notFound
        $relida = $this->em()->find($entidade::class, $id);
        self::assertNotNull($relida);

        return $relida;
    }

    /** Põe o usuário no perfil de sistema do escritório: vira "primário" para o aviso do sino. */
    private function tornarPrimario(User $user, Tenant $tenant): void
    {
        $perfil = new TenantRole();
        $perfil->setTenant($tenant);
        $perfil->setName('Primário ' . uniqid());
        $perfil->setIsSystem(true);
        $this->em()->persist($perfil);
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        self::assertNotNull($vinculo);
        $vinculo->setTenantRole($perfil);
        $this->em()->flush();
    }

    #[TestDox('Tela: o cartão é filho direto da coluna, depois do "Avisar quando chegar", com o título, o "Cadastrar e-mail" e o e-mail da ficha como "Cadastro · principal"')]
    public function testCartaoComItemVirtual(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, $pasta);

        $classes = $crawler->filter(self::COLUNA)->children()->each(static fn (Crawler $no): string => (string) $no->attr('class'));
        self::assertSame(['ps-push-painel', 'ps-push-avisos', 'ps-push-cliente'], $classes);
        $cartao = $crawler->filter(self::CARTAO);
        self::assertSame('Avisar cliente', $cartao->filter('.ps-push-cliente-cab > .ps-push-cliente-titulo')->text());
        self::assertSame('1', $cartao->filter('.ps-push-cliente-cab > .ps-push-cliente-cont')->text());
        self::assertSame('1 e-mail cadastrado, 1 recebendo avisos', $cartao->filter('.ps-push-cliente-cont')->attr('title'));
        self::assertSame('Cadastrar e-mail', trim($cartao->filter('.ps-push-cliente-cab > .ps-push-cliente-cadastrar')->text()));

        $item = $cartao->filter('.ps-push-cliente > .ps-push-cliente-item');
        self::assertCount(1, $item);
        self::assertSame('0', $item->attr('data-push-cliente-email'), 'item virtual: id 0');
        self::assertSame('maria.ficha@cliente.com.br', $item->filter('.ps-push-cliente-email')->text());
        self::assertSame('Cadastro · principal', $item->filter('.ps-push-cliente-sub')->text());
        self::assertSame('true', $item->filter('[data-push-cliente-acao="avisos"]')->attr('aria-checked'));
        self::assertSame('Recebendo avisos (clique para pausar)', $item->filter('[data-push-cliente-acao="avisos"]')->attr('title'));
        self::assertSame('E-mail principal', $item->filter('[data-push-cliente-acao="principal"]')->attr('aria-label'));
        self::assertSame(1, $cartao->filter('.ps-push-cliente > form[data-push-cliente-form][hidden] input[placeholder="email@cliente.com.br"]')->count());
        self::assertSame(['Pessoal', 'Trabalho', 'Familiar', 'Outro'], $cartao->filter('.ps-push-cliente-rotulos > button')->each(static fn (Crawler $b): string => $b->text()));
        self::assertCount(0, $cartao->filter('.ps-push-cliente-cc'), 'sem Controladoria a linha some');
        self::assertCount(0, $this->linhas($pasta->getClientePrincipal() ?? self::fail()), 'mostrar não grava');

        // Modal: sem Controladoria, o aviso vermelho do desenho.
        $modal = $crawler->filter('#push > .ps-push-envio-fundo[data-push-envio][hidden]');
        self::assertCount(1, $modal);
        self::assertSame('Enviar ao cliente', $modal->filter('.ps-push-envio-titulo')->text());
        self::assertSame('Nenhum usuário com perfil de Controladoria cadastrado.', $modal->filter('.ps-push-envio-sem-cc')->text());
        self::assertSame('Revisei e corrigi o conteúdo. Autorizo o envio ao cliente em nome do escritório.', $modal->filter('[data-push-envio-revisei] > span')->text());
        self::assertStringNotContainsString('—', $cartao->html() . $modal->html());
    }

    #[TestDox('Tela: sem e-mail nenhum, o vazio com os dois primeiros nomes e o contador âmbar no zero')]
    public function testVazio(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario('sem-email');
        $this->logarComTenant($client, $user, $tenant);

        $cartao = $this->abrir($client, $pasta)->filter(self::CARTAO);

        self::assertSame('Nenhum e-mail cadastrado para MARIA DA. Cadastre para manter o cliente informado sobre o processo.', $cartao->filter('.ps-push-cliente > .ps-push-cliente-vazio > span')->text());
        self::assertSame('ps-push-cliente-cont is-vazio', $cartao->filter('.ps-push-cliente-cont')->attr('class'));
        self::assertSame('Nenhum e-mail cadastrado', $cartao->filter('.ps-push-cliente-cont')->attr('title'));
    }

    #[TestDox('CRUD: cadastra (materializa o da ficha e grava "E-mail cadastrado" no histórico), torna principal, pausa e remove; cada resposta traz o cartão novo')]
    public function testCrud(): void
    {
        [$client, $user, $tenant, $pasta, $cliente] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);

        $this->postar($client, $pasta, '/emails', ['email' => ' Novo@Cliente.com.br ', 'rotulo' => 'trabalho']);
        self::assertResponseIsSuccessful();
        $resposta = $this->json($client);
        self::assertSame('E-mail cadastrado: novo@cliente.com.br', $resposta['mensagem']);
        self::assertStringContainsString('novo@cliente.com.br', $resposta['html']);
        $linhas = $this->linhas($cliente);
        self::assertSame(['maria.ficha@cliente.com.br', 'novo@cliente.com.br'], array_map(static fn (ClienteEmail $e): string => $e->getEmail(), $linhas));
        self::assertSame([true, false], array_map(static fn (ClienteEmail $e): bool => $e->ehPrincipal(), $linhas));
        $historico = $this->envios($pasta);
        self::assertCount(1, $historico);
        self::assertSame(['novo@cliente.com.br'], $historico[0]->getPara());

        [$ficha, $novo] = $linhas;
        $this->postar($client, $pasta, '/emails/' . $novo->getId() . '/principal');
        self::assertResponseIsSuccessful();
        self::assertSame([false, true], array_map(static fn (ClienteEmail $e): bool => $e->ehPrincipal(), $this->linhas($cliente)));

        $this->postar($client, $pasta, '/emails/' . $novo->getId() . '/avisos', ['ligado' => '0']);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->linhas($cliente)[1]->recebeAvisos());
        $this->postar($client, $pasta, '/emails/' . $novo->getId() . '/avisos', ['ligado' => '0']);
        self::assertFalse($this->linhas($cliente)[1]->recebeAvisos(), 'estado desejado: repetir não inverte');

        $this->postar($client, $pasta, '/emails/' . $novo->getId() . '/remover');
        self::assertResponseIsSuccessful();
        $restantes = $this->linhas($cliente);
        self::assertCount(1, $restantes);
        self::assertSame($ficha->getId(), $restantes[0]->getId());
        self::assertTrue($restantes[0]->ehPrincipal(), 'removido o principal, o que sobra assume');

        $cartao = $this->abrir($client, $pasta)->filter(self::CARTAO);
        self::assertSame('Ver histórico de envios (1)', $cartao->filter('.ps-push-cliente > [data-push-cliente-hist] > span')->text());
        self::assertSame('E-mail cadastrado', $cartao->filter('.ps-push-cliente-hist-item .ps-push-cliente-hist-t')->text());
        self::assertStringEndsWith(' · e-mail removido', $cartao->filter('.ps-push-cliente-hist-item .ps-push-cliente-hist-sub')->text());
    }

    #[TestDox('CRUD: o item virtual (id 0) é materializado ao ser removido e não volta; validações do desenho em 422')]
    public function testItemVirtualEValidacoes(): void
    {
        [$client, $user, $tenant, $pasta, $cliente] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);

        $this->postar($client, $pasta, '/emails', ['email' => 'nao-e-email', 'rotulo' => 'pessoal']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Digite um e-mail válido.'], $this->json($client));
        $this->postar($client, $pasta, '/emails', ['email' => 'MARIA.FICHA@cliente.com.br', 'rotulo' => 'pessoal']);
        self::assertSame(['erro' => 'Este e-mail já está cadastrado.'], $this->json($client));
        $this->postar($client, $pasta, '/emails', ['email' => 'a@b.com.br', 'rotulo' => 'cadastro']);
        self::assertResponseStatusCodeSame(422);

        $this->postar($client, $pasta, '/emails/0/remover');
        self::assertResponseIsSuccessful();
        $linhas = $this->linhas($cliente);
        self::assertCount(1, $linhas);
        self::assertTrue($linhas[0]->estaRemovido());

        $cartao = $this->abrir($client, $pasta)->filter(self::CARTAO);
        self::assertCount(0, $cartao->filter('.ps-push-cliente-item'), 'o da ficha não volta como virtual');

        for ($i = 1; $i <= 5; ++$i) {
            $this->postar($client, $pasta, '/emails', ['email' => 'e' . $i . '@cliente.com.br', 'rotulo' => 'outro']);
            self::assertResponseIsSuccessful();
        }
        $this->postar($client, $pasta, '/emails', ['email' => 'e6@cliente.com.br', 'rotulo' => 'outro']);
        self::assertSame(['erro' => 'Limite de 5 e-mails por cliente.'], $this->json($client));
    }

    #[TestDox('Guarda: sem CSRF 403; só VER 403 (e a tela trava tudo com a dica); outro escritório 404; e-mail de outro cliente 404')]
    public function testGuardas(): void
    {
        [$client, $user, $tenant, $pasta, $cliente] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);
        $this->postar($client, $pasta, '/emails', ['email' => 'x@cliente.com.br'], 'errado');
        self::assertResponseStatusCodeSame(403);

        // E-mail de um cliente de outra pasta do mesmo escritório: o id não serve nesta pasta.
        $tenant = $this->deNovo($tenant);
        [$outraPasta] = $this->criarPastaComPublicacao($tenant, '07011345720258070008');
        $outroCliente = $this->criarClientePF($tenant, $outraPasta, 'João Outro', '987.654.321-00');
        $alheio = new ClienteEmail($tenant, $outroCliente, 'joao@outro.com.br', \App\Cliente\Enum\RotuloDoEmail::Pessoal, true, null, new \DateTimeImmutable());
        $this->em()->persist($alheio);
        $this->em()->flush();
        $this->postar($client, $pasta, '/emails/' . $alheio->getId() . '/remover');
        self::assertResponseStatusCodeSame(404);

        $tenant = $this->deNovo($tenant);
        $soVe = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($client, $soVe, $tenant);
        $this->postar($client, $pasta, '/emails', ['email' => 'x@cliente.com.br']);
        self::assertResponseStatusCodeSame(403);
        $cartao = $this->abrir($client, $pasta)->filter(self::CARTAO);
        self::assertNotNull($cartao->filter('.ps-push-cliente-cadastrar')->attr('disabled'));
        self::assertSame('Só quem edita a pasta muda os e-mails do cliente.', $cartao->filter('[data-push-cliente-acao="avisos"]')->attr('title'));
        self::assertNotNull($cartao->filter('[data-push-cliente-acao="remover"]')->attr('disabled'));

        [$intruso, $outroTenant] = $this->criarAdmin();
        $this->logarComTenant($client, $intruso, $outroTenant);
        $this->postar($client, $pasta, '/emails', ['email' => 'x@cliente.com.br']);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/pasta/' . $pasta->getId() . '/push/cliente');
        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->linhas($cliente));
    }

    #[TestDox('Descadastrado pelo cliente: selo, switch travado com a dica e a rota recusa religar (422)')]
    public function testDescadastradoTravado(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        static::getContainer()->get(DescadastroDeEmailInterface::class)->descadastrar($tenant, 'maria.ficha@cliente.com.br', 'push_cliente', 'link_do_email');
        $this->logarComTenant($client, $user, $tenant);

        $item = $this->abrir($client, $pasta)->filter(self::CARTAO . ' > .ps-push-cliente-item');
        self::assertSame('Descadastrado pelo cliente', $item->filter('.ps-push-cliente-sub > .ps-push-cliente-selo')->text());
        $switch = $item->filter('[data-push-cliente-acao="avisos"]');
        self::assertNotNull($switch->attr('disabled'));
        self::assertSame('false', $switch->attr('aria-checked'));
        self::assertSame('O cliente pediu para não receber mais estes avisos. Só ele pode voltar a receber.', $switch->attr('title'));

        $this->postar($client, $pasta, '/emails/0/avisos', ['ligado' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'O cliente pediu para não receber mais estes avisos. Só ele pode voltar a receber.'], $this->json($client));
    }

    #[TestDox('Envio: e-mail ao cliente com Responder para, remetente do escritório e o descadastro; histórico gravado; o cartão da análise vira "Reenviar" com "Enviado"; o primário é avisado sem Controladoria')]
    public function testEnvioDaAnalise(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $this->tornarPrimario($user, $tenant);
        $responsavel = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view'], 'resp');
        $pasta->setResponsavel($responsavel);
        $this->em()->flush();
        $analise = $this->analiseConcluida($tenant, $user, $pasta);
        $this->logarComTenant($client, $user, $tenant);

        // O botão no cartão da análise, com o assunto e a mensagem padrão do desenho.
        $botao = $this->abrir($client, $pasta)->filter('.ps-ia-cartao[data-ia-analise="' . $analise->getId() . '"] .ps-ia-acoes > button[data-cliente-enviar]');
        self::assertSame('Enviar ao cliente', trim($botao->text()));
        self::assertSame('Revisar e enviar por e-mail para 1 destinatário', $botao->attr('title'));
        self::assertSame('Atualização do seu processo 0701134-57.2025.8.07.0007', $botao->attr('data-assunto'));
        self::assertStringStartsWith("Olá, Maria.\n\nSegue uma atualização sobre o seu processo 0701134-57.2025.8.07.0007.\n\nA ré contestou.\n\nPrazo: Réplica em 15 dias.", (string) $botao->attr('data-corpo'));

        $this->postar($client, $pasta, '/analises/' . $analise->getId() . '/enviar', [
            'para' => ['maria.ficha@cliente.com.br'], 'assunto' => 'Atualização do seu processo', 'corpo' => "Olá, Maria.\n\nTudo certo.", 'revisado' => '1',
        ]);

        self::assertResponseIsSuccessful();
        $resposta = $this->json($client);
        self::assertSame('Análise enviada para 1 e-mail do cliente', $resposta['mensagem']);
        self::assertMatchesRegularExpression('/^Enviado \d{2}\/\d{2} às \d{2}:\d{2}$/', $resposta['enviado']);
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Atualização do seu processo', $email->getSubject());
        self::assertSame('maria.ficha@cliente.com.br', $email->getTo()[0]->getAddress());
        self::assertSame([], $email->getCc());
        self::assertSame($responsavel->getEmail(), $email->getReplyTo()[0]->getAddress());
        self::assertSame($tenant->getName() . ' via BlueJus', $email->getFrom()[0]->getName());
        self::assertStringContainsString('/email/descadastro', (string) $email->getHeaders()->getHeaderBody('List-Unsubscribe'));
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->getHeaderBody('List-Unsubscribe-Post'));
        self::assertStringContainsString('Não quero mais receber estes avisos', (string) $email->getHtmlBody());
        self::assertStringContainsString('Tudo certo.', (string) $email->getHtmlBody());

        $envios = $this->envios($pasta);
        self::assertCount(1, $envios);
        self::assertSame(['maria.ficha@cliente.com.br'], $envios[0]->getPara());
        self::assertSame($analise->getId(), $envios[0]->getReferencia());
        self::assertSame('enviado', $envios[0]->getSituacao());

        $avisos = $this->em()->getRepository(Notificacao::class)->findBy(['usuario' => $user->getId(), 'tipo' => EnviarAnaliseAoClienteUseCase::TIPO_NOTIFICACAO_SEM_CONTROLADORIA]);
        self::assertCount(1, $avisos, 'sem Controladoria, o primário fica sabendo');

        $crawler = $this->abrir($client, $pasta);
        $acoes = $crawler->filter('.ps-ia-cartao[data-ia-analise="' . $analise->getId() . '"] .ps-ia-acoes');
        self::assertSame('Reenviar ao cliente', trim($acoes->filter('button[data-cliente-enviar]')->text()));
        self::assertMatchesRegularExpression('/^Enviado \d{2}\/\d{2} às \d{2}:\d{2}$/', trim($acoes->filter('[data-cliente-enviado]')->text()));
        self::assertStringContainsString('para maria.ficha@cliente.com.br', (string) $acoes->filter('[data-cliente-enviado]')->attr('title'));
        self::assertSame('Análise IA enviada', $crawler->filter(self::CARTAO . ' .ps-push-cliente-hist-item .ps-push-cliente-hist-t')->first()->text());
    }

    #[TestDox('Envio: checkbox obrigatório, destinatário fora da lista e e-mail descadastrado dão 422; nada sai nem é gravado')]
    public function testEnvioRecusado(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $analise = $this->analiseConcluida($tenant, $user, $pasta);
        $this->logarComTenant($client, $user, $tenant);
        $url = '/analises/' . $analise->getId() . '/enviar';
        $base = ['para' => ['maria.ficha@cliente.com.br'], 'assunto' => 'A', 'corpo' => 'B'];

        $this->postar($client, $pasta, $url, $base);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Confirme que revisou o conteúdo antes de enviar.'], $this->json($client));

        $this->postar($client, $pasta, $url, ['para' => ['estranho@x.com.br'], 'revisado' => '1'] + $base);
        self::assertSame(['erro' => 'Escolha os destinatários entre os e-mails cadastrados do cliente.'], $this->json($client));

        static::getContainer()->get(DescadastroDeEmailInterface::class)->descadastrar($tenant, 'maria.ficha@cliente.com.br', 'push_cliente', 'link_do_email');
        $this->postar($client, $pasta, $url, ['revisado' => '1'] + $base);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'O cliente pediu para não receber mais estes avisos em maria.ficha@cliente.com.br.'], $this->json($client));

        $this->postar($client, $pasta, $url, ['revisado' => '1'] + $base, 'errado');
        self::assertResponseStatusCodeSame(403);

        self::assertEmailCount(0);
        self::assertSame([], $this->envios($pasta));
    }

    #[TestDox('Envio: análise interna não sai (422) e o botão aparece travado com o motivo; análise de outra pasta 404; sem a BlueJus IA 403')]
    public function testAnaliseInternaEAlheia(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $interna = $this->analiseConcluida($tenant, $user, $pasta, true);
        $this->logarComTenant($client, $user, $tenant);
        $dados = ['para' => ['maria.ficha@cliente.com.br'], 'assunto' => 'A', 'corpo' => 'B', 'revisado' => '1'];

        $this->postar($client, $pasta, '/analises/' . $interna->getId() . '/enviar', $dados);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Análise interna do escritório. Libere o cadeado para enviar ao cliente.'], $this->json($client));
        $botao = $this->abrir($client, $pasta)->filter('.ps-ia-cartao[data-ia-analise="' . $interna->getId() . '"] button[data-cliente-enviar]');
        self::assertSame('true', $botao->attr('aria-disabled'));
        self::assertSame('Análise interna do escritório: libere o cadeado para enviar ao cliente.', $botao->attr('title'));

        $tenant = $this->deNovo($tenant);
        $user = $this->deNovo($user);
        [$outraPasta] = $this->criarPastaComPublicacao($tenant, '07011345720258070009');
        $alheia = $this->criarAnalisePendente($tenant, $user, $outraPasta);
        $this->concluirAnalise($alheia);
        $this->postar($client, $pasta, '/analises/' . $alheia->getId() . '/enviar', $dados);
        self::assertResponseStatusCodeSame(404);

        $tenant = $this->deNovo($tenant);
        $semIa = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit']);
        $this->logarComTenant($client, $semIa, $tenant);
        $this->postar($client, $pasta, '/analises/' . $interna->getId() . '/enviar', $dados);
        self::assertResponseStatusCodeSame(403);
        self::assertEmailCount(0);
    }

    #[TestDox('Revisão item 1, sequência exata: remover o 0, cadastrar o mesmo endereço como Pessoal, remover pelo id: a linha do_cadastro fica marcada e o virtual não volta')]
    public function testRemovidoDuasVezesNaoVoltaComoVirtual(): void
    {
        [$client, $user, $tenant, $pasta, $cliente] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);

        $this->postar($client, $pasta, '/emails/0/remover');
        self::assertResponseIsSuccessful();
        $this->postar($client, $pasta, '/emails', ['email' => 'maria.ficha@cliente.com.br', 'rotulo' => 'pessoal']);
        self::assertResponseIsSuccessful();
        $linhas = $this->linhas($cliente);
        self::assertCount(1, $linhas);
        self::assertTrue($linhas[0]->ehDoCadastro());
        self::assertFalse($linhas[0]->estaRemovido());

        $this->postar($client, $pasta, '/emails/' . $linhas[0]->getId() . '/remover');
        self::assertResponseIsSuccessful();
        $linhas = $this->linhas($cliente);
        self::assertCount(1, $linhas, 'a linha do_cadastro nunca é apagada');
        self::assertTrue($linhas[0]->estaRemovido());
        self::assertCount(0, $this->abrir($client, $pasta)->filter(self::CARTAO . ' > .ps-push-cliente-item'), 'o virtual não volta');
    }

    #[TestDox('Revisão item 3: trocar o principal e remover o principal (promovendo outro) passam pelo índice único parcial; um só principal ativo')]
    public function testPrincipalComIndiceParcial(): void
    {
        [$client, $user, $tenant, $pasta, $cliente] = $this->cenario();
        $this->logarComTenant($client, $user, $tenant);
        $this->postar($client, $pasta, '/emails', ['email' => 'b@cliente.com.br', 'rotulo' => 'pessoal']);
        $this->postar($client, $pasta, '/emails', ['email' => 'c@cliente.com.br', 'rotulo' => 'pessoal']);
        [$a, $b, $c] = $this->linhas($cliente);

        $this->postar($client, $pasta, '/emails/' . $c->getId() . '/principal');
        self::assertResponseIsSuccessful();
        $this->postar($client, $pasta, '/emails/' . $b->getId() . '/principal');
        self::assertResponseIsSuccessful();
        $this->postar($client, $pasta, '/emails/' . $b->getId() . '/remover');
        self::assertResponseIsSuccessful();

        $principais = array_values(array_filter($this->linhas($cliente), static fn (ClienteEmail $e): bool => $e->ehPrincipal() && !$e->estaRemovido()));
        self::assertCount(1, $principais);
        self::assertSame($a->getId(), $principais[0]->getId(), 'removido o principal, o primeiro que sobra assume');
        $indice = (string) $this->em()->getConnection()->fetchOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'uniq_cliente_email_principal'");
        self::assertStringContainsString('WHERE (principal AND (removido_em IS NULL))', $indice);
    }

    #[TestDox('Revisão item 4 (LGPD): remover um e-mail comum anonimiza a linha "E-mail cadastrado" da pasta; o envio real da análise guarda o endereço')]
    public function testRemoverAnonimizaOHistoricoDeCadastro(): void
    {
        [$client, $user, $tenant, $pasta, $cliente] = $this->cenario();
        $analise = $this->analiseConcluida($tenant, $user, $pasta);
        $this->logarComTenant($client, $user, $tenant);
        $this->postar($client, $pasta, '/emails', ['email' => 'some@cliente.com.br', 'rotulo' => 'pessoal']);
        $this->postar($client, $pasta, '/analises/' . $analise->getId() . '/enviar', ['para' => ['some@cliente.com.br'], 'assunto' => 'A', 'corpo' => 'B', 'revisado' => '1']);
        self::assertResponseIsSuccessful();
        $id = $this->linhas($cliente)[1]->getId();

        $this->postar($client, $pasta, '/emails/' . $id . '/remover');
        self::assertResponseIsSuccessful();

        $porTipo = [];
        foreach ($this->envios($pasta) as $envio) {
            $porTipo[$envio->getTipo()->value] = $envio->getPara();
        }
        self::assertSame(['e-mail removido'], $porTipo[TipoDeEnvioAoCliente::Cadastro->value]);
        self::assertSame(['some@cliente.com.br'], $porTipo[TipoDeEnvioAoCliente::Ia->value], 'o envio real é prova do ato');
    }

    #[TestDox('Revisão item 2: assunto acima de 200 e mensagem acima de 20.000 dão 422; a mesma análise ao mesmo endereço em 2 minutos volta como já enviada sem sair de novo')]
    public function testLimitesEIdempotencia(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $analise = $this->analiseConcluida($tenant, $user, $pasta);
        $this->logarComTenant($client, $user, $tenant);
        $url = '/analises/' . $analise->getId() . '/enviar';
        $base = ['para' => ['maria.ficha@cliente.com.br'], 'assunto' => 'A', 'corpo' => 'B', 'revisado' => '1'];

        $this->postar($client, $pasta, $url, ['assunto' => str_repeat('a', 201)] + $base);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'O assunto pode ter no máximo 200 caracteres.'], $this->json($client));
        $this->postar($client, $pasta, $url, ['corpo' => str_repeat('b', 20001)] + $base);
        self::assertSame(['erro' => 'A mensagem pode ter no máximo 20.000 caracteres.'], $this->json($client));

        $this->postar($client, $pasta, $url, $base);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        $this->postar($client, $pasta, $url, $base);
        self::assertResponseIsSuccessful();
        $resposta = $this->json($client);
        self::assertSame([], $resposta['enviados']);
        self::assertSame(['maria.ficha@cliente.com.br'], $resposta['jaEnviados']);
        self::assertSame('Esta análise já foi enviada a estes e-mails há menos de 2 minutos.', $resposta['mensagem']);
        self::assertEmailCount(0);
        self::assertCount(1, $this->envios($pasta));
    }

    #[TestDox('Revisão item 2: com 10 envios da pasta na última hora, o próximo dá 429 "Muitos envios em pouco tempo. Tente de novo mais tarde."')]
    public function testLimitePorHora(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $analise = $this->analiseConcluida($tenant, $user, $pasta);
        for ($i = 0; $i < 10; ++$i) {
            $this->em()->persist(new EnvioAoCliente($tenant, $pasta, null, TipoDeEnvioAoCliente::Ia, ['x' . $i . '@cliente.com.br'], $user, new \DateTimeImmutable('-10 minutes'), referencia: $analise->getId()));
        }
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $this->postar($client, $pasta, '/analises/' . $analise->getId() . '/enviar', ['para' => ['maria.ficha@cliente.com.br'], 'assunto' => 'A', 'corpo' => 'B', 'revisado' => '1']);

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['erro' => 'Muitos envios em pouco tempo. Tente de novo mais tarde.'], $this->json($client));
        self::assertEmailCount(0);
    }

    #[TestDox('Falha parcial do transporte: 502 com a lista "enviados", o que saiu fica no histórico')]
    public function testFalhaParcial502(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $analise = $this->analiseConcluida($tenant, $user, $pasta);
        $this->logarComTenant($client, $user, $tenant);
        $this->postar($client, $pasta, '/emails', ['email' => 'falha@cliente.com.br', 'rotulo' => 'pessoal']);
        static::getContainer()->get('event_dispatcher')->addListener(MessageEvent::class, static function (MessageEvent $evento): void {
            $mensagem = $evento->getMessage();
            if ($mensagem instanceof Email && $mensagem->getTo()[0]->getAddress() === 'falha@cliente.com.br') {
                throw new TransportException('SMTP fora do ar');
            }
        });

        $this->postar($client, $pasta, '/analises/' . $analise->getId() . '/enviar', [
            'para' => ['maria.ficha@cliente.com.br', 'falha@cliente.com.br'], 'assunto' => 'A', 'corpo' => 'B', 'revisado' => '1',
        ]);

        self::assertResponseStatusCodeSame(502);
        self::assertSame(['erro' => 'Falha no envio. O texto foi mantido; tente de novo.', 'enviados' => ['maria.ficha@cliente.com.br']], $this->json($client));
        $ia = array_values(array_filter($this->envios($pasta), static fn (EnvioAoCliente $e): bool => $e->getTipo() === TipoDeEnvioAoCliente::Ia));
        self::assertCount(1, $ia);
        self::assertSame(['maria.ficha@cliente.com.br'], $ia[0]->getPara());
    }

    #[TestDox('Envio: quem só VÊ a pasta 403; pasta de outro escritório 404; nada sai')]
    public function testEnvioSoVerEOutroEscritorio(): void
    {
        [$client, $user, $tenant, $pasta] = $this->cenario();
        $analise = $this->analiseConcluida($tenant, $user, $pasta);
        $dados = ['para' => ['maria.ficha@cliente.com.br'], 'assunto' => 'A', 'corpo' => 'B', 'revisado' => '1'];
        $soVe = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view']);
        [$intruso, $outroTenant] = $this->criarAdmin();

        $this->logarComTenant($client, $soVe, $tenant);
        $this->postar($client, $pasta, '/analises/' . $analise->getId() . '/enviar', $dados);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $intruso, $outroTenant);
        $this->postar($client, $pasta, '/analises/' . $analise->getId() . '/enviar', $dados);
        self::assertResponseStatusCodeSame(404);
        self::assertEmailCount(0);
        self::assertSame([], $this->envios($pasta));
    }

    #[TestDox('Modal e cartão com Controladoria: a cópia travada com "nome · e-mail" e a linha "Cópia para a Controladoria" com o selo "Sempre"')]
    public function testComControladoriaNaTela(): void
    {
        [, , , $pasta] = $this->cenario();
        $saida = new AvisarClienteOutput(true, 'Maria', '0701134-57.2025.8.07.0007', [], [['nome' => 'Ana Controle', 'email' => 'ana@escritorio.com.br']], [], 0, []);
        $twig = static::getContainer()->get('twig');

        $modal = new Crawler($twig->render('pasta/_push_cliente_modal.html.twig', ['pasta' => $pasta, 'cliente' => $saida]));
        self::assertSame('Ana Controle · ana@escritorio.com.br', trim($modal->filter('.ps-push-envio-cc')->text()));
        self::assertSame('Cópia obrigatória, não pode ser removida', $modal->filter('.ps-push-envio-cc')->attr('title'));
        self::assertCount(0, $modal->filter('.ps-push-envio-sem-cc'));
        self::assertSame('Análise da BlueJus IA · 0701134-57.2025.8.07.0007', $modal->filter('[data-push-envio-sub]')->text());

        $cartao = new Crawler($twig->render('pasta/_push_cliente.html.twig', ['pasta' => $pasta, 'cliente' => $saida]));
        $linha = $cartao->filter('.ps-push-cliente > .ps-push-cliente-cc');
        self::assertSame('Cópia para a Controladoria: Ana Controle', trim($linha->filter('.ps-push-cliente-cc-txt')->text()));
        self::assertSame('Sempre', trim($linha->filter('.ps-push-cliente-sempre')->text()));
        self::assertSame('Todo e-mail ao cliente sai com cópia para a Controladoria, para conferência e controle dos atos', $linha->attr('title'));
    }
}
