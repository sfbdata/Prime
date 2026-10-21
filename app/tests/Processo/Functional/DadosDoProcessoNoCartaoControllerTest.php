<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Pasta\Entity\Pasta;
use App\Processo\Controller\DadosDoProcessoNoCartaoController;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaFixturesPushDaPastaTrait;
use App\Tests\Pasta\Functional\CriaUsuarioComPermissoesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * P5b: "Editar dados do processo" no cartão (docs/specs/processo-cartao-edicao.md). O formulário vem
 * no cartão; salvar grava os valores manuais (o diferente do Datajud), devolve o cartão com o selo
 * "manual", as marcas e a linha de fonte, e deixa rastro no audit_log.
 */
#[CoversClass(DadosDoProcessoNoCartaoController::class)]
#[Group('pasta')]
final class DadosDoProcessoNoCartaoControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private const NUMERO = '07011345720258070007';
    private bool $csrfInstalado = false;

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        if (!$this->csrfInstalado) {
            $this->csrfInstalado = true;
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

        return $client;
    }

    /** @return array{Pasta, Processo} */
    private function pastaComProcesso(\App\Entity\Tenant\Tenant $tenant, string $numero = self::NUMERO): array
    {
        $pasta = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, $numero);
        $processo->setOrgaoJulgador('1ª Vara Cível de Brasília');
        $processo->setNivelSigilo(0);
        $processo->setDataDistribuicao(new \DateTime('2025-03-14'));
        $this->em()->flush();
        $this->vincular($pasta, $processo);

        return [$pasta, $processo];
    }

    /** @param array<string, string> $campos */
    private function salvar(KernelBrowser $client, Pasta $pasta, Processo $processo, array $campos, bool $comToken = true): void
    {
        if ($comToken) {
            $campos['_token'] = 'TOKEN_processo_dados_' . $processo->getId();
        }
        $client->request('POST', '/pasta/' . $pasta->getId() . '/processos/' . $processo->getId() . '/dados', $campos, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function cartao(string $html, Processo $processo): Crawler
    {
        return (new Crawler($html))->filter('article.ps-processo[data-processo-id="' . $processo->getId() . '"]');
    }

    private function gravado(Processo $processo): bool
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM processo WHERE id = :id AND dados_manuais_em IS NOT NULL', ['id' => $processo->getId()]) === 1;
    }

    #[TestDox('quem edita o processo recebe o formulário no cartão: dica, 11 campos com rótulos e placeholders, flags Sim/Não/Não informado, Cancelar e Salvar')]
    public function testFormularioNoCartao(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->pastaComProcesso($tenant);
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        $form = $crawler->filter('article.ps-processo > form.js-proc-edicao#psProcEdicao' . $processo->getId());

        self::assertCount(1, $form);
        self::assertNotNull($form->attr('hidden'));
        self::assertSame('/pasta/' . $pasta->getId() . '/processos/' . $processo->getId() . '/dados', $form->attr('action'));
        self::assertSame('Preencha apenas o que você confirmou nos autos. Campos vazios ficam como "Não informado".', trim($form->filter('.ps-processo-edicao-dica')->text()));
        self::assertSame('Ex.: 2ª Vara da Fazenda Pública do DF', $form->filter('input[name="orgao"]')->attr('placeholder'));
        self::assertSame('1ª VARA CÍVEL DE BRASÍLIA', $form->filter('input[name="orgao"]')->attr('value'), 'pré-preenchido com o valor efetivo');
        self::assertSame('Um assunto por linha', $form->filter('textarea[name="assuntos"]')->attr('placeholder'));
        self::assertSame(['Sim', 'Não', 'Não informado'], $form->filter('fieldset')->eq(0)->filter('label > span')->each(static fn (Crawler $n): string => trim($n->text())));
        self::assertSame('0', $form->filter('input[name="segredo"][checked]')->attr('value'), 'sigilo 0 do tribunal = "Não" marcado');
        self::assertSame(['Cancelar', 'Salvar'], $form->filter('.ps-processo-edicao-acoes > button')->each(static fn (Crawler $n): string => trim($n->text())));
    }

    #[TestDox('salvar: grava o diferente como manual, devolve o cartão com selo, marcas e a linha "Preenchido manualmente em", e deixa rastro no audit_log')]
    public function testSalvar(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->pastaComProcesso($tenant);
        $this->logarComTenant($client, $admin, $tenant);

        $this->salvar($client, $pasta, $processo, [
            'orgao' => '2ª Vara da Fazenda Pública do DF',
            'valorCausa' => 'R$ 12.500,00',
            'distribuicao' => '14/03/2025',
            'gratuita' => '1',
            'tutela' => '1',
            'segredo' => '0',
        ]);

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertTrue($dados['sucesso']);
        self::assertSame('Dados do processo salvos.', $dados['aviso']);
        $cartao = $this->cartao((string) $dados['html'], $processo);
        self::assertSame(['Justiça gratuita', 'Tutela/liminar'], $cartao->filter('.ps-processo-marcas > .ps-processo-marca')->each(static fn (Crawler $n): string => trim($n->text())));
        self::assertSame('2ª Vara da Fazenda Pública do DF', trim($cartao->filter('.ps-processo-resumo > [data-proc-campo="orgao"] > .ps-processo-valor')->text()));
        self::assertSame('R$ 12.500,00', trim($cartao->filter('.ps-processo-resumo > [data-proc-campo="valor"] > .ps-processo-valor')->text()));
        self::assertCount(1, $cartao->filter('.ps-processo-detalhes > [data-proc-campo="orgao"] > .ps-rotulo > .ps-processo-manual[title="Preenchido manualmente"]'));
        self::assertCount(0, $cartao->filter('.ps-processo-detalhes > [data-proc-campo="segredo"] .ps-processo-manual'), '"Não" igual ao tribunal não é manual');
        self::assertStringStartsWith('Preenchido manualmente em ', trim($cartao->filter('.ps-processo-fonte')->text()));
        self::assertStringEndsWith('· integração com o PJe não configurada', trim($cartao->filter('.ps-processo-fonte')->text()));

        self::assertTrue($this->gravado($processo));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne(
            "SELECT count(*) FROM processo WHERE id = :id AND orgao_manual = '2ª Vara da Fazenda Pública do DF' AND valor_causa_manual = 12500 AND gratuita_manual AND distribuicao_manual IS NULL AND segredo_manual IS NULL AND orgao_julgador = '1ª VARA CÍVEL DE BRASÍLIA'",
            ['id' => $processo->getId()],
        ), 'o Datajud fica intacto; a data igual não vira manual');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne(
            "SELECT count(*) FROM audit_log WHERE entity_class = :c AND entity_id = :id AND action = 'update' AND actor_user_id = :u",
            ['c' => Processo::class, 'id' => (string) $processo->getId(), 'u' => $admin->getId()],
        ));
    }

    #[TestDox('processo em mais de uma pasta: o aviso diz que vale para todas')]
    public function testAvisoComMaisDeUmaPasta(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->pastaComProcesso($tenant);
        $this->vincular($this->criarPasta($tenant), $processo);
        $this->logarComTenant($client, $admin, $tenant);

        $this->salvar($client, $pasta, $processo, ['jurisdicao' => 'Brasília']);

        self::assertSame('Dados do processo salvos. Valem para todas as pastas com este processo.', $this->json($client)['aviso']);
    }

    #[TestDox('recusas: data inválida e "Não" contra o sigilo do tribunal → 422 com a mensagem; nada é gravado')]
    public function testValidacao(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->pastaComProcesso($tenant);
        $processo->setNivelSigilo(5);
        $this->em()->flush();
        $this->logarComTenant($client, $admin, $tenant);

        $this->salvar($client, $pasta, $processo, ['distribuicao' => '31/02/2025']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Data inválida. Use dd/mm/aaaa.', $this->json($client)['erro']);

        $this->salvar($client, $pasta, $processo, ['segredo' => '0']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('O tribunal informou segredo de justiça neste processo. Mantenha "Sim" ou "Não informado".', $this->json($client)['erro']);

        self::assertFalse($this->gravado($processo));
    }

    #[TestDox('guardas: sem CSRF 403; sem EDIT no processo 403 (e sem o formulário); outro escritório e processo de fora da pasta 404; nada é gravado')]
    public function testGuardas(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->pastaComProcesso($tenant);
        [, $outroProcesso] = $this->pastaComProcesso($tenant, '07099999999999999999');
        [$adminB, $tenantB] = $this->criarAdmin();
        [$pastaB, $processoB] = $this->pastaComProcesso($tenantB);
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit', 'resources.processo.view']);

        $this->logarComTenant($client, $admin, $tenant);
        $this->salvar($client, $pasta, $processo, ['jurisdicao' => 'X'], comToken: false);
        self::assertResponseStatusCodeSame(403);
        $this->salvar($client, $pasta, $outroProcesso, ['jurisdicao' => 'X']);
        self::assertResponseStatusCodeSame(404);
        $this->salvar($client, $pastaB, $processoB, ['jurisdicao' => 'X']);
        self::assertResponseStatusCodeSame(404);
        $this->salvar($client, $pasta, $processoB, ['jurisdicao' => 'X']);
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($client, $leitor, $tenant);
        $this->salvar($client, $pasta, $processo, ['jurisdicao' => 'X']);
        self::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertCount(0, $crawler->filter('form.js-proc-edicao'));

        self::assertFalse($this->gravado($processo));
        self::assertFalse($this->gravado($outroProcesso));
        self::assertFalse($this->gravado($processoB));
        unset($adminB);
    }

    #[TestDox('lápide: o POST é recusado e o formulário não aparece')]
    public function testLapide(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->pastaComProcesso($tenant);
        $pasta->marcarExcluida($admin, new \DateTimeImmutable());
        $this->em()->flush();
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertCount(0, $crawler->filter('form.js-proc-edicao'));

        $this->salvar($client, $pasta, $processo, ['jurisdicao' => 'X']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->gravado($processo));
    }
}
