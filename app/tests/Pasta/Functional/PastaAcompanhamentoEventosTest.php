<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAcompanhamentoDesligado;
use App\Pasta\Repository\PastaAcompanhamentoDesligadoRepository;
use App\Pasta\Service\AvisoDePastaAcompanhada;
use App\Pasta\UseCase\DefinirSituacaoDaPastaUseCase;
use App\Pasta\UseCase\SalvarPecaTextoUseCase;
use App\Pasta\UseCase\UploadPecaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Os dois eventos que o "Acompanhar alterações" passou a avisar além da mensagem (decisão do dono,
 * 23/10/2026), pelas rotas de verdade: DOCUMENTO NOVO (upload e peça de texto, aba Documentos) e
 * TROCA DE SITUAÇÃO (a pílula, aba Dados). Para cada um: quem acompanha recebe, o autor e quem
 * desligou não, quem tem a aba do evento oculta não; e o limite de um não lido por pasta vale
 * somando os eventos.
 */
#[CoversClass(AvisoDePastaAcompanhada::class)]
#[CoversClass(UploadPecaUseCase::class)]
#[CoversClass(SalvarPecaTextoUseCase::class)]
#[CoversClass(DefinirSituacaoDaPastaUseCase::class)]
#[Group('pasta')]
final class PastaAcompanhamentoEventosTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    /** @var list<string> */
    private array $temporarios = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            if (file_exists($arquivo)) {
                @unlink($arquivo);
            }
        }
        parent::tearDown();
    }

    /** @return list<array{titulo: string, url: string, lida: bool}> */
    private function avisos(User $usuario): array
    {
        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT titulo, url, lida FROM notificacao WHERE usuario_id = :u AND tipo = :t ORDER BY id',
            ['u' => $usuario->getId(), 't' => Notificacao::TIPO_PASTA_ACOMPANHADA],
        );

        return array_map(static fn (array $l): array => [
            'titulo' => (string) $l['titulo'],
            'url'    => (string) $l['url'],
            'lida'   => (bool) $l['lida'],
        ], $linhas);
    }

    private function enviarArquivo(Pasta $pasta): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'acomp_pdf_');
        file_put_contents($tmp, '%PDF-1.4 dummy');
        $this->temporarios[] = $tmp;

        $this->client->request(
            'POST',
            '/pasta/' . $pasta->getId() . '/peticionar/upload',
            ['_token' => 'TOKEN_peticionar_upload_' . $pasta->getId(), 'categoria' => 'PECA'],
            ['arquivo' => new UploadedFile($tmp, 'peticao.pdf', 'application/pdf', null, true)],
        );
        self::assertResponseIsSuccessful();
    }

    private function criarPecaDeTexto(Pasta $pasta): void
    {
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/peticionar/texto', [
            '_token'    => 'TOKEN_peticionar_texto_' . $pasta->getId(),
            'titulo'    => 'Petição inicial',
            'categoria' => 'PECA',
            'conteudo'  => '<p>Excelentíssimo</p>',
        ]);
        self::assertResponseIsSuccessful();
    }

    private function mudarSituacao(Pasta $pasta, string $situacao): void
    {
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/alternar-situacao', [
            '_token'   => 'TOKEN_pasta_alternar_situacao_' . $pasta->getId(),
            'situacao' => $situacao,
        ]);
        self::assertResponseIsSuccessful();
    }

    private function enviarMensagem(Pasta $pasta, string $conteudo): void
    {
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/mensagem', [
            '_token'   => 'TOKEN_pasta_mensagem_' . $pasta->getId(),
            'conteudo' => $conteudo,
        ]);
        self::assertResponseIsSuccessful();
    }

    /** @param list<User> $pessoas */
    private function ocultarAba(Tenant $tenant, Pasta $pasta, AbaDaPasta $aba, array $pessoas, User $por): void
    {
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $pessoas);
        $this->em()->persist(AbaOcultaDaPasta::paraAlguns($tenant, $pasta, $aba, $ids, $por, new \DateTimeImmutable()));
        $this->em()->flush();
    }

    private function desligar(Tenant $tenant, User $quem, Pasta $pasta): void
    {
        $this->em()->persist(new PastaAcompanhamentoDesligado($tenant, $quem, $pasta));
        $this->em()->flush();
    }

    #[TestDox('Upload de documento avisa quem acompanha: "Novo documento na pasta N", link da aba Documentos; nunca o autor, quem desligou ou quem tem a aba Documentos oculta')]
    public function testUploadDeDocumentoAvisa(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana    = $this->criarColaborador($tenant, 'Ana Autora');
        $bruno  = $this->criarColaborador($tenant, 'Bruno Leitor');
        $carla  = $this->criarColaborador($tenant, 'Carla Desligou');
        $diego  = $this->criarColaborador($tenant, 'Diego Sem Documentos');
        $elisa  = $this->criarColaborador($tenant, 'Elisa Sem Dados');
        $pasta  = $this->criarPasta($tenant);
        $this->desligar($tenant, $carla, $pasta);
        $this->ocultarAba($tenant, $pasta, AbaDaPasta::Documentos, [$diego], $primario);
        $this->ocultarAba($tenant, $pasta, AbaDaPasta::Dados, [$elisa], $primario);

        $this->entrar($ana, $tenant);
        $this->enviarArquivo($pasta);

        $esperado = [['titulo' => 'Novo documento na pasta ' . $pasta->getNup(), 'url' => '/pasta/' . $pasta->getId() . '#documentos', 'lida' => false]];
        self::assertSame($esperado, $this->avisos($bruno));
        self::assertSame($esperado, $this->avisos($elisa), 'a aba Dados oculta não tira do aviso de documento');
        self::assertSame([], $this->avisos($ana), 'o autor não se avisa');
        self::assertSame([], $this->avisos($carla), 'quem desligou não recebe');
        self::assertSame([], $this->avisos($diego), 'quem tem a aba Documentos oculta não recebe');
    }

    #[TestDox('Peça de texto nova também é documento novo: avisa quem acompanha, menos o autor')]
    public function testPecaDeTextoAvisa(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Autora');
        $bruno = $this->criarColaborador($tenant, 'Bruno Leitor');
        $pasta = $this->criarPasta($tenant);

        $this->entrar($ana, $tenant);
        $this->criarPecaDeTexto($pasta);

        self::assertSame([['titulo' => 'Novo documento na pasta ' . $pasta->getNup(), 'url' => '/pasta/' . $pasta->getId() . '#documentos', 'lida' => false]], $this->avisos($bruno));
        self::assertSame([], $this->avisos($ana));
    }

    #[TestDox('Troca de situação avisa quem acompanha: "Situação da pasta N alterada para X", link do cartão no Registro; nunca o autor, quem desligou ou quem tem a aba Dados oculta')]
    public function testTrocaDeSituacaoAvisa(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Autora');
        $bruno = $this->criarColaborador($tenant, 'Bruno Leitor');
        $carla = $this->criarColaborador($tenant, 'Carla Desligou');
        $diego = $this->criarColaborador($tenant, 'Diego Sem Dados');
        $elisa = $this->criarColaborador($tenant, 'Elisa Sem Documentos');
        $pasta = $this->criarPasta($tenant);
        $this->desligar($tenant, $carla, $pasta);
        $this->ocultarAba($tenant, $pasta, AbaDaPasta::Dados, [$diego], $primario);
        $this->ocultarAba($tenant, $pasta, AbaDaPasta::Documentos, [$elisa], $primario);

        $this->entrar($ana, $tenant);
        $this->mudarSituacao($pasta, Pasta::SITUACAO_SUSPENSA);

        $cartao = (int) $this->em()->getConnection()->fetchOne(
            'SELECT MAX(id) FROM pasta_mensagem WHERE pasta_id = :p AND sistema = :s',
            ['p' => $pasta->getId(), 's' => 'situacao'],
        );
        self::assertGreaterThan(0, $cartao, 'a troca grava o cartão do sistema no Registro');
        $esperado = [['titulo' => 'Situação da pasta ' . $pasta->getNup() . ' alterada para Suspenso', 'url' => '/pasta/' . $pasta->getId() . '#pasta-msg-' . $cartao, 'lida' => false]];
        self::assertSame($esperado, $this->avisos($bruno));
        self::assertSame($esperado, $this->avisos($elisa), 'a aba Documentos oculta não tira do aviso de situação');
        self::assertSame([], $this->avisos($ana), 'o autor não se avisa');
        self::assertSame([], $this->avisos($carla), 'quem desligou não recebe');
        self::assertSame([], $this->avisos($diego), 'quem tem a aba Dados oculta não recebe');
    }

    #[TestDox('Pedir a mesma situação que já está não grava nada e não avisa')]
    public function testMesmaSituacaoNaoAvisa(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Autora');
        $bruno = $this->criarColaborador($tenant, 'Bruno Leitor');
        $pasta = $this->criarPasta($tenant);

        $this->entrar($ana, $tenant);
        $this->mudarSituacao($pasta, $pasta->getSituacao());

        self::assertSame([], $this->avisos($bruno));
    }

    #[TestDox('Um não lido por pasta SOMANDO os eventos: mensagem, documento e situação seguidos dão um aviso só; lido, o próximo evento volta a avisar')]
    public function testDeduplicacaoEntreEventos(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Autora');
        $bruno = $this->criarColaborador($tenant, 'Bruno Leitor');
        $pasta = $this->criarPasta($tenant);
        $this->entrar($ana, $tenant);

        $this->enviarMensagem($pasta, 'Cliente ligou');
        $this->enviarArquivo($pasta);
        $this->mudarSituacao($pasta, Pasta::SITUACAO_SUSPENSA);
        $avisos = $this->avisos($bruno);
        self::assertCount(1, $avisos, 'o primeiro ainda não foi lido');
        self::assertStringStartsWith('Nova mensagem na pasta', $avisos[0]['titulo']);

        $this->em()->getConnection()->executeStatement('UPDATE notificacao SET lida = true WHERE usuario_id = :u', ['u' => $bruno->getId()]);
        $this->criarPecaDeTexto($pasta);
        $avisos = $this->avisos($bruno);
        self::assertCount(2, $avisos, 'lido o anterior, o documento novo volta a avisar');
        self::assertSame('/pasta/' . $pasta->getId() . '#documentos', $avisos[1]['url']);
    }

    private function quebrarOAviso(): void
    {
        $quebrado = $this->createMock(PastaAcompanhamentoDesligadoRepository::class);
        $quebrado->method('vinculosQueNaoDesligaram')->willThrowException(new \RuntimeException('falha simulada'));
        static::getContainer()->set(PastaAcompanhamentoDesligadoRepository::class, $quebrado);
    }

    #[TestDox('Falha no aviso não derruba o upload: o documento fica gravado e a rota responde sucesso')]
    public function testFalhaNoAvisoNaoDerrubaOUpload(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Autora');
        $bruno = $this->criarColaborador($tenant, 'Bruno Leitor');
        $pasta = $this->criarPasta($tenant);
        $this->entrar($ana, $tenant);
        $this->quebrarOAviso();

        $this->enviarArquivo($pasta);

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE pasta_id = :p', ['p' => $pasta->getId()]));
        self::assertSame([], $this->avisos($bruno), 'o aviso de fato falhou');
    }

    #[TestDox('Falha no aviso não derruba a troca de situação: ela fica gravada e a rota responde sucesso')]
    public function testFalhaNoAvisoNaoDerrubaASituacao(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Autora');
        $bruno = $this->criarColaborador($tenant, 'Bruno Leitor');
        $pasta = $this->criarPasta($tenant);
        $this->entrar($ana, $tenant);
        $this->quebrarOAviso();

        $this->mudarSituacao($pasta, Pasta::SITUACAO_SUSPENSA);

        self::assertSame(Pasta::SITUACAO_SUSPENSA, $this->em()->getConnection()->fetchOne('SELECT situacao FROM pasta WHERE id = :p', ['p' => $pasta->getId()]));
        self::assertSame([], $this->avisos($bruno), 'o aviso de fato falhou');
    }
}
