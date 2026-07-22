<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\PessoaController;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEmail;
use App\Cobranca\Entity\PessoaEndereco;
use App\Cobranca\Entity\PessoaTelefone;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Ficha da pessoa (spec de qualificação §7, Ponto #1-B): qualificação (campos únicos) + as 3 listas
 * (endereço/telefone/e-mail) com histórico por adição e um `atual` por lista. Cobre gate de capacidade
 * `resources.cobranca.gerenciar`, CSRF, anti-IDOR (404 cross-tenant) e os happy paths.
 */
#[CoversClass(PessoaController::class)]
final class PessoaFichaControllerTest extends CobrancaWebTestCase
{
    #[TestDox('GET ficha da pessoa: 200 e mostra as seções de qualificação e listas')]
    public function testShowHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'Fulano da Ficha'])->_real();

        $client->request('GET', '/cobrancas/pessoas/' . $pessoa->getId());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Fulano da Ficha', $html);
        self::assertStringContainsString('Qualificação', $html);
        self::assertStringContainsString('Endereços', $html);
        self::assertStringContainsString('Telefones', $html);
        self::assertStringContainsString('E-mails', $html);
    }

    #[TestDox('IDOR: ficha de pessoa de OUTRO tenant → 404')]
    public function testShowCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $pessoaAlheia = PessoaFactory::createOne(['tenant' => $this->tenantAvulso()])->_real();

        $client->request('GET', '/cobrancas/pessoas/' . $pessoaAlheia->getId());

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Sem capacidade: negado (redirect)')]
    public function testShowSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant])->_real();

        $client->request('GET', '/cobrancas/pessoas/' . $pessoa->getId());

        self::assertResponseRedirects();
    }

    #[TestDox('Adicionar endereço: primeiro nasce atual e aparece na ficha')]
    public function testAdicionarEnderecoHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant])->_real();
        $pessoaId = (int) $pessoa->getId();

        $crawler = $client->request('GET', '/cobrancas/pessoas/' . $pessoaId);
        $token = $this->tokenDoFormulario($crawler, 'adicionar_endereco');

        $client->request('POST', '/cobrancas/pessoas/' . $pessoaId . '/enderecos', [
            'adicionar_endereco' => [
                'logradouro' => 'Rua das Flores',
                'numero' => '123',
                'complemento' => 'Apto 45',
                'bairro' => 'Centro',
                'cidade' => 'São Paulo',
                'uf' => 'SP',
                'cep' => '01000-000',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/pessoas/' . $pessoaId);
        $client->followRedirect();
        self::assertStringContainsString('Rua das Flores, 123', (string) $client->getResponse()->getContent());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $enderecos = $em->getRepository(PessoaEndereco::class)->findBy(['pessoa' => $pessoaId]);
        self::assertCount(1, $enderecos);
        self::assertTrue($enderecos[0]->isAtual());
    }

    #[TestDox('Adicionar telefone: primeiro nasce atual e aparece na ficha')]
    public function testAdicionarTelefoneHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant])->_real();
        $pessoaId = (int) $pessoa->getId();

        $crawler = $client->request('GET', '/cobrancas/pessoas/' . $pessoaId);
        $token = $this->tokenDoFormulario($crawler, 'adicionar_telefone');

        $client->request('POST', '/cobrancas/pessoas/' . $pessoaId . '/telefones', [
            'adicionar_telefone' => ['numero' => '(41) 99999-0000', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/pessoas/' . $pessoaId);
        $client->followRedirect();
        self::assertStringContainsString('(41) 99999-0000', (string) $client->getResponse()->getContent());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $telefones = $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $pessoaId]);
        self::assertCount(1, $telefones);
        self::assertTrue($telefones[0]->isAtual());
    }

    #[TestDox('Adicionar e-mail: primeiro nasce atual e aparece na ficha')]
    public function testAdicionarEmailHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant])->_real();
        $pessoaId = (int) $pessoa->getId();

        $crawler = $client->request('GET', '/cobrancas/pessoas/' . $pessoaId);
        $token = $this->tokenDoFormulario($crawler, 'adicionar_email');

        $client->request('POST', '/cobrancas/pessoas/' . $pessoaId . '/emails', [
            'adicionar_email' => ['email' => 'contato@example.com', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/pessoas/' . $pessoaId);
        $client->followRedirect();
        self::assertStringContainsString('contato@example.com', (string) $client->getResponse()->getContent());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $emails = $em->getRepository(PessoaEmail::class)->findBy(['pessoa' => $pessoaId]);
        self::assertCount(1, $emails);
        self::assertTrue($emails[0]->isAtual());
    }

    #[TestDox('Marcar endereço como atual: troca a flag e preserva o anterior na lista')]
    public function testMarcarEnderecoAtualHappy(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant])->_real();

        $primeiro = $this->criarEndereco($tenant, $pessoa, $usuario, 'Rua Primeira', true);
        $segundo = $this->criarEndereco($tenant, $pessoa, $usuario, 'Rua Segunda', false);
        $pessoaId = (int) $pessoa->getId();

        $crawler = $client->request('GET', '/cobrancas/pessoas/' . $pessoaId);
        $acaoUrl = '/cobrancas/pessoas/' . $pessoaId . '/enderecos/' . $segundo->getId() . '/atual';
        $token = (string) $crawler->filter('form[action="' . $acaoUrl . '"] input[name="_token"]')->attr('value');

        $client->request('POST', $acaoUrl, ['_token' => $token]);

        self::assertResponseRedirects('/cobrancas/pessoas/' . $pessoaId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $primeiroFresh = $em->find(PessoaEndereco::class, $primeiro->getId());
        $segundoFresh = $em->find(PessoaEndereco::class, $segundo->getId());
        self::assertFalse($primeiroFresh->isAtual(), 'o anterior deixa de ser atual');
        self::assertTrue($segundoFresh->isAtual(), 'o novo passa a ser atual');
        // Nunca perde histórico: o item antigo continua existindo.
        self::assertNotNull($em->find(PessoaEndereco::class, $primeiro->getId()));
    }

    #[TestDox('Editar qualificação: persiste os campos únicos e NÃO altera email/telefone')]
    public function testEditarQualificacaoHappyNaoAlteraEmailTelefone(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'Nome Original'])->_real();
        $pessoa->setEmail('original@example.com');
        $pessoa->setTelefone('(11) 90000-0000');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $pessoaId = (int) $pessoa->getId();

        $crawler = $client->request('GET', '/cobrancas/pessoas/' . $pessoaId);
        $token = $this->tokenDoFormulario($crawler, 'editar_qualificacao_pessoa');

        $client->request('POST', '/cobrancas/pessoas/' . $pessoaId . '/qualificacao', [
            'editar_qualificacao_pessoa' => [
                'nome' => 'Nome Atualizado',
                'cpf' => '123.456.789-01',
                'cnpj' => '',
                'dataNascimento' => '1990-05-20',
                'estadoCivil' => 'casado',
                'profissao' => 'Engenheiro',
                'rg' => '1234567',
                'orgaoEmissorRg' => 'SSP/CE',
                'observacao' => 'Observação nova',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/pessoas/' . $pessoaId);

        $em->clear();
        $fresh = $em->find(Pessoa::class, $pessoaId);
        self::assertSame('Nome Atualizado', $fresh->getNome());
        self::assertSame('123.456.789-01', $fresh->getCpf());
        self::assertNull($fresh->getCnpj());
        self::assertSame('1990-05-20', $fresh->getDataNascimento()->format('Y-m-d'));
        self::assertSame('Engenheiro', $fresh->getProfissao());
        self::assertSame('1234567', $fresh->getRg());
        self::assertSame('SSP/CE', $fresh->getOrgaoEmissorRg());
        self::assertSame('Observação nova', $fresh->getObservacao());
        // O bridge SPEC §6 não foi tocado: email/telefone continuam os mesmos de antes.
        self::assertSame('original@example.com', $fresh->getEmail());
        self::assertSame('(11) 90000-0000', $fresh->getTelefone());
    }

    #[TestDox('IDOR: adicionar endereço em pessoa de OUTRO tenant → 404')]
    public function testAdicionarEnderecoCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $pessoaAlheia = PessoaFactory::createOne(['tenant' => $this->tenantAvulso()])->_real();

        $client->request('POST', '/cobrancas/pessoas/' . $pessoaAlheia->getId() . '/enderecos', [
            'adicionar_endereco' => [
                'logradouro' => 'X', 'numero' => '1', 'bairro' => 'X', 'cidade' => 'X', 'uf' => 'SP', 'cep' => '00000-000',
                '_token' => 'irrelevante',
            ],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('IDOR: editar qualificação de pessoa de OUTRO tenant → 404')]
    public function testEditarQualificacaoCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $pessoaAlheia = PessoaFactory::createOne(['tenant' => $this->tenantAvulso()])->_real();

        $client->request('POST', '/cobrancas/pessoas/' . $pessoaAlheia->getId() . '/qualificacao', [
            'editar_qualificacao_pessoa' => ['nome' => 'X', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('IDOR: marcar endereço atual em pessoa de OUTRO tenant → 404')]
    public function testMarcarEnderecoAtualCrossTenant404(): void
    {
        $client = static::createClient();
        [$usuario] = $this->criarAdminLogado($client);
        $tenantAlheio = $this->tenantAvulso();
        $pessoaAlheia = PessoaFactory::createOne(['tenant' => $tenantAlheio])->_real();
        $endereco = $this->criarEndereco($tenantAlheio, $pessoaAlheia, $usuario, 'Rua X', true);

        $client->request('POST', '/cobrancas/pessoas/' . $pessoaAlheia->getId() . '/enderecos/' . $endereco->getId() . '/atual', [
            '_token' => 'irrelevante',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Sem capacidade: POST adicionar endereço é negado, nada é criado')]
    public function testAdicionarEnderecoSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant])->_real();
        $pessoaId = (int) $pessoa->getId();

        $client->request('POST', '/cobrancas/pessoas/' . $pessoaId . '/enderecos', [
            'adicionar_endereco' => [
                'logradouro' => 'X', 'numero' => '1', 'bairro' => 'X', 'cidade' => 'X', 'uf' => 'SP', 'cep' => '00000-000',
                '_token' => 'irrelevante',
            ],
        ]);

        self::assertResponseRedirects();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(PessoaEndereco::class)->findBy(['pessoa' => $pessoaId]));
    }

    /**
     * Semeia um endereço direto no banco (fora do UseCase — só apoio de teste), pra testar a marcação
     * de "atual" sem depender do fluxo de "adicionar" no mesmo teste.
     */
    private function criarEndereco(Tenant $tenant, Pessoa $pessoa, User $criadoPor, string $logradouro, bool $atual): PessoaEndereco
    {
        $endereco = new PessoaEndereco();
        $endereco->setTenant($tenant);
        $endereco->setPessoa($pessoa);
        $endereco->setLogradouro($logradouro);
        $endereco->setNumero('1');
        $endereco->setBairro('Centro');
        $endereco->setCidade('São Paulo');
        $endereco->setUf('SP');
        $endereco->setCep('01000-000');
        $endereco->setAtual($atual);
        $endereco->setCriadoPor($criadoPor);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($endereco);
        $em->flush();

        return $endereco;
    }
}
