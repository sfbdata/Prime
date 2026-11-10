<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Service\CercaDoPonto;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\TrabalhoExternoDaBatida;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Twig\Loader\FilesystemLoader;

/**
 * E12 do Ponto (spec ponto-desenho-0811 §5, P18 e P74): fora do raio vira trabalho externo, e o raio conta com a
 * precisão do GPS. Pelo POST /ponto/batida de verdade.
 *
 * A sede fica em (-23.55, -46.63) com raio de 100 m. Posições: na sede (0 m); a 0,0005 grau de latitude (cerca de
 * 56 m, dentro); a 0,0012 grau (cerca de 133 m, dentro só com a precisão); a 0,01 grau (cerca de 1.112 m, fora).
 */
#[CoversClass(PontoController::class)]
#[CoversClass(CercaDoPonto::class)]
#[CoversClass(TrabalhoExternoDaBatida::class)]
#[CoversClass(DetalheDaTrilhaDoPonto::class)]
#[Group('ponto')]
final class TrabalhoExternoNaBatidaTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const LAT = -23.55;
    private const LNG = -46.63;
    private const FORA = -23.56;
    private const PERTO = -23.5505;
    private const NA_BORDA = -23.5512;
    private const MOTIVO = 'Audiência no Fórum de Pinheiros';
    private const ID = '5c2a9e41-7b3d-4f60-9a1e-2d4c6b8f0a13';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $ana;
    private User $gestor;
    private Sede $sede;

    protected function setUp(): void
    {
        if ((int) (new \DateTimeImmutable())->format('Hi') < 5) {
            self::markTestSkipped('Nos primeiros 5 minutos do dia as batidas "de hoje" cairiam em ontem.');
        }
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->escritorio('Escritório Externo');
        $this->sede = $this->sede($this->escritorio, 'Sede Paulista');
        $this->ana = $this->pessoa('ana');
        $this->vincularColaborador($this->ana, $this->escritorio);
        $this->gestor = $this->pessoa('gestor');
        $this->vincularGestor($this->gestor, $this->escritorio);
    }

    #[TestDox('Fora do raio sem a função no escritório: 403 com `foraDoRaio.trabalhoExterno` false, sem a saída pelo trabalho externo; o pedido de trabalho externo também é recusado e nada grava')]
    public function testForaSemAFuncao(): void
    {
        $this->permitir($this->escritorio, false);
        $distancia = number_format(round(CercaDoPonto::distanciaEmMetros(self::FORA, self::LNG, self::LAT, self::LNG)), 0, ',', '.');

        $resposta = $this->bater(['tipo' => 'entrada', 'latitude' => self::FORA, 'longitude' => self::LNG, 'precisaoGps' => 10.0]);

        self::assertResponseStatusCodeSame(403);
        $detalhe = 'A sede mais próxima é Sede Paulista, a ' . $distancia . ' m. O raio permitido é de 100 m.';
        self::assertSame([
            'success'    => false,
            'message'    => 'Você está fora do raio permitido. ' . $detalhe,
            'foraDoRaio' => ['titulo' => 'Você está fora do raio permitido', 'detalhe' => $detalhe, 'trabalhoExterno' => false],
        ], $resposta);

        $this->bater(['tipo' => 'entrada', 'latitude' => self::FORA, 'longitude' => self::LNG, 'precisaoGps' => 10.0, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => self::MOTIVO]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([0, 0, []], [$this->contar('registro_ponto'), $this->contar('justificativa_ponto'), $this->eventos()]);
    }

    #[TestDox('Fora do raio com a função (padrão, sem linha de configuração) e sem pedido: 403 com `trabalhoExterno` true e a saída no detalhe')]
    public function testForaComAFuncaoSemPedido(): void
    {
        $resposta = $this->bater(['tipo' => 'entrada', 'latitude' => self::FORA, 'longitude' => self::LNG, 'precisaoGps' => 10.0]);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($resposta['foraDoRaio']['trabalhoExterno']);
        self::assertStringEndsWith('O raio permitido é de 100 m. Aproxime-se ou informe o trabalho externo.', $resposta['foraDoRaio']['detalhe']);
        self::assertSame('Você está fora do raio permitido. ' . $resposta['foraDoRaio']['detalhe'], $resposta['message']);
        self::assertSame(0, $this->contar('registro_ponto'));
    }

    #[TestDox('Fora do raio com o pedido e o motivo: 200; a batida sem sede, com a marca e o motivo; a justificativa em análise ligada; as duas trilhas; o gestor avisado')]
    public function testForaComPedidoRegistraTrabalhoExterno(): void
    {
        $resposta = $this->bater(['tipo' => 'entrada', 'latitude' => self::FORA, 'longitude' => self::LNG, 'precisaoGps' => 12.5, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => '  ' . self::MOTIVO . ' ']);

        self::assertResponseIsSuccessful();
        self::assertSame(true, $resposta['success']);
        self::assertSame('Ponto registrado como trabalho externo. Ele segue para a análise do gestor.', $resposta['message']);
        self::assertSame(['hora', 'tipo', 'trabalhoExterno'], array_keys($resposta['data']));
        self::assertSame(['entrada', true], [$resposta['data']['tipo'], $resposta['data']['trabalhoExterno']]);
        self::assertArrayHasKey('avisos', $resposta);

        $batida = $this->ultimaBatida();
        self::assertSame(
            [true, self::MOTIVO, null, null, false],
            [$batida['trabalho_externo'], $batida['motivo_trabalho_externo'], $batida['sede_id'], $batida['sede_nome_snapshot'], $batida['home_office']],
        );
        self::assertSame([self::FORA, self::LNG, 12.5], [(float) $batida['latitude'], (float) $batida['longitude'], (float) $batida['precisao_gps']]);

        $justificativa = $this->unicaJustificativa();
        self::assertSame(
            ['trabalho_externo', 'pendente', (new \DateTimeImmutable())->format('Y-m-d'), self::MOTIVO, $batida['id'], $this->ana->getId(), $this->escritorio->getId()],
            [$justificativa['tipo'], $justificativa['status'], $justificativa['data'], $justificativa['motivo'], $justificativa['registro_ponto_id'], $justificativa['user_id'], $justificativa['tenant_id']],
        );

        $eventos = $this->eventos();
        self::assertSame(['batida_registrada', 'justificativa_enviada'], array_column($eventos, 'acao'));
        self::assertSame([true, false, null, $batida['id']], [$eventos[0]['detalhe']['trabalho_externo'], $eventos[0]['detalhe']['sem_internet'], $eventos[0]['detalhe']['sede'], $eventos[0]['detalhe']['registro_id']]);
        self::assertSame([[$justificativa['id']], 'trabalho_externo'], [$eventos[1]['detalhe']['justificativa_ids'], $eventos[1]['detalhe']['tipo']]);
        self::assertStringEndsWith(', em trabalho externo', DetalheDaTrilhaDoPonto::texto('batida_registrada', $eventos[0]['detalhe']));

        self::assertSame(1, $this->avisosDoGestor(), 'o gestor recebe o aviso da justificativa enviada');
    }

    #[TestDox('Pedido de trabalho externo com o motivo vazio ou com mais de 500 caracteres: 422 e nada grava')]
    public function testMotivoVazioOuLongo(): void
    {
        foreach (['', '    ', null] as $motivo) {
            $resposta = $this->bater(['tipo' => 'entrada', 'latitude' => self::FORA, 'longitude' => self::LNG, 'precisaoGps' => 10.0, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => $motivo]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(['success' => false, 'message' => 'Informe o local e o motivo.'], $resposta);
        }

        $resposta = $this->bater(['tipo' => 'entrada', 'latitude' => self::FORA, 'longitude' => self::LNG, 'precisaoGps' => 10.0, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => str_repeat('m', 501)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['success' => false, 'message' => 'O local e o motivo podem ter até 500 caracteres.'], $resposta);

        self::assertSame([0, 0, []], [$this->contar('registro_ponto'), $this->contar('justificativa_ponto'), $this->eventos()]);
    }

    #[TestDox('Dentro do raio com o pedido de trabalho externo: o pedido é ignorado e a batida entra normal, na sede, sem justificativa')]
    public function testDentroIgnoraOPedido(): void
    {
        $resposta = $this->bater(['tipo' => 'entrada', 'latitude' => self::LAT, 'longitude' => self::LNG, 'precisaoGps' => 10.0, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => '']);

        self::assertResponseIsSuccessful();
        self::assertSame('Ponto registrado com sucesso!', $resposta['message']);
        self::assertSame('Sede Paulista', $resposta['data']['sede']);
        self::assertArrayNotHasKey('trabalhoExterno', $resposta['data']);
        $batida = $this->ultimaBatida();
        self::assertSame([false, null, $this->sede->getId(), 'Sede Paulista'], [$batida['trabalho_externo'], $batida['motivo_trabalho_externo'], $batida['sede_id'], $batida['sede_nome_snapshot']]);
        self::assertSame(0, $this->contar('justificativa_ponto'));
        self::assertSame(['batida_registrada'], array_column($this->eventos(), 'acao'));
        self::assertSame([false, false], [$this->eventos()[0]['detalhe']['trabalho_externo'], $this->eventos()[0]['detalhe']['sem_internet']]);
    }

    #[TestDox('Nunca mais rígido: com a precisão acima de 50 m e a distância dentro do raio, a batida entra na sede')]
    public function testPrecisaoRuimDentroDoRaioEntra(): void
    {
        $resposta = $this->bater(['tipo' => 'entrada', 'latitude' => self::PERTO, 'longitude' => self::LNG, 'precisaoGps' => 900.0]);

        self::assertResponseIsSuccessful();
        self::assertSame('Sede Paulista', $resposta['data']['sede']);
        self::assertFalse($this->ultimaBatida()['trabalho_externo']);
    }

    #[TestDox('Fora do raio por pouco: com a precisão até 50 m a leitura entra na sede; acima disso, 422 "GPS impreciso" com a saída pelo trabalho externo')]
    public function testIncerta(): void
    {
        $resposta = $this->bater(['tipo' => 'entrada', 'latitude' => self::NA_BORDA, 'longitude' => self::LNG, 'precisaoGps' => 60.0]);

        self::assertResponseStatusCodeSame(422);
        $detalhe = 'A precisão do GPS (60 m) não basta para confirmar que você está no raio da sede. Atualize a localização e tente de novo. Se está fora da sede, informe o trabalho externo.';
        self::assertSame([
            'success'    => false,
            'message'    => 'GPS impreciso. ' . $detalhe,
            'foraDoRaio' => ['titulo' => 'GPS impreciso', 'detalhe' => $detalhe, 'trabalhoExterno' => true],
        ], $resposta);
        self::assertSame(0, $this->contar('registro_ponto'));

        $resposta = $this->bater(['tipo' => 'entrada', 'latitude' => self::NA_BORDA, 'longitude' => self::LNG, 'precisaoGps' => 50.0]);
        self::assertResponseIsSuccessful();
        self::assertSame('Sede Paulista', $resposta['data']['sede'], 'distância menos precisão no raio, com a precisão no teto');
    }

    #[TestDox('GPS impreciso sem a função: 422 sem a saída; com a função e o pedido: entra como trabalho externo')]
    public function testIncertaComPedido(): void
    {
        $outro = $this->escritorio('Escritório Sem Externo');
        $this->sede($outro, 'Sede Vizinha');
        $this->permitir($outro, false);
        $this->vincularColaborador($this->ana, $outro);

        $this->logarComTenant($this->client, $this->ana, $outro);
        $resposta = $this->postar(['tipo' => 'entrada', 'latitude' => self::NA_BORDA, 'longitude' => self::LNG, 'precisaoGps' => 60.0, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => self::MOTIVO]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['GPS impreciso', false], [$resposta['foraDoRaio']['titulo'], $resposta['foraDoRaio']['trabalhoExterno']]);
        self::assertStringEndsWith('Atualize a localização e tente de novo.', $resposta['foraDoRaio']['detalhe']);

        $resposta = $this->bater(['tipo' => 'entrada', 'latitude' => self::NA_BORDA, 'longitude' => self::LNG, 'precisaoGps' => 60.0, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => self::MOTIVO]);
        self::assertResponseIsSuccessful();
        self::assertTrue($resposta['data']['trabalhoExterno'], 'a configuração do outro escritório não vale aqui');
        self::assertSame([true, null], [$this->ultimaBatida()['trabalho_externo'], $this->ultimaBatida()['sede_id']]);
        self::assertSame(1, $this->contar('justificativa_ponto'));
    }

    #[TestDox('Escritório sem sede: fora do raio (403, sem o antigo "Nenhuma sede configurada"); com o pedido, entra como trabalho externo')]
    public function testEscritorioSemSede(): void
    {
        $semSede = $this->escritorio('Escritório Sem Sede');
        $bia = $this->pessoa('bia');
        $this->vincularColaborador($bia, $semSede);
        $this->logarComTenant($this->client, $bia, $semSede);

        $resposta = $this->postar(['tipo' => 'entrada', 'latitude' => self::LAT, 'longitude' => self::LNG, 'precisaoGps' => 10.0]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            ['titulo' => 'Você está fora do raio permitido', 'detalhe' => 'O registro exige estar dentro do raio de uma sede do escritório. Aproxime-se ou informe o trabalho externo.', 'trabalhoExterno' => true],
            $resposta['foraDoRaio'],
        );

        $resposta = $this->postar(['tipo' => 'entrada', 'latitude' => self::LAT, 'longitude' => self::LNG, 'precisaoGps' => 10.0, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => self::MOTIVO]);
        self::assertResponseIsSuccessful();
        self::assertTrue($resposta['data']['trabalhoExterno']);
    }

    #[TestDox('Home office continua sem localização: a batida entra sem posição, e o pedido de trabalho externo é ignorado')]
    public function testHomeOfficeSemLocalizacao(): void
    {
        $config = new HomeOfficeConfig();
        $config->setUser($this->ana);
        $config->setTenant($this->escritorio);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();

        $resposta = $this->bater(['tipo' => 'entrada', 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => self::MOTIVO]);

        self::assertResponseIsSuccessful();
        self::assertSame('Ponto (home office) registrado com sucesso!', $resposta['message']);
        $batida = $this->ultimaBatida();
        self::assertSame([true, false, null, null], [$batida['home_office'], $batida['trabalho_externo'], $batida['motivo_trabalho_externo'], $batida['latitude']]);
        self::assertSame(0, $this->contar('justificativa_ponto'));
    }

    #[TestDox('O mesmo envio mandado duas vezes: uma batida e uma justificativa; a segunda resposta é "já registrada"')]
    public function testMesmoEnvioDuasVezes(): void
    {
        $corpo = ['tipo' => 'entrada', 'latitude' => self::FORA, 'longitude' => self::LNG, 'precisaoGps' => 10.0, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => self::MOTIVO, 'idEnvio' => self::ID];

        $this->bater($corpo);
        self::assertResponseIsSuccessful();
        $resposta = $this->bater($corpo);

        self::assertResponseIsSuccessful();
        self::assertTrue($resposta['data']['jaRegistrada']);
        self::assertSame([1, 1], [$this->contar('registro_ponto'), $this->contar('justificativa_ponto')]);
        self::assertSame(['batida_registrada', 'justificativa_enviada'], array_column($this->eventos(), 'acao'));
        self::assertSame(1, $this->avisosDoGestor());
    }

    #[TestDox('Batida de trabalho externo guardada sem internet: vale a hora do aparelho, e a justificativa leva o dia dela')]
    public function testDaFilaSemInternet(): void
    {
        $aparelho = (new \DateTimeImmutable('-1 day'))->setTime(23, 50);
        if ((new \DateTimeImmutable())->getTimestamp() - $aparelho->getTimestamp() > 72 * 3600) {
            self::fail('o aparelho deveria estar dentro do teto da fila');
        }

        $resposta = $this->bater([
            'tipo'                  => 'entrada',
            'latitude'              => self::FORA,
            'longitude'             => self::LNG,
            'precisaoGps'           => 10.0,
            'trabalhoExterno'       => true,
            'motivoTrabalhoExterno' => self::MOTIVO,
            'idEnvio'               => self::ID,
            'horaAparelho'          => $aparelho->getTimestamp() * 1000,
            'semInternet'           => true,
            'usuarioId'             => $this->ana->getId(),
            'escritorioId'          => $this->escritorio->getId(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('23:50:00', $resposta['data']['hora']);
        $batida = $this->ultimaBatida();
        self::assertSame([$aparelho->format('Y-m-d H:i:s'), true, true], [$batida['data_hora'], $batida['enviada_sem_internet'], $batida['trabalho_externo']]);
        self::assertSame($aparelho->format('Y-m-d'), $this->unicaJustificativa()['data'], 'o dia do momento da batida, não o da chegada');
        $detalhe = $this->eventos()[0]['detalhe'];
        self::assertSame([true, true], [$detalhe['trabalho_externo'], $detalhe['sem_internet']]);
        self::assertStringEndsWith(', em trabalho externo, enviada sem internet', DetalheDaTrilhaDoPonto::texto('batida_registrada', $detalhe));
    }

    #[TestDox('A folha da /ponto marca o dia com a etiqueta "Trabalho externo", na célula da semana, ao lado das outras')]
    public function testFolhaMarcaOTrabalhoExterno(): void
    {
        $this->bater(['tipo' => 'entrada', 'latitude' => self::FORA, 'longitude' => self::LNG, 'precisaoGps' => 10.0, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => self::MOTIVO]);
        self::assertResponseIsSuccessful();

        $this->logarComTenant($this->client, $this->ana, $this->escritorio);
        $tela = $this->client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();

        $linha = $tela->filter(sprintf('table.pe-tab.pe-resp > tbody > tr.pe-lin[data-chave-dia="%s"]', (new \DateTimeImmutable())->format('Y-m-d')));
        self::assertCount(1, $linha);
        $etiqueta = $linha->filter('td.pe-larga:nth-child(2) > span.pe-tag.pe-tag--info.pe-tag--extra[title="Batida registrada como trabalho externo, fora do raio das sedes."]');
        self::assertCount(1, $etiqueta);
        self::assertCount(1, $etiqueta->filter('i.bi.bi-briefcase[aria-hidden="true"]'));
        self::assertSame('Trabalho externo', trim($etiqueta->text()));
    }

    #[TestDox('A /ponto passa `trabalho_externo_permitido`: só com a cerca valendo hoje e a função permitida')]
    public function testVariavelDaTela(): void
    {
        self::assertSame('[sim]', $this->variavelDaTela());

        $this->permitir($this->escritorio, false);
        self::assertSame('[nao]', $this->variavelDaTela(), 'sem a função');

        $this->permitir($this->escritorio, true);
        $config = new HomeOfficeConfig();
        $config->setUser($this->em()->getReference(User::class, $this->ana->getId()));
        $config->setTenant($this->em()->getReference(Tenant::class, $this->escritorio->getId()));
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();
        self::assertSame('[nao]', $this->variavelDaTela(), 'em home office a cerca não vale');
    }

    /**
     * O valor de `trabalho_externo_permitido` que a /ponto passa ao template. O template da tela é de outra frente: aqui
     * um template de teste, na frente do de verdade no carregador do Twig, só escreve a variável.
     */
    private function variavelDaTela(): string
    {
        $pasta = sys_get_temp_dir() . '/ponto_e12_' . bin2hex(random_bytes(6));
        mkdir($pasta . '/ponto', 0o777, true);
        file_put_contents($pasta . '/ponto/index.html.twig', '[{{ trabalho_externo_permitido is same as(true) ? "sim" : (trabalho_externo_permitido is same as(false) ? "nao" : "outro") }}]');
        $carregador = static::getContainer()->get('twig')->getLoader();
        self::assertInstanceOf(FilesystemLoader::class, $carregador);
        $carregador->prependPath($pasta);

        try {
            $this->logarComTenant($this->client, $this->ana, $this->escritorio);
            $this->client->request('GET', '/ponto/');
            self::assertResponseIsSuccessful();

            return (string) $this->client->getResponse()->getContent();
        } finally {
            unlink($pasta . '/ponto/index.html.twig');
            rmdir($pasta . '/ponto');
            rmdir($pasta);
        }
    }

    private function permitir(Tenant $tenant, bool $permite): void
    {
        $em = $this->em();   // as entidades do cenário podem ter saído do EntityManager depois de uma requisição
        $tenant = $em->getReference(Tenant::class, $tenant->getId());
        $configuracao = $em->getRepository(ConfiguracaoDoPonto::class)->findOneBy(['tenant' => $tenant]) ?? new ConfiguracaoDoPonto($tenant);
        $configuracao->definirTrabalhoExterno($permite, $em->getReference(User::class, $this->gestor->getId()), new \DateTimeImmutable());
        $em->persist($configuracao);
        $em->flush();
    }

    private function sede(Tenant $tenant, string $nome): Sede
    {
        $sede = new Sede();
        $sede->setNome($nome);
        $sede->setLatitude((string) self::LAT);
        $sede->setLongitude((string) self::LNG);
        $sede->setRaioPermitido(100);
        $sede->setTimezone('America/Sao_Paulo');
        $sede->setTenant($tenant);
        $this->em()->persist($sede);
        $this->em()->flush();

        return $sede;
    }

    /**
     * Bate como a Ana, no escritório do cenário.
     *
     * @param array<string, mixed> $corpo
     *
     * @return array<string, mixed>
     */
    private function bater(array $corpo): array
    {
        $this->logarComTenant($this->client, $this->ana, $this->escritorio);

        return $this->postar($corpo);
    }

    /**
     * @param array<string, mixed> $corpo
     *
     * @return array<string, mixed>
     */
    private function postar(array $corpo): array
    {
        $this->client->request('POST', '/ponto/batida', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], content: (string) json_encode($corpo));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> */
    private function ultimaBatida(): array
    {
        return (array) $this->emSemFiltro()->getConnection()->fetchAssociative('SELECT * FROM registro_ponto WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$this->ana->getId()]);
    }

    /** @return array<string, mixed> */
    private function unicaJustificativa(): array
    {
        $linhas = $this->emSemFiltro()->getConnection()->fetchAllAssociative('SELECT * FROM justificativa_ponto WHERE user_id = ?', [$this->ana->getId()]);
        self::assertCount(1, $linhas);

        return $linhas[0];
    }

    private function contar(string $tabela): int
    {
        return (int) $this->emSemFiltro()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE user_id = ?', [$this->ana->getId()]);
    }

    private function avisosDoGestor(): int
    {
        return (int) $this->emSemFiltro()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM notificacao WHERE usuario_id = ? AND tenant_id = ? AND tipo = ?',
            [$this->gestor->getId(), $this->escritorio->getId(), Notificacao::TIPO_PONTO_JUSTIFICATIVA_ENVIADA],
        );
    }

    /** @return list<array{acao: string, detalhe: array<string, mixed>}> */
    private function eventos(): array
    {
        $linhas = $this->emSemFiltro()->getConnection()->fetchAllAssociative(
            'SELECT acao, detalhe FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia',
            [$this->escritorio->getId()],
        );

        return array_map(static fn (array $l): array => ['acao' => (string) $l['acao'], 'detalhe' => (array) json_decode((string) $l['detalhe'], true)], $linhas);
    }
}
