<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\DTO\AssinarFolhaInput;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Service\ImagemDaFolha\DesenhoDaFolha;
use App\Ponto\Service\ImagemDaFolha\ImagemDaFolha;
use App\Ponto\Service\PdfDeUmaImagem;
use App\Ponto\UseCase\GerarPdfDaFolhaUseCase;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDaFolhaDoMesTrait;
use App\Tests\Ponto\Support\DesenhoDaFolhaEspiao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * A folha do mês em PDF pela rota `ponto_exportar_pdf` (spec ponto-desenho-0811, E16, P34 e P89; decisões 2, 5 e 16):
 * a página desenhada em imagem, embrulhada num PDF de uma página; com o segredo do HMAC, cada PDF é uma folha emitida com
 * código próprio, devolvido em `X-Codigo-Folha` e válido na página pública; sem o segredo, o PDF sai sem código e nada
 * é gravado. As permissões são as de antes, com uma exceção (decisão 25): a pessoa sem vínculo ativo neste escritório
 * responde 404, como a que não existe (antes, 403); 10 PDFs por minuto por usuário, contados depois da permissão.
 *
 * O PDF é imagem: o que a folha mostra se lê no conteúdo que chegou ao desenho ({@see DesenhoDaFolhaEspiao}). Antes de
 * cada pedido, o EntityManager é limpo: a rota carrega o escritório, a pessoa e o aceite do zero, como em produção (a
 * emissão compara as instâncias por identidade).
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(GerarPdfDaFolhaUseCase::class)]
#[CoversClass(PdfDeUmaImagem::class)]
final class FolhaEmPdfTest extends JusPrimeWebTestCase
{
    use ClockSensitiveTrait;
    use CenarioDaFolhaDoMesTrait;

    private const SEGREDO = 'segredo-de-teste-da-folha-em-pdf';
    private const NOME = 'Mária da Silva Conceição';
    private const ARQUIVO = 'folha_ponto_MARIADASILVACONCEICAO-10-2026.pdf';
    private const OUTUBRO = '/ponto/exportar-folha-pdf?mes=10&ano=2026';

    private KernelBrowser $client;
    private RateLimiterFactoryEspiao $cota;
    private Tenant $escritorio;
    private User $maria;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::mockTime(new \DateTimeImmutable('2026-11-09 10:15:30', new \DateTimeZone(date_default_timezone_get())));
        // O segredo entra no container antes do primeiro uso e não pode ser trocado depois.
        $this->segredo($this->name() === 'testSemOSegredo' ? null : self::SEGREDO);
        $this->cota = new RateLimiterFactoryEspiao(aceita: $this->name() !== 'testCotaEstourada');
        if ($this->name() !== 'testLimiteConfigurado') {
            static::getContainer()->set('limiter.ponto_folha_pdf', $this->cota);
        }
        $this->escritorio = $this->escritorio('Escritório da folha em PDF');
        $this->maria = $this->pessoaComOutubro($this->escritorio, self::NOME);
    }

    #[TestDox('Com o segredo: PDF de uma página A4 com a imagem 2480 x 3507, o nome do arquivo do desenho e o código da folha emitida no cabeçalho, no conteúdo e no QR')]
    public function testComOSegredo(): void
    {
        $desenho = DesenhoDaFolhaEspiao::instalar(static::getContainer(), comODesenhoDeVerdade: true);

        $pdf = $this->pedir($this->maria, self::OUTUBRO);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="' . self::ARQUIVO . '"');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $folhas = $this->folhasNoBanco($this->escritorio, $this->maria);
        self::assertCount(1, $folhas, 'uma folha emitida por PDF');
        $codigo = (string) $folhas[0]['codigo'];
        self::assertMatchesRegularExpression('/^BJF-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $codigo);
        self::assertResponseHeaderSame('X-Codigo-Folha', $codigo);
        self::assertSame($codigo, $desenho->ultimo()->validacao?->codigo, 'o código desenhado é o gravado');
        self::assertMatchesRegularExpression('#^https?://[^/]+/ponto/validar/' . $codigo . '$#', (string) $desenho->enderecosDoQr[0], 'o QR leva à validação pública, em endereço absoluto');
        self::assertSame([$this->maria->getId(), ['folha_emitida']], [(int) $folhas[0]['emitida_por_id'], $this->acoesNaTrilha($this->escritorio)]);
        self::assertSame([(string) $this->maria->getId()], $this->cota->chavesConsumidas, 'a cota é da pessoa que pede');
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString('/Type /Pages /Kids [3 0 R] /Count 1', $pdf);
        self::assertStringContainsString('/MediaBox [0 0 595.28 841.89]', $pdf);
        self::assertStringContainsString('/Width 2480 /Height 3507 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode', $pdf);
        self::assertStringContainsString('/Title (Folha de Ponto 10/2026)', $pdf);
    }

    #[TestDox('Dois PDFs, dois códigos e duas folhas emitidas (decisão 2); "Ver PDF" (inline=1) abre na janela')]
    public function testDoisPdfsDoisCodigos(): void
    {
        DesenhoDaFolhaEspiao::instalar(static::getContainer());

        $this->pedir($this->maria, self::OUTUBRO);
        $primeiro = $this->client->getResponse()->headers->get('X-Codigo-Folha');
        $this->pedir($this->maria, self::OUTUBRO . '&inline=1');
        $segundo = $this->client->getResponse()->headers->get('X-Codigo-Folha');

        self::assertResponseHeaderSame('Content-Disposition', 'inline; filename="' . self::ARQUIVO . '"');
        self::assertNotNull($primeiro);
        self::assertNotSame($primeiro, $segundo);
        self::assertSame([$primeiro, $segundo], array_column($this->folhasNoBanco($this->escritorio, $this->maria), 'codigo'));
    }

    #[TestDox('Sem o segredo (decisão 16): o PDF sai, sem código, sem QR e sem o cabeçalho, e nada é gravado')]
    public function testSemOSegredo(): void
    {
        $desenho = DesenhoDaFolhaEspiao::instalar(static::getContainer());

        $pdf = $this->pedir($this->maria, self::OUTUBRO);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="' . self::ARQUIVO . '"');
        self::assertFalse($this->client->getResponse()->headers->has('X-Codigo-Folha'));
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertNull($desenho->ultimo()->validacao);
        self::assertSame([null], $desenho->enderecosDoQr);
        self::assertSame([], $this->folhasNoBanco($this->escritorio, $this->maria));
        self::assertSame([], $this->trilhaNoBanco($this->escritorio));
    }

    #[TestDox('Cota estourada: 429 com o aviso simples, sem emitir nem desenhar')]
    public function testCotaEstourada(): void
    {
        $desenho = DesenhoDaFolhaEspiao::instalar(static::getContainer());

        $this->pedir($this->maria, self::OUTUBRO);

        self::assertResponseStatusCodeSame(429);
        self::assertSame(PontoController::MUITOS_PEDIDOS_DE_FOLHA, $this->client->getResponse()->getContent());
        self::assertSame([], $desenho->desenhados);
        self::assertSame([], $this->folhasNoBanco($this->escritorio, $this->maria));
    }

    #[TestDox('O limite configurado: 10 PDFs por minuto por pessoa; o 11º é recusado')]
    public function testLimiteConfigurado(): void
    {
        $limitador = static::getContainer()->get('limiter.ponto_folha_pdf')->create('101');

        for ($i = 0; $i < 10; ++$i) {
            self::assertTrue($limitador->consume()->isAccepted(), 'PDF ' . ($i + 1));
        }
        self::assertFalse($limitador->consume()->isAccepted(), 'o 11º');
    }

    #[TestDox('Colaborador comum pedindo a folha de outro: 403, sem gastar a cota, sem emitir nem desenhar')]
    public function testColaboradorNaoExportaAFolhaDeOutro(): void
    {
        $desenho = DesenhoDaFolhaEspiao::instalar(static::getContainer());
        $joao = $this->pessoaComOutubro($this->escritorio, 'João Souza');

        $this->pedir($this->maria, self::OUTUBRO . '&userId=' . $joao->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $desenho->desenhados);
        self::assertSame([], $this->cota->chavesConsumidas);
        self::assertSame([], $this->folhasNoBanco($this->escritorio, $joao));
    }

    /** @param 'de outro escritório'|'com o vínculo suspenso aqui'|'que não existe' $alvo */
    #[DataProvider('pedidosDeQuemNaoEstaAqui')]
    #[TestDox('Pessoa sem vínculo ativo neste escritório ($_dataName): 404 como a que não existe, sem gastar a cota, sem emitir nem desenhar')]
    public function testPessoaSemVinculoAquiResponde404(bool $comAPermissao, string $alvo): void
    {
        $desenho = DesenhoDaFolhaEspiao::instalar(static::getContainer());
        $quem = $comAPermissao ? $this->gestora() : $this->maria;
        $alvoId = match ($alvo) {
            'de outro escritório'         => (int) $this->pessoaComOutubro($this->escritorio('Outro escritório'), 'João Souza')->getId(),
            'com o vínculo suspenso aqui' => $this->suspensaNesteEscritorio(),
            'que não existe'              => 999999999,
        };

        $this->pedir($quem, self::OUTUBRO . '&userId=' . $alvoId);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $desenho->desenhados);
        self::assertSame([], $this->cota->chavesConsumidas, 'o limitador não foi consumido');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ponto_folha_emitida'), 'nenhuma folha emitida');
        self::assertSame([], $this->acoesNaTrilha($this->escritorio));
    }

    /** @return iterable<string, array{bool, 'de outro escritório'|'com o vínculo suspenso aqui'|'que não existe'}> */
    public static function pedidosDeQuemNaoEstaAqui(): iterable
    {
        yield 'gestor com admin.users.manage, pessoa de outro escritório' => [true, 'de outro escritório'];
        yield 'gestor com admin.users.manage, pessoa com o vínculo suspenso aqui' => [true, 'com o vínculo suspenso aqui'];
        yield 'gestor com admin.users.manage, userId que não existe' => [true, 'que não existe'];
        // Sem a permissão também é 404: o 403 só vale para quem é deste escritório.
        yield 'colaborador sem a permissão, pessoa de outro escritório' => [false, 'de outro escritório'];
    }

    /** @param 'com o vínculo suspenso'|'que saiu da equipe'|'que saiu antes de a saída ser gravada' $como */
    #[DataProvider('exColaboradores')]
    #[TestDox('Master (ROLE_SUPER_ADMIN), pessoa $_dataName: exporta a folha, emitida neste escritório, em nome do Master e com o evento na trilha (decisão 28)')]
    public function testMasterExportaAFolhaDoExColaborador(string $como): void
    {
        $desenho = DesenhoDaFolhaEspiao::instalar(static::getContainer());
        $master = $this->master();
        $joaoId = match ($como) {
            'com o vínculo suspenso'                 => $this->suspensaNesteEscritorio(),
            'que saiu da equipe'                     => $this->quemSaiuDaEquipe($this->escritorio, true),
            'que saiu antes de a saída ser gravada'  => $this->quemSaiuDaEquipe($this->escritorio, false),
        };

        $this->pedir($master, self::OUTUBRO . '&userId=' . $joaoId);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="folha_ponto_JOAOSOUZA-10-2026.pdf"');
        $folhas = $this->em()->getConnection()->fetchAllAssociative('SELECT tenant_id, user_id, emitida_por_id, codigo FROM ponto_folha_emitida');
        self::assertCount(1, $folhas, 'uma folha emitida, e só uma');
        self::assertSame(
            [$this->escritorio->getId(), $joaoId, $master->getId()],
            [(int) $folhas[0]['tenant_id'], (int) $folhas[0]['user_id'], (int) $folhas[0]['emitida_por_id']],
            'a folha do ex-colaborador, no escritório da sessão, emitida pelo Master',
        );
        self::assertSame('JOÃO SOUZA', mb_strtoupper($desenho->ultimo()->cabecalho->nome));
        self::assertSame(mb_strtoupper((string) $this->escritorio->getName()), $desenho->ultimo()->cabecalho->empregador, 'o cabeçalho é o do escritório da sessão');
        $trilha = $this->trilhaNoBanco($this->escritorio);
        self::assertCount(1, $trilha);
        self::assertSame(
            ['folha_emitida', $master->getId(), $joaoId, $folhas[0]['codigo']],
            [$trilha[0]['acao'], $trilha[0]['autor_id'], $trilha[0]['detalhe']['colaborador_id'], $trilha[0]['detalhe']['codigo']],
            'o evento na trilha deste escritório: o Master emitiu a folha do ex-colaborador',
        );
        self::assertSame([(string) $master->getId()], $this->cota->chavesConsumidas);
    }

    /** @return iterable<string, array{'com o vínculo suspenso'|'que saiu da equipe'|'que saiu antes de a saída ser gravada'}> */
    public static function exColaboradores(): iterable
    {
        yield 'com o vínculo suspenso aqui' => ['com o vínculo suspenso'];
        yield 'que saiu da equipe (vínculo apagado, saída gravada)' => ['que saiu da equipe'];
        yield 'que saiu antes de 19/10/2026 (vínculo apagado, sem a saída gravada, com as batidas daqui)' => ['que saiu antes de a saída ser gravada'];
    }

    /** @param 'de outro escritório'|'sem escritório nenhum' $alvo */
    #[DataProvider('pessoasSemVinculoAqui')]
    #[TestDox('Master (ROLE_SUPER_ADMIN) e pessoa que nunca foi deste escritório ($_dataName): 404 com a mesma mensagem, sem gastar a cota, sem emitir, sem desenhar e sem evento (decisão 28)')]
    public function testMasterNaoExportaAFolhaDeQuemNaoTemVinculoAqui(string $alvo): void
    {
        $desenho = DesenhoDaFolhaEspiao::instalar(static::getContainer());
        $master = $this->master();
        $outro = $this->escritorio('Outro escritório');
        $alvoId = (int) match ($alvo) {
            'de outro escritório'   => $this->pessoaComOutubro($outro, 'João Souza')->getId(),
            'sem escritório nenhum' => $this->pessoa('João Souza')->getId(),
        };

        $this->pedir($master, self::OUTUBRO . '&userId=' . $alvoId);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Usuário para exportação não encontrado.', html_entity_decode((string) $this->client->getResponse()->getContent()));
        self::assertSame([], $desenho->desenhados);
        self::assertSame([], $this->cota->chavesConsumidas, 'o limitador não foi consumido');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ponto_folha_emitida'), 'nenhuma folha emitida');
        self::assertSame([], $this->trilhaNoBanco($this->escritorio));
        self::assertSame([], $this->trilhaNoBanco($outro));
    }

    /** @return iterable<string, array{'de outro escritório'|'sem escritório nenhum'}> */
    public static function pessoasSemVinculoAqui(): iterable
    {
        yield 'pessoa de outro escritório' => ['de outro escritório'];
        yield 'pessoa sem vínculo com escritório nenhum' => ['sem escritório nenhum'];
    }

    /** @param 'no desenho'|'no embrulho' $onde */
    #[DataProvider('falhasDepoisDaEmissao')]
    #[TestDox('Falha $_dataName: erro do servidor (500), nunca o 400 da competência; a folha emitida e o evento ficam (decisão 25)')]
    public function testFalhaDepoisDaEmissaoNaoViraCompetenciaInvalida(string $onde): void
    {
        static::getContainer()->set(ImagemDaFolha::class, self::desenhoQueFalha($onde));

        $this->pedir($this->maria, self::OUTUBRO);

        self::assertResponseStatusCodeSame(500);
        self::assertCount(1, $this->folhasNoBanco($this->escritorio, $this->maria), 'a folha emitida fica');
        self::assertSame(['folha_emitida'], $this->acoesNaTrilha($this->escritorio), 'o evento fica');
    }

    /** @return iterable<string, array{'no desenho'|'no embrulho'}> */
    public static function falhasDepoisDaEmissao(): iterable
    {
        yield 'no desenho (o QR recusa o endereço com \\InvalidArgumentException)' => ['no desenho'];
        yield 'no embrulho (o desenho não entrega um JPEG e o PdfDeUmaImagem recusa)' => ['no embrulho'];
    }

    #[TestDox('Gestor com admin.users.manage e vínculo: exporta a folha do colaborador, emitida em nome dele')]
    public function testGestorExportaAFolhaDoColaborador(): void
    {
        $desenho = DesenhoDaFolhaEspiao::instalar(static::getContainer());
        $gestor = $this->pessoa('Gestora do Ponto');
        $this->vincular($gestor, $this->escritorio, ['modules.ponto.view', 'admin.users.manage']);

        $this->pedir($gestor, self::OUTUBRO . '&userId=' . $this->maria->getId());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="' . self::ARQUIVO . '"');
        $folhas = $this->folhasNoBanco($this->escritorio, $this->maria);
        self::assertCount(1, $folhas);
        self::assertSame($gestor->getId(), (int) $folhas[0]['emitida_por_id'], 'quem emitiu é o gestor');
        self::assertSame(mb_strtoupper(self::NOME), mb_strtoupper($desenho->ultimo()->cabecalho->nome));
        self::assertSame([(string) $gestor->getId()], $this->cota->chavesConsumidas, 'a cota é de quem pede');
    }

    #[TestDox('Aceite já gravado: o carimbo vai ao conteúdo; uma batida nova no mês depois do aceite tira o carimbo')]
    public function testCarimboDoAceite(): void
    {
        $desenho = DesenhoDaFolhaEspiao::instalar(static::getContainer());
        $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'ressalva', 'O dia 6 foi de folga.'), $this->maria, $this->escritorio);

        $this->pedir($this->maria, self::OUTUBRO);

        self::assertResponseIsSuccessful();
        $carimbo = $desenho->ultimo()->carimbo;
        self::assertNotNull($carimbo, 'a folha não mudou desde o aceite');
        self::assertSame(SituacaoDoAceiteDaFolha::Ressalva, $carimbo->situacao);
        self::assertSame('Aceite com ressalva em 09/11/2026, 10:15:30', $carimbo->texto());
        self::assertSame('Ressalva: O dia 6 foi de folga.', $carimbo->textoDaRessalva());
        self::assertCount(2, $this->folhasNoBanco($this->escritorio, $this->maria), 'a folha vista no aceite e a do PDF');

        $maria = $this->em()->find(User::class, $this->maria->getId());
        $escritorio = $this->em()->find(Tenant::class, $this->escritorio->getId());
        self::assertNotNull($maria);
        self::assertNotNull($escritorio);
        $this->diaCompleto($maria, $escritorio, '2026-10-07');
        $this->pedir($maria, self::OUTUBRO);

        self::assertResponseIsSuccessful();
        self::assertNull($desenho->ultimo()->carimbo, 'a folha mudou depois do aceite');
    }

    #[TestDox('De ponta a ponta: o código do PDF é autêntico na validação pública, sem login')]
    public function testCodigoDoPdfValidaNaPaginaPublica(): void
    {
        DesenhoDaFolhaEspiao::instalar(static::getContainer());
        $this->pedir($this->maria, self::OUTUBRO);
        $codigo = (string) $this->client->getResponse()->headers->get('X-Codigo-Folha');

        $this->sair($this->client);
        $this->client->request('GET', '/ponto/validar/' . $codigo, [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['autentico' => true, 'texto' => 'Folha de Ponto 10/2026 de ' . self::NOME . ' em 09/11/2026, 10:15:30.'],
            json_decode((string) $this->client->getResponse()->getContent(), true),
        );
    }

    #[TestDox('Competência fora do calendário: 400, sem emitir nem desenhar')]
    public function testCompetenciaInvalida(): void
    {
        $desenho = DesenhoDaFolhaEspiao::instalar(static::getContainer());

        $this->pedir($this->maria, '/ponto/exportar-folha-pdf?mes=10&ano=10000');

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $desenho->desenhados);
        self::assertSame([], $this->folhasNoBanco($this->escritorio, $this->maria));
    }

    private function gestora(): User
    {
        $gestora = $this->pessoa('Gestora do Ponto');
        $this->vincular($gestora, $this->escritorio, ['modules.ponto.view', 'admin.users.manage']);

        return $gestora;
    }

    /** O Master do BlueJus, com vínculo neste escritório (o escritório da sessão). */
    private function master(): User
    {
        $master = $this->pessoa('Master do BlueJus', ['ROLE_SUPER_ADMIN']);
        $this->vincular($master, $this->escritorio);

        return $master;
    }

    /** O João com a folha de outubro neste escritório e o vínculo suspenso (inativo): o id dele. */
    private function suspensaNesteEscritorio(): int
    {
        $joao = $this->pessoaComOutubro($this->escritorio, 'João Souza');
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $joao, 'tenant' => $this->escritorio]);
        self::assertNotNull($vinculo);
        $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();

        return (int) $joao->getId();
    }

    /** O desenho que falha depois da emissão: lança como o QR que recusa o endereço, ou entrega o que não é JPEG. */
    private static function desenhoQueFalha(string $onde): DesenhoDaFolha
    {
        return new class ($onde) implements DesenhoDaFolha {
            public function __construct(private readonly string $onde)
            {
            }

            public function gerar(ConteudoDaFolha $conteudo, ?string $enderecoDoQr, string $enderecoDaPagina): string
            {
                if ($this->onde === 'no desenho') {
                    throw new \InvalidArgumentException('Endereço do QR recusado.');
                }

                return 'isto não é um JPEG';
            }
        };
    }

    /** Entra como a pessoa no escritório e pede o PDF, com o EntityManager limpo (como uma requisição de produção). */
    private function pedir(User $quem, string $endereco): string
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->em()->clear();
        $this->client->request('GET', $endereco);

        return (string) $this->client->getResponse()->getContent();
    }
}
