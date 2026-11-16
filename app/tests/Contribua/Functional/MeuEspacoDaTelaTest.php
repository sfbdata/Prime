<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Controller\ContribuaController;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\BonusDeEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\PedidoDeEspaco;
use App\Contribua\Enum\OrigemDaLiberacao;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Indicacao\Entity\Indicacao;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A região "Meu espaço" (#espaco) da tela Contribua igual ao desenho de 14 e 15/11 (etapa 4): caixa do pedido (N4),
 * cartões só liberados (N5, D2), garantias (A5) e painel de pedidos só do Master (N7).
 */
#[CoversClass(ContribuaController::class)]
final class MeuEspacoDaTelaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private Tenant $outro;
    private User $primario;
    private User $master;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = $this->tenant('A');
        $this->outro = $this->tenant('B');
        $this->primario = $this->pessoa('primario', $this->escritorio, true);
        $this->master = $this->pessoa('master', $this->escritorio, false, ['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('Sem liberação: sem cartões nem preços, caixa padrão, garantias "sem liberação" e arranjo por filho direto')]
    public function testSemLiberacao(): void
    {
        $this->abrir($this->primario);

        self::assertSelectorCount(1, '.ct-pagina > section#espaco[data-screen-label="Meu espaço"]');
        self::assertSelectorCount(0, '#espaco .ct-pacote');
        self::assertSelectorCount(0, '#espaco .ct-pacotes');
        self::assertSelectorNotExists('#espaco .ct-liberacao');
        self::assertStringNotContainsString('R$ 19,90', (string) $this->client->getCrawler()->filter('#espaco')->html());
        self::assertSelectorCount(1, '#espaco > .ct-espaco-corpo > .ct-pedido[data-espaco-pedido="padrao"]');
        self::assertSelectorTextSame('#espaco .ct-pedido > .ct-pedido-texto > b', 'Precisa de mais espaço?');
        self::assertSelectorTextContains('#espaco .ct-pedido > .ct-pedido-texto > span', 'Solicite e a equipe BlueJus libera as opções de espaço extra para o seu escritório. O espaço extra é um serviço opcional; solicitar não gera cobrança.');
        self::assertSelectorTextContains('#espaco .ct-pedido > button[data-espaco-solicitar]', 'Solicitar mais espaço');
        self::assertSelectorTextContains('#espaco > .ct-espaco-topo .ct-espaco-titulos > b', 'Seu espaço de armazenamento');
        self::assertSelectorTextContains('#espaco > .ct-espaco-topo .ct-espaco-titulos > span', 'O BlueJus é gratuito. Se o espaço ficar pequeno, solicite mais espaço. Nada do que você guardou é apagado.');
        self::assertSelectorTextContains('#espaco > .ct-espaco-uso .ct-espaco-uso-texto', 'de 10 GB usados');
        self::assertStringNotContainsString('no plano', (string) $this->client->getCrawler()->filter('#espaco > .ct-espaco-uso')->text());
        $this->assertGarantias('sem', ['Gratuito', 'Seus arquivos seguros', 'Consulta sempre liberada', 'Liberação pela equipe']);
        self::assertSelectorNotExists('#espaco [data-pedidos-espaco]');
        self::assertSelectorNotExists('#espaco [data-abrir-master]');
        self::assertStringNotContainsString('Liberado na hora', (string) $this->client->getResponse()->getContent());
        self::assertSelectorNotExists('#espaco .ct-pedido > button[data-espaco-solicitar][disabled]');
    }

    #[TestDox('A linha do bônus de indicação (função do sistema, fora do desenho) aparece sem ícone')]
    public function testBonusSemIcone(): void
    {
        $this->darBonus();
        $this->abrir($this->primario);

        self::assertSelectorCount(1, '#espaco > .ct-espaco-uso > .ct-espaco-bonus[data-ct-bonus]');
        self::assertSelectorCount(0, '#espaco [data-ct-bonus] i, #espaco [data-ct-bonus] .bi');
    }

    #[TestDox('Quem não é primário vê a caixa, mas o "Solicitar mais espaço" sai desabilitado (só o primário pede, D4)')]
    public function testSecundarioNaoSolicita(): void
    {
        $this->abrir($this->pessoa('secundario', $this->escritorio, false));

        self::assertSelectorCount(1, '#espaco .ct-pedido > button[data-espaco-solicitar][disabled]');
    }

    #[TestDox('Liberação do Master: cartões, linha "liberadas pela equipe", caixa "liberadas" com "Ver opções" e garantias "com liberação"')]
    public function testComLiberacaoDoMaster(): void
    {
        $this->liberarPeloMaster($this->escritorio);

        $this->abrir($this->primario);

        self::assertSelectorCount(4, '#espaco > .ct-espaco-corpo > .ct-pacotes > .ct-pacote');
        self::assertSelectorTextContains('#espaco > .ct-espaco-corpo > .ct-liberacao', 'Opções liberadas pela equipe BlueJus a seu pedido. O BlueJus continua gratuito; o pacote é opcional.');
        self::assertSelectorCount(1, '#espaco > .ct-espaco-corpo > .ct-liberacao > i.bi-unlock');
        self::assertSelectorNotExists('#espaco .ct-pacotes[hidden]');
        self::assertSelectorTextSame('#espaco .ct-pedido > .ct-pedido-texto > b', 'Opções de espaço extra liberadas');
        self::assertSelectorTextContains('#espaco .ct-pedido > .ct-pedido-texto > span', 'Escolha abaixo um pacote, se precisar. Sem fidelidade; nada do que você guardou é apagado.');
        self::assertSelectorTextContains('#espaco .ct-pedido > button[data-espaco-ver-opcoes]', 'Ver opções');
        self::assertSelectorTextContains('#espaco > .ct-espaco-topo .ct-espaco-titulos > span', 'O BlueJus continua gratuito. O espaço extra é opcional: escolha um pacote só se precisar. Nada do que você guardou é apagado.');
        $this->assertGarantias('com', ['Sistema gratuito', 'Seus arquivos seguros', 'Sem fidelidade', 'Nota fiscal']);
        self::assertSelectorTextContains('#espaco .ct-garantias', 'Nada é apagado, mesmo se cancelar o pacote.');
    }

    #[TestDox('Uso a partir de 97%: liberação automática (cartões e linha "liberadas automaticamente"), caixa "liberadas"')]
    public function testLiberacaoAutomaticaAos97(): void
    {
        $this->usoEmGb(9.8);

        $this->abrir($this->primario);

        self::assertSelectorCount(4, '#espaco .ct-pacotes > .ct-pacote');
        self::assertSelectorTextContains('#espaco > .ct-espaco-corpo > .ct-liberacao', 'Opções liberadas automaticamente porque o uso passou de 97%. O BlueJus continua gratuito; o pacote é opcional.');
        self::assertSelectorCount(1, '#espaco .ct-pedido[data-espaco-pedido="liberado"]');
        self::assertSelectorTextContains('#espaco .ct-espaco-titulos > b', 'Seu escritório está com 98% do espaço ocupado');
    }

    #[TestDox('A4: a 24,3 GB o pacote menor que o uso mostra "Não comporta seu uso" na tela')]
    public function testPacoteQueNaoComporta(): void
    {
        $this->usoEmGb(24.3);

        $this->abrir($this->primario);

        self::assertSelectorTextContains('#espaco .ct-pacote > button[data-pedir-plano="essencial"][disabled]', 'Não comporta seu uso');
        self::assertSelectorTextContains('#espaco .ct-espaco-titulos > b', 'Seu espaço está cheio');
        self::assertSelectorTextSame('#espaco .ct-pedido > .ct-pedido-texto > b', 'Opções de espaço extra liberadas');
    }

    #[TestDox('D2: escritório que já paga um plano continua vendo e gerindo o próprio plano, sem a linha de liberação')]
    public function testEscritorioComPlanoPagoVeOsPacotes(): void
    {
        $essencial = array_values(array_filter(static::getContainer()->get(PlanoRepository::class)->ativosEmOrdem(), static fn ($p): bool => $p->getCodigo() === 'essencial'))[0];
        $this->em->persist(new EspacoDoEscritorio($this->escritorio, $essencial));
        $this->em->flush();

        $this->abrir($this->primario);

        self::assertSelectorCount(4, '#espaco .ct-pacotes > .ct-pacote');
        self::assertSelectorCount(1, '#espaco .ct-pacote--atual');
        self::assertSelectorNotExists('#espaco .ct-liberacao');
        self::assertSelectorNotExists('#espaco [data-espaco-previa]');
    }

    #[TestDox('N4: pedido pendente mostra título, sub com a data e "Ver pedido"')]
    public function testPedidoPendente(): void
    {
        $this->em->persist(new PedidoDeEspaco($this->escritorio, $this->primario, 50, null, 3 * 1024 ** 3, 10 * 1024 ** 3, new \DateTimeImmutable('2026-11-14 09:05')));
        $this->em->flush();

        $this->abrir($this->primario);

        self::assertSelectorCount(1, '#espaco .ct-pedido.ct-pedido--pendente[data-espaco-pedido="pendente"] > i.bi-hourglass-split');
        self::assertSelectorTextSame('#espaco .ct-pedido > .ct-pedido-texto > b', 'Seu pedido de +50 GB está em análise');
        self::assertSelectorTextContains('#espaco .ct-pedido > .ct-pedido-texto > span', 'Enviado em 14/11/2026 09:05. A equipe BlueJus libera e avisa por e-mail. Enquanto isso, tudo continua funcionando.');
        self::assertSelectorTextContains('#espaco .ct-pedido > button[data-espaco-ver-pedido]', 'Ver pedido');
        self::assertSelectorCount(0, '#espaco .ct-pacote');
    }

    #[TestDox('N7: o painel só existe para o Master; vazio mostra "Nenhum pedido de espaço até agora." e a prévia, e o botão "Configurar pagamento" segue')]
    public function testPainelVazioDoMaster(): void
    {
        $this->abrir($this->primario);
        self::assertSelectorNotExists('[data-pedidos-espaco]');
        self::assertStringNotContainsString('Pedidos de espaço', (string) $this->client->getResponse()->getContent());

        $this->abrir($this->master);

        self::assertSelectorCount(1, '#espaco > .ct-espaco-corpo > .ct-pedidos[data-pedidos-espaco]');
        self::assertSelectorTextContains('.ct-pedidos > .ct-pedidos-cabeca > b', 'Pedidos de espaço · só Master');
        self::assertSelectorTextContains('.ct-pedidos > .ct-pedidos-cabeca > .ct-pedidos-resumo', '0 pendente(s) · 0 liberado(s)');
        self::assertSelectorTextContains('.ct-pedidos > .ct-pedidos-vazio', 'Nenhum pedido de espaço até agora.');
        self::assertSelectorTextContains('.ct-pedidos-cabeca > button[data-espaco-previa]', 'Ver prévia das opções de compra');
        // A prévia: cartões no HTML do Master, escondidos até o botão (só JS, nada é gravado).
        self::assertSelectorCount(4, '#espaco > .ct-espaco-corpo > .ct-pacotes[hidden] > .ct-pacote');
        self::assertSelectorTextContains('#espaco > .ct-espaco-corpo > .ct-liberacao[hidden]', 'Visível só para você, como Master. O escritório vê estas opções quando você liberar ou quando o uso passar de 97%.');
        self::assertSelectorExists('#espaco [data-abrir-master]');
        self::assertSelectorTextContains('#espaco .co-abrir-master', 'Configurar pagamento');
    }

    #[TestDox('N7: linha pendente de outro escritório com conta, estimativa, detalhe, motivo, selo e os dois botões; o escritório comum não vê nada')]
    public function testLinhaPendenteParaOMaster(): void
    {
        $pedido = new PedidoDeEspaco($this->outro, null, 100, 'Vamos digitalizar tudo', 3 * 1024 ** 3, 10 * 1024 ** 3, new \DateTimeImmutable('2026-11-14 09:05'));
        $this->em->persist($pedido);
        $this->em->flush();

        $this->abrir($this->master);

        self::assertSelectorTextContains('.ct-pedidos-resumo', '1 pendente(s) · 0 liberado(s)');
        self::assertSelectorNotExists('.ct-pedidos-vazio');
        $linha = '.ct-pedidos > .ct-pedido-linha[data-pedido-espaco="' . $pedido->getId() . '"]';
        self::assertSelectorCount(1, $linha);
        self::assertSelectorTextContains($linha . ' .ct-pedido-conta', (string) $this->outro->getName());
        self::assertSelectorTextContains($linha . ' .ct-pedido-linha-texto', 'estima precisar de mais de 50 GB');
        self::assertSelectorTextContains($linha . ' .ct-pedido-detalhe', 'uso 3 GB de 10 GB · pedido em 14/11/2026 09:05');
        self::assertSelectorTextContains($linha . ' .ct-pedido-motivo', '"Vamos digitalizar tudo"');
        self::assertSelectorTextSame($linha . ' > .ct-pedido-selo.ct-pedido-selo--pendente', 'Pendente');
        $liberar = $this->client->getCrawler()->filter($linha . ' > button.ct-pedido-liberar[data-pedido-decidir]');
        self::assertCount(1, $liberar);
        self::assertSame('Liberar opções de compra', trim($liberar->text()));
        self::assertSame('/contribua/espaco/pedidos/' . $pedido->getId() . '/liberar', $liberar->attr('data-url'));
        $recusar = $this->client->getCrawler()->filter($linha . ' > button.ct-pedido-recusar[data-pedido-decidir]');
        self::assertSame('Recusar', trim($recusar->text()));
        self::assertSame('/contribua/espaco/pedidos/' . $pedido->getId() . '/recusar', $recusar->attr('data-url'));

        $this->abrir($this->primario);
        self::assertStringNotContainsString((string) $this->outro->getName(), (string) $this->client->getResponse()->getContent());
        self::assertSelectorNotExists('[data-pedido-decidir]');
    }

    #[TestDox('N7: com as opções liberadas para o Master não há botão de prévia, e a linha de origem aparece à vista')]
    public function testMasterComOpcoesLiberadasSemPrevia(): void
    {
        $this->liberarPeloMaster($this->escritorio);

        $this->abrir($this->master);

        self::assertSelectorNotExists('[data-espaco-previa]');
        self::assertSelectorNotExists('#espaco [hidden]');
        self::assertSelectorCount(4, '#espaco .ct-pacotes > .ct-pacote');
    }

    /** @param list<string> $titulos */
    private function assertGarantias(string $versao, array $titulos): void
    {
        self::assertSelectorCount(1, '#espaco > .ct-espaco-corpo > .ct-garantias[data-garantias="' . $versao . '"]');
        self::assertSame($titulos, $this->client->getCrawler()->filter('#espaco .ct-garantias > .ct-garantia > span > b')->each(static fn ($b): string => trim($b->text())));
    }

    private function usoEmGb(float $gb): void
    {
        $consumo = $this->createStub(ConsumoDeArmazenamentoRepository::class);
        $consumo->method('bytesDoEscritorio')->willReturn((int) ($gb * RetratoDoEspaco::BYTES_POR_GB));
        static::getContainer()->set(ConsumoDeArmazenamentoRepository::class, $consumo);
    }

    private function darBonus(): void
    {
        $indicador = $this->pessoa('indicador', $this->outro, true);
        $agora = new \DateTimeImmutable();
        $indicacao = Indicacao::porCadastroDoLink($indicador, null, $this->primario, $agora);
        $this->em->persist($indicacao);
        $this->em->persist(new BonusDeEspaco($this->escritorio, $indicacao, BonusDeEspaco::GIGABYTES_DA_INDICACAO, $agora->modify('-1 day'), $agora->modify('+30 days')));
        $this->em->flush();
    }

    private function liberarPeloMaster(Tenant $escritorio): void
    {
        $espaco = new EspacoDoEscritorio($escritorio, static::getContainer()->get(PlanoRepository::class)->free());
        $espaco->liberarOpcoes(OrigemDaLiberacao::Master, new \DateTimeImmutable());
        $this->em->persist($espaco);
        $this->em->flush();
    }

    private function abrir(User $quem): void
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->client->request('GET', '/contribua');
        self::assertResponseIsSuccessful();
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório ESPACO ' . $nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $roles */
    private function pessoa(string $prefixo, Tenant $tenant, bool $primario, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . ucfirst($prefixo));
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName(($primario ? 'Primário ' : 'Equipe ') . uniqid());
        $papel->setIsSystem($primario);
        $this->em->persist($papel);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }
}
