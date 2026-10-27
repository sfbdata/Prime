<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * PMC-059 (+PMC-039, PMC-041) — corpo do modal "Nova meta" no desenho 1.2.3 (dc L.77-188):
 * rótulos do desenho, contador da descrição e os responsáveis em chips sobre o <select>,
 * que continua sendo a fonte do envio.
 *
 * O comportamento (marcar, filtrar, contar) roda no navegador (pasta-dialogos.js); aqui se
 * prova o arranjo que ele lê, com combinador de FILHO DIRETO.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaNovaMetaCorpoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function abrir(object $client, Pasta $pasta): object
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function colega(\App\Entity\Tenant\Tenant $tenant, string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user   = new User();
        $user->setEmail('colega_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    #[TestDox('rótulos do desenho (.ps-nm-rotulo) em Título, Descrição, Prazo, Caixa / etapa e Responsáveis, os cinco com "*" (D-META e desenho 02 L179, o servidor exige os cinco)')]
    public function testRotulosDoDesenho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $corpo = $this->abrir($client, $pasta)->filter('#formCriarTarefa > .modal-body.ps-modal-meta-corpo');

        $blocos = $corpo->filter('.ps-modal-meta-corpo > .ps-nm-campo-bloco');
        self::assertSame(
            ['Título', 'Descrição', 'Prazo', 'Caixa / etapa', 'Responsáveis'],
            $blocos->each(static fn ($b): string => trim((string) preg_replace('/\s*\*.*$/u', '', $b->filter('.ps-nm-campo-bloco > .ps-nm-rotulo')->text()))),
        );
        self::assertCount(1, $corpo->filter('label.ps-nm-rotulo[for="tarefaTitulo"] > .ps-nm-obrig'));
        self::assertCount(1, $corpo->filter('label.ps-nm-rotulo[for="tarefaDescricao"] > .ps-nm-obrig'));
        self::assertCount(1, $corpo->filter('label.ps-nm-rotulo[for="tarefaPrazo"] > .ps-nm-obrig'), 'prazo obrigatório (D-META, D:4044)');
        self::assertCount(1, $corpo->filter('span.ps-nm-rotulo#tarefaResponsavelRotulo > .ps-nm-obrig'), 'responsáveis obrigatórios (D-META, D:4044)');
        self::assertCount(1, $corpo->filter('span.ps-nm-rotulo#tarefaEtapaRotulo > .ps-nm-obrig'), 'caixa / etapa obrigatória (desenho 02 L179 e L4048 `inval`)');
        self::assertSame('*', trim($corpo->filter('label.ps-nm-rotulo[for="tarefaPrazo"] > .ps-nm-obrig')->text()));
        self::assertSame('*', trim($corpo->filter('span.ps-nm-rotulo#tarefaResponsavelRotulo > .ps-nm-obrig')->text()));
        self::assertCount(0, $corpo->filter('.form-label-sm, .form-control-sm'), 'sai o tamanho pequeno do Bootstrap');
    }

    #[TestDox('PMC-039: o contador fica no rótulo da Descrição e conta contra o limite do servidor (5000)')]
    public function testContadorDaDescricao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $contador = $crawler->filter('label.ps-nm-rotulo[for="tarefaDescricao"] > span.ps-nm-contador[data-ps-nm-contador="tarefaDescricao"]');
        self::assertCount(1, $contador);
        self::assertSame('0/5000', trim($contador->text()));
        self::assertSame('5000', $crawler->filter('textarea#tarefaDescricao')->attr('maxlength'), 'o limite é o de hoje; o 600 do desenho é decisão');
    }

    #[TestDox('PMC-041: chips sobre o campo, lista com cada colega (nome em caixa alta) e o <select multiple> de sempre como fonte')]
    public function testResponsaveisEmChips(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->colega($tenant, 'Beatriz Lima');
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $bloco = $crawler->filter('#formCriarTarefa .ps-modal-meta-corpo > .ps-nm-resp[data-ps-nm-resp]');
        self::assertCount(1, $bloco);

        $campo = $bloco->filter('.ps-nm-resp > .ps-nm-resp-campo[role="button"][data-ps-nm-resp-campo]');
        self::assertCount(1, $campo);
        self::assertSame('tarefaResponsavelLista', $campo->attr('aria-controls'));
        self::assertCount(1, $campo->filter('.ps-nm-resp-campo > [data-ps-nm-resp-chips]'));
        self::assertSame('Selecionar responsáveis', trim($campo->filter('.ps-nm-resp-campo > .ps-nm-resp-vazio')->text()));
        self::assertCount(1, $campo->filter('.ps-nm-resp-vazio > i.bi-person-plus'));
        self::assertSame('Selecione um ou mais responsáveis pela meta.', trim($bloco->filter('.ps-nm-resp > .ps-nm-dica')->text()));

        $lista = $bloco->filter('.ps-nm-resp > #tarefaResponsavelLista[data-ps-nm-resp-lista]');
        self::assertNotNull($lista->attr('hidden'), 'a lista nasce fechada');
        self::assertSame('Filtrar…', $lista->filter('.ps-nm-resp-busca > input[data-ps-nm-resp-busca]')->attr('placeholder'));
        $opcao = $lista->filter('.ps-nm-resp-opcoes > button.ps-nm-resp-opcao[data-ps-nm-resp-opcao="' . $colega->getId() . '"]');
        self::assertCount(1, $opcao);
        self::assertSame('Beatriz Lima', $opcao->attr('data-nome'));
        self::assertSame('BEATRIZ LIMA', trim($opcao->filter('.ps-nm-resp-opcao-nome')->text()));
        self::assertCount(1, $opcao->filter('button > .resp-avatar'), 'avatar (foto ou iniciais) do desenho');
        self::assertSame('Concluir', trim($lista->filter('.ps-nm-resp-rodape > button[data-ps-nm-resp-concluir]')->text()));
        self::assertSame('Ninguém encontrado', trim($lista->filter('[data-ps-nm-resp-nada]')->text()));

        // A fonte do envio: o mesmo <select>, com uma <option> por colega da lista.
        $select = $bloco->filter('.ps-nm-resp > select#tarefaResponsavel[name="responsaveis[]"][multiple]');
        self::assertCount(1, $select);
        self::assertSame(
            $lista->filter('button.ps-nm-resp-opcao')->each(static fn ($b) => $b->attr('data-ps-nm-resp-opcao')),
            $select->filter('option')->each(static fn ($o) => $o->attr('value')),
            'cada opção da lista marca a <option> de mesmo valor',
        );
        self::assertSame('-1', $select->attr('tabindex'), 'o select fica fora da ordem de foco: quem se usa é a lista');
    }

    #[TestDox('PMC-041: o Select2 sai do campo de responsáveis e o pasta-dialogos.js entra')]
    public function testSemSelect2(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $this->abrir($client, $pasta);
        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString("$('#tarefaResponsavel').select2", $html);
        self::assertStringContainsString('/js/pasta-dialogos.js', $html);
    }

    #[TestDox('PMC-040: o botão "dd/mm/aaaa" abre o calendário do desenho; o <input type="date"> continua o campo enviado')]
    public function testCalendarioDoPrazo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $prazo = $this->abrir($client, $pasta)->filter('#formCriarTarefa .ps-nm-prazo');

        $linha = $prazo->filter('.ps-nm-prazo > .ps-nm-prazo-linha');
        self::assertSame(
            ['button', 'input', 'button', 'button', 'button', 'button', 'span'],
            $linha->children()->each(static fn ($c): string => $c->nodeName()),
            'o campo do desenho vem antes dos atalhos; o input fica logo depois, fora da vista; o chip do prazo sugerido (Análise do prazo) fecha a linha',
        );
        $campo = $linha->filter('.ps-nm-prazo-linha > button.ps-nm-cal-campo[data-ps-nm-cal-campo]');
        self::assertSame('tarefaPrazoCalendario', $campo->attr('aria-controls'));
        self::assertSame('dd/mm/aaaa', trim($campo->filter('[data-ps-nm-cal-txt]')->text()));
        self::assertCount(1, $campo->filter('i.bi-calendar3'));

        $input = $linha->filter('.ps-nm-prazo-linha > input#tarefaPrazo[type="date"][name="prazo"]');
        self::assertCount(1, $input, 'o valor enviado continua o ISO do input');
        self::assertStringContainsString('visually-hidden', (string) $input->attr('class'));
        self::assertSame('-1', $input->attr('tabindex'));

        $cal = $prazo->filter('.ps-nm-prazo > #tarefaPrazoCalendario[role="dialog"][data-ps-nm-cal]');
        self::assertCount(1, $cal);
        self::assertNotNull($cal->attr('hidden'));
        self::assertSame('Mês anterior', $cal->filter('.ps-nm-cal-cab > [data-ps-nm-cal-ant]')->attr('aria-label'));
        self::assertSame('Próximo mês', $cal->filter('.ps-nm-cal-cab > [data-ps-nm-cal-prox]')->attr('aria-label'));
        self::assertSame(['D', 'S', 'T', 'Q', 'Q', 'S', 'S'], $cal->filter('.ps-nm-cal-semana > span')->each(static fn ($d) => $d->text()));
        self::assertSame(
            ['Hoje', 'Limpar', '', 'Fim de semana em cinza'],
            $cal->filter('.ps-nm-cal-rodape')->children()->each(static fn ($c): string => trim($c->text())),
        );
        // O campo enviado está fora da vista (visually-hidden, tabindex -1): o `required` nativo mostraria o balão
        // do navegador sobre nada. Quem cobra o prazo é o "Criar meta" desabilitado (pasta-metas.js) e o 422.
        self::assertNull($prazo->filter('#tarefaPrazo')->attr('required'), 'sem required nativo no campo escondido');
    }

    #[TestDox('Desenho 02 L179: a Caixa / etapa fica entre Prazo e Responsáveis, com 8 botões na ordem da esteira (nome e setor), a dica escondida, a regra do enum e o campo etapa vazio')]
    public function testCaixaEtapaEntrePrazoEResponsaveis(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $corpo = $this->abrir($client, $pasta)->filter('#formCriarTarefa > .modal-body.ps-modal-meta-corpo');

        $filhos = $corpo->filter('.ps-modal-meta-corpo > .ps-nm-campo-bloco')->each(static fn ($b): string => (string) $b->attr('class'));
        $posEtapa = array_key_first(array_filter($filhos, static fn (string $c): bool => str_contains($c, 'ps-nm-etapa')));
        self::assertNotNull($posEtapa);
        self::assertStringContainsString('ps-nm-prazo', $filhos[$posEtapa - 1], 'logo depois do Prazo');
        self::assertStringContainsString('ps-nm-resp', $filhos[$posEtapa + 1], 'logo antes dos Responsáveis');

        $bloco = $corpo->filter('.ps-modal-meta-corpo > .ps-nm-campo-bloco.ps-nm-etapa[data-ps-nm-etapa]');
        self::assertCount(1, $bloco);
        $rotulo = $bloco->filter('.ps-nm-etapa > span.ps-nm-rotulo#tarefaEtapaRotulo');
        self::assertSame('*', trim($rotulo->filter('#tarefaEtapaRotulo > .ps-nm-obrig')->text()));
        $dica = $rotulo->filter('#tarefaEtapaRotulo > .ps-nm-etapa-sug[data-ps-nm-etapa-sug]');
        self::assertNotNull($dica->attr('hidden'), 'a dica nasce escondida: o modal abre sem título');
        self::assertCount(1, $dica->filter('.ps-nm-etapa-sug > i.bi.bi-stars'));
        self::assertCount(1, $dica->filter('.ps-nm-etapa-sug > [data-ps-nm-etapa-sug-tx]'));

        $botoes = $bloco->filter('.ps-nm-etapa > .ps-nm-etapa-grade[role="group"] > button.ps-nm-etapa-op[type="button"]');
        self::assertSame(array_map(static fn (EtapaDaPasta $e): string => $e->value, EtapaDaPasta::cases()), $botoes->each(static fn ($b) => $b->attr('data-ps-nm-etapa-op')), '8 botões, na ordem do enum');
        self::assertSame(['Comercial', 'Abertura e relatório', 'Controladoria', 'Documentação', 'Jurídico', 'Protocolo', 'Acompanhamento', 'Encerramento'], $botoes->each(static fn ($b) => trim($b->filter('button > b.ps-nm-etapa-nome')->text())));
        self::assertSame(['Comercial', 'Atendimento', 'Controladoria', 'Secretaria', 'Advogado(a)', 'Advogado(a)', 'Advogado(a)', 'Controladoria'], $botoes->each(static fn ($b) => trim($b->filter('button > span.ps-nm-etapa-setor')->text())));
        self::assertSame(['false'], array_values(array_unique($botoes->each(static fn ($b) => $b->attr('aria-pressed')))), 'nenhuma etapa escolhida ao abrir');

        $campo = $bloco->filter('.ps-nm-etapa > input#tarefaEtapa[type="hidden"][name="etapa"]');
        self::assertCount(1, $campo, 'o campo enviado mora dentro do form');
        self::assertSame('', $campo->attr('value'));

        $regra = json_decode((string) $bloco->attr('data-regra'), true);
        self::assertSame(['regras' => EtapaDaPasta::regrasDoTitulo(), 'padrao' => 'juridico'], $regra, 'a regra da sugestão sai do enum');
    }

    #[TestDox('o botão leva o nome que o escritório deu à etapa (Configurar caixa), não só o do catálogo')]
    public function testCaixaEtapaComONomeDoEscritorio(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $caixa           = new CaixaDaEsteira($tenant, EtapaDaPasta::Documentacao);
        $caixa->configurar(null, 'Papelada', []);
        $this->em()->persist($caixa);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $botao = $this->abrir($client, $pasta)->filter('#formCriarTarefa .ps-nm-etapa-grade > button[data-ps-nm-etapa-op="documentacao"]');

        self::assertSame('Papelada', trim($botao->filter('button > b.ps-nm-etapa-nome')->text()));
        self::assertSame('Papelada', $botao->attr('data-nome'), 'a dica "Sugestão pelo título" usa o mesmo nome');
        self::assertSame('Secretaria', trim($botao->filter('button > span.ps-nm-etapa-setor')->text()));
    }
}
