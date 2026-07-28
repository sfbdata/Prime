<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\PessoaController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEmail;
use App\Cobranca\Entity\PessoaEndereco;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Enum\EstadoCivil;
use App\Cobranca\Enum\TipoTelefone;
use App\Cobranca\Enum\TipoVinculo;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Cobranca\VinculoPessoaObjetoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Modal único de pessoa na aba Responsáveis (spec `docs/specs/cobranca-modal-unico-pessoa.md`):
 * cadastrar e editar passaram a ser a MESMA tela, com as abas Qualificação · Endereços · Telefones e
 * E-mails.
 *
 * O que este arquivo trava — e é onde a tela erraria em silêncio:
 * - o fragmento do modal existe, tem as três abas e exige a MESMA capacidade da página da ficha
 *   (sem isso, o modal seria um caminho paralelo para PII protegida);
 * - pessoa de outro escritório é 404 no fragmento, como é no `show`;
 * - cada bloco grava SOZINHO por AJAX e a resposta traz o fragmento já atualizado — é o que sustenta
 *   "cada bloco grava sozinho" sem inventar gravação em lote;
 * - a MESMA rota, sem AJAX, continua respondendo com o PRG de sempre para a página da ficha: a página
 *   não pode ter sido quebrada pelo modal;
 * - os três gatilhos da aba (Editar da cobrada, Editar do vinculado, badge) apontam para o modal.
 */
#[CoversClass(PessoaController::class)]
final class FichaPessoaModalControllerTest extends CobrancaWebTestCase
{
    // ───────────────────────────── O fragmento ─────────────────────────────

    #[TestDox('O fragmento do modal traz as três abas com os quatro blocos da ficha')]
    public function testFragmentoTrazAsTresAbas(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pessoa = $this->cobradaVinculada($tenant, $caso);
        $this->telefone($tenant, $pessoa, '(21) 99999-1111', atual: true);
        $this->email($tenant, $pessoa, 'devedor@example.com', atual: true);
        $this->endereco($tenant, $pessoa, 'Rua das Acácias', '100', atual: true);

        $fragmento = $this->abrirFragmento($client, $pessoa);

        // Três abas, na ordem pedida pelo dono — telefones e e-mails na MESMA.
        $abas = $fragmento->filter('.cob-ficha-abas .nav-link');
        self::assertCount(3, $abas);
        self::assertSame(['qualificacao', 'enderecos', 'contatos'], $abas->extract(['data-aba']));

        // Cada bloco no seu painel: o form da qualificação, a lista de endereços, e as DUAS listas de
        // contato juntas. É o contrato do partial — se um include cair, some daqui.
        self::assertCount(1, $fragmento->filter('.tab-pane form[action$="/qualificacao"]'));
        self::assertCount(1, $fragmento->filter('.tab-pane form[action$="/enderecos"]'));
        self::assertCount(1, $fragmento->filter('.tab-pane form[action$="/telefones"]'));
        self::assertCount(1, $fragmento->filter('.tab-pane form[action$="/emails"]'));

        // Fragmento é fragmento: não pode vir a página inteira embrulhada nele.
        self::assertStringNotContainsString('<html', $client->getResponse()->getContent() ?: '');
    }

    #[TestDox('O fragmento mostra o dado gravado da pessoa, não um formulário vazio')]
    public function testFragmentoVemPreenchidoComOQueEstaGravado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pessoa = $this->cobradaVinculada($tenant, $caso);
        $pessoa->setNome('Devedora Qualificada');
        $pessoa->setCpf('123.456.789-00');
        $pessoa->setEstadoCivil(EstadoCivil::Casado);
        $this->salvar();
        $this->telefone($tenant, $pessoa, '(21) 98888-2222', atual: true);

        $fragmento = $this->abrirFragmento($client, $pessoa);

        self::assertSame('Devedora Qualificada', $fragmento->filter('#editar_qualificacao_pessoa_nome')->attr('value'));
        self::assertSame('123.456.789-00', $fragmento->filter('#editar_qualificacao_pessoa_cpf')->attr('value'));
        self::assertStringContainsString('(21) 98888-2222', $fragmento->text());
    }

    #[TestDox('O fragmento exige a capacidade de gerenciar — é a mesma PII que a página da ficha protege')]
    public function testFragmentoExigeCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pessoa = $this->cobradaVinculada($tenant, $caso);

        $client->request('GET', '/cobrancas/pessoas/' . $pessoa->getId() . '/ficha-modal');

        // `semAcesso()` do módulo: flash + volta para a home. Mesma recusa da página da ficha.
        self::assertResponseRedirects('/');
    }

    #[TestDox('Pessoa de OUTRO escritório é 404 no fragmento (anti-IDOR)')]
    public function testFragmentoDePessoaDeOutroTenantE404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $alheio = $this->tenantAvulso();
        $pessoaAlheia = PessoaFactory::createOne(['tenant' => $alheio])->_real();

        $client->request('GET', '/cobrancas/pessoas/' . $pessoaAlheia->getId() . '/ficha-modal');

        self::assertResponseStatusCodeSame(404);
    }

    // ───────────────────────────── Cada bloco grava sozinho ─────────────────────────────

    #[TestDox('Salvar a qualificação por AJAX grava e devolve o fragmento já atualizado')]
    public function testQualificacaoPorAjaxGravaEDevolveOFragmento(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pessoa = $this->cobradaVinculada($tenant, $caso);
        $fragmento = $this->abrirFragmento($client, $pessoa);
        $token = $this->tokenDoFormulario($fragmento, 'editar_qualificacao_pessoa');

        $this->postAjax($client, '/cobrancas/pessoas/' . $pessoa->getId() . '/qualificacao', [
            'editar_qualificacao_pessoa' => [
                'nome' => 'Nome Corrigido',
                'cpf' => '111.222.333-44',
                'profissao' => 'Marceneira',
                '_token' => $token,
            ],
        ]);

        $dados = $this->respostaAjax($client);
        self::assertTrue($dados['ok']);
        self::assertSame('Qualificação atualizada.', $dados['mensagem']);
        // O fragmento devolvido é o ESTADO NOVO: é ele que o JS põe no lugar. Voltar o antigo deixaria
        // o modal mostrando o que acabou de ser corrigido.
        self::assertStringContainsString('Nome Corrigido', $dados['html']);

        $gravada = $this->reler($pessoa);
        self::assertSame('Nome Corrigido', $gravada->getNome());
        self::assertSame('Marceneira', $gravada->getProfissao());
    }

    #[TestDox('Adicionar endereço e e-mail por AJAX entra na lista, já como atual')]
    public function testAdicionarEnderecoEEmailPorAjax(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pessoa = $this->cobradaVinculada($tenant, $caso);
        $fragmento = $this->abrirFragmento($client, $pessoa);

        $this->postAjax($client, '/cobrancas/pessoas/' . $pessoa->getId() . '/enderecos', [
            'adicionar_endereco' => [
                'logradouro' => 'Rua Nova',
                'numero' => '42',
                'bairro' => 'Centro',
                'cidade' => 'Niterói',
                'uf' => 'RJ',
                'cep' => '24000-000',
                '_token' => $this->tokenDoFormulario($fragmento, 'adicionar_endereco'),
            ],
        ]);

        $dados = $this->respostaAjax($client);
        self::assertTrue($dados['ok']);
        self::assertStringContainsString('Rua Nova', $dados['html']);
        self::assertStringContainsString('Atual', $dados['html'], 'o primeiro endereço nasce atual');

        $this->postAjax($client, '/cobrancas/pessoas/' . $pessoa->getId() . '/emails', [
            'adicionar_email' => [
                'email' => 'novo@example.com',
                '_token' => $this->tokenDoFormulario($fragmento, 'adicionar_email'),
            ],
        ]);

        $dados = $this->respostaAjax($client);
        self::assertTrue($dados['ok']);
        self::assertStringContainsString('novo@example.com', $dados['html']);
    }

    #[TestDox('Recusa por AJAX não grava, devolve a mensagem e mantém o fragmento')]
    public function testRecusaPorAjaxDevolveMensagemENaoGrava(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pessoa = $this->cobradaVinculada($tenant, $caso);
        $fragmento = $this->abrirFragmento($client, $pessoa);

        // Nome em branco: a qualificação exige nome (`EditarQualificacaoPessoaInput`).
        $this->postAjax($client, '/cobrancas/pessoas/' . $pessoa->getId() . '/qualificacao', [
            'editar_qualificacao_pessoa' => [
                'nome' => '',
                '_token' => $this->tokenDoFormulario($fragmento, 'editar_qualificacao_pessoa'),
            ],
        ]);

        $dados = $this->respostaAjax($client);
        self::assertFalse($dados['ok'], 'nome em branco não pode passar');
        self::assertSame('Informe o nome da pessoa.', $dados['mensagem']);
        // Mesmo na recusa o fragmento volta: é o que o JS repõe, e ele tem de mostrar o estado REAL.
        self::assertNotSame('', $dados['html']);
    }

    #[TestDox('A MESMA rota sem AJAX continua no PRG de sempre para a página da ficha')]
    public function testSemAjaxAsRotasSeguemNoPrgDaFicha(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pessoa = $this->cobradaVinculada($tenant, $caso);
        $crawler = $client->request('GET', '/cobrancas/pessoas/' . $pessoa->getId());
        self::assertResponseIsSuccessful();

        $client->request('POST', '/cobrancas/pessoas/' . $pessoa->getId() . '/qualificacao', [
            'editar_qualificacao_pessoa' => [
                'nome' => 'Pela Página',
                '_token' => $this->tokenDoFormulario($crawler, 'editar_qualificacao_pessoa'),
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/pessoas/' . $pessoa->getId());
        self::assertSame('Pela Página', $this->reler($pessoa)->getNome());
    }

    // ───────────────────────────── Os gatilhos na aba ─────────────────────────────

    #[TestDox('Os três gatilhos da aba abrem o modal e carregam o fragmento da pessoa certa')]
    public function testGatilhosDaAbaApontamParaOModal(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        // Ficha incompleta de propósito: é o que faz o badge aparecer.
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $outra = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'Maria Fiadora'])->_real();
        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant, 'objeto' => $caso->getObjeto(), 'pessoa' => $outra, 'tipoVinculo' => TipoVinculo::Representante,
        ]);

        $aba = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId())->filter('#tab-responsaveis');

        // Nenhum LINK para a página da ficha sobrou na aba (decisão do dono: o modal substitui a página).
        self::assertCount(0, $aba->filter('a[href="/cobrancas/pessoas/' . $cobrada->getId() . '"]'));

        $gatilhos = $aba->filter('[data-bs-target="#modalFichaPessoa"][data-modo="edicao"]');
        self::assertCount(3, $gatilhos, 'Editar da cobrada + Editar do vinculado + badge');

        $urls = $gatilhos->extract(['data-url']);
        self::assertContains('/cobrancas/pessoas/' . $cobrada->getId() . '/ficha-modal', $urls);
        self::assertContains('/cobrancas/pessoas/' . $outra->getId() . '/ficha-modal', $urls, 'o Editar do accordion abre a ficha DAQUELA pessoa');
    }

    #[TestDox('Cadastrar e editar são o mesmo modal: um em modo cadastro, outro em modo edição')]
    public function testCadastrarEEditarCompartilhamOModal(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->cobradaVinculada($tenant, $caso);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertCount(1, $crawler->filter('#modalFichaPessoa'), 'um modal só para as duas ações');
        self::assertCount(1, $crawler->filter('#modalFichaPessoa [data-modo="cadastro"]'));
        self::assertCount(1, $crawler->filter('#modalFichaPessoa [data-modo="edicao"]'));
        // As mesmas três abas do modo edição, também no cadastro.
        self::assertSame(
            ['qualificacao', 'enderecos', 'contatos'],
            $crawler->filter('#modalFichaPessoa [data-modo="cadastro"] .nav-link')->extract(['data-aba']),
        );
        // O gatilho do dropdown abre ESTE modal, em modo cadastro.
        self::assertCount(1, $crawler->filter('#tab-responsaveis [data-bs-target="#modalFichaPessoa"][data-modo="cadastro"]'));
    }

    #[TestDox('O fragmento do modal não repete NENHUM id da página do objeto (o `for` do label marcaria o campo errado)')]
    public function testFragmentoNaoColideDeIdComAPagina(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pessoa = $this->cobradaVinculada($tenant, $caso);
        $this->telefone($tenant, $pessoa, '(21) 99999-1111', atual: true);

        // A página do objeto renderiza o bloco §2.3 de telefones com o MESMO `AdicionarTelefoneType`
        // que o fragmento do modal usa. O Symfony deriva o id do NOME do form, então sem prefixo os
        // dois saem iguais — e o `for` do label do modal passa a marcar o rádio da aba, calado.
        $pagina = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $idsDaPagina = array_filter($pagina->filter('[id]')->extract(['id']));
        $idsDoFragmento = array_filter($this->abrirFragmento($client, $pessoa)->filter('[id]')->extract(['id']));

        self::assertNotEmpty($idsDoFragmento);
        self::assertSame(
            [],
            array_values(array_intersect($idsDaPagina, $idsDoFragmento)),
            'o fragmento é injetado DENTRO desta página: id repetido faz o label apontar para o campo do outro bloco',
        );
    }

    // ── apoio ────────────────────────────────────────────────────────────────────────────────────

    private function abrirFragmento(KernelBrowser $client, Pessoa $pessoa): Crawler
    {
        $crawler = $client->request(
            'GET',
            '/cobrancas/pessoas/' . $pessoa->getId() . '/ficha-modal',
            server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @param array<string, mixed> $payload */
    private function postAjax(KernelBrowser $client, string $url, array $payload): void
    {
        $client->request('POST', $url, $payload, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    /** @return array{ok: bool, mensagem: string, html: string} */
    private function respostaAjax(KernelBrowser $client): array
    {
        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($dados);

        return $dados;
    }

    private function cobradaVinculada(Tenant $tenant, CasoCobranca $caso): Pessoa
    {
        $pessoa = $caso->getPessoaCobradaAtual();
        self::assertNotNull($pessoa);
        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant, 'objeto' => $caso->getObjeto(), 'pessoa' => $pessoa, 'tipoVinculo' => TipoVinculo::Proprietario,
        ]);

        return $pessoa;
    }

    private function telefone(Tenant $tenant, Pessoa $pessoa, string $numero, bool $atual): PessoaTelefone
    {
        $telefone = new PessoaTelefone();
        $telefone->setTenant($tenant);
        $telefone->setPessoa($pessoa);
        $telefone->setNumero($numero);
        $telefone->setTipo(TipoTelefone::Fixo);
        $telefone->setAtual($atual);
        $this->em()->persist($telefone);
        $this->salvar();

        return $telefone;
    }

    private function email(Tenant $tenant, Pessoa $pessoa, string $endereco, bool $atual): PessoaEmail
    {
        $email = new PessoaEmail();
        $email->setTenant($tenant);
        $email->setPessoa($pessoa);
        $email->setEmail($endereco);
        $email->setAtual($atual);
        $this->em()->persist($email);
        $this->salvar();

        return $email;
    }

    private function endereco(Tenant $tenant, Pessoa $pessoa, string $logradouro, string $numero, bool $atual): PessoaEndereco
    {
        $endereco = new PessoaEndereco();
        $endereco->setTenant($tenant);
        $endereco->setPessoa($pessoa);
        $endereco->setLogradouro($logradouro);
        $endereco->setNumero($numero);
        $endereco->setBairro('Centro');
        $endereco->setCidade('Rio de Janeiro');
        $endereco->setUf('RJ');
        $endereco->setCep('20000-000');
        $endereco->setAtual($atual);
        $this->em()->persist($endereco);
        $this->salvar();

        return $endereco;
    }

    /** Relê a pessoa do banco (o EM é limpo antes): sem isso o teste conferiria o objeto em memória. */
    private function reler(Pessoa $pessoa): Pessoa
    {
        $id = (int) $pessoa->getId();
        $this->em()->clear();
        $fresca = $this->em()->getRepository(Pessoa::class)->find($id);
        self::assertInstanceOf(Pessoa::class, $fresca);

        return $fresca;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function salvar(): void
    {
        $this->em()->flush();
    }
}
