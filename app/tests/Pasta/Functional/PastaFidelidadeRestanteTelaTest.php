<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lote P13 — resto da fidelidade da Pasta (3ª passada, `pendencias-pos-documentos.md` §3.1)
 * contra o desenho "02 - EXPEDIENTES 1.2.3.dc.html".
 *
 * N5 (⋮ do cabeçalho do Processo), confirmação de desvincular com o texto do desenho,
 * N7 (⋮ da observação de Detalhes, só "Copiar texto"), N9 ("hoje, " no Modificada em) e o
 * vazio do cartão de Clientes que o JS remonta. Todo assert de arranjo usa FILHO DIRETO / irmão
 * adjacente a partir do bloco certo. Peso de fonte e hover (N10, N11) são CSS — ver
 * `PastaFidelidadeRestanteFolhaTest`; o resto é smoke do dono.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaFidelidadeRestanteTelaTest extends JusPrimeWebTestCase
{
    /** @return array{0: EntityManagerInterface, 1: User, 2: Tenant, 3: Pasta} */
    private function criarBase(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant P13 ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('p13_' . uniqid() . '@test.com');
        $user->setFullName('Admin Fidelidade');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('NUP-P13-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($user);
        $pasta->setResponsavel($user);
        $em->persist($pasta);

        return [$em, $user, $tenant, $pasta];
    }

    private function vincularProcesso(EntityManagerInterface $em, Tenant $tenant, Pasta $pasta): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso('10593167220224013400');
        $processo->setTenant($tenant);
        $em->persist($processo);

        $vinculo = new PastaProcesso($pasta, $processo);
        $vinculo->setPrincipal(true);
        $em->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);

        return $processo;
    }

    // =========================================================================
    // N5 — ⋮ do cabeçalho do cartão de processos
    // =========================================================================

    #[TestDox('N5: o ⋮ do cabeçalho fica ENTRE o interruptor "Administrativo" e "Vincular processo"')]
    public function testMaisOpcoesEntreInterruptorEVincular(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $cab = '#processo .ps-processos > .ps-card-cab--painel';
        self::assertCount(
            1,
            $crawler->filter($cab . ' > form.js-pasta-administrativa + button.js-proc-menu-gatilho[aria-label="Mais opções de processos"] + button[data-bs-target="#modalVincularProcesso"]'),
            'interruptor · ⋮ · Vincular, nessa ordem e como filhos diretos do cabeçalho'
        );

        $menuId = 'psProcHdMenu' . $pasta->getId();
        self::assertSame($menuId, $crawler->filter($cab . ' > .js-proc-menu-gatilho')->attr('aria-controls'));
    }

    #[TestDox('N5: o menu tem só o item com lastro (administrativo), que submete o MESMO form do interruptor')]
    public function testMenuDoCabecalhoSoComAdministrativo(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $id   = $pasta->getId();
        $menu = $crawler->filter('#processo .ps-processos > #psProcHdMenu' . $id . '[role="menu"]');
        self::assertCount(1, $menu, 'o menu é filho direto do cartão (dentro dele, para voltar junto no XHR)');
        self::assertCount(1, $menu->filter('[role="menuitem"]'), 'Relatório geral e Enviar à Controladoria não têm função: não entram');

        $item = $menu->filter('#psProcHdMenu' . $id . ' > button[type="submit"][form="psAdmForm' . $id . '"]');
        self::assertCount(1, $item);
        self::assertSame('Marcar como administrativo sem processo', trim($item->text()));
        self::assertCount(1, $crawler->filter('form#psAdmForm' . $id . '.js-pasta-administrativa'), 'o form do interruptor é o alvo');
    }

    #[TestDox('N5: com a pasta marcada, o item vira "Desmarcar administrativo sem processo"')]
    public function testMenuDoCabecalhoDesmarcar(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $pasta->setAdministrativa(true);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertSame(
            'Desmarcar administrativo sem processo',
            trim($crawler->filter('#psProcHdMenu' . $pasta->getId() . ' > .js-proc-hd-adm')->text())
        );
    }

    // =========================================================================
    // Desvincular processo — texto da confirmação do desenho
    // =========================================================================

    #[TestDox('desvincular: o form do ⋮ do cartão leva o texto de confirmação do desenho (número + pasta)')]
    public function testTextoDaConfirmacaoDeDesvincular(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $this->vincularProcesso($em, $tenant, $pasta);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('#processo .ps-processo-menu > form.js-ajax-desvincular-processo');
        self::assertCount(1, $form);
        self::assertSame(
            'Desvincular o processo 1059316-72.2022.4.01.3400 da Pasta ' . $pasta->getNup() . "?\nOs dados do processo continuam salvos e você pode vincular de novo.",
            $form->attr('data-confirmar')
        );
    }

    // =========================================================================
    // N7 — ⋮ da observação de Detalhes
    // =========================================================================

    #[TestDox('N7: o ⋮ da observação vem logo depois da hora, com o menu de um item só ("Copiar texto")')]
    public function testMaisAcoesDaObservacao(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        $obs = new PastaObservacaoDetalhes();
        $obs->setPasta($pasta);
        $obs->setTenant($tenant);
        $obs->setAutor($user);
        $obs->setConteudo('Relato do cliente.');
        $em->persist($obs);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $topo = '#detalhes-obs-' . $obs->getId() . ' > .ps-anotacao-topo';
        self::assertCount(
            1,
            $crawler->filter($topo . ' > .ps-anotacao-data + button.js-obs-menu[aria-controls="psObsMenu' . $obs->getId() . '"]'),
            'o ⋮ é o irmão logo depois da hora'
        );

        $menu = $crawler->filter($topo . ' > #psObsMenu' . $obs->getId() . '[role="menu"]');
        self::assertCount(1, $menu);
        self::assertCount(1, $menu->filter('[role="menuitem"]'), 'só "Copiar texto" tem função hoje');
        self::assertSame('Copiar texto', trim($menu->filter('#psObsMenu' . $obs->getId() . ' > .js-obs-copiar')->text()));
    }

    #[TestDox('N7: o cartão montado em JS (observação recém-enviada) tem o MESMO ⋮ que o Twig')]
    public function testCartaoDoJsTemOMesmoMenu(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $inicio = strpos($html, 'function inserirItem(data)');
        self::assertNotFalse($inicio);
        $fim = strpos($html, 'obsLista.insertAdjacentHTML', $inicio);
        self::assertNotFalse($fim);
        $montador = substr($html, $inicio, $fim - $inicio);

        $data = strpos($montador, '<span class="ps-anotacao-data ps-num"');
        $btn  = strpos($montador, 'class="ps-obs-menu-btn js-obs-menu"');
        $menu = strpos($montador, 'id="psObsMenu${data.id}"');
        self::assertNotFalse($data);
        self::assertNotFalse($btn);
        self::assertNotFalse($menu);
        self::assertLessThan($btn, $data, 'a hora vem antes do ⋮');
        self::assertLessThan($menu, $btn);
        self::assertStringContainsString('class="ps-processo-menu-item js-obs-copiar"', $montador);
    }

    // =========================================================================
    // N9 — "Modificada em" com "hoje, "
    // =========================================================================

    #[TestDox('N9: modificada HOJE mostra "hoje, dd/mm/aaaa HH:MM"')]
    public function testModificadaHoje(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $valor = $this->valorModificadaEm($crawler);
        self::assertMatchesRegularExpression('#^hoje, \d{2}/\d{2}/\d{4} \d{2}:\d{2}$#', $valor);
    }

    #[TestDox('N9: modificada em outro dia mostra só a data e a hora, sem "hoje"')]
    public function testModificadaEmOutroDia(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();
        $em->getConnection()->executeStatement(
            "UPDATE pasta SET modificado_em = '2020-03-04 10:05:00' WHERE id = ?",
            [$pasta->getId()]
        );
        $em->clear();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertSame('04/03/2020 10:05', $this->valorModificadaEm($crawler));
    }

    private function valorModificadaEm(object $crawler): string
    {
        $campos = $crawler->filter('[data-trilho="registro-pasta"] > .ps-registro-pasta-campos > div');
        $valor  = null;
        $campos->each(static function ($c) use (&$valor): void {
            if (trim($c->filter('.ps-rotulo')->text()) === 'Modificada em') {
                $valor = trim($c->filter('.ps-registro-pasta-valor')->text());
            }
        });
        self::assertNotNull($valor, 'o campo "Modificada em" existe no registro da pasta');

        return (string) $valor;
    }

    // =========================================================================
    // Clientes — o vazio que o JS remonta ao desvincular o último
    // =========================================================================

    #[TestDox('clientes: #clientesList leva o identificador em texto solto, para o JS remontar o vazio igual ao Twig')]
    public function testListaDeClientesLevaONomeSolto(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $pasta->setNomeCliente('Condomínio Solto');

        $cliente = new ClientePF();
        $cliente->setEmail('p13c' . uniqid() . '@test.com');
        $cliente->setCep('80000-000');
        $cliente->setEndereco('Rua Um, 1');
        $cliente->setCidade('Curitiba');
        $cliente->setEstado('PR');
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto('Maria Aparecida');
        $cliente->setCpf('12345678901');
        $cliente->setRg('12.345.678-9');
        $cliente->setRgOrgaoExpedidor('SSP');
        $em->persist($cliente);
        $pasta->addCliente($cliente);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $lista = $crawler->filter('[data-trilho="clientes"] > #clientesList');
        self::assertCount(1, $lista);
        self::assertSame($pasta->getNomeCliente(), $lista->attr('data-nome-cliente'), 'o mesmo valor que o Twig imprime no vazio');
    }
}
