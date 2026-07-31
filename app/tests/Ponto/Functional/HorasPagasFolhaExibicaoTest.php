<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Entity\RegistroPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A linha "Horas pagas" no rodapé da folha (Tarefa 6): aparece só quando há lançamento na
 * competência exibida, some por completo quando não há, e não quebra a ficha do admin — que
 * inclui o MESMO partial `_folha_table.html.twig` (armadilha central desta tarefa: o partial é
 * incluído tanto por `ponto/index.html.twig` quanto por `tenant/edit_user_role.html.twig`, cada
 * um com o seu próprio conjunto de variáveis passadas via `only`).
 */
#[CoversClass(PontoController::class)]
#[CoversClass(TenantController::class)]
final class HorasPagasFolhaExibicaoTest extends JusPrimeWebTestCase
{
    #[TestDox('colaborador com lancamento na competencia do mes ve a linha Horas pagas com o sinal certo')]
    public function testColaboradorComLancamentoVeALinha(): void
    {
        // Usuário SEM bypass (TenantRole não-isSystem, com a permissão real de módulo): prova que é o
        // colaborador visualizando a PRÓPRIA folha, não um perfil administrativo qualquer que passaria
        // por qualquer checagem.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaboradorComum($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $ano = (int) $agora->format('Y');
        $mes = (int) $agora->format('n');

        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, -600);

        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        $conteudo = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Horas pagas', $conteudo, 'a linha tem de aparecer quando há lançamento na competência');
        self::assertStringContainsString('-10h00m', $conteudo, 'o sinal e o valor têm de bater com o lançamento (-600 minutos)');
    }

    #[TestDox('colaborador sem lancamento na competencia do mes NAO ve a linha Horas pagas')]
    public function testColaboradorSemLancamentoNaoVeALinha(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaboradorComum($tenant);

        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        $conteudo = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Horas pagas', $conteudo, 'sem lançamento a tela tem de ficar igual à de antes desta tarefa');
        self::assertStringNotContainsString('Total do mês', $conteudo, 'o bloco de totais inteiro tem de sumir junto com a linha, não só ela');
    }

    #[TestDox('colaborador ve o bloco de totais: saldo do mes, horas pagas e a soma dos dois')]
    public function testColaboradorVeOBlocoDeTotaisComASoma(): void
    {
        // Sem o bloco, o colaborador via o saldo do mês na última célula da coluna "Banco de Horas",
        // a linha de horas pagas logo abaixo e NENHUMA soma — tinha de fazer a conta de cabeça.
        // Jornada com `diasSemana = []` de propósito: a carga esperada vira 0 em qualquer dia da
        // semana, então as 2h batidas valem +120min independentemente de quando o teste rodar.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaboradorComum($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $ano = (int) $agora->format('Y');
        $mes = (int) $agora->format('n');
        $primeiroDia = $agora->format('Y-m-01');

        $this->criarJornadaSemDiasDeTrabalho($colaborador);
        $this->criarRegistro($colaborador, $tenant, $primeiroDia . ' 09:00:00');
        $this->criarRegistro($colaborador, $tenant, $primeiroDia . ' 11:00:00', RegistroPonto::TIPO_SAIDA);
        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, -600);

        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        $conteudo = (string) $client->getResponse()->getContent();
        $this->assertBlocoTotaisPresente($conteudo, '+2h00m', '-10h00m', '-8h00m');

        // Âncora do cabeçalho renomeado: a coluna é "Saldo do Mês" (o acumulado DO MÊS, dia a dia), e
        // não "Banco de Horas" — nome que no bloco assinado passou a significar o acumulado do ANO.
        // Sem esta asserção, voltar o rótulo antigo recria a colisão de nomes na mesma página, que foi
        // o que o smoke do dono pegou no PDF.
        self::assertStringContainsString('>Saldo do Mês</th>', $conteudo, 'a coluna tem de se chamar "Saldo do Mês"');
        self::assertStringNotContainsString('>Banco de Horas</th>', $conteudo, 'o cabeçalho antigo não pode voltar');
    }

    #[TestDox('ficha do admin mostra o MESMO bloco de totais (as duas telas incluem o mesmo partial)')]
    public function testFichaDoAdminMostraOMesmoBlocoDeTotais(): void
    {
        // A armadilha desta frente: `_folha_table.html.twig` é incluído por ponto/index.html.twig E
        // por tenant/edit_user_role.html.twig, cada um com `only`. `saldoMesMinutos` passado só num
        // deles faria o outro exibir "Saldo do mês 0h00m" e um total ERRADO (aqui, -10h00m em vez de
        // -8h00m) — sem erro nenhum, porque o partial se protege com `is defined`.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $ano = (int) $agora->format('Y');
        $mes = (int) $agora->format('n');
        $primeiroDia = $agora->format('Y-m-01');

        $this->criarJornadaSemDiasDeTrabalho($colaborador);
        $this->criarRegistro($colaborador, $tenant, $primeiroDia . ' 09:00:00');
        $this->criarRegistro($colaborador, $tenant, $primeiroDia . ' 11:00:00', RegistroPonto::TIPO_SAIDA);
        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, -600);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));

        self::assertResponseIsSuccessful();
        $this->assertBlocoTotaisPresente(
            (string) $client->getResponse()->getContent(),
            '+2h00m',
            '-10h00m',
            '-8h00m',
        );
    }

    #[TestDox('mes sem batida nenhuma: o bloco trata o saldo do mes como zero e o total vira o proprio lancamento')]
    public function testBlocoDeTotaisEmMesSemBatidaTrataSaldoDoMesComoZero(): void
    {
        // Nenhuma linha da folha tem saldo apurado (`saldoAcumuladoFinal` devolve null). Some o total
        // seria pior do que somar zero: é justamente o mês em que só existe o lançamento.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaboradorComum($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $ano = (int) $agora->format('Y');
        $mes = (int) $agora->format('n');

        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, -600);

        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        $this->assertBlocoTotaisPresente(
            (string) $client->getResponse()->getContent(),
            '0h00m',
            '-10h00m',
            '-10h00m',
        );
    }

    #[TestDox('dois lancamentos que se anulam na mesma competencia NAO produzem linha fantasma')]
    public function testLancamentosQueSeAnulamNaoMostramALinha(): void
    {
        // A implementação usa `!= 0` (não `is not null`) de propósito: um refactor para `is not null`
        // faria +600 e -600 na mesma competência renderizar "+0h00m" — nada foi pago de fato, e a
        // linha teria de sumir por completo.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaboradorComum($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $ano = (int) $agora->format('Y');
        $mes = (int) $agora->format('n');

        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, 600);
        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, -600);

        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        $conteudo = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Horas pagas', $conteudo, 'lançamentos que se anulam não podem gerar uma linha "+0h00m" fantasma');
    }

    #[TestDox('ficha do admin (mesmo partial compartilhado) continua carregando com lancamento na competencia')]
    public function testFichaDoAdminContinuaCarregando(): void
    {
        // Armadilha central da Tarefa 6: `_folha_table.html.twig` é incluído tanto por
        // ponto/index.html.twig quanto por tenant/edit_user_role.html.twig, cada include com
        // `only` e o seu próprio conjunto de variáveis. Se só um dos dois passasse
        // `horasPagasMinutos`, este segundo render quebraria (ou renderizaria errado) em silêncio.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $ano = (int) $agora->format('Y');
        $mes = (int) $agora->format('n');

        $this->criarRegistro($colaborador, $tenant, $agora->format('Y-m-d') . ' 09:00:00');
        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, 480);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));

        self::assertResponseIsSuccessful('a ficha do admin não pode quebrar quando a folha do colaborador ganha o rodapé de horas pagas');
        $conteudo = (string) $client->getResponse()->getContent();
        // Não basta procurar "+8h00m" solto: a aba "Horas pagas" da Tarefa 5 já lista o MESMO
        // lançamento com a MESMA formatação, incondicionalmente — o rodapé precisa ser verificado
        // no seu próprio marcador HTML, senão a asserção passa mesmo com o rodapé quebrado.
        $this->assertRodapeHorasPagasPresente($conteudo, '+8h00m');
    }

    #[TestDox('colaborador SEM nenhuma batida: admin e colaborador veem a mesma linha na competencia atual')]
    public function testColaboradorSemBatidaNenhumaFicaConsistenteEntreAsDuasTelas(): void
    {
        // O bug de paridade: TenantController só calculava horasPagasMinutosPonto DENTRO do
        // `if (competência com histórico de batida)`. Colaborador recém-admitido, zero batidas —
        // a lista de competências fica vazia e a ficha do admin nunca calculava nada, enquanto o
        // /ponto do próprio colaborador (que sempre resolve para o mês corrente) já refletia o
        // lançamento. Este teste ancora os dois caminhos batendo para o mesmo cenário.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaboradorComum($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $ano = (int) $agora->format('Y');
        $mes = (int) $agora->format('n');

        // Nenhum RegistroPonto é criado de propósito — é o cenário "zero batidas".
        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, -600);

        // Caminho 1 — o próprio colaborador em /ponto.
        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Horas pagas',
            (string) $client->getResponse()->getContent(),
            'o colaborador sem batida nenhuma ainda assim tem de ver o lançamento do mês corrente',
        );

        // Caminho 2 — a ficha do mesmo colaborador vista pelo admin, mesma competência (corrente).
        //
        // ⚠️ Nem "Horas pagas" solto nem "-10h00m" solto provam algo aqui: a aba "Horas pagas" da
        // Tarefa 5 (`_horas_pagas_tab.html.twig`) lista o MESMO lançamento, com a MESMA formatação
        // ("-10h00m"), de forma INCONDICIONAL em toda resposta 200 dessa rota. Reverter a correção
        // do `TenantController` mantém as duas strings na página e o teste passaria mesmo quebrado
        // — só o marcador HTML exclusivo do rodapé da folha (Tarefa 6) prova o caminho certo.
        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));
        self::assertResponseIsSuccessful();
        $this->assertRodapeHorasPagasPresente(
            (string) $client->getResponse()->getContent(),
            '-10h00m',
        );
    }

    #[TestDox('competencia malformada na querystring nao derruba a ficha do admin (cai no fallback, sem 500)')]
    public function testCompetenciaMalformadaNaQuerystringNaoQuebra(): void
    {
        // Regressão introduzida pela própria correção do achado 2 (rodada 1): promover o `explode`
        // para fora do `if` de validação fez a ficha do admin rodar `explode('-', 'julho')` direto —
        // sem hífen, o destructuring deixa o mês `null` e `somarHorasPagasDaCompetencia(..., int $mes)`
        // estoura TypeError (500). "2026-" e "abc-def" NÃO reproduzem (têm dois elementos); só
        // valores sem hífen — daí o valor de teste escolhido a dedo.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaborador($tenant);
        $competenciaAtual = (new \DateTimeImmutable())->format('Y-m');

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?competencia=julho', $tenant->getId(), $colaborador->getId()));

        self::assertResponseIsSuccessful('competência sem hífen na querystring tem de cair no fallback do mês corrente, não estourar 500');
        // Só o 200 não prova a SEMÂNTICA do fallback — poderia ter caído em qualquer outra
        // competência (ou em nenhuma) e o teste continuaria verde. Conferir que o mês CORRENTE
        // aparece marcado como selecionado no `<select>` prova o fallback exato.
        self::assertStringContainsString(
            sprintf('<option value="%s" selected>', $competenciaAtual),
            (string) $client->getResponse()->getContent(),
            'competência malformada tem de cair especificamente no mês corrente, marcado como selecionado no seletor',
        );
    }

    #[TestDox('colaborador com batida so em mes antigo: o mes corrente ainda aparece como opcao no seletor do admin')]
    public function testMesCorrenteApareceNoSeletorMesmoComBatidaSoEmMesAntigo(): void
    {
        // O buraco maior do achado 3: `findCompetenciasComRegistroPorUsuario` só devolve
        // competências COM batida. Colaborador com última batida há meses e lançamento no mês
        // corrente — sem injetar o mês corrente na lista, o admin só alcança aquela competência
        // editando a URL na mão; o <select> nem oferece a opção.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $competenciaAtual = $agora->format('Y-m');

        $mesAntigo = $agora->modify('-3 months');
        $this->criarRegistro($colaborador, $tenant, $mesAntigo->format('Y-m-d') . ' 09:00:00');
        $this->criarLancamento($tenant, $colaborador, $autor, (int) $agora->format('Y'), (int) $agora->format('n'), -600);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));

        self::assertResponseIsSuccessful();
        // ⚠️ `value="2026-07"` solto casaria com QUALQUER atributo `value` da página que tenha o
        // mesmo conteúdo — o campo oculto `modalAddCompetencia` (que espelha `competenciaSelecionada`)
        // é um candidato real quando a competência selecionada é a corrente. Ancorar em
        // `<option value="…"` prova que a competência está especificamente entre as OPÇÕES do `<select>`.
        self::assertStringContainsString(
            sprintf('<option value="%s"', $competenciaAtual),
            (string) $client->getResponse()->getContent(),
            'o seletor de competência tem de oferecer o mês corrente, mesmo sem batida nele',
        );
    }

    #[TestDox('competencia bem formada, sem batida e sem lancamento, PERMANECE selecionada — nao salta para o mes corrente')]
    public function testCompetenciaValidaSemBatidaNemLancamentoPermanece(): void
    {
        // O caso que motivou relaxar a validação (achado 1, rodada 3): antes, exigir pertencimento
        // à lista de "com batida" fazia a ficha SALTAR de mês sozinha sempre que a competência pedida
        // não tinha batida — inclusive ao excluir a ÚLTIMA batida de um mês (o redirect da exclusão
        // manda de volta ?competencia=<aquele mês>, que passou a não ter mais batida nenhuma) ou ao
        // reabrir uma competência de lançamento retroativo sem nenhuma batida. Formato bem-formado
        // basta agora: o cálculo é seguro (devolve zero, tabela vazia, sem erro).
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaborador($tenant);
        $competenciaAtual = (new \DateTimeImmutable())->format('Y-m');
        $competenciaSemNada = (new \DateTimeImmutable())->modify('-6 months')->format('Y-m');
        self::assertNotSame($competenciaAtual, $competenciaSemNada, 'a competência do teste precisa ser diferente da corrente');

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?competencia=%s', $tenant->getId(), $colaborador->getId(), $competenciaSemNada));

        self::assertResponseIsSuccessful();
        // O campo oculto `modalAddCompetencia` espelha `competenciaSelecionada` diretamente — é o
        // jeito certo de conferir o valor INTERNO efetivamente usado, independente de a competência
        // aparecer ou não como opção no `<select>` (ela não aparece: nem tem batida, nem lançamento).
        self::assertStringContainsString(
            sprintf('id="modalAddCompetencia" value="%s"', $competenciaSemNada),
            (string) $client->getResponse()->getContent(),
            'a competência pedida (sem batida e sem lançamento) tem de permanecer selecionada, não saltar para o mês corrente',
        );
    }

    #[TestDox('lancamento em mes sem nenhuma batida: opcao existe no seletor e, selecionada, a folha mostra a linha')]
    public function testLancamentoEmMesSemBatidaApareceNoSeletorEMostraALinhaQuandoSelecionado(): void
    {
        // Fecha o "buraco maior" do achado 2 (rodada 3): não bastava injetar o mês CORRENTE na
        // lista — um lançamento num mês PASSADO sem nenhuma batida (ajuste retroativo) também
        // precisa ser alcançável pelo seletor do admin, e mostrar a linha quando selecionado.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);

        $primeiroDiaDesteMes = (new \DateTimeImmutable())->modify('first day of this month');
        $mesDoLancamento = $primeiroDiaDesteMes->modify('-2 months')->format('Y-m');
        [$anoLancamento, $mesLancamento] = array_map('intval', explode('-', $mesDoLancamento));
        $this->criarLancamento($tenant, $colaborador, $autor, $anoLancamento, $mesLancamento, -600);

        // ⚠️ A batida do mês corrente existe para que o mês do lançamento NÃO seja o mês padrão de
        // abertura da ficha. Sem ela, o padrão (último mês com dado) já cairia no mês do lançamento e
        // a etapa 2 viraria tautologia: passaria mesmo que o controller ignorasse a querystring por
        // completo. O mês do LANÇAMENTO continua sem batida nenhuma — que é o ponto do cenário.
        $this->criarRegistro($colaborador, $tenant, $primeiroDiaDesteMes->format('Y-m-d') . ' 09:00:00');

        $this->logarComTenant($client, $admin, $tenant);

        // 1. Sem selecionar nada: a competência do lançamento (sem batida nenhuma) tem de existir
        //    como opção no <select> — antes desta correção, não existia jeito de alcançá-la senão
        //    editando a URL na mão. E o padrão de abertura é o mês CORRENTE (o mais recente com
        //    dado), não o do lançamento: é isso que dá sentido à etapa 2.
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));
        self::assertResponseIsSuccessful();
        $conteudoSemQuerystring = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(
            sprintf('<option value="%s"', $mesDoLancamento),
            $conteudoSemQuerystring,
            'a competência do lançamento (sem nenhuma batida) tem de aparecer como opção no seletor',
        );
        self::assertStringContainsString(
            sprintf('<option value="%s" selected>', $primeiroDiaDesteMes->format('Y-m')),
            $conteudoSemQuerystring,
            'sem querystring a ficha abre no mês corrente (o mais recente com dado) — o mês do lançamento é OUTRO',
        );

        // 2. Selecionando-a explicitamente: a folha mostra a linha "Horas pagas".
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?competencia=%s', $tenant->getId(), $colaborador->getId(), $mesDoLancamento));
        self::assertResponseIsSuccessful();
        $this->assertRodapeHorasPagasPresente((string) $client->getResponse()->getContent(), '-10h00m');
    }

    #[TestDox('ficha do admin ABRE no ultimo mes COM BATIDA, nao no mes corrente vazio')]
    public function testFichaAbreNoUltimoMesComBatidaNaoNoMesCorrente(): void
    {
        // O mês padrão de abertura da ficha SEMPRE foi o mais recente com dado. A lista de opções
        // ganhou (nesta frente) o mês corrente injetado e passou a ser ordenada por `krsort` — se o
        // padrão sair do índice 0 dessa lista, ele vira o mês corrente e a ficha de quem parou de
        // bater ponto (afastado, férias, desligado) abre vazia, com "Não há batidas para a
        // competência selecionada". Este teste trava o padrão contra essa regressão.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $competenciaAtual = $agora->format('Y-m');
        $mesAntigo = $agora->modify('first day of this month')->modify('-4 months');
        $competenciaAntiga = $mesAntigo->format('Y-m');

        $this->criarRegistro($colaborador, $tenant, $mesAntigo->format('Y-m-d') . ' 09:00:00');

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));

        self::assertResponseIsSuccessful();
        $conteudo = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(
            sprintf('<option value="%s" selected>', $competenciaAntiga),
            $conteudo,
            'sem ?competencia= na URL, a ficha tem de abrir no último mês COM BATIDA',
        );
        self::assertStringNotContainsString(
            sprintf('<option value="%s" selected>', $competenciaAtual),
            $conteudo,
            'o mês corrente continua sendo uma OPÇÃO, mas não pode ser o mês de abertura quando não tem dado nenhum',
        );
    }

    #[TestDox('lancamento de horas pagas mais recente que a ultima batida vira o mes de abertura')]
    public function testFichaAbreNoMesDoLancamentoQuandoEleEMaisRecenteQueAUltimaBatida(): void
    {
        // A parte nova (e desejada) da regra: mês com LANÇAMENTO de horas pagas também conta como
        // "mês com dado". Batida em -5 meses, lançamento em -2 meses, hoje é o mês corrente: a ficha
        // abre no mês do lançamento — nem no mês da batida (dado mais velho), nem no corrente (vazio).
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $primeiroDia = $agora->modify('first day of this month');
        $mesDaBatida = $primeiroDia->modify('-5 months');
        $mesDoLancamento = $primeiroDia->modify('-2 months');

        $this->criarRegistro($colaborador, $tenant, $mesDaBatida->format('Y-m-d') . ' 09:00:00');
        $this->criarLancamento(
            $tenant,
            $colaborador,
            $autor,
            (int) $mesDoLancamento->format('Y'),
            (int) $mesDoLancamento->format('n'),
            -600,
        );

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));

        self::assertResponseIsSuccessful();
        $conteudo = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(
            sprintf('<option value="%s" selected>', $mesDoLancamento->format('Y-m')),
            $conteudo,
            'o mês do lançamento é o dado mais recente — é nele que a ficha tem de abrir',
        );
        self::assertStringNotContainsString(
            sprintf('<option value="%s" selected>', $mesDaBatida->format('Y-m')),
            $conteudo,
            'o mês da batida é mais antigo que o do lançamento — não pode ser o de abertura',
        );
        self::assertStringNotContainsString(
            sprintf('<option value="%s" selected>', $agora->format('Y-m')),
            $conteudo,
            'o mês corrente não tem dado nenhum — não pode ser o de abertura',
        );
    }

    #[TestDox('mes com lancamento e SEM batida: o titulo do card nomeia a competencia mesmo assim')]
    public function testTituloDoCardNomeiaACompetenciaEmMesComLancamentoESemBatida(): void
    {
        // `mesCompetenciaPonto`/`anoCompetenciaPonto` (únicas fontes do "- MM/AAAA" do título, em
        // tenant/edit_user_role.html.twig) só eram preenchidas quando a competência tinha batida.
        // Como a ficha agora pode ABRIR num mês com lançamento e sem batida nenhuma (rescisão,
        // afastamento), o card saía "Batidas de ponto" seco, com a linha "Horas pagas -10h00m"
        // logo abaixo: dinheiro exibido sem dizer de que mês é.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $mesDoLancamento = (new \DateTimeImmutable())->modify('first day of this month')->modify('-2 months');

        // Nenhuma batida em lugar nenhum — só o lançamento.
        $this->criarLancamento(
            $tenant,
            $colaborador,
            $autor,
            (int) $mesDoLancamento->format('Y'),
            (int) $mesDoLancamento->format('n'),
            -600,
        );

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));

        self::assertResponseIsSuccessful();
        $conteudo = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(
            sprintf('<h3 class="card-title mb-0">Batidas de ponto - %s</h3>', $mesDoLancamento->format('m/Y')),
            $conteudo,
            'o card tem de nomear a competência exibida mesmo quando ela não tem batida nenhuma',
        );
        // E é mesmo o mês do lançamento que está sendo exibido (o rodapé com o valor prova).
        $this->assertRodapeHorasPagasPresente($conteudo, '-10h00m');
    }

    /**
     * Confere o valor exatamente dentro do marcador HTML exclusivo do rodapé "Horas pagas" da folha
     * (`_folha_table.html.twig:282`) — `<span class="text-muted">Horas pagas</span>` seguido do
     * `<span class="fw-semibold ...">valor</span>`. Não existe em nenhum outro template: a aba
     * "Horas pagas" da Tarefa 5 (`_horas_pagas_tab.html.twig`) usa outra marcação e lista o MESMO
     * lançamento com a MESMA formatação de valor — checar só a substring do valor (ou só a palavra
     * "Horas pagas") colide com aquela aba e prova exatamente nada sobre o rodapé desta tarefa.
     */
    private function assertRodapeHorasPagasPresente(string $conteudo, string $valorEsperado): void
    {
        $padrao = '/<span class="text-muted">Horas pagas<\/span>\s*<span class="fw-semibold[^"]*">\s*'
            . preg_quote($valorEsperado, '/') . '\s*<\/span>/';

        self::assertMatchesRegularExpression(
            $padrao,
            $conteudo,
            'o rodapé da folha (Tarefa 6) tem de mostrar o valor exato — não basta a aba de lançamentos (Tarefa 5) mostrar o mesmo número em outro lugar da página',
        );
    }

    /**
     * Confere as TRÊS linhas do bloco de totais do rodapé (spec §7), na ordem, com os valores
     * exatos: "Saldo do mês", "Horas pagas" e o total "Total do mês". Verificar só a
     * soma não bastaria — o total certo com as parcelas erradas continua enganando quem confere.
     *
     * O rótulo do total é "Total do mês" de propósito, e o teste ancora a string: chamá-lo de
     * "banco de horas" colidiria com o card do topo da mesma página, que mostra o banco ACUMULADO
     * (ver o comentário em `_folha_table.html.twig` e a spec §7).
     */
    private function assertBlocoTotaisPresente(
        string $conteudo,
        string $saldoMesEsperado,
        string $horasPagasEsperado,
        string $totalEsperado,
    ): void {
        $padrao = '/<span class="text-muted">Saldo do mês<\/span>\s*<span class="fw-semibold[^"]*">\s*'
            . preg_quote($saldoMesEsperado, '/') . '\s*<\/span>'
            . '.*?<span class="text-muted">Horas pagas<\/span>\s*<span class="fw-semibold[^"]*">\s*'
            . preg_quote($horasPagasEsperado, '/') . '\s*<\/span>'
            . '.*?<span class="text-muted">Total do mês<\/span>\s*<span class="fw-bold[^"]*">\s*'
            . preg_quote($totalEsperado, '/') . '\s*<\/span>/s';

        self::assertMatchesRegularExpression(
            $padrao,
            $conteudo,
            sprintf(
                'o bloco de totais tem de sair "%s" + "%s" = "%s", nas três linhas e nesta ordem',
                $saldoMesEsperado,
                $horasPagasEsperado,
                $totalEsperado,
            ),
        );
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant HORAS PAGAS FOLHA ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarColaborador(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('colab_folha_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Folha Horas Pagas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Perfil ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    /**
     * Colaborador com `TenantRole` de verdade (NÃO-isSystem, logo sem bypass) carregando a permissão
     * real `modules.ponto.view` — prova que a rota é acessada como o próprio colaborador enxergaria,
     * não por um perfil administrativo que passa por qualquer checagem.
     */
    private function criarColaboradorComum(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $codigoPermissao = 'modules.ponto.view';
        $permissao = $em->getRepository(Permission::class)->findOneBy(['code' => $codigoPermissao]);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode($codigoPermissao);
            $permissao->setDescription('Permissão de teste ' . $codigoPermissao);
            $permissao->setGroup(explode('.', $codigoPermissao)[0]);
            $em->persist($permissao);
        }

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Colaborador comum ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $vinculoPermissao = new TenantRolePermission();
        $vinculoPermissao->setTenantRole($role);
        $vinculoPermissao->setPermission($permissao);
        $em->persist($vinculoPermissao);
        $role->getTenantRolePermissions()->add($vinculoPermissao);

        $user = new User();
        $user->setEmail('colab_comum_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Comum Folha Horas Pagas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarAdmin(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('admin_folha_' . uniqid() . '@test.com');
        $user->setFullName('Admin Folha Horas Pagas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    /**
     * Jornada sem nenhum dia de trabalho: a carga esperada do dia vira 0 em qualquer dia da semana
     * (`CalculadoraJornada::calcularSaldoDia`), então o saldo do dia é exatamente o que foi batido —
     * o único jeito de fixar o "Saldo do mês" num teste que roda em qualquer data do calendário.
     * Também garante `getJornadaColaborador() !== null`, sem o que a ficha do admin nem apura saldo.
     */
    private function criarJornadaSemDiasDeTrabalho(User $user): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $jornada = new JornadaColaborador();
        $jornada->setUser($user);
        $jornada->setDiasSemana([]);
        $em->persist($jornada);
        $user->setJornadaColaborador($jornada);
        $em->flush();
    }

    private function criarRegistro(User $user, Tenant $tenant, string $dataHora, string $tipo = RegistroPonto::TIPO_ENTRADA): RegistroPonto
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime($dataHora));
        $registro->setSedeNomeSnapshot('Teste');
        $em->persist($registro);
        $em->flush();

        return $registro;
    }

    private function criarLancamento(Tenant $tenant, User $colaborador, User $autor, int $ano, int $mes, int $minutos): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($tenant);
        $lancamento->setUser($colaborador);
        $lancamento->setAno($ano);
        $lancamento->setMes($mes);
        $lancamento->setMinutos($minutos);
        $lancamento->setMotivo('Lançamento de fixture');
        $lancamento->setCriadoPor($autor);
        $lancamento->setCriadoEm(new \DateTimeImmutable());
        $em->persist($lancamento);
        $em->flush();
    }
}
