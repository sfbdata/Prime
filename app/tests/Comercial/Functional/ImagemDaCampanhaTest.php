<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Armazenamento\ChavesDeComercial;
use App\Comercial\Campanha\ChaveDasCampanhas;
use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\ExpansorDeCampanhas;
use App\Comercial\Campanha\ImagensDaCampanha;
use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\Campanha\PreparadorDeImagemDaCampanha;
use App\Comercial\Controller\CampanhaDoMasterController;
use App\Comercial\Controller\ImagemDaCampanhaController;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Repository\DestinoDaCampanhaRepository;
use App\Comercial\Repository\ImagemDaCampanhaRepository;
use App\Comercial\UseCase\ConfirmarCampanhaUseCase;
use App\Comercial\UseCase\EnviarImagemDaCampanhaUseCase;
use App\Comercial\UseCase\PurgarConfirmacoesVencidasUseCase;
use App\Comercial\UseCase\PurgarDadosDoComercialUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tests\Comercial\Support\CriaCampanhas;
use App\Tests\Comercial\Support\FabricaDeImagens;
use App\Tests\Comercial\Support\LogEmMemoria;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A imagem da campanha do modo simples (spec comercial-e-emails.md §12, F3i; dc 05 VS 21.10.26 L274, L596, L659):
 *   - o envio (só o Master, com o CSRF da seção): JPG e PNG aceitos, o tipo pelo conteúdo, 1 MB, 4000 px, sem EXIF;
 *   - o endereço público `/e/i/{token}.{ext}`: anônimo, com os cabeçalhos; 404 para o token inexistente, vencido ou
 *     com a extensão de outro tipo;
 *   - a mensagem com token desconhecido ou vencido é recusada na prévia, no teste, no salvar e na confirmação;
 *   - a validade: 1 ano a cada envio (a confirmação e cada execução da recorrente);
 *   - a purga: as vencidas saem do banco e só depois do storage; dry-run só conta; falha do storage é só log.
 */
#[CoversClass(EnviarImagemDaCampanhaUseCase::class)]
#[CoversClass(CampanhaDoMasterController::class)]
#[CoversClass(ImagemDaCampanhaController::class)]
#[CoversClass(ImagemDaCampanhaRepository::class)]
#[CoversClass(ImagensDaCampanha::class)]
#[CoversClass(ChavesDeComercial::class)]
#[CoversClass(ConfirmarCampanhaUseCase::class)]
#[CoversClass(ExpansorDeCampanhas::class)]
#[CoversClass(PurgarDadosDoComercialUseCase::class)]
final class ImagemDaCampanhaTest extends JusPrimeWebTestCase
{
    use CriaCampanhas;

    private const ENVIO = '/master/comercial/imagens';

    private User $master;

    #[TestDox('JPG e PNG aceitos: {token, url} com a URL absoluta; a linha guarda o tipo, o tamanho, o sha256, as dimensões, quem enviou e 30 dias de validade')]
    public function testAceitaJpgEPng(): void
    {
        $client = $this->clienteMaster();
        $antes = new \DateTimeImmutable();

        foreach (['jpg' => FabricaDeImagens::jpeg(60, 40), 'png' => FabricaDeImagens::png(20, 30)] as $ext => $bytes) {
            $this->enviar($client, $bytes, 'foto.' . $ext);
            self::assertResponseIsSuccessful();
            $saida = $this->json($client);
            self::assertMatchesRegularExpression('{^[a-f0-9]{32}$}', $saida['token']);
            self::assertSame('http://localhost/e/i/' . $saida['token'] . '.' . $ext, $saida['url']);

            $linha = $this->conexao()->fetchAssociative('SELECT * FROM comercial_imagem WHERE token = ?', [$saida['token']]);
            self::assertIsArray($linha);
            self::assertSame([$ext === 'jpg' ? 'image/jpeg' : 'image/png', $this->master->getId(), null], [$linha['mime'], $linha['enviada_por_id'], $linha['ultimo_uso_em']]);
            self::assertSame($ext === 'jpg' ? [60, 40] : [20, 30], [$linha['largura'], $linha['altura']]);
            $gravado = $this->armazenamento()->ler(ChavesDeComercial::imagemPorNome((string) $linha['nome_arquivo']));
            self::assertSame([\strlen($gravado), hash('sha256', $gravado)], [$linha['tamanho_bytes'], $linha['sha256']]);
            self::assertStringEndsWith('.' . $ext, (string) $linha['nome_arquivo']);
            self::assertStringNotContainsString('foto', (string) $linha['nome_arquivo'], 'o nome é cunhado pelo storage');
            $expira = new \DateTimeImmutable((string) $linha['expira_em']);
            self::assertEqualsWithDelta($antes->modify('+30 days')->getTimestamp(), $expira->getTimestamp(), 120);
        }
    }

    #[TestDox('O EXIF (com a localização) não chega ao arquivo gravado: o servidor regrava')]
    public function testExifSome(): void
    {
        $client = $this->clienteMaster();
        $original = FabricaDeImagens::jpegComExif(50, 50, 'GPS-LOCAL-DA-CASA');
        self::assertStringContainsString('GPS-LOCAL-DA-CASA', $original);

        $this->enviar($client, $original, 'foto.jpg');

        self::assertResponseIsSuccessful();
        $gravado = $this->armazenamento()->ler(ChavesDeComercial::imagemPorNome($this->nomeDoArquivo($this->json($client)['token'])));
        self::assertStringNotContainsString('GPS-LOCAL-DA-CASA', $gravado);
        self::assertStringNotContainsString('Exif', $gravado);
    }

    #[TestDox('Recusa em 422: GIF, texto com nome de .jpg, acima de 1 MB, acima de 4000 px e sem arquivo; nada gravado')]
    public function testRecusas(): void
    {
        $client = $this->clienteMaster();
        $casos = [
            [FabricaDeImagens::gif(10, 10), 'animada.gif', PreparadorDeImagemDaCampanha::TIPO_RECUSADO],
            ['só um texto com nome de foto', 'foto.jpg', PreparadorDeImagemDaCampanha::TIPO_RECUSADO],
            [FabricaDeImagens::jpeg(10, 10) . str_repeat("\0", PreparadorDeImagemDaCampanha::LIMITE_EM_BYTES), 'grande.jpg', PreparadorDeImagemDaCampanha::GRANDE_DEMAIS],
            [FabricaDeImagens::png(4001, 3), 'larga.png', PreparadorDeImagemDaCampanha::DIMENSAO_RECUSADA],
        ];
        foreach ($casos as [$bytes, $nome, $erro]) {
            $this->enviar($client, $bytes, $nome);
            self::assertResponseStatusCodeSame(422, $nome);
            self::assertSame($erro, $this->json($client)['erro'], $nome);
        }
        $client->request('POST', self::ENVIO, [], [], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(CampanhaDoMasterController::SEM_IMAGEM, $this->json($client)['erro']);

        self::assertSame(0, $this->contar());
    }

    #[TestDox('Sem o CSRF: 403 e nada gravado')]
    public function testSemCsrf(): void
    {
        $client = $this->clienteMaster();
        $this->enviar($client, FabricaDeImagens::jpeg(10, 10), 'foto.jpg', csrf: 'errado');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contar());
    }

    #[TestDox('Quem não é Master: 403 e nada gravado')]
    public function testSoMaster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->trocarCsrf();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Imagem ' . uniqid());
        $em->persist($tenant);
        $usuario = $this->usuario('naomaster');
        $em->persist(new UserTenant($usuario, $tenant));
        $em->flush();
        $this->logarComTenant($client, $usuario, $tenant);

        $this->enviar($client, FabricaDeImagens::jpeg(10, 10), 'foto.jpg');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contar());
    }

    #[TestDox('Endereço público, anônimo: 200 com o tipo gravado, nosniff, cache de um dia, noindex e sem sessão')]
    public function testEnderecoPublico(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->imagemGravada(FabricaDeImagens::png(12, 12));

        $client->request('GET', '/e/i/' . $token . '.png');

        self::assertResponseIsSuccessful();
        $resposta = $client->getResponse();
        self::assertSame('image/png', $resposta->headers->get('Content-Type'));
        self::assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'));
        self::assertSame('noindex', $resposta->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('public', (string) $resposta->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=86400', (string) $resposta->headers->get('Cache-Control'));
        self::assertStringStartsWith('inline', (string) $resposta->headers->get('Content-Disposition'));
        self::assertSame([], $resposta->headers->getCookies(), 'a rota pública não abre sessão');
        self::assertSame($this->armazenamento()->ler(ChavesDeComercial::imagemPorNome($this->nomeDoArquivo($token))), $client->getInternalResponse()->getContent());
    }

    #[TestDox('Endereço público: 404 para o token inexistente, o vencido, a extensão de outro tipo e a extensão fora da lista')]
    public function testEnderecoPublico404(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $jpg = $this->imagemGravada(FabricaDeImagens::jpeg(12, 12));
        $vencida = $this->imagemGravada(FabricaDeImagens::jpeg(12, 12));
        $this->validade($vencida, '-1 minute');

        foreach (['/e/i/' . str_repeat('0', 32) . '.jpg', '/e/i/' . $vencida . '.jpg', '/e/i/' . $jpg . '.png', '/e/i/' . $jpg . '.gif', '/e/i/' . strtoupper($jpg) . '.jpg'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $client->request('GET', '/e/i/' . $jpg . '.jpg');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('A prévia mostra a imagem no e-mail (URL absoluta, alt = título); token desconhecido, vencido ou URL são recusados na prévia, no teste, no salvar e no confirmar')]
    public function testMensagemComImagem(): void
    {
        $client = $this->clienteMaster();
        $token = $this->imagemGravada(FabricaDeImagens::jpeg(30, 30));
        $convite = (array) (new ModelosDeCampanha())->fixo('convite');

        $this->postar($client, '/master/comercial/previa-campanha', ['imagem' => $token] + $convite);
        self::assertResponseIsSuccessful();
        $html = $this->json($client)['html'];
        self::assertStringContainsString('<tr><td style="padding:24px 30px 0;"><img src="http://localhost/e/i/' . $token . '.jpg" alt="Dra. Ana, conheça o escritório digital BlueJus" width="540"', $html);
        self::assertStringContainsString('<tr><td style="padding:18px 30px 8px;">', $html);

        $vencida = $this->imagemGravada(FabricaDeImagens::jpeg(30, 30));
        $this->validade($vencida, '-1 minute');
        $this->postar($client, '/master/comercial/modelos', ['acao' => 'salvar_como', 'modelo' => $convite, 'nome' => 'Meu convite']);
        $proprio = (int) $this->json($client)['modelo']['id'];
        $pedidos = [
            '/master/comercial/previa-campanha' => static fn (array $m): array => $m,
            '/master/comercial/teste' => static fn (array $m): array => $m,
            '/master/comercial/modelos/' . $proprio => static fn (array $m): array => $m,
            '/master/comercial/campanhas' => fn (array $m): array => ['chave' => bin2hex(random_bytes(16)), 'modelo' => $m, 'publico' => 'indiv', 'individual' => (string) $this->cadastradoAtivo()->getEmail(), 'frequencia' => 'unica', 'agendar' => ''],
        ];
        foreach ($pedidos as $url => $pedido) {
            foreach ([str_repeat('e', 32), $vencida] as $invalido) {
                $this->postar($client, $url, $pedido(['imagem' => $invalido] + $convite));
                self::assertResponseStatusCodeSame(422, $url);
                self::assertSame(ImagensDaCampanha::EXPIRADA, $this->json($client)['erro'], $url);
            }
            $this->postar($client, $url, $pedido(['imagem' => 'https://exemplo.com/foto.jpg'] + $convite));
            self::assertResponseStatusCodeSame(422, $url);
            self::assertSame(MensagemDaCampanha::IMAGEM_INVALIDA, $this->json($client)['erro'], $url);
        }
        self::assertSame(0, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM comercial_campanha WHERE publico <> 'teste'"));
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM comercial_envio'));
    }

    #[TestDox('Validade: confirmar a campanha leva a imagem a 1 ano e marca o uso; o modelo salvo com ela também vai a 1 ano')]
    public function testValidadeNaConfirmacaoENoModelo(): void
    {
        $client = $this->clienteMaster();
        $convite = (array) (new ModelosDeCampanha())->fixo('convite');
        $token = $this->imagemGravada(FabricaDeImagens::jpeg(30, 30));
        $agora = new \DateTimeImmutable();

        $this->postar($client, '/master/comercial/campanhas', ['chave' => bin2hex(random_bytes(16)), 'modelo' => ['imagem' => $token] + $convite, 'publico' => 'indiv', 'individual' => (string) $this->cadastradoAtivo()->getEmail(), 'frequencia' => 'unica', 'agendar' => '']);

        self::assertResponseIsSuccessful();
        $linha = $this->linha($token);
        self::assertEqualsWithDelta($agora->modify('+1 year')->getTimestamp(), (new \DateTimeImmutable((string) $linha['expira_em']))->getTimestamp(), 120);
        self::assertNotNull($linha['ultimo_uso_em']);
        $conteudo = json_decode((string) $this->conexao()->fetchOne('SELECT conteudo FROM comercial_campanha WHERE id = ?', [$this->json($client)['id']]), true);
        self::assertSame($token, $conteudo['imagem'], 'a cópia congelada guarda só o token');

        $doModelo = $this->imagemGravada(FabricaDeImagens::jpeg(30, 30));
        $this->postar($client, '/master/comercial/modelos', ['acao' => 'salvar_como', 'modelo' => ['imagem' => $doModelo] + $convite, 'nome' => 'Com foto']);
        self::assertResponseIsSuccessful();
        self::assertSame($doModelo, $this->json($client)['modelo']['imagem'], 'a grade devolve o token do modelo próprio');
        $linha = $this->linha($doModelo);
        self::assertEqualsWithDelta($agora->modify('+1 year')->getTimestamp(), (new \DateTimeImmutable((string) $linha['expira_em']))->getTimestamp(), 120);
        self::assertNull($linha['ultimo_uso_em'], 'salvar o modelo não é uso num envio');
    }

    #[TestDox('Validade: cada execução da recorrente leva a imagem a 1 ano depois do envio e marca o uso; o e-mail sai com a imagem')]
    public function testValidadeNaRecorrente(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $token = $this->imagemGravada(FabricaDeImagens::jpeg(30, 30));
        $this->validade($token, '+2 days');
        $mensagem = MensagemDaCampanha::deArray(['imagem' => $token] + (array) (new ModelosDeCampanha())->fixo('convite'));
        $agora = new \DateTimeImmutable();
        $id = $this->campanha([(string) $this->cadastradoAtivo()->getEmail()], [
            'conteudo' => $c->get(ComposicaoDaCampanha::class)->congelar($mensagem),
            'estado' => 'recorrente', 'frequencia' => 'semanal', 'agendada_para' => $agora->modify('-1 minute'), 'proxima' => $agora->modify('-1 minute'),
        ]);

        self::assertSame(1, $c->get(ExpansorDeCampanhas::class)->materializarDevidas($agora));

        $linha = $this->linha($token);
        self::assertEqualsWithDelta($agora->modify('+1 year')->getTimestamp(), (new \DateTimeImmutable((string) $linha['expira_em']))->getTimestamp(), 5);
        self::assertSame($agora->format('Y-m-d H:i:s'), $linha['ultimo_uso_em']);
        self::assertSame(1, (int) $this->conexao()->fetchOne('SELECT execucoes FROM comercial_campanha WHERE id = ?', [$id]));
        $html = (string) $c->get(ComposicaoDaCampanha::class)->mensagemPara($mensagem, 'a@b.com', 'Ana', 'campanha:' . $id)->corpoHtml;
        self::assertStringContainsString('/e/i/' . $token . '.jpg" alt="', $html);
    }

    #[TestDox('Purga: dry-run só conta; de verdade, a vencida sai do banco e DEPOIS do storage; a vigente fica; falha do storage é só log')]
    public function testPurga(): void
    {
        self::bootKernel();
        $vencida = $this->imagemGravada(FabricaDeImagens::jpeg(10, 10));
        $vigente = $this->imagemGravada(FabricaDeImagens::jpeg(10, 10));
        $outraVencida = $this->imagemGravada(FabricaDeImagens::png(10, 10));
        $this->validade($vencida, '-1 day');
        $this->validade($outraVencida, '-1 day');
        $arquivoVencido = ChavesDeComercial::imagemPorNome($this->nomeDoArquivo($vencida));
        $arquivoVigente = ChavesDeComercial::imagemPorNome($this->nomeDoArquivo($vigente));
        $arquivoQueFalha = ChavesDeComercial::imagemPorNome($this->nomeDoArquivo($outraVencida));
        $agora = new \DateTimeImmutable();

        $espiao = new class ($this->armazenamento(), $this->conexao(), $arquivoQueFalha) implements ArmazenamentoDeArquivos {
            /** @var list<array{string, int}> o nome excluído e quantas linhas ainda apontavam para ele */
            public array $excluidos = [];

            public function __construct(private readonly ArmazenamentoDeArquivos $real, private readonly Connection $conexao, private readonly ChaveDeArquivo $falha)
            {
            }

            public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
            {
                return $this->real->gravar($destino, $fonte);
            }

            public function abrir(ChaveDeArquivo $chave): mixed
            {
                return $this->real->abrir($chave);
            }

            public function ler(ChaveDeArquivo $chave): string
            {
                return $this->real->ler($chave);
            }

            public function existe(ChaveDeArquivo $chave): bool
            {
                return $this->real->existe($chave);
            }

            public function excluir(ChaveDeArquivo $chave): void
            {
                $this->excluidos[] = [$chave->nome, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_imagem WHERE nome_arquivo = ?', [$chave->nome])];
                if ($chave->nome === $this->falha->nome) {
                    throw new FalhaDeArmazenamento('disco fora');
                }
                $this->real->excluir($chave);
            }

            public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
            {
                return $this->real->metadados($chave);
            }
        };
        $log = new LogEmMemoria();
        $c = static::getContainer();
        $purga = new PurgarDadosDoComercialUseCase(
            $c->get(PurgarConfirmacoesVencidasUseCase::class),
            $this->conexao(),
            $c->get(DestinoDaCampanhaRepository::class),
            $log,
            'segredo',
            $c->get(ImagemDaCampanhaRepository::class),
            new RemocaoAposTransacao($espiao, $log),
        );

        $contagem = $purga->executar($agora, true);
        self::assertSame(3, $this->contar());
        self::assertTrue($this->armazenamento()->existe($arquivoVencido));
        self::assertSame([], $espiao->excluidos, 'o dry-run não toca o storage');

        self::assertSame($contagem, $purga->executar($agora, false));
        self::assertSame([$vigente], $this->conexao()->fetchFirstColumn('SELECT token FROM comercial_imagem'));
        self::assertFalse($this->armazenamento()->existe($arquivoVencido));
        self::assertTrue($this->armazenamento()->existe($arquivoVigente));
        self::assertTrue($this->armazenamento()->existe($arquivoQueFalha), 'a falha do storage deixa o órfão');
        self::assertEqualsCanonicalizing([[$arquivoVencido->nome, 0], [$arquivoQueFalha->nome, 0]], $espiao->excluidos, 'o arquivo só sai depois que a linha saiu');
        self::assertStringContainsString('Arquivo não removido depois da transação', $log->tudo);
        self::assertSame($contagem - 2, $purga->executar($agora, true), 'nada vencido sobrou para contar');
    }

    private function clienteMaster(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->trocarCsrf();
        static::getContainer()->set(ChaveDasCampanhas::class, new ChaveDasCampanhas(true));
        $this->master = $this->usuario('master');
        $this->master->setRoles(['ROLE_SUPER_ADMIN']);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($this->master);
        $this->marcarTermosAceitos($client);

        return $client;
    }

    private function trocarCsrf(): void
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

    private function enviar(KernelBrowser $client, string $bytes, string $nome, string $csrf = 'TOKEN_master_comercial'): void
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'imgcamp');
        file_put_contents($caminho, $bytes);
        $client->request('POST', self::ENVIO, [], ['imagem' => new UploadedFile($caminho, $nome, null, null, true)], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        @unlink($caminho);
    }

    /** Uma imagem enviada pelo caso de uso, por um Master novo; devolve o token. */
    private function imagemGravada(string $bytes): string
    {
        $master = $this->usuario('autor');

        return static::getContainer()->get(EnviarImagemDaCampanhaUseCase::class)->executar($bytes, $master)->token;
    }

    /** @param array<mixed> $corpo */
    private function postar(KernelBrowser $client, string $url, array $corpo): void
    {
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial'], (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> */
    private function linha(string $token): array
    {
        $linha = $this->conexao()->fetchAssociative('SELECT * FROM comercial_imagem WHERE token = ?', [$token]);
        self::assertIsArray($linha);

        return $linha;
    }

    /** A validade no relógio da aplicação (a coluna não tem fuso; o NOW() do banco é de outro fuso). */
    private function validade(string $token, string $deslocamento): void
    {
        $this->conexao()->executeStatement('UPDATE comercial_imagem SET expira_em = ? WHERE token = ?', [(new \DateTimeImmutable($deslocamento))->format('Y-m-d H:i:s'), $token]);
    }

    private function nomeDoArquivo(string $token): string
    {
        return (string) $this->linha($token)['nome_arquivo'];
    }

    private function contar(): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM comercial_imagem');
    }

    private function armazenamento(): ArmazenamentoDeArquivos
    {
        return static::getContainer()->get(ArmazenamentoDeArquivos::class);
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
