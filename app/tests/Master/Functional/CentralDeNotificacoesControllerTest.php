<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Auth\Entity\MensagemSuporte;
use App\Contribua\Entity\Sugestao;
use App\Contribua\Enum\TipoDeSugestao;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Indicacao\Entity\IndicacaoAlerta;
use App\Master\Controller\CentralDeNotificacoesController;
use App\Master\Notificacoes\LeitorDeSugestoes;
use App\Master\Repository\CentralMasterEstadoRepository;
use App\Master\Repository\CentralMasterRespostaRepository;
use App\Master\UseCase\AbrirMensagemDaCentralUseCase;
use App\Master\UseCase\ListarCentralDoMasterUseCase;
use App\Master\UseCase\MarcarNaCentralUseCase;
use App\Master\UseCase\ResponderNaCentralUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A Central de Notificações do Master (spec master-central-de-notificacoes.md §5): as três origens de todos os
 * escritórios (o Master logado num escritório vê a sugestão de outro, pela VisaoDoMaster), só o Master, CSRF em todo
 * POST, estado por Master, "Respondida" para todos, as respostas por e-mail e pela Central, o alerta recusado e a
 * origem apagada sumindo sem erro.
 */
#[CoversClass(CentralDeNotificacoesController::class)]
#[CoversClass(ListarCentralDoMasterUseCase::class)]
#[CoversClass(MarcarNaCentralUseCase::class)]
#[CoversClass(AbrirMensagemDaCentralUseCase::class)]
#[CoversClass(ResponderNaCentralUseCase::class)]
#[CoversClass(LeitorDeSugestoes::class)]
#[CoversClass(CentralMasterEstadoRepository::class)]
#[CoversClass(CentralMasterRespostaRepository::class)]
final class CentralDeNotificacoesControllerTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorioDoMaster;
    private Tenant $outroEscritorio;
    private string $marca;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarTokensFalsos();
        $this->marca = 'mk' . bin2hex(random_bytes(4));
        $this->escritorioDoMaster = $this->escritorio('Conta do Master');
        $this->outroEscritorio = $this->escritorio('Escritório Cliente');
    }

    #[TestDox('O Master, na sessão do escritório dele, vê sugestão de outro escritório, suporte e alerta, com o desenho de cada tipo')]
    public function testMasterVeAsTresOrigens(): void
    {
        [$sugestao, $suporte, $alerta] = $this->semear();
        $this->entrarComoMaster();

        $caixa = $this->listar('rec', $this->marca);

        $porChave = array_column($caixa['itens'], null, 'chave');
        self::assertArrayHasKey('sugestao:' . $sugestao->getId(), $porChave, 'sugestão de outro escritório aparece (VisaoDoMaster)');
        self::assertArrayHasKey('suporte:' . $suporte->getId(), $porChave);
        self::assertArrayHasKey('alerta_indicacao:' . $alerta->getId(), $porChave);
        $s = $porChave['sugestao:' . $sugestao->getId()];
        self::assertSame(['prob', 'Algo atrapalha', 'Bia Autora', 'Algo atrapalha', 'Conta Nº ' . str_pad((string) $this->outroEscritorio->getId(), 6, '0', \STR_PAD_LEFT), false, false, true], [$s['tipo'], $s['rotulo'], $s['de'], $s['assunto'], $s['escritorio'], $s['lida'], $s['respondida'], $s['podeResponder']]);
        $u = $porChave['suporte:' . $suporte->getId()];
        self::assertSame(['sup', 'Suporte', 'Não consigo entrar', 'visitante@exemplo.com · tela de login'], [$u['tipo'], $u['rotulo'], $u['assunto'], $u['escritorio']]);
        $a = $porChave['alerta_indicacao:' . $alerta->getId()];
        self::assertSame(['alerta', 'Sistema BlueJus', 'Disparo alto de convites', false], [$a['tipo'], $a['de'], $a['assunto'], $a['podeResponder']]);
        self::assertSame(50, $caixa['porPagina']);
        self::assertGreaterThanOrEqual(3, $caixa['abas']['nl']);
    }

    #[TestDox('Quem não é Master (nem o primário do escritório) recebe 403 em todas as rotas; CSRF errado, 403')]
    public function testSoMasterECsrf(): void
    {
        [$sugestao] = $this->semear();
        $primario = $this->usuario(['ROLE_USER'], 'Primário');
        $papel = (new TenantRole())->setTenant($this->outroEscritorio)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($papel);
        $vinculo = new UserTenant($primario, $this->outroEscritorio);
        $vinculo->setTenantRole($papel);
        $this->em->persist($vinculo);
        $this->em->flush();
        $this->logarComTenant($this->client, $primario, $this->outroEscritorio);

        $id = (int) $sugestao->getId();
        $this->client->request('GET', '/master/notificacoes');
        self::assertResponseStatusCodeSame(403);
        foreach (['/master/notificacoes/marcar', '/master/notificacoes/sugestao/' . $id . '/abrir', '/master/notificacoes/sugestao/' . $id . '/responder'] as $url) {
            $this->postar($url, ['acao' => 'lida', 'chaves' => ['sugestao:' . $id], 'texto' => 'Oi']);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->entrarComoMaster();
        foreach (['/master/notificacoes/marcar', '/master/notificacoes/sugestao/' . $id . '/abrir', '/master/notificacoes/sugestao/' . $id . '/responder'] as $url) {
            $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'errado'], '{"texto":"Oi"}');
            self::assertResponseStatusCodeSame(403, $url . ' com CSRF errado');
        }
        self::assertSame(0, $this->contar('central_master_estado'), 'nada gravado sem o token');
        self::assertSame(0, $this->contar('central_master_resposta'));
    }

    #[TestDox('Estado por Master: A marca lida, estrela e arquivada sem mudar a caixa de B; a resposta de A aparece como Respondida para B')]
    public function testEstadoPorMasterERespondidaParaTodos(): void
    {
        [, $suporte] = $this->semear();
        $chave = 'suporte:' . $suporte->getId();
        $this->entrarComoMaster();

        foreach (['lida', 'estrela', 'arquivar'] as $acao) {
            $this->postar('/master/notificacoes/marcar', ['acao' => $acao, 'chaves' => [$chave]]);
            self::assertSame(1, $this->json()['marcadas'], $acao);
        }
        self::assertNull($this->item($this->listar('rec', $this->marca), $chave), 'arquivada sai de Recebidas');
        $arq = $this->item($this->listar('arq', $this->marca), $chave);
        self::assertSame([true, true, true], [$arq['lida'], $arq['estrela'], $arq['arquivada']]);
        $this->postar('/master/notificacoes/' . str_replace(':', '/', $chave) . '/responder', ['texto' => 'Já liberamos o seu acesso.']);
        self::assertResponseIsSuccessful();

        $this->entrarComoMaster('Master B');
        $doB = $this->item($this->listar('rec', $this->marca), $chave);
        self::assertNotNull($doB, 'para B continua na caixa');
        self::assertSame([false, false, false, true], [$doB['lida'], $doB['estrela'], $doB['arquivada'], $doB['respondida']]);
        self::assertNotNull($this->item($this->listar('resp', $this->marca), $chave));

        $this->postar('/master/notificacoes/' . str_replace(':', '/', $chave) . '/abrir', []);
        $aberta = $this->json();
        self::assertTrue($aberta['mensagem']['lida'], 'abrir marca lida para B');
        self::assertSame([false, 'Master Teste'], [$aberta['historico'][0]['minha'], $aberta['historico'][0]['porNome']], 'B vê quem respondeu');
    }

    #[TestDox('Responder sugestão com e-mail: Carteiro transacional, origem central_master:resposta, sem o texto original; a sugestão fica atendida')]
    public function testResponderSugestaoPorEmail(): void
    {
        [$sugestao] = $this->semear();
        $master = $this->entrarComoMaster();

        $this->postar('/master/notificacoes/sugestao/' . $sugestao->getId() . '/responder', ['texto' => 'Vamos melhorar a tela de prazos.']);

        self::assertResponseIsSuccessful();
        self::assertSame(['email', true], [$this->json()['canal'], $this->json()['minha']]);
        $envio = $this->conexao()->fetchAssociative("SELECT e.id, e.tipo, e.lista, e.origem, e.assunto, e.tenant_id, e.destinatario, c.corpo_html, c.nome_do_remetente, c.motivo_do_envio FROM comercial_envio e JOIN comercial_envio_conteudo c ON c.envio_id = e.id WHERE e.origem = 'central_master:resposta' ORDER BY e.id DESC LIMIT 1");
        self::assertIsArray($envio);
        self::assertSame(['transacional', null, 'Re: Algo atrapalha', $this->outroEscritorio->getId(), 'bia_' . $this->marca . '@exemplo.com', 'BlueJus', 'Você escreveu ao BlueJus'], [$envio['tipo'], $envio['lista'], $envio['assunto'], $envio['tenant_id'], $envio['destinatario'], $envio['nome_do_remetente'], $envio['motivo_do_envio']]);
        self::assertStringContainsString('Vamos melhorar a tela de prazos.', $envio['corpo_html']);
        self::assertStringNotContainsString('A tela de prazos demora', $envio['corpo_html'], 'o e-mail não cita a mensagem original');
        $resposta = $this->conexao()->fetchAssociative("SELECT canal, envio_id, destino, tenant_id, por_id FROM central_master_resposta WHERE origem = 'sugestao' AND id_origem = ?", [$sugestao->getId()]);
        self::assertSame(['email', $envio['id'], 'bia_' . $this->marca . '@exemplo.com', $this->outroEscritorio->getId(), $master->getId()], [$resposta['canal'], $resposta['envio_id'], $resposta['destino'], $resposta['tenant_id'], $resposta['por_id']]);
        $linha = $this->conexao()->fetchAssociative('SELECT atendida, resposta, respondida_por_id, respondida_em FROM contribua_sugestao WHERE id = ?', [$sugestao->getId()]);
        self::assertSame([true, 'Vamos melhorar a tela de prazos.', $master->getId()], [(bool) $linha['atendida'], $linha['resposta'], $linha['respondida_por_id']]);
        self::assertNotNull($linha['respondida_em']);
    }

    #[TestDox('Responder de novo uma sugestão já atendida troca a resposta e a mantém atendida')]
    public function testResponderDeNovo(): void
    {
        [$sugestao] = $this->semear();
        $this->entrarComoMaster();
        $url = '/master/notificacoes/sugestao/' . $sugestao->getId() . '/responder';

        $this->postar($url, ['texto' => 'Primeira resposta.']);
        $this->postar($url, ['texto' => 'Segunda resposta.']);

        $linha = $this->conexao()->fetchAssociative('SELECT atendida, resposta FROM contribua_sugestao WHERE id = ?', [$sugestao->getId()]);
        self::assertSame([true, 'Segunda resposta.'], [(bool) $linha['atendida'], $linha['resposta']]);
        self::assertSame(2, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM central_master_resposta WHERE origem = 'sugestao' AND id_origem = ?", [$sugestao->getId()]));
    }

    #[TestDox('Sugestão de autor sem e-mail válido: aviso na Central do autor, no escritório da sugestão, sem e-mail')]
    public function testResponderSugestaoSemEmail(): void
    {
        $autor = $this->usuario(['ROLE_USER'], 'Sem Email', 'sem-arroba-' . $this->marca);
        $sugestao = new Sugestao($this->outroEscritorio, $autor, 'Sem Email', TipoDeSugestao::Falta, 'Falta exportar planilha ' . $this->marca);
        $this->em->persist($sugestao);
        $this->em->flush();
        $this->entrarComoMaster();

        $this->postar('/master/notificacoes/sugestao/' . $sugestao->getId() . '/responder', ['texto' => 'Entra no próximo mês.']);

        self::assertResponseIsSuccessful();
        self::assertSame('central', $this->json()['canal']);
        $aviso = $this->conexao()->fetchAssociative("SELECT tenant_id, titulo, mensagem FROM notificacao WHERE usuario_id = ? AND tipo = 'central_master.resposta'", [$autor->getId()]);
        self::assertSame([$this->outroEscritorio->getId(), 'Resposta do BlueJus: O que falta', 'Entra no próximo mês.'], [$aviso['tenant_id'], $aviso['titulo'], $aviso['mensagem']]);
        self::assertSame(0, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM comercial_envio WHERE origem = 'central_master:resposta'"));
        self::assertSame((string) $autor->getId(), $this->conexao()->fetchOne("SELECT destino FROM central_master_resposta WHERE origem = 'sugestao' AND id_origem = ?", [$sugestao->getId()]));
    }

    #[TestDox('Suporte: a resposta vai ao e-mail digitado, sem escritório; alerta não aceita resposta (422)')]
    public function testSuporteEAlerta(): void
    {
        [, $suporte, $alerta] = $this->semear();
        $this->entrarComoMaster();

        $this->postar('/master/notificacoes/suporte/' . $suporte->getId() . '/responder', ['texto' => 'Use o link de redefinir senha.']);
        self::assertResponseIsSuccessful();
        $envio = $this->conexao()->fetchAssociative("SELECT destinatario, tenant_id, assunto FROM comercial_envio WHERE origem = 'central_master:resposta' ORDER BY id DESC LIMIT 1");
        self::assertSame(['visitante@exemplo.com', null, 'Re: Não consigo entrar'], [$envio['destinatario'], $envio['tenant_id'], $envio['assunto']]);

        $this->postar('/master/notificacoes/alerta_indicacao/' . $alerta->getId() . '/responder', ['texto' => 'Oi']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Esta mensagem não aceita resposta.', $this->json()['erro']);
        $this->postar('/master/notificacoes/suporte/' . $suporte->getId() . '/responder', ['texto' => '   ']);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Origem apagada some da lista sem erro, mesmo com estado gravado; abrir dá 404')]
    public function testOrigemApagadaSome(): void
    {
        [, $suporte] = $this->semear();
        $this->entrarComoMaster();
        $chave = 'suporte:' . $suporte->getId();
        $this->postar('/master/notificacoes/marcar', ['acao' => 'estrela', 'chaves' => [$chave]]);
        $this->conexao()->executeStatement('DELETE FROM mensagem_suporte WHERE id = ?', [$suporte->getId()]);
        $this->em->clear();

        $this->client->request('GET', '/master/notificacoes?aba=est');
        self::assertResponseIsSuccessful();
        self::assertNull($this->item($this->json(), $chave));
        $this->postar('/master/notificacoes/suporte/' . $suporte->getId() . '/abrir', []);
        self::assertResponseStatusCodeSame(404);
        $this->postar('/master/notificacoes/marcar', ['acao' => 'lida', 'chaves' => [$chave]]);
        self::assertSame(0, $this->json()['marcadas']);
    }

    #[TestDox('Tela: a seção entra no painel pelo ponto de entrada (filho direto), com as abas, a barra e a janela da mensagem')]
    public function testTela(): void
    {
        $this->entrarComoMaster();
        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $externa = $crawler->filter('div.mp-painel[data-painel-aba="painel"] > div.mp-secao-externa[data-sec-externa="notif"]');
        self::assertCount(1, $externa);
        $nf = $externa->filter('div.mp-secao-externa > div.nf[data-nf]');
        self::assertCount(1, $nf);
        self::assertSame('/master/notificacoes', $nf->attr('data-url-listar'));
        self::assertSame(
            ['Recebidas', 'Não lidas', 'Com estrela', 'Respondidas', 'Arquivadas'],
            $nf->filter('.nf-caixa > .nf-abas > button.nf-aba .nf-aba-rot')->each(static fn ($b): string => trim($b->text())),
        );
        self::assertCount(2, $nf->filter('.nf-abas [data-nf-n]'), 'só Recebidas e Não lidas têm contador');
        self::assertSame('Buscar mensagem', $nf->filter('.nf-caixa > .nf-barra > label.nf-busca > input')->attr('placeholder'));
        self::assertCount(3, $nf->filter('.nf-barra > .nf-acoes > button[data-nf-lote]'));
        self::assertSame('para Master do Sistema', trim($nf->filter('.nf > .nf-ver .nf-ver-janela .nf-ver-para')->text()));
        self::assertStringContainsString('Enviar resposta', $nf->filter('.nf-ver-janela .nf-resp button[data-nf-enviar]')->text());
        self::assertCount(1, $crawler->filter('script[src*="js/master/notificacoes.js"]'));
    }

    /** @return array{Sugestao, MensagemSuporte, IndicacaoAlerta} */
    private function semear(): array
    {
        $autor = $this->usuario(['ROLE_USER'], 'Bia Autora', 'bia_' . $this->marca . '@exemplo.com');
        $sugestao = new Sugestao($this->outroEscritorio, $autor, 'Bia Autora', TipoDeSugestao::Problema, 'A tela de prazos demora ' . $this->marca);
        $suporte = new MensagemSuporte('Visitante', 'visitante@exemplo.com', 'Não consigo entrar', 'Perdi o acesso ' . $this->marca, '127.0.0.1');
        $alerta = new IndicacaoAlerta($autor, 'Disparo alto de convites: ' . $this->marca . ' enviou 30', new \DateTimeImmutable());
        foreach ([$sugestao, $suporte, $alerta] as $entidade) {
            $this->em->persist($entidade);
        }
        $this->em->flush();

        return [$sugestao, $suporte, $alerta];
    }

    private function entrarComoMaster(string $nome = 'Master Teste'): User
    {
        $escritorio = $this->em->find(Tenant::class, $this->escritorioDoMaster->getId());
        self::assertInstanceOf(Tenant::class, $escritorio);
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO'], $nome);
        $this->em->persist(new UserTenant($master, $escritorio));
        $this->em->flush();
        $this->logarComTenant($this->client, $master, $escritorio);

        return $master;
    }

    /** @return array<string, mixed> */
    private function listar(string $aba, string $q): array
    {
        $this->client->request('GET', '/master/notificacoes?' . http_build_query(['aba' => $aba, 'q' => $q]));
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @param array<string, mixed> $caixa
     *
     * @return array<string, mixed>|null
     */
    private function item(array $caixa, string $chave): ?array
    {
        return array_column($caixa['itens'], null, 'chave')[$chave] ?? null;
    }

    /** @param array<string, mixed> $corpo */
    private function postar(string $url, array $corpo): void
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_' . CentralDeNotificacoesController::TOKEN], (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function contar(string $tabela): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM ' . $tabela);
    }

    private function escritorio(string $nome): Tenant
    {
        $tenant = (new Tenant())->setName($nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis, string $nome, ?string $email = null): User
    {
        $user = (new User())->setEmail($email ?? 'central_' . uniqid() . '@test.com')->setFullName($nome)->setRoles($papeis)->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function instalarTokensFalsos(): void
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
}
