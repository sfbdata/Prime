<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Controller\CampanhaDoMasterController;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Comercial\UseCase\EnviarTesteDaCampanhaUseCase;
use App\Comercial\UseCase\ListarModelosDeCampanhaUseCase;
use App\Comercial\UseCase\PreviaDaCampanhaUseCase;
use App\Comercial\UseCase\SalvarModeloDeCampanhaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O bloco "Nova campanha de e-mail" da 2a (spec comercial-e-emails.md §8.2 a §8.4, §8.6, §8.7): só o Master, CSRF no
 * header, 422 com {erro}; modelos próprios com a trilha manual sem o conteúdo; a prévia em JSON sem enviar nada; o
 * "Enviar teste" ao Master logado, com o teto de 10 por hora e o descadastro respeitado.
 */
#[CoversClass(CampanhaDoMasterController::class)]
#[CoversClass(ListarModelosDeCampanhaUseCase::class)]
#[CoversClass(PreviaDaCampanhaUseCase::class)]
#[CoversClass(EnviarTesteDaCampanhaUseCase::class)]
#[CoversClass(ComposicaoDaCampanha::class)]
#[CoversClass(ModeloDeCampanhaRepository::class)]
final class CampanhaDoMasterControllerTest extends JusPrimeWebTestCase
{
    private User $master;

    #[TestDox('Quem não é Master não lê os modelos, não vê a prévia nem manda teste: 403')]
    public function testSoMaster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Campanha ' . uniqid());
        $em->persist($tenant);
        $usuario = $this->usuario(['ROLE_USER']);
        $em->persist(new UserTenant($usuario, $tenant));
        $em->flush();
        $this->logarComTenant($client, $usuario, $tenant);

        $client->request('GET', '/master/comercial/modelos');
        self::assertResponseStatusCodeSame(403);
        $this->postar($client, '/master/comercial/previa-campanha', $this->convite());
        self::assertResponseStatusCodeSame(403);
        $this->postar($client, '/master/comercial/teste', $this->convite());
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contar('comercial_envio'));
    }

    #[TestDox('Sem o token CSRF: 403 em criar, salvar, excluir, prévia e teste; pedido inválido: 422 com {erro}')]
    public function testCsrfE422(): void
    {
        $client = $this->clienteMaster();
        foreach (['/master/comercial/modelos', '/master/comercial/modelos/1', '/master/comercial/modelos/1/excluir', '/master/comercial/previa-campanha', '/master/comercial/teste'] as $url) {
            $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], '{"acao":"novo"}');
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->postar($client, '/master/comercial/previa-campanha', ['modo' => 'qualquer']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Modo do modelo inválido.', $this->json($client)['erro']);
        $this->postar($client, '/master/comercial/modelos', ['acao' => 'apagar-tudo']);
        self::assertResponseStatusCodeSame(422);
        $this->postar($client, '/master/comercial/modelos/9999', $this->convite());
        self::assertSame('Modelo não encontrado.', $this->json($client)['erro']);
    }

    #[TestDox('A grade traz os 11 fixos do dc, as 17 datas e os próprios depois; "Novo modelo" grava e audita só {nome, modo}')]
    public function testListaENovo(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/modelos', ['acao' => 'novo']);
        self::assertResponseIsSuccessful();
        $novo = $this->json($client);
        self::assertSame('Modelo criado. Monte os blocos e clique em Salvar modelo.', $novo['mensagem']);

        $client->request('GET', '/master/comercial/modelos');
        $modelos = $this->json($client)['modelos'];
        self::assertCount(29, $modelos, 'os 11 fixos, as 17 datas comemorativas (F4) e o próprio');
        self::assertSame('convite', $modelos[0]['chave']);
        self::assertCount(17, array_filter(\array_slice($modelos, 11, 17), static fn (array $m): bool => str_starts_with($m['chave'], 'data-') && $m['proprio'] === false));
        self::assertSame(['cu' . $novo['modelo']['id'], true], [$modelos[28]['chave'], $modelos[28]['proprio']]);
        self::assertSame(['nome' => 'Novo modelo', 'modo' => 'blocos'], $this->trilha('modelo_new', (int) $novo['modelo']['id']));
        self::assertSame('master_comercial_modelo_criar', $this->conexao()->fetchOne("SELECT route FROM audit_log WHERE action = 'modelo_new' AND entity_id = ?", [(string) $novo['modelo']['id']]), 'a trilha manual leva a rota da requisição (6.9, decisão 36)');
    }

    #[TestDox('Salvar: num fixo, a mensagem do dc (422); no próprio, grava; a trilha nunca leva o conteúdo')]
    public function testSalvar(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/modelos/news', $this->convite());
        self::assertResponseStatusCodeSame(422);
        self::assertSame(SalvarModeloDeCampanhaUseCase::FIXO_NAO_SE_ALTERA, $this->json($client)['erro']);

        $this->postar($client, '/master/comercial/modelos', ['acao' => 'novo']);
        $id = (int) $this->json($client)['modelo']['id'];
        $this->postar($client, '/master/comercial/modelos/' . $id, ['modo' => 'blocos', 'nome' => 'Promoção', 'assunto' => 'Oferta secreta', 'blocos' => [['t' => 'texto', 'a' => 'Conteúdo sigiloso da oferta']]]);

        self::assertResponseIsSuccessful();
        self::assertSame('Modelo salvo.', $this->json($client)['mensagem']);
        $trilha = (string) $this->conexao()->fetchOne("SELECT changes FROM audit_log WHERE action = 'modelo_upd' AND entity_id = ?", [(string) $id]);
        self::assertSame(['nome' => 'Promoção', 'modo' => 'blocos'], json_decode($trilha, true));
        self::assertStringNotContainsString('sigiloso', $trilha);
        self::assertStringNotContainsString('Oferta secreta', $trilha);
    }

    #[TestDox('Importar HTML: guardado sanitizado, com o aviso do <style>; Excluir apaga e audita')]
    public function testImportarEExcluir(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/modelos', ['acao' => 'importar', 'arquivo' => 'oferta.html', 'html' => '<head><style>p{}</style></head><p onclick="x()">Oi {nome}</p><script>alert(1)</script>']);

        self::assertResponseIsSuccessful();
        $saida = $this->json($client);
        self::assertStringEndsWith('Estilos em bloco (<style>) não são mantidos; use estilos na própria tag.', $saida['mensagem']);
        $id = (int) $saida['modelo']['id'];
        self::assertSame('<p>Oi {nome}</p>', $this->conexao()->fetchOne('SELECT conteudo FROM comercial_modelo WHERE id = ?', [$id]));

        $this->postar($client, '/master/comercial/modelos/' . $id . '/excluir', []);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->conexao()->fetchOne('SELECT 1 FROM comercial_modelo WHERE id = ?', [$id]));
        self::assertSame(['nome' => 'oferta', 'modo' => 'html'], $this->trilha('modelo_del', $id));
    }

    #[TestDox('Prévia: JSON {html} com o nome de exemplo do dc, o rodapé legal e CSP sandbox; nada é enviado nem gravado')]
    public function testPrevia(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/previa-campanha', $this->convite());

        self::assertResponseIsSuccessful();
        self::assertSame('sandbox', $client->getResponse()->headers->get('Content-Security-Policy'));
        $html = (string) $this->json($client)['html'];
        self::assertStringContainsString('Dra. Ana, conheça o escritório digital BlueJus', $html);
        self::assertStringContainsString('Criar minha conta grátis', $html);
        self::assertStringContainsString('Você recebeu este e-mail porque se cadastrou no BlueJus ou autorizou o envio de novidades.', $html);
        self::assertEmailCount(0);
        self::assertSame(0, $this->contar('comercial_envio'));

        $this->postar($client, '/master/comercial/previa-campanha', ['modo' => 'html', 'assunto' => 'X', 'html' => '<p>Oi {nome}</p><script>alert(1)</script><img src="x" onerror="alert(1)">']);
        $cru = (string) $this->json($client)['html'];
        self::assertStringContainsString('<p>Oi Dra. Ana</p>', $cru);
        self::assertStringNotContainsString('<script', $cru);
        self::assertStringNotContainsString('onerror', $cru);
    }

    #[TestDox('Salvar como meu modelo (dc 21.10 L653): a cópia do fixo com o nome dado e no mesmo modo; sem nome, 422; o fixo continua')]
    public function testSalvarComoMeuModelo(): void
    {
        $client = $this->clienteMaster();
        $convite = $this->convite();
        $this->postar($client, '/master/comercial/modelos', ['acao' => 'salvar_como', 'modelo' => $convite, 'nome' => '  Convite   do Ricardo  ']);

        self::assertResponseIsSuccessful();
        $saida = $this->json($client);
        self::assertSame('Modelo "Convite do Ricardo" salvo em Meus modelos.', $saida['mensagem']);
        $id = (int) $saida['modelo']['id'];
        $linha = $this->conexao()->fetchAssociative('SELECT nome, modo, assunto FROM comercial_modelo WHERE id = ?', [$id]);
        self::assertSame(['Convite do Ricardo', $convite['modo'], $convite['assunto']], [$linha['nome'] ?? null, $linha['modo'] ?? null, $linha['assunto'] ?? null], 'o mesmo modo do fixo (o Duplicar converte em blocos)');
        self::assertSame(['nome' => 'Convite do Ricardo', 'modo' => $convite['modo']], $this->trilha('modelo_new', $id));

        $this->postar($client, '/master/comercial/modelos', ['acao' => 'salvar_como', 'modelo' => $convite, 'nome' => '   ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Dê um nome ao seu modelo.', $this->json($client)['erro']);
    }

    #[TestDox('Prévia com o "Nome na prévia" (dc 21.10 L270): o nome digitado troca o {nome} como foi escrito, só na prévia')]
    public function testPreviaComNomeDigitado(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/previa-campanha', ['modo' => 'html', 'assunto' => 'X', 'html' => '<p>Oi {nome}</p>', 'nomeDaPrevia' => 'Dr. Paulo Lima']);

        self::assertStringContainsString('<p>Oi Dr. Paulo Lima</p>', (string) $this->json($client)['html']);
    }

    #[TestDox('Enviar teste: vai ao Master logado, lista comercial_campanhas, origem teste_campanha, layout do modo')]
    public function testEnviarTeste(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/teste', $this->convite());

        self::assertResponseIsSuccessful();
        self::assertSame('Teste enviado para ' . $this->master->getEmail() . '.', $this->json($client)['mensagem']);
        $envio = $this->conexao()->fetchAssociative(
            'SELECT e.destinatario, e.tipo, e.lista, e.origem, e.situacao, c.layout FROM comercial_envio e JOIN comercial_envio_conteudo c ON c.envio_id = e.id',
        );
        self::assertSame([
            'destinatario' => $this->master->getEmail(), 'tipo' => 'lista', 'lista' => 'comercial_campanhas',
            'origem' => 'teste_campanha', 'situacao' => 'enfileirado', 'layout' => 'linhas',
        ], $envio);
        self::assertSame(1, $this->contar('comercial_envio'), 'só uma pessoa');
    }

    #[TestDox('Enviar teste (F3f): [TESTE] no assunto e o teste gravado como campanha "teste", com o destino ligado ao envio, fora das ativas e no KPI')]
    public function testTesteGravado(): void
    {
        $client = $this->clienteMaster();
        $repositorio = static::getContainer()->get(\App\Comercial\Repository\CampanhaDeEmailRepository::class);
        [$antes, $testesAntes] = [$repositorio->contarNaoCanceladas(), $repositorio->contarTestes()];
        $this->postar($client, '/master/comercial/teste', $this->convite());

        self::assertResponseIsSuccessful();
        $envio = $this->conexao()->fetchAssociative('SELECT id, assunto FROM comercial_envio');
        self::assertStringStartsWith('[TESTE] ', (string) $envio['assunto']);
        $campanha = $this->conexao()->fetchAssociative("SELECT id, nome, estado, publico, total, execucoes FROM comercial_campanha WHERE estado = 'teste'");
        self::assertSame(['teste', 'teste', 1, 1], [$campanha['estado'], $campanha['publico'], (int) $campanha['total'], (int) $campanha['execucoes']]);
        self::assertStringStartsWith('[TESTE] ', (string) $campanha['nome']);
        $destino = $this->conexao()->fetchAssociative('SELECT email, envio_id FROM comercial_campanha_destino WHERE campanha_id = ?', [$campanha['id']]);
        self::assertSame([$this->master->getEmail(), (int) $envio['id']], [$destino['email'], (int) $destino['envio_id']]);
        self::assertNotContains('teste', \App\Comercial\Entity\CampanhaDeEmail::ATIVAS, 'o expansor nunca pega o teste');
        self::assertSame($antes, $repositorio->contarNaoCanceladas(), 'o KPI Campanhas não conta o teste (M8.3)');
        self::assertSame($testesAntes + 1, $repositorio->contarTestes(), 'o teste vai à parte');

        $client->request('GET', '/master/comercial/relatorio?aba=env&q=&p=1');
        $linha = array_column((array) $this->json($client)['campanhas'], null, 'id')[(int) $campanha['id']] ?? null;
        self::assertSame(['Teste', 'Teste · 1 e-mail(s)'], [$linha['rotulo'] ?? null, $linha['publico'] ?? null], 'o Relatório mostra a linha com o selo e o público do desenho (L717)');
        self::assertNull($this->conexao()->fetchOne('SELECT publico_parametros FROM comercial_campanha WHERE id = ?', [$campanha['id']]), 'o endereço só fica no destino, que a retenção anonimiza');

        $client->request('GET', '/master/comercial/campanhas/' . $campanha['id']);
        $visto = $this->json($client);
        self::assertSame(['E-mail de teste', 'Teste · 1 e-mail(s)'], [$visto['situacao'] ?? null, $visto['publico'] ?? null], 'o visualizador mostra o mesmo público da lista (L709)');
    }

    #[TestDox('Enviar teste: sem `para` ou vazio vai só ao Master (compatível)')]
    public function testSemParaVaiSoAoMaster(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/teste', $this->convite() + ['para' => '  ']);

        self::assertResponseIsSuccessful();
        self::assertSame([$this->master->getEmail()], $this->json($client)['enviados']);
        self::assertSame(1, $this->contar('comercial_envio'));
        self::assertSame(0, (int) $this->conexao()->fetchOne(
            "SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = ? AND pid = pg_backend_pid()",
            [\App\Comercial\Repository\CampanhaDeEmailRepository::TRAVA_DO_TESTE],
        ), 'a trava do teste por Master é solta no fim');
    }

    #[TestDox('Enviar teste: vários endereços (vírgula, ponto e vírgula, espaço), um registro com N destinos ligados e a trilha camp_teste só com o total')]
    public function testVariosEnderecos(): void
    {
        $client = $this->clienteMaster();
        $meu = (string) $this->master->getEmail();
        $this->postar($client, '/master/comercial/teste', $this->convite() + ['para' => $meu . ', Ana@Exemplo.com; bia@exemplo.com  ANA@exemplo.com']);

        self::assertResponseIsSuccessful();
        $saida = $this->json($client);
        self::assertSame([$meu, 'ana@exemplo.com', 'bia@exemplo.com'], $saida['enviados'], 'duplicado ignorando caixa');
        self::assertSame('Teste enviado para ' . $meu . ', ana@exemplo.com, bia@exemplo.com.', $saida['mensagem']);
        self::assertSame('', $saida['aviso']);
        self::assertSame(3, $this->contar('comercial_envio'));
        self::assertSame(1, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM comercial_campanha WHERE estado = 'teste'"), 'uma linha por clique');
        $campanha = $this->conexao()->fetchAssociative("SELECT id, total, execucoes FROM comercial_campanha WHERE estado = 'teste'");
        self::assertSame([3, 1], [(int) $campanha['total'], (int) $campanha['execucoes']]);
        $destinos = $this->conexao()->fetchAllKeyValue('SELECT d.email, e.destinatario FROM comercial_campanha_destino d JOIN comercial_envio e ON e.id = d.envio_id WHERE d.campanha_id = ?', [$campanha['id']]);
        ksort($destinos);
        $esperado = ['ana@exemplo.com' => 'ana@exemplo.com', 'bia@exemplo.com' => 'bia@exemplo.com', $meu => $meu];
        ksort($esperado);
        self::assertSame($esperado, $destinos, 'cada destino ligado ao próprio envio');
        $nomes = $this->conexao()->fetchAllKeyValue('SELECT email, nome FROM comercial_campanha_destino WHERE campanha_id = ?', [$campanha['id']]);
        self::assertSame('Pessoa Master', $nomes[$meu]);
        self::assertNull($nomes['ana@exemplo.com'], 'sem nome: o montador usa "colega"');
        $trilha = $this->conexao()->fetchAssociative("SELECT changes, actor_email FROM audit_log WHERE action = 'camp_teste' AND entity_id = ?", [(string) $campanha['id']]);
        self::assertIsArray($trilha);
        self::assertSame(['total' => 3], json_decode((string) $trilha['changes'], true), 'sem e-mails na trilha');
        self::assertSame($meu, $trilha['actor_email']);
    }

    #[TestDox('Enviar teste: endereço inválido e mais de 5 endereços são recusados em 422 e nada sai')]
    public function testInvalidosEExcesso(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/teste', $this->convite() + ['para' => 'ana@exemplo.com, isto-nao-e-email']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Endereço inválido: isto-nao-e-email.', $this->json($client)['erro']);

        $this->postar($client, '/master/comercial/teste', $this->convite() + ['para' => 'a@x.com,b@x.com,c@x.com,d@x.com,e@x.com,f@x.com']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe de 1 a 5 endereços.', $this->json($client)['erro']);

        $this->postar($client, '/master/comercial/teste', $this->convite() + ['para' => ['x']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->contar('comercial_envio'));
        self::assertSame(0, $this->contar('comercial_campanha'));
    }

    #[TestDox('Enviar teste: o teto é de 10 por hora por Master e cada endereço conta 1; passou do que resta, recusa inteira')]
    public function testTetoPorHora(): void
    {
        $client = $this->clienteMaster();
        $this->testesDoMaster(8, '-10 minutes');
        $this->testesDoMaster(5, '-2 hours');
        $this->postar($client, '/master/comercial/teste', $this->convite() + ['para' => 'a@x.com, b@x.com, c@x.com']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Limite de 10 testes por hora: restam 2.', $this->json($client)['erro']);
        self::assertSame(0, $this->contar('comercial_envio'));

        $this->postar($client, '/master/comercial/teste', $this->convite() + ['para' => 'a@x.com, b@x.com']);
        self::assertResponseIsSuccessful();

        $this->postar($client, '/master/comercial/teste', $this->convite());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Limite de 10 testes por hora atingido.', $this->json($client)['erro']);
        self::assertSame(2, $this->contar('comercial_envio'));
    }

    #[TestDox('Enviar teste: o teto de um Master não vale para outro')]
    public function testTetoEPorMaster(): void
    {
        $client = $this->clienteMaster();
        $this->testesDoMaster(10, '-10 minutes', $this->usuario(['ROLE_SUPER_ADMIN']));
        $this->postar($client, '/master/comercial/teste', $this->convite());

        self::assertResponseIsSuccessful();
    }

    #[TestDox('Enviar teste: descadastrado parcial sai para os outros e avisa; descadastrado de todos é recusado sem linha de teste')]
    public function testDescadastrado(): void
    {
        $client = $this->clienteMaster();
        $descadastro = static::getContainer()->get(DescadastroDeEmailInterface::class);
        $descadastro->descadastrar(null, 'fora@exemplo.com', ComposicaoDaCampanha::LISTA, 'teste');
        $this->postar($client, '/master/comercial/teste', $this->convite() + ['para' => 'fora@exemplo.com, dentro@exemplo.com']);

        self::assertResponseIsSuccessful();
        $saida = $this->json($client);
        self::assertSame('Teste enviado para dentro@exemplo.com. Não enviado para fora@exemplo.com (descadastrado da lista).', $saida['mensagem']);
        self::assertSame(['dentro@exemplo.com'], $saida['enviados']);
        self::assertSame(1, (int) $this->conexao()->fetchOne("SELECT total FROM comercial_campanha WHERE estado = 'teste'"), 'total = quantos saíram');
        self::assertSame(1, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM comercial_campanha_destino'));

        $this->conexao()->executeStatement('DELETE FROM comercial_campanha_destino');
        $this->conexao()->executeStatement('DELETE FROM comercial_campanha');
        $descadastro->descadastrar(null, (string) $this->master->getEmail(), ComposicaoDaCampanha::LISTA, 'teste');
        $this->postar($client, '/master/comercial/teste', $this->convite());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('O endereço está descadastrado da lista de campanhas; o teste não foi enviado.', $this->json($client)['erro']);
        self::assertSame(0, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM comercial_campanha WHERE estado = 'teste'"), 'sem envio, sem linha de teste');

        $this->postar($client, '/master/comercial/teste', $this->convite() + ['para' => 'fora@exemplo.com, ' . $this->master->getEmail()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Os endereços estão descadastrados da lista de campanhas; o teste não foi enviado.', $this->json($client)['erro']);
    }

    /** Linhas de teste já gravadas pelo Master (1 endereço cada), na hora dada: o que o teto lê. */
    private function testesDoMaster(int $quantos, string $quando, ?User $de = null): void
    {
        $de ??= $this->master;
        $repositorio = static::getContainer()->get(\App\Comercial\Repository\CampanhaDeEmailRepository::class);
        $em = new \DateTimeImmutable($quando);
        for ($i = 0; $i < $quantos; ++$i) {
            $repositorio->inserir([
                'chave' => 'teste_' . bin2hex(random_bytes(8)), 'assunto' => '[TESTE] x', 'modelo_chave' => 'convite', 'modelo_nome' => 'Convite',
                'modo' => 'simples', 'selo' => '', 'titulo' => 'x', 'conteudo' => '{}', 'publico' => 'teste', 'parametros' => null,
                'frequencia' => 'unica', 'agendada_para' => null, 'proxima' => $em, 'estado' => 'teste', 'total' => 1,
                'criada_por' => $de->getId(), 'agora' => $em,
            ]);
        }
    }

    /** @return array<string, mixed> o fixo "convite" como a tela manda */
    private function convite(): array
    {
        $fixos = new \App\Comercial\Campanha\ModelosDeCampanha();

        return (array) $fixos->fixo('convite');
    }

    /** @return array<string, mixed> */
    private function trilha(string $acao, int $id): array
    {
        $linha = $this->conexao()->fetchAssociative('SELECT entity_class, changes FROM audit_log WHERE action = ? AND entity_id = ?', [$acao, (string) $id]);
        self::assertIsArray($linha, 'a linha da trilha existe');
        self::assertSame(\App\Comercial\Entity\ModeloDeCampanha::class, $linha['entity_class']);

        return (array) json_decode((string) $linha['changes'], true);
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

    private function clienteMaster(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $storage = new class () implements ClearableTokenStorageInterface {
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
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);
        $this->master = $this->usuario(['ROLE_SUPER_ADMIN']);
        $client->loginUser($this->master);
        $this->marcarTermosAceitos($client);

        return $client;
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('campanha_' . uniqid() . '@test.com')->setFullName('Pessoa Master')->setRoles($papeis)->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
