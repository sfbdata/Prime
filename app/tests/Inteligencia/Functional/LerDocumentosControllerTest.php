<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Controller\AnaliseDaPastaController;
use App\Inteligencia\Prompt\PromptDoAgente;
use App\Inteligencia\Service\RegraDaLeituraDeDocumentos;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O "Ler N documentos" da aba Documentos pela HTTP (PR F da spec ia-anthropic-ligar): as guardas de sempre
 * (CSRF, pasta do escritório, permissão do módulo, sigilo) e as da leitura, todas ANTES da fila e sem gastar
 * o limitador: seleção dentro da lista da aba (tenant alheio e aba oculta são 422), no máximo 3 documentos,
 * só PDF e imagem, tamanho, e o aceite do escritório dado com o texto que cita os documentos (409).
 */
#[CoversClass(AnaliseDaPastaController::class)]
final class LerDocumentosControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
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

        return $client;
    }

    /** @param list<int|string> $documentos */
    private function ler(KernelBrowser $client, int $pastaId, array $documentos): void
    {
        $client->request(
            'POST',
            "/pasta/{$pastaId}/ia/comandos/ler_documentos/analises",
            ['_token' => 'TOKEN_inteligencia_agentes_' . $pastaId, 'documentos' => $documentos],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function arquivo(Tenant $tenant, Pasta $pasta, string $nome, string $mime = 'application/pdf', int $tamanho = 2048, ?int $paginas = null): PastaDocumento
    {
        $documento = $this->criarDocumento($tenant, $pasta, $nome);
        $documento->setNomeOriginal($nome);
        $documento->setMimeType($mime);
        $documento->setTamanhoBytes($tamanho);
        $documento->setPaginas($paginas);
        $this->em()->flush();

        return $documento;
    }

    private function linhas(int $pastaId): int
    {
        return $this->contarNoBanco('SELECT count(*) FROM inteligencia_analise WHERE alvo_id = :id', ['id' => $pastaId]);
    }

    /** O limitador recusando: se a recusa da leitura gastasse o limitador, a resposta seria 429. */
    private function limitadorRecusando(): void
    {
        static::getContainer()->set('limiter.inteligencia_solicitar', new RateLimiterFactoryEspiao(aceita: false));
    }

    private function semNadaNaFila(int $pastaId): void
    {
        self::assertSame(0, $this->linhas($pastaId));
        self::assertCount(0, $this->transporteAsync()->getSent());
    }

    #[TestDox('PDF e imagem marcados → 202: linha do Documental com o comando ler_documentos, a versão da leitura e os ids ORDENADOS; UMA mensagem')]
    public function testLeComSucesso(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $pdf = $this->arquivo($tenant, $pasta, 'peticao.pdf');
        $foto = $this->arquivo($tenant, $pasta, 'rg.jpg', 'image/jpeg');
        $this->logarComTenant($client, $user, $tenant);

        $this->ler($client, (int) $pasta->getId(), [(string) $foto->getId(), (string) $pdf->getId()]);

        self::assertResponseStatusCodeSame(202);
        $json = $this->json($client);
        self::assertSame('ler_documentos', $json['comando']);
        self::assertSame('documental', $json['agente']);
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT contexto_resumo, versao_do_prompt FROM inteligencia_analise WHERE id = :id', ['id' => $json['id']]);
        self::assertIsArray($linha);
        self::assertSame(PromptDoAgente::VERSAO_DA_LEITURA, $linha['versao_do_prompt']);
        $resumo = json_decode((string) $linha['contexto_resumo'], true);
        $ids = [(int) $pdf->getId(), (int) $foto->getId()];
        sort($ids);
        self::assertSame($ids, $resumo['documentosSelecionados']);
        self::assertArrayNotHasKey('documentosLidos', $resumo, 'quem lê é o worker');
        self::assertCount(1, $this->transporteAsync()->getSent());
    }

    #[TestDox('mais de 3 documentos → 422 limite_da_leitura com a mensagem clara, nada persistido, limitador intacto')]
    public function testMaisDeTres(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $ids = [];
        foreach (['a.pdf', 'b.pdf', 'c.pdf', 'd.pdf'] as $nome) {
            $ids[] = (int) $this->arquivo($tenant, $pasta, $nome)->getId();
        }
        $this->limitadorRecusando();
        $this->logarComTenant($client, $user, $tenant);

        $this->ler($client, (int) $pasta->getId(), $ids);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('limite_da_leitura', $this->json($client)['motivo']);
        self::assertSame('A IA lê no máximo 3 documentos por vez. Desmarque alguns e tente de novo.', $this->json($client)['mensagem']);
        $this->semNadaNaFila((int) $pasta->getId());
    }

    #[TestDox('formato que não é PDF nem imagem (DOCX), PDF acima de 10 MB e imagem acima de 3 MB → 422 citando o arquivo')]
    public function testFormatoETamanho(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $casos = [
            [$this->arquivo($tenant, $pasta, 'contrato.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), 'A IA lê só PDF e imagem (JPG, PNG, GIF ou WEBP): "contrato.docx" não é um desses formatos.'],
            [$this->arquivo($tenant, $pasta, 'grande.pdf', 'application/pdf', RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_DO_PDF + 1), '"grande.pdf" passa de 10 MB, o limite para a IA ler este tipo de arquivo.'],
            [$this->arquivo($tenant, $pasta, 'foto.png', 'image/png', RegraDaLeituraDeDocumentos::TAMANHO_MAXIMO_DA_IMAGEM + 1), '"foto.png" passa de 3 MB, o limite para a IA ler este tipo de arquivo.'],
        ];
        $this->limitadorRecusando();
        $this->logarComTenant($client, $user, $tenant);

        foreach ($casos as [$documento, $mensagem]) {
            $this->ler($client, (int) $pasta->getId(), [(int) $documento->getId()]);

            self::assertResponseStatusCodeSame(422, $mensagem);
            self::assertSame('limite_da_leitura', $this->json($client)['motivo']);
            self::assertSame($mensagem, $this->json($client)['mensagem']);
        }
        $this->semNadaNaFila((int) $pasta->getId());
    }

    #[TestDox('três PDFs que juntos passam de 20 MB → 422 (a requisição ao provedor tem teto)')]
    public function testTotalAcimaDoTeto(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $ids = [];
        foreach (['a.pdf', 'b.pdf', 'c.pdf'] as $nome) {
            $ids[] = (int) $this->arquivo($tenant, $pasta, $nome, 'application/pdf', 7 * RegraDaLeituraDeDocumentos::MEGABYTE)->getId();
        }
        $this->limitadorRecusando();
        $this->logarComTenant($client, $user, $tenant);

        $this->ler($client, (int) $pasta->getId(), $ids);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Os documentos marcados passam de 20 MB juntos. Marque menos documentos e tente de novo.', $this->json($client)['mensagem']);
        $this->semNadaNaFila((int) $pasta->getId());
    }

    #[TestDox('PDFs que somam mais de 100 páginas → 422; um só com 101 também; páginas desconhecidas não barram aqui (o worker conta)')]
    public function testPaginasNoTotal(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $soma = [
            (int) $this->arquivo($tenant, $pasta, 'a.pdf', 'application/pdf', 4096, 60)->getId(),
            (int) $this->arquivo($tenant, $pasta, 'b.pdf', 'application/pdf', 4096, 41)->getId(),
        ];
        $um = (int) $this->arquivo($tenant, $pasta, 'autos.pdf', 'application/pdf', 4096, 101)->getId();
        $this->limitadorRecusando();
        $this->logarComTenant($client, $user, $tenant);

        foreach ([$soma, [$um]] as $ids) {
            $this->ler($client, (int) $pasta->getId(), $ids);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('limite_da_leitura', $this->json($client)['motivo']);
            self::assertSame('Os PDFs marcados passam de 100 páginas juntos, o limite para a IA ler de uma vez. Marque menos documentos e tente de novo.', $this->json($client)['mensagem']);
        }
        $this->semNadaNaFila((int) $pasta->getId());
    }

    #[TestDox('aceite do escritório anterior ao texto que cita os documentos → 409 aceite_desatualizado; o Índice dos mesmos documentos continua (202)')]
    public function testAceiteAntigo(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->envelhecerAceite($this->ligarIaNoTenant($tenant, $user));
        $pdf = $this->arquivo($tenant, $pasta, 'peticao.pdf');
        $this->logarComTenant($client, $user, $tenant);

        $this->ler($client, (int) $pasta->getId(), [(int) $pdf->getId()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('aceite_desatualizado', $this->json($client)['motivo']);
        self::assertStringContainsString('o administrador renova o aceite', $this->json($client)['mensagem']);
        $this->semNadaNaFila((int) $pasta->getId());

        $client->request('POST', "/pasta/{$pasta->getId()}/ia/comandos/indice_documentos/analises", ['_token' => 'TOKEN_inteligencia_agentes_' . $pasta->getId(), 'documentos' => [(int) $pdf->getId()]], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(202, 'o aceite antigo vale para o resto da IA');
    }

    #[TestDox('processo em segredo de justiça na pasta → 409 contexto_bloqueado: nada sai, nem o arquivo')]
    public function testSigilo(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->criarPastaComPublicacao($tenant);
        $processo->setNivelSigilo(1);
        $this->ligarIaNoTenant($tenant, $user);
        $pdf = $this->arquivo($tenant, $pasta, 'peticao.pdf');
        $this->logarComTenant($client, $user, $tenant);

        $this->ler($client, (int) $pasta->getId(), [(int) $pdf->getId()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('contexto_bloqueado', $this->json($client)['motivo']);
        $this->semNadaNaFila((int) $pasta->getId());
    }

    #[TestDox('documento de OUTRO escritório (ou de outra pasta) na seleção → 422 selecao_invalida sem confirmar o id; pasta de outro escritório → 404')]
    public function testTenantAlheio(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        [$outraPasta] = $this->criarPastaComPublicacao($tenant, '07099995520258070001');
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $this->ligarIaNoTenant($tenant, $user);
        $this->ligarIaNoTenant($tenantB, $userB);
        $meu = $this->arquivo($tenant, $pasta, 'meu.pdf');
        $alheio = $this->arquivo($tenantB, $pastaB, 'alheio.pdf');
        $daOutraPasta = $this->arquivo($tenant, $outraPasta, 'outra.pdf');
        $this->logarComTenant($client, $user, $tenant);

        foreach ([$alheio, $daOutraPasta] as $intruso) {
            $this->ler($client, (int) $pasta->getId(), [(int) $meu->getId(), (int) $intruso->getId()]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('selecao_invalida', $this->json($client)['motivo']);
            self::assertStringNotContainsString((string) $intruso->getId(), $this->json($client)['mensagem']);
        }

        $this->ler($client, (int) $pastaB->getId(), [(int) $alheio->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->semNadaNaFila((int) $pasta->getId());
        self::assertSame(0, $this->linhas((int) $pastaB->getId()));
    }

    #[TestDox('quem vê a pasta sem modules.inteligencia.view → 403; quem tem a aba Documentos oculta → 422 (a lista dele é vazia)')]
    public function testPermissaoEAbaOculta(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $admin);
        $pdf = $this->arquivo($tenant, $pasta, 'peticao.pdf');
        $semModulo = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view'], 'sem-ia');
        $this->logarComTenant($client, $semModulo, $tenant);

        $this->ler($client, (int) $pasta->getId(), [(int) $pdf->getId()]);
        self::assertResponseStatusCodeSame(403);

        $comum = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view'], 'com-ia');
        $this->ocultarAbaParaTodos($pasta, AbaDaPasta::Documentos, $admin);
        $this->logarComTenant($client, $comum, $tenant);

        $this->ler($client, (int) $pasta->getId(), [(int) $pdf->getId()]);
        self::assertResponseStatusCodeSame(422);
        $this->semNadaNaFila((int) $pasta->getId());
    }

    #[TestDox('documento na lixeira não está na lista da aba → 422, nada persistido')]
    public function testLixeira(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $pdf = $this->arquivo($tenant, $pasta, 'peticao.pdf');
        $pdf->marcarExcluido($user, new \DateTimeImmutable());
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $this->ler($client, (int) $pasta->getId(), [(int) $pdf->getId()]);

        self::assertResponseStatusCodeSame(422);
        $this->semNadaNaFila((int) $pasta->getId());
    }
}
