<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/**
 * Meu Escritório (desenho 13, docs/specs/meu-escritorio.md): o template renderizado com o `escritorio` montado
 * à mão (o MeuEscritorioOutput é do backend). Layout, rotas e assets entram como dublês: o que se prova aqui é a
 * marcação da tela, o arranjo por FILHO DIRETO e o que o desenho não tem não aparecer. Sem navegador.
 */
final class MeuEscritorioTelaTest extends KernelTestCase
{
    private const TEMPLATE = __DIR__ . '/../../../templates/tenant/meu_escritorio.html.twig';

    /** @return array<string, mixed> */
    private function escritorio(array $sobrescreve = []): array
    {
        return array_merge([
            'codigo' => 'BJ-7K2M-9QXA-4TRW',
            'nome' => 'Escritório Alfa Advocacia',
            'doc' => '11222333000181',
            'docFormatado' => '11.222.333/0001-81',
            'oab' => 'OAB/DF 1234',
            'email' => 'contato@alfa.com.br',
            'telefone' => '(61) 99999-0000',
            'cep' => '70040-010',
            'endereco' => 'Esplanada dos Ministérios, 1',
            'cidadeUf' => 'Brasília/DF',
            'criadoEm' => new \DateTimeImmutable('2026-04-07 19:16:03'),
            'ativo' => true,
            'statusDica' => 'Plano Free · 0,4 de 10 GB usados (4%)',
            'alteradoPor' => 'Farlei Rocha',
            'alteradoEm' => new \DateTimeImmutable('2026-10-28 14:05:59'),
            'temLogo' => true,
            'podeEditar' => true,
            'podeExcluir' => true,
            'driveConectado' => false,
        ], $sobrescreve);
    }

    private function renderizar(array $escritorio, array $extra = []): string
    {
        self::bootKernel();
        $c = static::getContainer();

        $twig = new Environment(new ArrayLoader([
            'base.html.twig' => '{% block title %}{% endblock %}{% block stylesheets %}{% endblock %}{% block body %}{% endblock %}{% block javascripts %}{% endblock %}',
            'tenant/meu_escritorio.html.twig' => (string) file_get_contents(self::TEMPLATE),
        ]), ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($c->get('translator')));
        $twig->addFunction(new TwigFunction('path', static fn (string $rota, array $p = []): string => '/' . $rota . (isset($p['tenantId']) ? '/' . $p['tenantId'] : '')));
        $twig->addFunction(new TwigFunction('asset', static fn (string $a): string => '/' . $a));
        $twig->addFunction(new TwigFunction('catalogo_js', static fn (string ...$p): string => '', ['is_safe' => ['html']]));

        return $twig->render('tenant/meu_escritorio.html.twig', array_merge([
            'escritorio' => $escritorio,
            'tenant' => (object) ['id' => 7],
            'csrf' => 'token-teste',
            'urlDriveConectar' => '/sync/drive/conexao/iniciar',
            'urlDriveGerenciar' => '/sync/drive/conexao',
        ], $extra));
    }

    private function pagina(array $escritorio, array $extra = []): Crawler
    {
        return new Crawler($this->renderizar($escritorio, $extra));
    }

    #[TestDox('arranjo por filho direto: 3 cartões em .me-pagina, grade de linhas e faixa da logo no primeiro')]
    public function testArranjoPorFilhoDireto(): void
    {
        $p = $this->pagina($this->escritorio());

        self::assertCount(3, $p->filter('.me-tela > .me-pagina > .me-cartao'));
        self::assertCount(1, $p->filter('.me-pagina > .me-cartao--info > div > .me-logo'));
        self::assertCount(9, $p->filter('.me-pagina > .me-cartao--info .me-linhas > .me-linha'));
        self::assertCount(1, $p->filter('.me-pagina > .me-cartao--info > .me-rodape'));
        self::assertCount(1, $p->filter('.me-pagina > .me-cartao--backup > .me-backup'));
        self::assertSame(
            ['Informações do Escritório', 'Backup de arquivos', 'Excluir conta'],
            $p->filter('.me-pagina > .me-cartao')->each(static fn (Crawler $c): string => (string) $c->attr('data-screen-label')),
        );
        // Só o primeiro cartão tem a animação de entrada do desenho (meIn); o título do cartão é a faixa azul.
        self::assertCount(3, $p->filter('.me-pagina > .me-cartao > .me-cartao-titulo'));
    }

    #[TestDox('linhas na ordem do desenho, com "Não informado" no vazio e copiar só onde há valor')]
    public function testLinhasNaOrdemDoDesenho(): void
    {
        $p = $this->pagina($this->escritorio(['oab' => '', 'telefone' => null]));

        self::assertSame(
            ['Código da conta', 'Nome', 'CNPJ / CPF', 'Registro OAB', 'E-mail', 'Telefone', 'Endereço', 'Criado em', 'Status'],
            $p->filter('.me-linhas > .me-linha > .me-linha-rotulo')->each(static fn (Crawler $c): string => $c->text()),
        );
        self::assertSame('BJ-7K2M-9QXA-4TRW', $p->filter('[data-me-linha="codigo"] [data-me-valor]')->text());
        self::assertStringContainsString('me-valor--mono', (string) $p->filter('[data-me-linha="codigo"] [data-me-valor]')->attr('class'));
        self::assertSame('11.222.333/0001-81', $p->filter('[data-me-linha="doc"] [data-me-valor]')->text());
        self::assertSame('Esplanada dos Ministérios, 1 · Brasília/DF · CEP 70040-010', $p->filter('[data-me-linha="endereco"] [data-me-valor]')->text());
        self::assertSame('07/04/2026 19:16:03', $p->filter('[data-me-linha="criado_em"] [data-me-valor]')->text());
        self::assertSame('Não informado', $p->filter('[data-me-linha="oab"] [data-me-valor]')->text());
        self::assertSame('Não informado', $p->filter('[data-me-linha="telefone"] [data-me-valor]')->text());
        self::assertNotNull($p->filter('[data-me-linha="oab"] [data-me-copiar]')->attr('hidden'));
        self::assertNull($p->filter('[data-me-linha="email"] [data-me-copiar]')->attr('hidden'));
        // Nome, Endereço, Criado em e Status não têm o botão de copiar (desenho).
        foreach (['nome', 'endereco', 'criado_em', 'status'] as $chave) {
            self::assertCount(0, $p->filter('[data-me-linha="' . $chave . '"] [data-me-copiar]'), $chave);
        }
        self::assertSame('Código da conta', $p->filter('[data-me-linha="codigo"] [data-me-copiar]')->attr('data-me-copiar'));
        // Zebra do desenho: #f2f2f2 na primeira, #fff na segunda.
        self::assertStringNotContainsString('me-linha--impar', (string) $p->filter('[data-me-linha="codigo"]')->attr('class'));
        self::assertStringContainsString('me-linha--impar', (string) $p->filter('[data-me-linha="nome"]')->attr('class'));
    }

    #[TestDox('status: selo Ativo verde ou Em quarentena, com a dica de plano e espaço')]
    public function testStatus(): void
    {
        $ativo = $this->pagina($this->escritorio());
        self::assertSame('Ativo', $ativo->filter('[data-me-linha="status"] .me-selo')->text());
        self::assertSame('Plano Free · 0,4 de 10 GB usados (4%)', $ativo->filter('[data-me-linha="status"] .me-selo')->attr('title'));
        self::assertStringNotContainsString('me-selo--quarentena', (string) $ativo->filter('[data-me-linha="status"] .me-selo')->attr('class'));

        $q = $this->pagina($this->escritorio(['ativo' => false]));
        self::assertSame('Em quarentena', $q->filter('[data-me-linha="status"] .me-selo')->text());
        self::assertCount(1, $q->filter('[data-me-linha="status"] .me-selo--quarentena'));
    }

    #[TestDox('rodapé: "Alterado por X em dd/mm/aaaa hh:mm" ou "Sem alterações registradas"')]
    public function testRodape(): void
    {
        $com = $this->pagina($this->escritorio());
        self::assertSame('Alterado por Farlei Rocha em 28/10/2026 14:05', trim($com->filter('[data-me-alterado]')->text()));

        $sem = $this->pagina($this->escritorio(['alteradoEm' => null, 'alteradoPor' => null]));
        self::assertSame('Sem alterações registradas', trim($sem->filter('[data-me-alterado]')->text()));
        self::assertSame('/app_tenant_role_index/7', $sem->filter('[data-me-acoes-vista] a')->attr('href'));
    }

    #[TestDox('primário: Editar, enviar logo, lixeira, modo edição com os 8 campos e cartão de exclusão')]
    public function testPrimarioVeTudo(): void
    {
        $p = $this->pagina($this->escritorio());

        self::assertCount(1, $p->filter('[data-me-editar]'));
        self::assertCount(1, $p->filter('[data-me-logo-arquivo]'));
        self::assertCount(1, $p->filter('[data-me-logo-remover]'));
        self::assertNull($p->filter('[data-me-logo-remover]')->attr('hidden'));
        self::assertSame('Trocar logo', trim($p->filter('[data-me-logo-rotulo]')->text()));
        self::assertCount(1, $p->filter('[data-me-cancelar]'));
        self::assertCount(1, $p->filter('[data-me-salvar]'));
        self::assertCount(1, $p->filter('[data-me-exclusao]'));
        self::assertNotNull($p->filter('[data-me-edicao]')->attr('hidden'));
        self::assertSame(
            ['nome', 'doc', 'oab', 'email', 'telefone', 'cep', 'endereco', 'cidadeUf'],
            $p->filter('.me-form > .me-campo > input')->each(static fn (Crawler $c): string => (string) $c->attr('data-me-campo')),
        );
        self::assertSame(
            ['NOME DO ESCRITÓRIO', 'CNPJ OU CPF', 'REGISTRO NA OAB', 'E-MAIL', 'TELEFONE', 'CEP', 'ENDEREÇO', 'CIDADE / UF'],
            $p->filter('.me-form > .me-campo')->each(static fn (Crawler $c): string => trim($c->text())),
        );
        self::assertSame('Somente números', $p->filter('[data-me-campo="doc"]')->attr('placeholder'));
        self::assertSame('Ex.: OAB/DF 1234', $p->filter('[data-me-campo="oab"]')->attr('placeholder'));
        self::assertSame('Preenche o endereço', $p->filter('[data-me-campo="cep"]')->attr('placeholder'));
        self::assertSame('Brasília/DF', $p->filter('[data-me-campo="cidadeUf"]')->attr('placeholder'));
        self::assertSame('11222333000181', $p->filter('[data-me-campo="doc"]')->attr('value'));
        // Campos largos do desenho (grid-column 1 / -1): nome e endereço.
        self::assertCount(2, $p->filter('.me-form > .me-campo--larga'));
    }

    #[TestDox('secundário: sem Editar, sem lixeira, sem enviar logo, sem modo edição e sem cartão de exclusão; Perfis fica')]
    public function testSecundarioNaoVeControles(): void
    {
        $p = $this->pagina($this->escritorio(['podeEditar' => false, 'podeExcluir' => false]));

        foreach (['[data-me-editar]', '[data-me-logo-remover]', '[data-me-logo-arquivo]', '.me-btn-logo', '[data-me-edicao]', '[data-me-salvar]', '[data-me-cancelar]', '[data-me-exclusao]', '.me-cartao--exclusao'] as $sel) {
            self::assertCount(0, $p->filter($sel), $sel);
        }
        self::assertCount(2, $p->filter('.me-pagina > .me-cartao'));
        self::assertCount(1, $p->filter('[data-me-acoes-vista] a'));
        self::assertSame('Perfis', trim($p->filter('[data-me-acoes-vista] a')->text()));
        // A faixa da logo continua (só leitura).
        self::assertCount(1, $p->filter('.me-logo .me-logo-quadro'));
    }

    #[TestDox('sem logo: ícone bi-image, "Enviar logo" e lixeira escondida')]
    public function testSemLogo(): void
    {
        $p = $this->pagina($this->escritorio(['temLogo' => false]));

        self::assertCount(1, $p->filter('.me-logo-quadro > i.bi-image'));
        self::assertCount(0, $p->filter('.me-logo-quadro img'));
        self::assertSame('Enviar logo', trim($p->filter('[data-me-logo-rotulo]')->text()));
        self::assertNotNull($p->filter('[data-me-logo-remover]')->attr('hidden'));

        $com = $this->pagina($this->escritorio(['temLogo' => true]));
        self::assertSame('/meu_escritorio_logo/7', $com->filter('.me-logo-quadro > img')->attr('src'));
        self::assertCount(0, $com->filter('.me-logo-quadro > i.bi-image'));
    }

    #[TestDox('backup: só a conexão com o Drive; desconectado avisa, conectado leva à página de conexão')]
    public function testBackup(): void
    {
        $off = $this->pagina($this->escritorio(['driveConectado' => false]));
        self::assertSame('Não conectado', $off->filter('.me-drive-situacao')->text());
        self::assertStringContainsString('Backup desativado: conecte o Drive para começar', $off->filter('.me-backup-situacao')->text());
        self::assertSame('Conectar meu Drive', trim($off->filter('.me-btn-google')->text()));
        self::assertSame('/sync/drive/conexao/iniciar', $off->filter('.me-btn-google')->attr('href'));

        $on = $this->pagina($this->escritorio(['driveConectado' => true]));
        self::assertSame('Conectado', $on->filter('.me-drive-situacao')->text());
        self::assertCount(0, $on->filter('.me-backup-situacao'));
        self::assertSame('Drive conectado', trim($on->filter('.me-btn-google')->text()));
        self::assertSame('/sync/drive/conexao', $on->filter('.me-btn-google')->attr('href'));

        $semUrl = $this->pagina($this->escritorio(), ['urlDriveConectar' => null, 'urlDriveGerenciar' => null]);
        self::assertCount(0, $semUrl->filter('a.me-btn-google'));
        self::assertCount(1, $semUrl->filter('span.me-btn-google[aria-disabled="true"]'));
    }

    #[TestDox('exclusão: recolhida por padrão, texto da regra vigente (365 dias, sem "30 dias" nem "irreversível"), botão desabilitado')]
    public function testExclusao(): void
    {
        $p = $this->pagina($this->escritorio());
        $cartao = $p->filter('.me-pagina > .me-cartao--exclusao');

        self::assertNull($cartao->filter('[data-me-exclusao-fechado]')->attr('hidden'));
        self::assertNotNull($cartao->filter('[data-me-exclusao-aberto]')->attr('hidden'));
        self::assertStringContainsString('Opções para excluir o escritório. Use só se tiver certeza.', $cartao->filter('[data-me-exclusao-fechado]')->text());
        self::assertStringContainsString('Mostrar opções de exclusão', $cartao->filter('[data-me-exclusao-fechado] button')->text());
        self::assertSame(
            'Excluir o escritório desativa o acesso de todos os colaboradores. O pedido segue para o BlueJus decidir e os dados ficam guardados por 365 dias, podendo ser recuperados pelo suporte ou pelo Master antes da remoção definitiva.',
            preg_replace('/\s+/', ' ', trim($cartao->filter('[data-me-exclusao-aberto] > div')->first()->text())),
        );
        self::assertSame('Senha do usuário primário:', $cartao->filter('.me-exclusao-grupo > span')->first()->text());
        self::assertSame('Escritório Alfa Advocacia', $cartao->filter('[data-me-exclusao-nome]')->text());
        self::assertStringContainsString('para confirmar:', $cartao->filter('.me-exclusao-grupo > span')->eq(1)->text());
        self::assertNotNull($cartao->filter('[data-me-exclusao-excluir]')->attr('disabled'));
        self::assertSame('password', $cartao->filter('[data-me-exclusao-senha]')->attr('type'));
        self::assertSame('Excluir escritório', trim($cartao->filter('[data-me-exclusao-excluir]')->text()));
        self::assertSame('Ocultar', trim($cartao->filter('.me-btn-ocultar')->text()));
    }

    #[TestDox('a raiz leva só rotas, token e nome para o JS; o escritório vem da rota, nunca de campo')]
    public function testDadosParaOJs(): void
    {
        $raiz = $this->pagina($this->escritorio())->filter('[data-me-pagina]');

        self::assertSame('token-teste', $raiz->attr('data-csrf'));
        self::assertSame('/meu_escritorio_salvar/7', $raiz->attr('data-url-salvar'));
        self::assertSame('/meu_escritorio_logo_enviar/7', $raiz->attr('data-url-logo-enviar'));
        self::assertSame('/meu_escritorio_logo_remover/7', $raiz->attr('data-url-logo-remover'));
        self::assertSame('/meu_escritorio_exclusao_conferir/7', $raiz->attr('data-url-exclusao-conferir'));
        self::assertSame('/app_profile#mpExclusao', $raiz->attr('data-url-exclusao-fluxo'));
        self::assertCount(0, $raiz->filter('input[name="tenantId"], input[name="tenant_id"], input[name="tenant"]'));
    }

    #[TestDox('o que o desenho não tem não aparece: abas, KPIs, backup agora/semanal/histórico/último, exportação, faltando')]
    public function testNaoApareceOQueODesenhoNaoTem(): void
    {
        $html = $this->renderizar($this->escritorio(['driveConectado' => true]));
        $texto = (string) preg_replace('/\s+/', ' ', strip_tags($html));

        foreach ([
            'Fazer backup agora', 'Backup automático', 'Ver histórico', 'Ocultar histórico', 'Último backup', 'Nenhum backup',
            'informação(ões) faltando', 'informações faltando', 'Exportar', 'Exportação', 'EXPEDIENTE', 'MINHAS METAS', 'AGENDA',
            'Colaboradores', 'Espaço usado', 'irreversível', '30 dias', 'Zona de perigo',
        ] as $proibido) {
            self::assertStringNotContainsString($proibido, $texto, $proibido);
        }
        foreach (['modal', 'btn btn-', 'card-body', 'alert-', 'toast-', 'data-bs-', 'select2'] as $componenteDoSistema) {
            self::assertStringNotContainsString($componenteDoSistema, $html, $componenteDoSistema);
        }
        self::assertSame(0, substr_count($html, '<nav'));
        self::assertSame(1, substr_count($html, '<h1'));
    }

    #[TestDox('sem travessão em template, CSS, JS, catálogo e HTML renderizado')]
    public function testSemTravessao(): void
    {
        $base = __DIR__ . '/../../../';
        $fontes = [
            'templates/tenant/meu_escritorio.html.twig', 'public/css/meu-escritorio.css', 'public/js/meu-escritorio.js',
            'translations/tenant/messages+intl-icu.pt_BR.yaml',
        ];
        foreach ($fontes as $f) {
            self::assertDoesNotMatchRegularExpression('/[—–]/u', (string) file_get_contents($base . $f), $f);
        }
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $this->renderizar($this->escritorio()));
    }

    #[TestDox('toda chave tenant.meu_escritorio.* usada no template e no JS existe no catálogo do Tenant')]
    public function testChavesNoCatalogo(): void
    {
        $catalogo = CatalogoPtBr::carregar();
        $existe = static function (string $chave) use ($catalogo): bool {
            $no = $catalogo;
            foreach (explode('.', $chave) as $parte) {
                if (!\is_array($no) || !\array_key_exists($parte, $no)) {
                    return false;
                }
                $no = $no[$parte];
            }

            return \is_string($no);
        };

        $twig = (string) file_get_contents(self::TEMPLATE);
        preg_match_all("/'(tenant\\.meu_escritorio\\.[a-z_.]+)'/", $twig, $a);
        self::assertNotEmpty($a[1]);
        foreach (array_unique($a[1]) as $chave) {
            if ($chave === 'tenant.meu_escritorio.js') {
                continue; // prefixo do catalogo_js, não é uma mensagem
            }
            self::assertTrue($existe($chave), $chave);
        }

        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/meu-escritorio.js');
        preg_match_all("/T\\('([a-z_]+)'/", $js, $b);
        self::assertNotEmpty($b[1]);
        foreach (array_unique($b[1]) as $chave) {
            self::assertTrue($existe('tenant.meu_escritorio.js.' . $chave), 'js.' . $chave);
        }
    }

    #[TestDox('o CSS usa os valores do desenho e arranjo responsivo igual ao dele')]
    public function testCssComValoresDoDesenho(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/meu-escritorio.css');

        foreach ([
            'grid-template-columns: minmax(140px, 40%) minmax(0, 1fr)', 'grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))',
            '#0a6fa0', '#b02a37', '#e6b9be', '#f2f2f2', '#f7f9fb', '#12242f', 'max-width: 820px', 'border: 1px dashed #b9c7d2',
            'width: 64px', 'box-shadow: 0 1px 3px rgba(18, 36, 47, .06)', 'bottom: 22px',
        ] as $valor) {
            self::assertStringContainsString($valor, $css, $valor);
        }
        self::assertDoesNotMatchRegularExpression('/\.(card|btn|modal|toast|alert)\b(?!-)/', $css);
    }
}
