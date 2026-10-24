<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\DemandaExternaController;
use App\TrabalhoExterno\Controller\TrabalhoExternoController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

/**
 * O DESPACHO no Resumo pela HTTP (D8, desenho 06 de 24/10, l.512 a 530 e 1391 a 1394): só o Master despacha e
 * liga "Aceitar resposta"; o solicitante e o convidado respondem só com a chave ligada; outro escritório é 404;
 * o anexo é um arquivo de verdade que só aparece no despacho; o Extrato é a impressão do desenho.
 */
#[CoversClass(DemandaExternaController::class)]
#[CoversClass(TrabalhoExternoController::class)]
final class DespachoDaDemandaTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    #[TestDox('Master despacha com natureza, prazo e anexo: toast do P4, registro no chat, anexo baixável só no despacho')]
    public function testMasterDespachaComAnexo(): void
    {
        [$client, $codigo, $master, $central] = $this->demandaEmExecucao();

        $r = $this->despachar($client, $codigo, ['texto' => 'Junte a procuração atualizada.', 'natureza' => 'providencia', 'prazo' => '2026-12-01'], [$this->arquivo('procuracao.pdf')]);
        self::assertResponseIsSuccessful();
        self::assertSame(['titulo' => 'Despacho publicado', 'texto' => 'O solicitante vê o despacho no Resumo e recebe um aviso.'], array_intersect_key($r['toast'], ['titulo' => 1, 'texto' => 1]));
        $d = $r['demanda'];
        self::assertSame('Novo despacho da Central BlueJus · Para providência.', end($d['mensagens'])['txt']);
        self::assertSame([], $d['documentos'], 'o anexo do despacho não entra em DOCUMENTOS');
        self::assertTrue($d['semDocumentos']);
        $item = $d['despacho']['lista'][0];
        self::assertSame(['DESPACHO 1', 'Para providência', 'Prazo: 01/12/2026', 'Junte a procuração atualizada.'], [$item['n'], $item['nat'], $item['prazo'], $item['txt']]);
        self::assertSame('procuracao.pdf', $item['arqs'][0]['nome']);
        self::assertStringStartsWith('Brasília, ', $item['local']);
        self::assertMatchesRegularExpression('/^Assinado eletronicamente por .+ \(Central BlueJus\) em \d{2}\/\d{2}\/\d{4}, \d{2}:\d{2} · Cód\. \d{8}$/u', $item['ass']);

        $client->request('GET', "/trabalho-externo/demandas/$codigo/documentos/{$item['arqs'][0]['id']}");
        self::assertResponseIsSuccessful('o anexo do despacho se baixa pela rota dos documentos');

        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $releitura = $this->json($client)['demanda']['despacho']['lista'][0]['ass'];
        self::assertSame($item['ass'], $releitura, 'o código da assinatura não muda ao reler');
    }

    #[TestDox('validações literais: sem texto "O texto do despacho está vazio." (422); prazo torto e natureza desconhecida também')]
    public function testValidacoes(): void
    {
        [$client, $codigo] = $this->demandaEmExecucao();

        foreach ([[['texto' => 'ab'], 'O texto do despacho está vazio.'], [['texto' => 'Texto', 'natureza' => 'urgente'], 'Natureza do despacho desconhecida.'], [['texto' => 'Texto', 'prazo' => '01/12/2026'], 'Prazo do despacho inválido.']] as [$corpo, $msg]) {
            $r = $this->despachar($client, $codigo, $corpo);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($msg, $r['mensagem'] ?? null);
        }
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertSame([], $this->json($client)['demanda']['despacho']['lista']);
    }

    #[TestDox('a Equipe, o solicitante e o convidado não despacham nem ligam a chave (403); outro escritório leva 404')]
    public function testQuemNaoDespacha(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaEmExecucao();
        $this->definirSede($central);
        $equipe = $this->criarUsuario($central, nome: 'Colaborador');
        $this->logarComTenant($client, $master, $central);
        $token = $this->convidar($client, $codigo, 'conv@test.com');
        $convidado = $this->contaDoConvidado($client, $token, 'conv@test.com');

        $this->logarComTenant($client, $equipe, $central);
        $this->despachar($client, $codigo, ['texto' => 'Da equipe']);
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/despachos/aceitar-resposta", ['liberar' => true]);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->despachar($client, $codigo, ['texto' => 'Do solicitante']);
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/despachos/aceitar-resposta", ['liberar' => true]);
        self::assertResponseStatusCodeSame(403);

        $this->logarComoConvidado($client, $convidado);
        $this->despachar($client, $codigo, ['texto' => 'Do convidado']);
        self::assertResponseStatusCodeSame(403);

        $outro = $this->criarTenant('Outro');
        $deOutro = $this->criarUsuario($outro);
        $this->logarComTenant($client, $deOutro, $outro);
        $this->despachar($client, $codigo, ['texto' => 'De fora']);
        self::assertResponseStatusCodeSame(404);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/despachos/respostas", ['texto' => 'De fora']);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', "/trabalho-externo/demandas/$codigo/despachos/extrato");
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($client, $master, $central);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertSame([], $this->json($client)['demanda']['despacho']['lista']);
    }

    #[TestDox('resposta: bloqueada com a chave desligada; ligada, o solicitante e o convidado respondem e o Master lê "Resposta ao despacho de {quem}."')]
    public function testResposta(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaEmExecucao();
        $this->despachar($client, $codigo, ['texto' => 'Confirme a audiência.']);
        $token = $this->convidar($client, $codigo, 'conv@test.com');
        $convidado = $this->contaDoConvidado($client, $token, 'conv@test.com');

        $this->logarComTenant($client, $solicitante, $escritorio);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $bloco = $this->json($client)['demanda']['despacho'];
        self::assertFalse($bloco['podeResponder']);
        self::assertTrue($bloco['leSem']);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/despachos/respostas", ['texto' => 'Confirmada']);
        self::assertResponseStatusCodeSame(403, 'a chave está desligada');

        $this->logarComTenant($client, $master, $central);
        $d = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/despachos/aceitar-resposta", ['liberar' => true])['demanda'];
        self::assertTrue($d['despacho']['respostasLiberadas']);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/despachos/respostas", ['texto' => 'Do Master']);
        self::assertResponseStatusCodeSame(403, 'o Master não responde ao próprio despacho');

        $this->logarComTenant($client, $solicitante, $escritorio);
        $r = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/despachos/respostas", ['texto' => 'Confirmada']);
        self::assertResponseIsSuccessful();
        self::assertSame('Resposta enviada', $r['toast']['titulo']);
        self::assertTrue($r['demanda']['despacho']['respInfo']);
        self::assertSame('Resposta ao despacho de ' . $solicitante->getFullName() . '.', end($r['demanda']['mensagens'])['txt']);

        $this->logarComoConvidado($client, $convidado);
        $r = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/despachos/respostas", ['texto' => 'Estarei lá']);
        self::assertResponseIsSuccessful();
        self::assertSame(['resposta', 'resposta', 'despacho'], array_column($r['demanda']['despacho']['lista'], 'tipo'));

        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/despachos/aceitar-resposta", ['liberar' => false]);
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/despachos/respostas", ['texto' => 'Mais uma']);
        self::assertResponseStatusCodeSame(403, 'desligada de novo');
    }

    #[TestDox('o convidado sem "Ver instruções" lê o despacho sem o nome dos anexos')]
    public function testConvidadoSemVerNaoVeAnexos(): void
    {
        [$client, $codigo] = $this->demandaEmExecucao();
        $this->despachar($client, $codigo, ['texto' => 'Leve o mapa.'], [$this->arquivo('mapa.pdf')]);
        $token = $this->convidar($client, $codigo, 'semver@test.com', ['ver' => false, 'baixar' => false, 'enviar' => false, 'chat' => true, 'andamento' => false, 'concluir' => false]);
        $convidado = $this->contaDoConvidado($client, $token, 'semver@test.com');

        $this->logarComoConvidado($client, $convidado);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $item = $this->json($client)['demanda']['despacho']['lista'][0];
        self::assertSame('Leve o mapa.', $item['txt']);
        self::assertSame([], $item['arqs']);
    }

    #[TestDox('Extrato: o HTML de impressão do desenho, na ordem de publicação, com o texto escapado')]
    public function testExtrato(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaEmExecucao();
        $this->despachar($client, $codigo, ['texto' => 'Primeiro <b>despacho</b>', 'natureza' => 'ciencia']);
        $this->despachar($client, $codigo, ['texto' => 'Segundo']);

        $crawler = $client->request('GET', "/trabalho-externo/demandas/$codigo/despachos/extrato");
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<title>Extrato de despachos ' . $codigo . '</title>', $html);
        self::assertStringContainsString('<body style="font-family:Arial;padding:30px;color:#12242f"><h2>Extrato de despachos · ' . $codigo . '</h2>', $html);
        self::assertSame(['DESPACHO 1 · Para ciência', 'DESPACHO 2'], $crawler->filter('body > div > b')->each(static fn ($n): string => $n->text()));
        self::assertSame('border-left:3px solid #0a6fa0;padding:8px 12px;margin:12px 0', $crawler->filter('body > div')->first()->attr('style'));
        self::assertSame('white-space:pre-line', $crawler->filter('body > div > p')->first()->attr('style'));
        self::assertStringContainsString('Primeiro &lt;b&gt;despacho&lt;/b&gt;', $html);
        self::assertMatchesRegularExpression('/^Assinado eletronicamente por .+ em \d{2}\/\d{2}\/\d{4}, \d{2}:\d{2}:\d{2}$/u', $crawler->filter('body > div > small')->first()->text());
        self::assertStringContainsString('window.print()', $html);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $crawler = $client->request('GET', "/trabalho-externo/demandas/$codigo/despachos/extrato");
        self::assertResponseIsSuccessful('o Extrato é para todos que veem a demanda');
        self::assertCount(2, $crawler->filter('body > div'));
    }

    #[TestDox('o anexo do despacho não se baixa pelo convidado sem "Ver instruções" nem por outro escritório (404)')]
    public function testDownloadDoAnexoDoDespacho(): void
    {
        [$client, $codigo] = $this->demandaEmExecucao();
        $r = $this->despachar($client, $codigo, ['texto' => 'Leve o mapa.'], [$this->arquivo('mapa.pdf')]);
        $id = $r['demanda']['despacho']['lista'][0]['arqs'][0]['id'];
        $url = "/trabalho-externo/demandas/$codigo/documentos/$id";
        $token = $this->convidar($client, $codigo, 'semver@test.com', ['ver' => false, 'baixar' => false, 'enviar' => false, 'chat' => true, 'andamento' => false, 'concluir' => false]);
        $convidado = $this->contaDoConvidado($client, $token, 'semver@test.com');

        $this->logarComoConvidado($client, $convidado);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(404, 'convidado sem "ver"');

        $outro = $this->criarTenant('Outro');
        $this->logarComTenant($client, $this->criarUsuario($outro), $outro);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(404, 'outro escritório');
    }

    #[TestDox('convite revogado e convite vencido: 404 em responder e no Extrato daquela demanda (o convidado segue com outro convite vigente); sem convite nenhum, a conta perde o módulo (403)')]
    public function testConviteRevogadoOuVencido(): void
    {
        [$client, $codigo, $master, $central] = $this->demandaEmExecucao();
        $this->despachar($client, $codigo, ['texto' => 'Confirme a audiência.']);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/despachos/aceitar-resposta", ['liberar' => true]);
        $token = $this->convidar($client, $codigo, 'conv@test.com');
        $convidado = $this->contaDoConvidado($client, $token, 'conv@test.com');
        $outra = $this->outraDemandaEmExecucao($client);
        $this->logarComTenant($client, $master, $central);
        $this->aceitarLogado($client, $convidado, $this->convidar($client, $outra, 'conv@test.com'));

        $responder = function () use ($client, $codigo): void {
            $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/despachos/respostas", ['texto' => 'Estarei lá']);
        };
        $this->logarComoConvidado($client, $convidado);
        $client->request('GET', "/trabalho-externo/demandas/$codigo/despachos/extrato");
        self::assertResponseIsSuccessful('com o convite vigente, lê o Extrato');

        // Revogado.
        $this->logarComTenant($client, $master, $central);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        $convite = $this->json($client)['demanda']['convites'][0];
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/convites/{$convite['id']}/revogar");
        self::assertResponseIsSuccessful();
        $this->logarComoConvidado($client, $convidado);
        $responder();
        self::assertResponseStatusCodeSame(404, 'revogado: responder');
        $client->request('GET', "/trabalho-externo/demandas/$codigo/despachos/extrato");
        self::assertResponseStatusCodeSame(404, 'revogado: Extrato');

        // Vencido: o da outra demanda vence ontem e o da primeira volta a valer, para provar o vencido nela.
        $this->em()->getConnection()->executeStatement('UPDATE trabalho_externo_convite SET revogado_em = NULL WHERE id = ?', [$convite['id']]);
        $this->em()->getConnection()->executeStatement('UPDATE trabalho_externo_convite SET validade = ? WHERE id = ?', [(new \DateTimeImmutable('yesterday'))->format('Y-m-d'), $convite['id']]);
        $this->em()->clear();
        $responder();
        self::assertResponseStatusCodeSame(404, 'vencido: responder');
        $client->request('GET', "/trabalho-externo/demandas/$codigo/despachos/extrato");
        self::assertResponseStatusCodeSame(404, 'vencido: Extrato');

        // Sem convite vigente nenhum: a conta deixa de ter o módulo, como no resto do Trabalho Externo.
        $this->em()->getConnection()->executeStatement('UPDATE trabalho_externo_convite SET validade = ? WHERE email = ?', [(new \DateTimeImmutable('yesterday'))->format('Y-m-d'), 'conv@test.com']);
        $this->em()->clear();
        $responder();
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', "/trabalho-externo/demandas/$codigo/despachos/extrato");
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Extrato na prévia do Master (?como=): o recorte de quem é pré-visualizado, com o nome pela regra do nomeVisivel')]
    public function testExtratoNaPrevia(): void
    {
        [$client, $codigo] = $this->demandaEmExecucao();
        $this->despachar($client, $codigo, ['texto' => 'Primeiro']);

        $ass = function (string $query) use ($client, $codigo): string {
            $crawler = $client->request('GET', "/trabalho-externo/demandas/$codigo/despachos/extrato$query");
            self::assertResponseIsSuccessful($query);

            return $crawler->filter('body > div > small')->first()->text();
        };
        self::assertMatchesRegularExpression('/^Assinado eletronicamente por Master \\S+ em /', $ass(''), 'o Master vê o nome de quem despachou');
        self::assertStringStartsWith('Assinado eletronicamente por Central BlueJus em ', $ass('?como=solicitante'), 'o solicitante vê "Central BlueJus"');
        self::assertMatchesRegularExpression('/^Assinado eletronicamente por Master \\S+ em /', $ass('?como=equipe'), 'a Equipe é interna');

        $client->request('GET', "/trabalho-externo/demandas/$codigo/despachos/extrato?como=convidado");
        self::assertResponseStatusCodeSame(404, 'sem convite aceito, a prévia do convidado não tem a demanda');

        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/public/js/trabalho-externo.js');
        self::assertStringContainsString('window.open(comComo(ROTA.despachoExtrato(dz.codigo)), \'_blank\')', $js, 'a tela passa o ?como= ao abrir o Extrato');
    }

    #[TestDox('o código da assinatura não se repete: com os 8 dígitos já em uso, o seguinte livre')]
    public function testCodigoUnico(): void
    {
        [$client, $codigo] = $this->demandaEmExecucao();
        $this->despachar($client, $codigo, ['texto' => 'Primeiro']);
        $usado = (int) $this->em()->getConnection()->fetchOne('SELECT codigo FROM trabalho_externo_despacho ORDER BY id DESC LIMIT 1');
        $repo = static::getContainer()->get(\App\TrabalhoExterno\Repository\DemandaExternaRepository::class);

        self::assertSame($usado + 1, $repo->codigoDeDespachoLivre($usado));
        self::assertSame($usado + 100_000_001, $repo->codigoDeDespachoLivre($usado + 100_000_000), 'os mesmos 8 dígitos de outro ciclo também colidem');
        self::assertSame($usado + 5, $repo->codigoDeDespachoLivre($usado + 5));

        $this->em()->getConnection()->executeStatement('UPDATE trabalho_externo_despacho SET codigo = codigo + 1 WHERE codigo = ?', [$usado]);
        self::assertSame($usado + 2, $repo->codigoDeDespachoLivre($usado + 1), 'anda até o primeiro livre');

        try {
            $this->em()->getConnection()->executeStatement('INSERT INTO trabalho_externo_despacho (codigo, nome_autor, perfil_autor, em, tipo, texto, cidade, demanda_id) SELECT codigo, nome_autor, perfil_autor, em, tipo, texto, cidade, demanda_id FROM trabalho_externo_despacho LIMIT 1');
            self::fail('o índice único barra o código repetido');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
        }
    }

    #[TestDox('Extrato sem despacho: "Nenhum despacho."')]
    public function testExtratoVazio(): void
    {
        [$client, $codigo] = $this->demandaEmExecucao();

        $crawler = $client->request('GET', "/trabalho-externo/demandas/$codigo/despachos/extrato");
        self::assertSame('Nenhum despacho.', $crawler->filter('body > p')->text());
    }

    /**
     * @param array<string, string> $campos
     * @param list<UploadedFile>    $arquivos
     *
     * @return array<string, mixed>
     */
    private function despachar(KernelBrowser $client, string $codigo, array $campos, array $arquivos = []): array
    {
        $client->request('POST', "/trabalho-externo/api/demandas/$codigo/despachos", $campos + ['natureza' => '', 'prazo' => ''], $arquivos === [] ? [] : ['documentos' => $arquivos], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax']);

        return $this->json($client);
    }

    private function arquivo(string $nome): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'te');
        file_put_contents($caminho, '%PDF-1.4 despacho');

        return new UploadedFile($caminho, $nome, 'application/pdf', null, true);
    }

    /** @return array{0: KernelBrowser, 1: string, 2: User, 3: Tenant, 4: User, 5: Tenant} já logado como o Master */
    private function demandaEmExecucao(): array
    {
        $client = static::createClient();
        $this->instalarCsrf($client);
        $escritorio = $this->criarTenant('Solicitante');
        $central = $this->criarTenant('Central');
        $solicitante = $this->criarUsuario($escritorio, nome: 'Solicitante Teste');
        $master = $this->criarMaster($central);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/analise");
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/propostas", ['valor' => '300']);
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/aceitar");
        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/iniciar");
        self::assertResponseIsSuccessful();

        return [$client, $codigo, $master, $central, $solicitante, $escritorio];
    }

    /** Convida pelo endpoint real e devolve o token tirado do link do e-mail. */
    private function convidar(KernelBrowser $client, string $codigo, string $email, ?array $permissoes = null): string
    {
        $corpo = ['nome' => 'Profissional Convidado', 'email' => $email, 'funcao' => 'Advogado correspondente'];
        if ($permissoes !== null) {
            $corpo['permissoes'] = $permissoes;
        }
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/convites", $corpo);
        self::assertResponseIsSuccessful();

        $html = '';
        foreach (static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getEvents() as $evento) {
            if (!$evento->isQueued() && $evento->getMessage() instanceof Email) {
                $html = (string) $evento->getMessage()->getHtmlBody();
            }
        }
        self::assertSame(1, preg_match('#/trabalho-externo/convite/([a-f0-9]{64})#', $html, $m));

        return $m[1];
    }

    private function contaDoConvidado(KernelBrowser $client, string $token, string $email): User
    {
        $client->getCookieJar()->clear();
        $crawler = $client->request('GET', "/trabalho-externo/convite/$token");
        $client->request('POST', "/trabalho-externo/convite/$token/criar-conta", ['full_name' => 'Convidado', 'senha' => 'senha-forte-1', 'genero' => 'masculino', 'aceite_termos' => '1', '_token' => $crawler->filter('form[action$="/criar-conta"] input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects();

        return $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    /** Uma segunda demanda em execução, de outro escritório, no mesmo cliente. */
    private function outraDemandaEmExecucao(KernelBrowser $client): string
    {
        $escritorio = $this->criarTenant('Solicitante 2');
        $central = $this->criarTenant('Central 2');
        $solicitante = $this->criarUsuario($escritorio);
        $master = $this->criarMaster($central);

        $this->logarComTenant($client, $solicitante, $escritorio);
        $codigo = $this->solicitar($client, self::solicitacaoValida())['codigo'];
        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/analise");
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/propostas", ['valor' => '300']);
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/aceitar");
        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/iniciar");

        return $codigo;
    }

    /** A conta que já existe aceita mais um convite pelo link. */
    private function aceitarLogado(KernelBrowser $client, User $usuario, string $token): void
    {
        $this->logarComoConvidado($client, $usuario);
        $crawler = $client->request('GET', "/trabalho-externo/convite/$token");
        $client->request('POST', "/trabalho-externo/convite/$token/aceitar", ['_token' => $crawler->filter('form[action$="/aceitar"] input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects();
    }

    private function logarComoConvidado(KernelBrowser $client, User $convidado): void
    {
        $client->getCookieJar()->clear();
        $client->loginUser($convidado);
        $this->marcarTermosAceitos($client);
    }
}
