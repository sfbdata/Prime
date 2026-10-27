<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\DemandaExternaController;
use App\TrabalhoExterno\Controller\TrabalhoExternoController;
use App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

/**
 * A aba Pagamento pela HTTP (desenho 06 de 25/10, l.620 a 637 e 1417 a 1427; spec trabalho-externo-25-10-26.md):
 * dinheiro. O valor sai da proposta aceita, o Solicitante envia o comprovante (arquivo de verdade), o interno confirma,
 * o Convidado não vê nada e o outro escritório não acha a demanda.
 */
#[CoversClass(DemandaExternaController::class)]
#[CoversClass(TrabalhoExternoController::class)]
final class AbaPagamentoTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    #[TestDox('Master vê a aba entre Chat e Histórico, com valor, forma, txid, QR em SVG e o código do desenho')]
    public function testMasterVeAAba(): void
    {
        [$client, $codigo] = $this->demandaAceita();

        $d = $this->detalhe($client, $codigo);
        self::assertSame(['resumo', 'proposta', 'exec', 'chat', 'pag', 'hist', 'whats'], $d['abas']);
        $pg = $d['pagamento'];
        self::assertSame(['R$ 1.500,00', 'Pix mensal', $codigo, 'aguarda', true], [$pg['valor'], $pg['forma'], $pg['txid'], $pg['estado'], $pg['mostraQr']]);
        self::assertSame('Aguardando pagamento', $pg['stTxt']);
        self::assertStringStartsWith('<svg ', $pg['qr']);
        self::assertSame($pg['cod'], (new \App\TrabalhoExterno\Service\PixDaDemanda())->gerar($codigo, '1500.00'));
        self::assertStringContainsString('540715' . '00.00', $pg['cod']);
        self::assertTrue($pg['podeConfirmar']);
        self::assertFalse($pg['podeComprovante'], 'o interno não envia comprovante');
        self::assertSame('Confirmar o recebimento de R$ 1.500,00 via PIX?', $pg['confirmacao']);
    }

    #[TestDox('o código copia-e-cola e o valor saem da proposta aceita: sem forma de pagamento, "À vista"')]
    public function testFormaPadrao(): void
    {
        [$client, $codigo] = $this->demandaAceita(['pagamento' => '']);

        self::assertSame('À vista', $this->detalhe($client, $codigo)['pagamento']['forma']);
    }

    #[TestDox('sem proposta aceita: sem QR, sem código, sem confirmação; o texto do desenho; os endpoints recusam')]
    public function testSemPropostaAceita(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        // A proposta deixa de estar aceita (a demanda segue em Aceita): não há valor a cobrar.
        $this->em()->getConnection()->executeStatement("UPDATE trabalho_externo_proposta SET situacao = 'enviada'");
        $this->em()->clear();

        $pg = $this->detalhe($client, $codigo)['pagamento'];
        self::assertTrue($pg['semValor']);
        self::assertFalse($pg['mostraQr']);
        self::assertSame(['', ''], [$pg['qr'], $pg['cod']]);
        self::assertFalse($pg['podeConfirmar']);
        self::assertFalse($pg['podeComprovante']);

        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");
        self::assertResponseStatusCodeSame(409);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/aviso");
        self::assertResponseStatusCodeSame(409);
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->comprovante($client, $codigo, $this->pdf('pix.pdf'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->contar('trabalho_externo_documento WHERE comprovante = true'));
    }

    #[TestDox('demanda ainda em análise (sem aceite): a aba existe, sem valor; a ação genérica "Registrar pagamento" continua sem aparecer')]
    public function testEmAnalise(): void
    {
        [$client, $codigo] = $this->demandaEmAnalise();

        $d = $this->detalhe($client, $codigo);
        self::assertContains('pag', $d['abas']);
        self::assertTrue($d['pagamento']['semValor']);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");
        self::assertResponseStatusCodeSame(409);
    }

    #[TestDox('Confirmar recebimento: pago, "PIX R$ x" no histórico, registro do desenho, toast, e o valor NUNCA vem do cliente')]
    public function testConfirmar(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();

        $r = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao", ['valor' => '1', 'txid' => 'FRAUDE']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Pagamento confirmado', 'O solicitante foi avisado no chat e por e-mail.'], [$r['toast']['titulo'], $r['toast']['texto']]);
        $d = $r['demanda'];
        self::assertSame('pago', $d['pagamento']['estado']);
        self::assertMatchesRegularExpression('/^Pagamento confirmado em \d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/', $d['pagamento']['stTxt']);
        self::assertSame(['', ''], [$d['pagamento']['qr'], $d['pagamento']['cod']], 'pago esconde QR e código');
        self::assertFalse($d['pagamento']['podeConfirmar']);
        $textos = array_column($d['mensagens'], 'txt');
        self::assertContains('✅ Pagamento de R$ 1.500,00 confirmado via PIX. A execução pode começar.', $textos);
        self::assertNotContains('Pagamento registrado.', $textos);
        $pago = array_values(array_filter($d['historico'], static fn (array $h): bool => $h['para'] === 'Pagamento registrado'));
        self::assertSame('PIX R$ 1.500,00', $pago[0]['motivo']);
        self::assertSame(1, $this->contar("trabalho_externo_historico WHERE para = 'pago'"));

        // Idempotente: a segunda chamada é 409 e não duplica marco, mensagem nem aviso.
        $avisos = $this->contar('notificacao');
        $mensagens = $this->contar("trabalho_externo_mensagem WHERE texto LIKE '%confirmado via PIX%'");
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->contar("trabalho_externo_historico WHERE para = 'pago'"));
        self::assertSame($mensagens, $this->contar("trabalho_externo_mensagem WHERE texto LIKE '%confirmado via PIX%'"));
        self::assertSame($avisos, $this->contar('notificacao'));
        self::assertSame(1, $mensagens);

        // O Solicitante vê o pago e o aviso no chat.
        $this->logarComTenant($client, $solicitante, $escritorio);
        $pg = $this->detalhe($client, $codigo)['pagamento'];
        self::assertSame(['pago', false, false], [$pg['estado'], $pg['mostraQr'], $pg['podeComprovante']]);
    }

    #[TestDox('Solicitante envia o comprovante (PDF de verdade): faixa âmbar, QR segue, fora de DOCUMENTOS, mensagem do desenho e download; o segundo é recusado')]
    public function testSolicitanteEnviaComprovante(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        $this->logarComTenant($client, $solicitante, $escritorio);

        $antes = $this->detalhe($client, $codigo)['pagamento'];
        self::assertTrue($antes['podeComprovante']);
        self::assertFalse($antes['podeConfirmar'], 'o Solicitante não confirma');
        self::assertTrue($antes['mostraQr'], 'o Solicitante vê o QR');

        $r = $this->comprovante($client, $codigo, $this->pdf('comprovante-pix.pdf'));
        self::assertResponseIsSuccessful();
        $d = $r['demanda'];
        self::assertSame('conf', $d['pagamento']['estado']);
        self::assertMatchesRegularExpression('/^Comprovante enviado em \d{2}\/\d{2}\/\d{4} \d{2}:\d{2} · aguardando confirmação do BlueJus$/u', $d['pagamento']['stTxt']);
        self::assertTrue($d['pagamento']['mostraQr']);
        self::assertFalse($d['pagamento']['podeComprovante'], 'um só por demanda');
        self::assertSame('comprovante-pix.pdf', $d['pagamento']['comprovante']['nome']);
        self::assertSame([], $d['documentos'], 'o comprovante fica fora de DOCUMENTOS');
        self::assertTrue($d['semDocumentos']);
        self::assertContains('💳 Comprovante de pagamento PIX enviado (comprovante-pix.pdf). Aguardando confirmação.', array_column($d['mensagens'], 'txt'));
        self::assertTrue($this->contar('trabalho_externo_documento WHERE comprovante = true') === 1);

        $id = $d['pagamento']['comprovante']['id'];
        $client->request('GET', "/trabalho-externo/demandas/$codigo/documentos/$id");
        self::assertResponseIsSuccessful('o Solicitante baixa o próprio comprovante');
        self::assertStringContainsString('%PDF-1.4 comprovante', $this->conteudoDaResposta($client));

        $this->comprovante($client, $codigo, $this->pdf('segundo.pdf'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->contar('trabalho_externo_documento WHERE comprovante = true'));

        // O interno vê o comprovante, baixa e confirma.
        $this->logarComTenant($client, $master, $central);
        $pg = $this->detalhe($client, $codigo)['pagamento'];
        self::assertSame(['conf', 'comprovante-pix.pdf', true], [$pg['estado'], $pg['comprovante']['nome'], $pg['podeConfirmar']]);
        $client->request('GET', "/trabalho-externo/demandas/$codigo/documentos/$id");
        self::assertResponseIsSuccessful();

        // Depois de pago, não se envia mais.
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");
        self::assertResponseIsSuccessful();
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->comprovante($client, $codigo, $this->pdf('tarde.pdf'));
        self::assertResponseStatusCodeSame(409);
    }

    #[TestDox('comprovante: aceita imagem; recusa texto, executável e SVG (422); sem arquivo, 422; nada é gravado nas recusas')]
    public function testTiposDeArquivo(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        $this->logarComTenant($client, $solicitante, $escritorio);

        foreach ([['nota.txt', 'texto simples', 'text/plain'], ['virus.exe', "MZ\x90\x00\x03", 'application/x-msdownload'], ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'], ['pagina.html', '<html><script>alert(1)</script></html>', 'text/html']] as [$nome, $conteudo, $mime]) {
            $caminho = tempnam(sys_get_temp_dir(), 'te');
            file_put_contents($caminho, $conteudo);
            $r = $this->comprovante($client, $codigo, new UploadedFile($caminho, $nome, $mime, null, true));
            self::assertResponseStatusCodeSame(422, $nome);
            self::assertSame('O comprovante precisa ser um PDF ou uma imagem.', $r['mensagem']);
        }
        $client->request('POST', "/trabalho-externo/api/demandas/$codigo/pagamento/comprovante", [], [], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->contar('trabalho_externo_documento WHERE comprovante = true'));

        $png = tempnam(sys_get_temp_dir(), 'te');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));
        $this->comprovante($client, $codigo, new UploadedFile($png, 'foto.png', 'image/png', null, true));
        self::assertResponseIsSuccessful('uma imagem vale');
    }

    #[TestDox('comprovante: o mime vem do CONTEÚDO, não do nome ("pix.pdf" que é HTML é recusado)')]
    public function testMimePeloConteudo(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        $this->logarComTenant($client, $solicitante, $escritorio);

        $caminho = tempnam(sys_get_temp_dir(), 'te');
        file_put_contents($caminho, '<html><script>alert(1)</script></html>');
        $this->comprovante($client, $codigo, new UploadedFile($caminho, 'pix.pdf', 'application/pdf', null, true));

        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Enviar no chat: só o interno; grava o aviso do desenho com o valor da proposta e o código da demanda; o Solicitante lê e o Convidado, não')]
    public function testEnviarNoChat(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/aviso");
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $master, $central);
        $r = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/aviso");
        self::assertResponseIsSuccessful();
        self::assertContains("💳 Pagamento disponível: R\$ 1.500,00 via PIX para BlueJus Gestão e Tecnologia LTDA (CNPJ 66.803.163/0001-10), identificação $codigo. Veja o QR Code na aba Pagamento.", array_column($r['demanda']['mensagens'], 'txt'));

        $this->logarComTenant($client, $solicitante, $escritorio);
        self::assertContains('· registro', array_column($this->detalhe($client, $codigo)['mensagens'], 'tipo'));
    }

    #[TestDox('a página do módulo leva as três rotas da aba para o JavaScript e nada do sistema no lugar do desenho (sem modal Bootstrap nem card da aba)')]
    public function testPaginaLevaAsRotas(): void
    {
        [$client] = $this->demandaAceita();

        $crawler = $client->request('GET', '/trabalho-externo');
        self::assertResponseIsSuccessful();
        $raiz = $crawler->filter('#tePagina');
        self::assertSame(1, $raiz->count());
        self::assertSame('/trabalho-externo/api/demandas/TE-0/pagamento/comprovante', $raiz->attr('data-url-pagamento-comprovante'));
        self::assertSame('/trabalho-externo/api/demandas/TE-0/pagamento/confirmacao', $raiz->attr('data-url-pagamento-confirmar'));
        self::assertSame('/trabalho-externo/api/demandas/TE-0/pagamento/aviso', $raiz->attr('data-url-pagamento-avisar'));
        self::assertSame('/trabalho-externo/api/demandas/TE-0/pagamento/valor', $raiz->attr('data-url-pagamento-valor'));
        self::assertSame(0, $crawler->filter('#tePagina .te-pg, #tePagina .modal, #tePagina .card')->count(), 'a aba nasce no JavaScript, com os componentes do desenho');
    }

    #[TestDox('Equipe: vê a aba; confirma só com "Alterar a situação" ligado (a guarda do botão Registrar pagamento); nunca envia comprovante')]
    public function testEquipe(): void
    {
        [$client, $codigo, $master, $central] = $this->demandaAceita();
        $this->definirSede($central);
        $equipe = $this->criarUsuario($central, nome: 'Colaborador');
        $this->logarComTenant($client, $equipe, $central);

        $d = $this->detalhe($client, $codigo);
        self::assertContains('pag', $d['abas']);
        self::assertFalse($d['pagamento']['podeConfirmar']);
        self::assertFalse($d['pagamento']['podeComprovante']);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");
        self::assertResponseStatusCodeSame(403, 'padrão: a Equipe não altera a situação');
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/aviso");
        self::assertResponseStatusCodeSame(403);
        $this->comprovante($client, $codigo, $this->pdf('x.pdf'));
        self::assertResponseStatusCodeSame(403);

        $repo = static::getContainer()->get(ConfiguracaoCentralRepository::class);
        $cfg = $repo->atual();
        $cfg->definirEquipe('situacao', true);
        $repo->salvar($cfg, flush: true);
        $this->logarComTenant($client, $equipe, $central);
        self::assertTrue($this->detalhe($client, $codigo)['pagamento']['podeConfirmar']);

        // "Enviar no chat" também exige o chat: com "Alterar a situação" mas sem "Responder no chat", 403.
        $cfg = $repo->atual();
        $cfg->definirEquipe('chat', false);
        $repo->salvar($cfg, flush: true);
        $this->logarComTenant($client, $equipe, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/aviso");
        self::assertResponseStatusCodeSame(403, 'sem chat, a Equipe não escreve o aviso');
        self::assertSame(0, $this->contar("trabalho_externo_mensagem WHERE texto LIKE '%Pagamento disponível%'"));
        $cfg = $repo->atual();
        $cfg->definirEquipe('chat', true);
        $repo->salvar($cfg, flush: true);
        $this->logarComTenant($client, $equipe, $central);
        $r = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");
        self::assertResponseIsSuccessful();
        self::assertSame('pago', $r['demanda']['pagamento']['estado']);
    }

    #[TestDox('corrida do comprovante: o índice único parcial barra o segundo, a transação desfaz e o arquivo do perdedor sai do storage')]
    public function testCorridaDoComprovante(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        $id = (int) substr($codigo, 3);
        $demanda = $this->em()->getRepository(\App\TrabalhoExterno\Entity\DemandaExterna::class)->find($id);
        self::assertCount(0, $demanda->getDocumentos(), 'a coleção velha, sem comprovante, é o estado que o perdedor enxerga');

        // O vencedor gravou o dele por outra conexão de fato: a linha entra no banco sem passar por esta entidade.
        $this->em()->getConnection()->executeStatement("INSERT INTO trabalho_externo_documento (demanda_id, nome_original, nome_armazenado, tamanho, mime_type, nome_autor, perfil_autor, em, comprovante) VALUES (?, 'vencedor.pdf', 'vencedor.pdf', 10, 'application/pdf', 'X', 'solicitante', now(), true)", [$id]);

        $armazenador = static::getContainer()->get(\App\TrabalhoExterno\Service\ArmazenadorDeDocumentos::class);
        $transacao = static::getContainer()->get(\App\TrabalhoExterno\Service\TransacaoDoComprovante::class);
        $armazenamento = static::getContainer()->get(\App\Shared\Armazenamento\ArmazenamentoDeArquivos::class);
        $repo = static::getContainer()->get(\App\TrabalhoExterno\Repository\DemandaExternaRepository::class);
        $gravados = new \ArrayObject();
        $autor = \App\TrabalhoExterno\DTO\Autor::de($this->em()->find(User::class, $solicitante->getId()), \App\TrabalhoExterno\Enum\Perfil::Solicitante);

        try {
            $transacao->executar(function () use ($armazenador, $demanda, $autor, $gravados, $repo): void {
                $demanda->enviarComprovante($armazenador->gravarComprovante($demanda, $this->pdf('perdedor.pdf'), $autor, $gravados), $autor);
                $repo->salvar($demanda);
            }, static fn (): array => array_values($gravados->getArrayCopy()));
            self::fail('o índice único barra o segundo comprovante');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
        }

        self::assertCount(1, $gravados, 'o perdedor chegou a gravar o arquivo');
        self::assertFalse($armazenamento->existe($gravados[0]), 'e ele foi removido, porque o COMMIT não aconteceu');
    }

    #[TestDox('Convidado: sem a aba, sem o campo `pagamento`, sem o valor no chat nem no histórico; as três rotas e o download do comprovante negam')]
    public function testConvidadoNaoVeNada(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        $this->logarComTenant($client, $solicitante, $escritorio);
        $idComprovante = $this->comprovante($client, $codigo, $this->pdf('comprovante.pdf'))['demanda']['pagamento']['comprovante']['id'];
        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/aviso");
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/iniciar");
        $token = $this->convidar($client, $codigo, 'conv@test.com');
        $convidado = $this->contaDoConvidado($client, $token, 'conv@test.com');

        $this->logarComoConvidado($client, $convidado);
        $d = $this->detalhe($client, $codigo);
        self::assertSame(['resumo', 'exec', 'chat', 'hist'], $d['abas']);
        self::assertArrayNotHasKey('pagamento', $d);
        $json = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('1.500,00', $json, 'o valor não vaza em nenhum campo');
        self::assertStringNotContainsString('1500.00', $json);
        self::assertStringNotContainsString('br.gov.bcb.pix', $json);
        self::assertStringNotContainsString('comprovante.pdf', $json);
        self::assertStringNotContainsString('Comprovante de pagamento', $json);
        foreach ($d['historico'] as $h) {
            self::assertStringNotContainsString('PIX', $h['motivo']);
        }

        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/aviso");
        self::assertResponseStatusCodeSame(403);
        $this->comprovante($client, $codigo, $this->pdf('y.pdf'));
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', "/trabalho-externo/demandas/$codigo/documentos/$idComprovante");
        self::assertResponseStatusCodeSame(404, 'o Convidado nunca baixa o comprovante, mesmo com "baixar"');
    }

    #[TestDox('outro escritório: 404 nas três rotas e no download do comprovante; o Master de outra conta de Solicitante também não acha')]
    public function testOutroEscritorio(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        $this->logarComTenant($client, $solicitante, $escritorio);
        $id = $this->comprovante($client, $codigo, $this->pdf('comprovante.pdf'))['demanda']['pagamento']['comprovante']['id'];

        $outro = $this->criarTenant('Outro');
        $this->logarComTenant($client, $this->criarUsuario($outro), $outro);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertResponseStatusCodeSame(404);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");
        self::assertResponseStatusCodeSame(404);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/aviso");
        self::assertResponseStatusCodeSame(404);
        $this->comprovante($client, $codigo, $this->pdf('z.pdf'));
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', "/trabalho-externo/demandas/$codigo/documentos/$id");
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->contar('trabalho_externo_documento WHERE comprovante = true'));
        self::assertSame(0, $this->contar("trabalho_externo_historico WHERE para = 'pago'"));
    }

    #[TestDox('prévia do Master (?como=): o recorte de quem é pré-visualizado, sem nenhum controle de escrita; nada grava')]
    public function testPreviaNaoGrava(): void
    {
        [$client, $codigo] = $this->demandaAceita();

        foreach (['solicitante', 'equipe'] as $como) {
            $client->request('GET', "/trabalho-externo/api/demandas/$codigo?como=$como");
            self::assertResponseIsSuccessful($como);
            $pg = $this->json($client)['demanda']['pagamento'];
            self::assertSame('R$ 1.500,00', $pg['valor'], $como);
            self::assertFalse($pg['podeComprovante'], "$como: sem escrita na prévia");
            self::assertFalse($pg['podeConfirmar'], "$como: sem escrita na prévia");
        }
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo?como=convidado");
        self::assertResponseStatusCodeSame(404, 'sem convite aceito, a prévia do convidado não tem a demanda');

        self::assertSame(0, $this->contar("trabalho_externo_historico WHERE para = 'pago'"));
        self::assertSame(0, $this->contar('trabalho_externo_documento WHERE comprovante = true'));
    }

    #[TestDox('CSRF: as cinco escritas sem o token são 403 e nada muda')]
    public function testCsrf(): void
    {
        [$client, $codigo] = $this->demandaAceita();

        foreach (['confirmacao', 'aviso', 'comprovante', 'valor', 'recusa'] as $rota) {
            $client->request('POST', "/trabalho-externo/api/demandas/$codigo/pagamento/$rota", [], [], ['HTTP_X_CSRF_TOKEN' => 'errado']);
            self::assertResponseStatusCodeSame(403, $rota);
        }
        self::assertSame(0, $this->contar("trabalho_externo_historico WHERE para = 'pago'"));
    }

    #[TestDox('o aviso de andamento do recebimento chega ao Solicitante (sino), com o texto do desenho')]
    public function testSolicitanteRecebeOAviso(): void
    {
        [$client, $codigo, $master, $central, $solicitante] = $this->demandaAceita();
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");

        $avisos = $this->em()->getConnection()->fetchFirstColumn('SELECT mensagem FROM notificacao WHERE usuario_id = ?', [$solicitante->getId()]);
        self::assertContains('✅ Pagamento de R$ 1.500,00 confirmado via PIX. A execução pode começar.', $avisos);
    }

    #[TestDox('Master ajusta o valor: QR, txid, código, confirmação, chat e histórico usam o novo; marco e mensagem sem motivo; o Solicitante é avisado')]
    public function testMasterAjustaOValor(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        self::assertTrue($this->detalhe($client, $codigo)['pagamento']['podeEditarValor']);

        $r = $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/valor", ['valor' => '1.800,50', 'motivo' => 'Acordo de desconto com o cliente']);
        self::assertResponseIsSuccessful();
        self::assertSame('Valor alterado', $r['toast']['titulo']);
        $pg = $r['demanda']['pagamento'];
        self::assertSame(['R$ 1.800,50', true, '1800.50'], [$pg['valor'], $pg['valorAjustado'], $pg['valorNumero']]);
        self::assertSame((new \App\TrabalhoExterno\Service\PixDaDemanda())->gerar($codigo, '1800.50'), $pg['cod']);
        self::assertStringContainsString('540718' . '00.50', $pg['cod']);
        self::assertSame('Confirmar o recebimento de R$ 1.800,50 via PIX?', $pg['confirmacao']);
        self::assertSame($codigo, $pg['txid']);

        $textos = array_column($r['demanda']['mensagens'], 'txt');
        self::assertContains('Valor do pagamento alterado de R$ 1.500,00 para R$ 1.800,50.', $textos);
        foreach ($textos as $t) {
            self::assertStringNotContainsString('Acordo de desconto', $t, 'o motivo não vai ao chat');
        }
        $marco = array_values(array_filter($r['demanda']['historico'], static fn (array $h): bool => $h['para'] === 'Valor do pagamento alterado'));
        self::assertCount(1, $marco);
        self::assertSame('De R$ 1.500,00 para R$ 1.800,50. Motivo: Acordo de desconto com o cliente', $marco[0]['motivo']);
        self::assertSame('1800.50', $this->em()->getConnection()->fetchOne('SELECT valor_pagamento_ajustado FROM trabalho_externo_demanda'));
        self::assertSame('1500.00', $this->em()->getConnection()->fetchOne('SELECT valor FROM trabalho_externo_proposta'), 'a proposta aceita não muda');

        $avisos = $this->em()->getConnection()->fetchFirstColumn('SELECT mensagem FROM notificacao WHERE usuario_id = ?', [$solicitante->getId()]);
        self::assertContains('Valor do pagamento alterado de R$ 1.500,00 para R$ 1.800,50.', $avisos);

        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/aviso");
        self::assertContains("💳 Pagamento disponível: R\$ 1.800,50 via PIX para BlueJus Gestão e Tecnologia LTDA (CNPJ 66.803.163/0001-10), identificação $codigo. Veja o QR Code na aba Pagamento.", array_column($this->detalhe($client, $codigo)['mensagens'], 'txt'));

        // Igual ao efetivo: 409 sem gravar. Depois, a confirmação grava o valor novo.
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/valor", ['valor' => '1800.50', 'motivo' => 'Acordo de desconto com o cliente']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->contar("trabalho_externo_historico WHERE para = 'valor'"));
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");
        $pago = array_values(array_filter($this->detalhe($client, $codigo)['historico'], static fn (array $h): bool => $h['para'] === 'Pagamento registrado'));
        self::assertSame('PIX R$ 1.800,50', $pago[0]['motivo']);

        // Pago: nem o lápis nem a rota.
        self::assertFalse($this->detalhe($client, $codigo)['pagamento']['podeEditarValor']);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/valor", ['valor' => '10,00', 'motivo' => 'Motivo suficiente']);
        self::assertResponseStatusCodeSame(409);
    }

    #[TestDox('valor inválido ou motivo curto: 422; Solicitante e Equipe: 403; comprovante enviado: 409 e o lápis some; CSRF errado: 403')]
    public function testAjusteDeValorRecusas(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        $url = "/trabalho-externo/api/demandas/$codigo/pagamento/valor";

        $this->postarJson($client, $url, ['valor' => 'abc', 'motivo' => 'Motivo suficiente']);
        self::assertResponseStatusCodeSame(422);
        $this->postarJson($client, $url, ['valor' => '0,00', 'motivo' => 'Motivo suficiente']);
        self::assertResponseStatusCodeSame(422);
        $this->postarJson($client, $url, ['valor' => '10,00', 'motivo' => 'curto']);
        self::assertResponseStatusCodeSame(422);
        $this->postarJson($client, $url, ['valor' => '10,00', 'motivo' => 'Motivo suficiente'], 'errado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contar("trabalho_externo_historico WHERE para = 'valor'"));

        $this->logarComTenant($client, $solicitante, $escritorio);
        self::assertFalse($this->detalhe($client, $codigo)['pagamento']['podeEditarValor']);
        $this->postarJson($client, $url, ['valor' => '10,00', 'motivo' => 'Motivo suficiente']);
        self::assertResponseStatusCodeSame(403);
        $this->comprovante($client, $codigo, $this->pdf('pix.pdf'));
        self::assertResponseIsSuccessful();

        $this->logarComTenant($client, $master, $central);
        self::assertFalse($this->detalhe($client, $codigo)['pagamento']['podeEditarValor'], 'com comprovante, sem lápis');
        $this->postarJson($client, $url, ['valor' => '10,00', 'motivo' => 'Motivo suficiente']);
        self::assertResponseStatusCodeSame(409);
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT valor_pagamento_ajustado FROM trabalho_externo_demanda'));
    }

    #[TestDox('Equipe com "Alterar a situação" não ajusta (403); Convidado e outro escritório: 403 e 404; a prévia não traz o lápis')]
    public function testAjusteDeValorEquipeConvidadoOutroEscritorioPrevia(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        $url = "/trabalho-externo/api/demandas/$codigo/pagamento/valor";
        $corpo = ['valor' => '10,00', 'motivo' => 'Motivo suficiente'];

        $this->definirSede($central);
        $equipe = $this->criarUsuario($central, nome: 'Colaborador');
        $repo = static::getContainer()->get(ConfiguracaoCentralRepository::class);
        $cfg = $repo->atual();
        $cfg->definirEquipe('situacao', true);
        $repo->salvar($cfg, flush: true);
        $this->logarComTenant($client, $equipe, $central);
        self::assertFalse($this->detalhe($client, $codigo)['pagamento']['podeEditarValor']);
        $this->postarJson($client, $url, $corpo);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $master, $central);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo?como=solicitante");
        self::assertFalse($this->json($client)['demanda']['pagamento']['podeEditarValor']);

        $token = $this->convidar($client, $codigo, 'conv@test.com');
        $convidado = $this->contaDoConvidado($client, $token, 'conv@test.com');
        $this->logarComoConvidado($client, $convidado);
        $this->postarJson($client, $url, $corpo);
        self::assertResponseStatusCodeSame(403);

        $outro = $this->criarTenant('Outro');
        $this->logarComTenant($client, $this->criarUsuario($outro), $outro);
        $this->postarJson($client, $url, $corpo);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->contar("trabalho_externo_historico WHERE para = 'valor'"));
    }

    #[TestDox('o Convidado não vê o marco do ajuste nem o valor novo no histórico, no chat ou no JSON')]
    public function testConvidadoNaoVeOAjuste(): void
    {
        [$client, $codigo, $master, $central] = $this->demandaAceita();
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/valor", ['valor' => '1.777,77', 'motivo' => 'Desconto especial']);
        self::assertResponseIsSuccessful();
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/iniciar");
        $token = $this->convidar($client, $codigo, 'conv@test.com');
        $convidado = $this->contaDoConvidado($client, $token, 'conv@test.com');
        $this->logarComoConvidado($client, $convidado);

        $this->detalhe($client, $codigo);
        $json = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('1.777,77', $json);
        self::assertStringNotContainsString('Desconto especial', $json);
        self::assertStringNotContainsString('Valor do pagamento alterado', $json);
    }

    #[TestDox('Master recusa o comprovante: marco, chat sem motivo, aviso com motivo, documento e arquivo saem, lápis volta e o Solicitante reenvia')]
    public function testMasterRecusaOComprovante(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        $url = "/trabalho-externo/api/demandas/$codigo/pagamento/recusa";
        $this->logarComTenant($client, $solicitante, $escritorio);
        $enviado = $this->comprovante($client, $codigo, $this->pdf('pix-errado.pdf'));
        $nomeArmazenado = (string) $this->em()->getConnection()->fetchOne('SELECT nome_armazenado FROM trabalho_externo_documento WHERE comprovante = true');
        $armazenamento = static::getContainer()->get(\App\Shared\Armazenamento\ArmazenamentoDeArquivos::class);
        $chave = \App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno::documento($this->em()->getRepository(\App\TrabalhoExterno\Entity\DocumentoDemanda::class)->findOneBy(['nomeArmazenado' => $nomeArmazenado]));
        self::assertTrue($armazenamento->existe($chave));

        $this->logarComTenant($client, $master, $central);
        $pg = $this->detalhe($client, $codigo)['pagamento'];
        self::assertSame([true, false], [$pg['podeRecusar'], $pg['podeEditarValor']]);

        $this->postarJson($client, $url, ['motivo' => 'curto']);
        self::assertResponseStatusCodeSame(422);
        $r = $this->postarJson($client, $url, ['motivo' => 'Valor diferente do combinado']);
        self::assertResponseIsSuccessful();
        self::assertSame('Comprovante recusado', $r['toast']['titulo']);
        $pg = $r['demanda']['pagamento'];
        self::assertSame(['aguarda', null, false, true], [$pg['estado'], $pg['comprovante'], $pg['podeRecusar'], $pg['podeEditarValor']]);
        self::assertSame('Aguardando pagamento', $pg['stTxt']);
        self::assertSame(0, $this->contar('trabalho_externo_documento WHERE comprovante = true'));
        self::assertFalse($armazenamento->existe($chave), 'o arquivo saiu depois do COMMIT');

        $textos = array_column($r['demanda']['mensagens'], 'txt');
        self::assertContains('Comprovante de pagamento recusado. Envie um novo comprovante na aba Pagamento.', $textos);
        foreach ($textos as $t) {
            self::assertStringNotContainsString('Valor diferente', $t, 'o motivo não vai ao chat');
        }
        $marco = array_values(array_filter($r['demanda']['historico'], static fn (array $h): bool => $h['para'] === 'Comprovante recusado'));
        self::assertCount(1, $marco);
        self::assertSame('Arquivo: pix-errado.pdf. Motivo: Valor diferente do combinado', $marco[0]['motivo']);
        $avisos = $this->em()->getConnection()->fetchFirstColumn('SELECT mensagem FROM notificacao WHERE usuario_id = ?', [$solicitante->getId()]);
        self::assertContains('Comprovante de pagamento recusado. Motivo: Valor diferente do combinado. Envie um novo comprovante na aba Pagamento.', $avisos);

        // Sem comprovante: 409. O valor volta a ser editável.
        $this->postarJson($client, $url, ['motivo' => 'Valor diferente do combinado']);
        self::assertResponseStatusCodeSame(409);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/valor", ['valor' => '1.400,00', 'motivo' => 'Ajuste combinado']);
        self::assertResponseIsSuccessful();

        // O Solicitante reenvia (o índice único parcial já está livre).
        $this->logarComTenant($client, $solicitante, $escritorio);
        self::assertTrue($this->detalhe($client, $codigo)['pagamento']['podeComprovante']);
        $novo = $this->comprovante($client, $codigo, $this->pdf('pix-certo.pdf'));
        self::assertResponseIsSuccessful();
        self::assertSame('pix-certo.pdf', $novo['demanda']['pagamento']['comprovante']['nome']);
        self::assertSame(1, $this->contar('trabalho_externo_documento WHERE comprovante = true'));
    }

    #[TestDox('recusa do comprovante: Solicitante e Equipe 403, Convidado 403 sem ver o marco, outro escritório 404, pago 409, prévia sem o botão')]
    public function testRecusaDoComprovanteQuemNaoPode(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        $url = "/trabalho-externo/api/demandas/$codigo/pagamento/recusa";
        $corpo = ['motivo' => 'Motivo suficiente'];
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->comprovante($client, $codigo, $this->pdf('pix.pdf'));
        self::assertFalse($this->detalhe($client, $codigo)['pagamento']['podeRecusar']);
        $this->postarJson($client, $url, $corpo);
        self::assertResponseStatusCodeSame(403);

        $this->definirSede($central);
        $equipe = $this->criarUsuario($central, nome: 'Colaborador');
        $repo = static::getContainer()->get(ConfiguracaoCentralRepository::class);
        $cfg = $repo->atual();
        $cfg->definirEquipe('situacao', true);
        $repo->salvar($cfg, flush: true);
        $this->logarComTenant($client, $equipe, $central);
        self::assertFalse($this->detalhe($client, $codigo)['pagamento']['podeRecusar']);
        $this->postarJson($client, $url, $corpo);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $master, $central);
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo?como=solicitante");
        self::assertFalse($this->json($client)['demanda']['pagamento']['podeRecusar']);

        $token = $this->convidar($client, $codigo, 'conv@test.com');
        $convidado = $this->contaDoConvidado($client, $token, 'conv@test.com');
        $this->logarComoConvidado($client, $convidado);
        $this->postarJson($client, $url, $corpo);
        self::assertResponseStatusCodeSame(403);

        $outro = $this->criarTenant('Outro');
        $this->logarComTenant($client, $this->criarUsuario($outro), $outro);
        $this->postarJson($client, $url, $corpo);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->contar('trabalho_externo_documento WHERE comprovante = true'));
        self::assertSame(0, $this->contar("trabalho_externo_historico WHERE para = 'comprovante_recusado'"));

        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/confirmacao");
        $this->postarJson($client, $url, $corpo);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->contar('trabalho_externo_documento WHERE comprovante = true'));
    }

    #[TestDox('o Convidado não vê o marco da recusa do comprovante nem o motivo')]
    public function testConvidadoNaoVeARecusa(): void
    {
        [$client, $codigo, $master, $central, $solicitante, $escritorio] = $this->demandaAceita();
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/iniciar");
        $this->logarComTenant($client, $solicitante, $escritorio);
        $this->comprovante($client, $codigo, $this->pdf('pix.pdf'));
        $this->logarComTenant($client, $master, $central);
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/pagamento/recusa", ['motivo' => 'Comprovante ilegível']);
        self::assertResponseIsSuccessful();
        $token = $this->convidar($client, $codigo, 'conv@test.com');
        $convidado = $this->contaDoConvidado($client, $token, 'conv@test.com');
        $this->logarComoConvidado($client, $convidado);

        $this->detalhe($client, $codigo);
        $json = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Comprovante recusado', $json);
        self::assertStringNotContainsString('Comprovante ilegível', $json);
        self::assertStringNotContainsString('Comprovante de pagamento recusado', $json);
    }

    /**
     * @param array<string, string> $proposta
     *
     * @return array{0: KernelBrowser, 1: string, 2: User, 3: Tenant, 4: User, 5: Tenant} já logado como o Master
     */
    private function demandaAceita(array $proposta = []): array
    {
        $r = $this->demandaEmAnalise($proposta + ['valor' => '1.500,00', 'pagamento' => 'Pix mensal'], aceitar: true);

        return $r;
    }

    /** @return array{0: KernelBrowser, 1: string, 2: User, 3: Tenant, 4: User, 5: Tenant} */
    private function demandaEmAnalise(array $proposta = [], bool $aceitar = false): array
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
        if ($aceitar) {
            $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/propostas", $proposta);
            $this->logarComTenant($client, $solicitante, $escritorio);
            $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/acoes/aceitar");
            self::assertResponseIsSuccessful();
            $this->logarComTenant($client, $master, $central);
        }

        return [$client, $codigo, $master, $central, $solicitante, $escritorio];
    }

    /** @return array<string, mixed> */
    private function detalhe(KernelBrowser $client, string $codigo): array
    {
        $client->request('GET', "/trabalho-externo/api/demandas/$codigo");
        self::assertResponseIsSuccessful();

        return $this->json($client)['demanda'];
    }

    /** @return array<string, mixed> */
    private function comprovante(KernelBrowser $client, string $codigo, UploadedFile $arquivo): array
    {
        $client->request('POST', "/trabalho-externo/api/demandas/$codigo/pagamento/comprovante", [], ['comprovante' => $arquivo], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax']);

        return $this->json($client);
    }

    private function pdf(string $nome): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'te');
        file_put_contents($caminho, '%PDF-1.4 comprovante');

        return new UploadedFile($caminho, $nome, 'application/pdf', null, true);
    }

    private function contar(string $tabelaEWhere): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabelaEWhere);
    }

    private function conteudoDaResposta(KernelBrowser $client): string
    {
        $resposta = $client->getResponse();

        return $resposta instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            ? (string) file_get_contents($resposta->getFile()->getPathname())
            : (string) $resposta->getContent();
    }

    /** Convida pelo endpoint real e devolve o token tirado do link do e-mail. */
    private function convidar(KernelBrowser $client, string $codigo, string $email): string
    {
        $this->postarJson($client, "/trabalho-externo/api/demandas/$codigo/convites", ['nome' => 'Profissional Convidado', 'email' => $email, 'funcao' => 'Advogado correspondente', 'permissoes' => ['ver' => true, 'baixar' => true, 'enviar' => true, 'chat' => true, 'andamento' => true, 'concluir' => true]]);
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

    private function logarComoConvidado(KernelBrowser $client, User $convidado): void
    {
        $client->getCookieJar()->clear();
        $client->loginUser($convidado);
        $this->marcarTermosAceitos($client);
    }
}
