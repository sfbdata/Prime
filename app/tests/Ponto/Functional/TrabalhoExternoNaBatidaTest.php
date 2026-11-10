<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\DTO\DadosDoComprovante;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\AlertasAntesDaBatida;
use App\Ponto\Service\CercaDoPonto;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\TrabalhoExternoDaBatida;
use App\Ponto\UseCase\ExcluirJustificativaEmAnaliseUseCase;
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
#[CoversClass(ExcluirJustificativaEmAnaliseUseCase::class)]
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
        self::assertSame([true, null, $batida['id']], [$eventos[0]['detalhe']['trabalho_externo'], $eventos[0]['detalhe']['sede'], $eventos[0]['detalhe']['registro_id']]);
        self::assertSame([[$justificativa['id']], 'trabalho_externo'], [$eventos[1]['detalhe']['justificativa_ids'], $eventos[1]['detalhe']['tipo']]);

        self::assertSame(1, $this->avisosDoGestor(), 'o gestor recebe o aviso da justificativa enviada');
    }

    #[TestDox('Com o selo pronto, o comprovante da batida externa leva a marca "Trabalho externo" no texto selado (E15)')]
    public function testComprovanteDaBatidaExterna(): void
    {
        static::getContainer()->set(ChavesDoHmacDoPonto::class, new ChavesDoHmacDoPonto('segredo-de-teste-do-comprovante'));

        $resposta = $this->bater(['tipo' => 'entrada', 'latitude' => self::FORA, 'longitude' => self::LNG, 'precisaoGps' => 12.5, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => self::MOTIVO]);

        self::assertSame('Ponto registrado como trabalho externo. Ele segue para a análise do gestor. Comprovante na aba Comprovantes.', $resposta['message']);
        $canonico = $this->emSemFiltro()->getConnection()->fetchOne('SELECT dados_assinados FROM ponto_comprovante WHERE user_id = ?', [$this->ana->getId()]);
        self::assertIsString($canonico);
        self::assertTrue(DadosDoComprovante::deCanonico($canonico)->trabalhoExterno);
        self::assertSame(1, $this->contarNoEscritorio('justificativa_ponto'), 'a justificativa ligada entra no mesmo COMMIT');
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
        self::assertFalse($this->eventos()[0]['detalhe']['trabalho_externo']);
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

    #[TestDox('Batida externa no 7º dia seguido (E11): o alerta volta sem gravar nada; o "Registrar assim mesmo" com o mesmo envio grava a batida externa, a justificativa ligada e o aviso ao gestor')]
    public function testAlertaAntesDaBatidaExterna(): void
    {
        for ($d = 1; $d <= AlertasAntesDaBatida::DIAS_SEGUIDOS_PARA_AVISAR; ++$d) {
            $this->batidaAntiga(new \DateTimeImmutable(sprintf('-%d days 08:00', $d)));
        }
        $antes = $this->contar('registro_ponto');
        $corpo = ['tipo' => 'entrada', 'latitude' => self::FORA, 'longitude' => self::LNG, 'precisaoGps' => 10.0, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => self::MOTIVO, 'idEnvio' => self::ID];

        $alerta = $this->bater($corpo);

        self::assertResponseIsSuccessful();
        self::assertFalse($alerta['success']);
        self::assertSame(sprintf(AlertasAntesDaBatida::ALERTA_DESCANSO_SEMANAL, 7), $alerta['alerta']);
        self::assertSame([$antes, 0, 0], [$this->contar('registro_ponto'), $this->contar('justificativa_ponto'), $this->avisosDoGestor()], 'o alerta não grava nada');

        $resposta = $this->bater($corpo + [AlertasAntesDaBatida::CAMPO_DA_CONFIRMACAO => true]);

        self::assertResponseIsSuccessful();
        self::assertTrue($resposta['success']);
        self::assertTrue($resposta['data']['trabalhoExterno']);
        $batida = $this->ultimaBatida();
        self::assertSame([true, self::MOTIVO, self::ID], [$batida['trabalho_externo'], $batida['motivo_trabalho_externo'], $batida['id_envio']]);
        self::assertSame($batida['id'], $this->unicaJustificativa()['registro_ponto_id']);
        self::assertSame([$antes + 1, 1], [$this->contar('registro_ponto'), $this->avisosDoGestor()]);
    }

    #[TestDox('Dois pedidos do mesmo envio ao mesmo tempo: o índice único barra o segundo, que responde "já registrada" sem a hora e não deixa batida, justificativa, trilha nem aviso')]
    public function testIndiceUnicoBarraOSegundoPedido(): void
    {
        // O envio já entrou em outro escritório da Ana: a consulta daqui não o vê, como o pedido que perdeu a corrida.
        $outro = $this->escritorio('Outro escritório');
        $ganhou = new RegistroPonto();
        $ganhou->setUser($this->ana);
        $ganhou->setTenant($outro);
        $ganhou->setTipo(RegistroPonto::TIPO_ENTRADA);
        $ganhou->setDataHora(new \DateTime('-2 hours'));
        $ganhou->setIdEnvio(self::ID);
        $this->em()->persist($ganhou);
        $this->em()->flush();

        $resposta = $this->bater(['tipo' => 'entrada', 'latitude' => self::FORA, 'longitude' => self::LNG, 'precisaoGps' => 10.0, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => self::MOTIVO, 'idEnvio' => self::ID]);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'success' => true,
            'message' => 'Esta batida já tinha sido registrada.',
            'data'    => ['tipo' => 'entrada', 'jaRegistrada' => true],
            'avisos'  => [],
        ], $resposta);
        self::assertSame([0, 0], [$this->contarNoEscritorio('registro_ponto'), $this->contarNoEscritorio('justificativa_ponto')], 'nada entrou aqui');
        self::assertSame([[], 0], [$this->eventos(), $this->avisosDoGestor()], 'nem a trilha nem o aviso ao gestor');
        self::assertSame(1, $this->contar('registro_ponto'), 'só a batida que ganhou a corrida, no outro escritório');
    }

    #[TestDox('A justificativa da batida de trabalho externo não sai pela exclusão do colaborador: 422 com a frase, e a justificativa, a batida e a trilha ficam; a não ligada continua saindo')]
    public function testColaboradorNaoExcluiAJustificativaDaBatida(): void
    {
        $avulsa = (int) $this->justificativa($this->ana, $this->escritorio, new \DateTimeImmutable('first day of this month'), 'pendente')->getId();
        $this->bater(['tipo' => 'entrada', 'latitude' => self::FORA, 'longitude' => self::LNG, 'precisaoGps' => 10.0, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => self::MOTIVO]);
        $daBatida = $this->justificativaDaBatida();

        $resposta = $this->excluir((int) $daBatida['id']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['ok' => false, 'mensagem' => 'A justificativa da batida de trabalho externo não pode ser excluída. Ela segue para a análise do gestor.'], $resposta);
        self::assertSame($daBatida, $this->justificativaDaBatida(), 'a justificativa fica como estava');
        self::assertSame(1, $this->contar('registro_ponto'), 'a batida fica');
        self::assertSame(['batida_registrada', 'justificativa_enviada'], array_column($this->eventos(), 'acao'), 'a trilha não ganha a exclusão');

        self::assertSame(['ok' => true], $this->excluir($avulsa));
        self::assertNull($this->statusNoBanco($avulsa), 'a justificativa sem batida continua podendo sair');
    }

    #[TestDox('A justificativa da batida de trabalho externo não muda pela edição do colaborador: volta para a aba com o aviso, sem mudar nada; a não ligada continua editável')]
    public function testColaboradorNaoEditaAJustificativaDaBatida(): void
    {
        $avulsa = (int) $this->justificativa($this->ana, $this->escritorio, new \DateTimeImmutable('first day of this month'), 'pendente', ['motivo' => 'Consulta'])->getId();
        $this->bater(['tipo' => 'entrada', 'latitude' => self::FORA, 'longitude' => self::LNG, 'precisaoGps' => 10.0, 'trabalhoExterno' => true, 'motivoTrabalhoExterno' => self::MOTIVO]);
        $daBatida = $this->justificativaDaBatida();

        $flashes = $this->editar((int) $daBatida['id'], ['tipo' => 'atestado_medico', 'motivo' => 'Outro motivo']);

        self::assertResponseRedirects('/ponto/?competencia=' . (new \DateTimeImmutable())->format('Y-m') . '&aba=justificativas');
        self::assertSame(['A justificativa da batida de trabalho externo não pode ser editada. Ela segue para a análise do gestor.'], $flashes['warning'] ?? []);
        self::assertArrayNotHasKey('success', $flashes);
        self::assertSame($daBatida, $this->justificativaDaBatida(), 'nem o tipo nem o motivo mudam');

        $flashes = $this->editar($avulsa, ['tipo' => 'atestado_medico', 'motivo' => 'Consulta remarcada']);

        self::assertSame(['Justificativa atualizada com sucesso.'], $flashes['success'] ?? []);
        self::assertSame('Consulta remarcada', $this->emSemFiltro()->getConnection()->fetchOne('SELECT motivo FROM justificativa_ponto WHERE id = ?', [$avulsa]));
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
        self::assertTrue($detalhe['trabalho_externo']);
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

    /** Uma batida de um dia que passou, na sede, gravada direto (sem a tela), para o 7º dia seguido. */
    private function batidaAntiga(\DateTimeImmutable $quando): void
    {
        $registro = new RegistroPonto();
        $registro->setUser($this->ana);
        $registro->setTenant($this->escritorio);
        $registro->setTipo(RegistroPonto::TIPO_ENTRADA);
        $registro->setDataHora(\DateTime::createFromImmutable($quando));
        $this->em()->persist($registro);
        $this->em()->flush();
    }

    private function contar(string $tabela): int
    {
        return (int) $this->emSemFiltro()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE user_id = ?', [$this->ana->getId()]);
    }

    private function contarNoEscritorio(string $tabela): int
    {
        return (int) $this->emSemFiltro()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE user_id = ? AND tenant_id = ?', [$this->ana->getId(), $this->escritorio->getId()]);
    }

    /** @return array<string, mixed> a linha inteira, para provar que nada mudou */
    private function justificativaDaBatida(): array
    {
        $linhas = $this->emSemFiltro()->getConnection()->fetchAllAssociative('SELECT * FROM justificativa_ponto WHERE user_id = ? AND registro_ponto_id IS NOT NULL', [$this->ana->getId()]);
        self::assertCount(1, $linhas);

        return $linhas[0];
    }

    /** @return array<string, mixed> */
    private function excluir(int $id): array
    {
        $this->logarComTenant($this->client, $this->ana, $this->escritorio);
        $this->em()->clear();
        $this->client->request('POST', '/ponto/justificativa/' . $id . '/excluir', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax']);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * Edita pela janela "Editar justificativa", como a Ana, e devolve os avisos da volta.
     *
     * @param array<string, string> $campos
     *
     * @return array<string, list<string>>
     */
    private function editar(int $id, array $campos): array
    {
        $this->logarComTenant($this->client, $this->ana, $this->escritorio);
        $this->em()->clear();   // cada requisição com o EntityManager vazio, como em produção (o filtro do escritório vale)
        $this->client->request('POST', '/ponto/justificativa/' . $id . '/editar', ['_token' => 'TOKEN_editar_justificativa_' . $id] + $campos);

        return $this->client->getRequest()->getSession()->getFlashBag()->peekAll();
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
