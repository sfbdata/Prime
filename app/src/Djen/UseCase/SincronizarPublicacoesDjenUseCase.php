<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\PeriodoConsulta;
use App\Djen\DTO\ResultadoSincronizacao;
use App\Djen\Entity\OabMonitorada;
use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Enum\MotivoFalhaDjen;
use App\Djen\Exception\ConsultaDjenException;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Service\DjenClientInterface;
use App\Djen\Service\DjenPublicacaoMapper;
use App\Djen\Service\EsperaEntreConsultasInterface;
use App\Djen\Service\NotificadorPublicacoesDjenInterface;
use App\Entity\Tenant\Tenant;
use App\Processo\Repository\ProcessoRepository;

/**
 * Sincroniza as publicações do DJEN de UM escritório por duas fontes:
 *
 *  1. cada OAB ativa monitorada (o que sempre existiu);
 *  2. cada processo CADASTRADO no escritório, sem baixa e sem monitoramento pausado, consultado
 *     pelo próprio número (e cada consulta bem-sucedida grava a última verificação). Pega a
 *     publicação em que nenhuma OAB monitorada aparece (advogado de fora da lista, substabelecimento,
 *     processo assumido em andamento), que pela OAB nunca chegaria.
 *
 * Nas duas, consulta o DJEN no período, deduplica (por escritório + dentro da execução, então a mesma
 * publicação vinda pela OAB e pelo número vira UMA linha), persiste as novas vinculando ao Processo
 * quando o número CNJ já existe no escritório, e notifica os usuários com acesso ao módulo.
 *
 * Recebe o Tenant EXPLICITAMENTE — roda tanto no request (botão "sincronizar agora") quanto no comando
 * CLI (TenantFilter desligado). Toda query e criação é escopada por esse tenant; nunca depende do filtro.
 *
 * Idempotente: reexecutar o mesmo período não duplica (dedupe por (tenant, djen_id)) nem renotifica
 * publicações já existentes (só as realmente novas contam).
 */
final class SincronizarPublicacoesDjenUseCase
{
    private const ITENS_POR_PAGINA = 100;
    private const MAX_PAGINAS = 100; // teto de 10.000 resultados da API
    private const DIGITOS_NUMERO_CNJ = 20;
    private const ROTULO_PROCESSOS = 'Processos cadastrados';
    /** Janela do limite do DJEN (20 requisições por IP), com 1s de folga. */
    private const SEGUNDOS_JANELA_LIMITE = 61;
    private const MAX_ESPERAS_SEGUIDAS = 3;

    public function __construct(
        private readonly DjenClientInterface $djenClient,
        private readonly DjenPublicacaoMapper $mapper,
        private readonly OabMonitoradaRepository $oabRepository,
        private readonly PublicacaoDjenRepository $publicacaoRepository,
        private readonly ProcessoRepository $processoRepository,
        private readonly NotificadorPublicacoesDjenInterface $notificador,
        private readonly ReconciliarPublicacoesComProcessosUseCase $reconciliar,
        private readonly EsperaEntreConsultasInterface $espera,
    ) {
    }

    /**
     * Sincronização do escritório pelas duas fontes, dentro de um REQUEST (botão "Sincronizar agora").
     *
     * As OABs vão inteiras. Os processos cadastrados vão em MELHOR ESFORÇO: o DJEN aceita 20
     * requisições por IP na janela (medido em 16/10/2026) e um request não pode esperar a janela
     * passar. Vão primeiro os cadastrados mais recentemente (quem acabou de cadastrar vê na hora);
     * no primeiro sinal de limite ou de lentidão o laço para, e o resumo diz quantos ficaram para a
     * captação diária, que cobre todos (ver `sincronizarProcessosCobrindoTodos`).
     *
     * Com `$incluirProcessos = false` só as OABs são consultadas: é a primeira fase do comando.
     */
    public function executar(
        Tenant $tenant,
        PeriodoConsulta $periodo,
        bool $dryRun = false,
        bool $incluirProcessos = true,
    ): ResultadoSincronizacao {
        $resultado = new ResultadoSincronizacao();
        $vistosNesteRun = [];

        foreach ($this->oabRepository->listarAtivasDoTenant($tenant) as $oab) {
            try {
                $itens = $this->consultarTodosItens(static fn (int $pagina): ConsultaComunicacoesQuery => new ConsultaComunicacoesQuery(
                    numeroOab: $oab->getNumero(),
                    ufOab: $oab->getUf(),
                    dataDisponibilizacaoInicio: $periodo->inicio,
                    dataDisponibilizacaoFim: $periodo->fim,
                    pagina: $pagina,
                    itensPorPagina: self::ITENS_POR_PAGINA,
                ));
            } catch (ConsultaDjenException $e) {
                // Falha de uma OAB não derruba as demais — registra o motivo e segue.
                $resultado->registrarFalha($oab->getRotulo(), $e->getMotivo());

                continue;
            }

            $resultado->registrarRecebidas(\count($itens));
            $this->processarItens($oab, $tenant, $itens, $dryRun, $vistosNesteRun, $resultado);
        }

        if ($incluirProcessos) {
            $this->consultarProcessos($tenant, $this->numerosAcompanhados($tenant), $periodo, $dryRun, $vistosNesteRun, $resultado, false);
        }

        $this->concluir($tenant, $dryRun, $resultado);
        $this->registrarVerificacoes($tenant, $dryRun, $resultado);

        // A vinculação acima olha o cadastro no instante da captura. Quando o processo entra no
        // sistema DEPOIS — o caso normal ao assumir um caso em andamento —, a publicação ficaria
        // órfã para sempre e a tela do módulo a chamaria de "Avulsa", que é falso. Reconciliar aqui
        // faz a captação diária curar o passivo sozinha, em duas consultas.
        if (!$dryRun) {
            $this->reconciliar->executar($tenant);
        }

        return $resultado;
    }

    /**
     * Segunda fase do comando diário: TODOS os processos cadastrados (sem baixa) do escritório,
     * pelo número. Ao bater no limite do DJEN, espera a janela passar e retoma do MESMO número,
     * então nenhum fica de fora por estar no fim da lista. O comando roda esta fase depois das
     * OABs de todos os escritórios, para a cota gasta aqui nunca faltar à captação por OAB.
     *
     * @param list<string> $djenIdsJaCaptados djenId que a fase de OABs já contou como novos
     */
    public function sincronizarProcessosCobrindoTodos(
        Tenant $tenant,
        PeriodoConsulta $periodo,
        bool $dryRun = false,
        array $djenIdsJaCaptados = [],
    ): ResultadoSincronizacao {
        $resultado = new ResultadoSincronizacao();
        // O que a fase de OABs já captou. Na execução real o banco já sabe disso; no --dry-run nada
        // foi gravado, e sem isto a simulação contaria em dobro a publicação vinda pelas duas fontes.
        $vistosNesteRun = array_fill_keys($djenIdsJaCaptados, true);

        $this->consultarProcessos($tenant, $this->numerosAcompanhados($tenant), $periodo, $dryRun, $vistosNesteRun, $resultado, true);
        $this->concluir($tenant, $dryRun, $resultado);
        $this->registrarVerificacoes($tenant, $dryRun, $resultado);

        return $resultado;
    }

    /**
     * "Buscar agora" do painel do Push na pasta (spec push-painel-monitoramento §2): só os números
     * informados (os processos da pasta com monitoramento ativo), dentro de um REQUEST. Mesmas regras
     * da fase de processos do botão do módulo: deduplica, vincula, notifica as novas, para no
     * primeiro sinal de limite do DJEN (o resultado conta os adiados) e reconcilia as avulsas.
     *
     * @param list<string> $numeros números CNJ, com ou sem máscara
     */
    public function sincronizarNumeros(Tenant $tenant, PeriodoConsulta $periodo, array $numeros): ResultadoSincronizacao
    {
        $resultado = new ResultadoSincronizacao();
        $vistosNesteRun = [];

        $this->consultarProcessos($tenant, $this->numerosCnj($numeros), $periodo, false, $vistosNesteRun, $resultado, false);
        $this->concluir($tenant, false, $resultado);
        $this->registrarVerificacoes($tenant, false, $resultado);
        $this->reconciliar->executar($tenant);

        return $resultado;
    }

    /** @return list<string> os números acompanhados do escritório (sem baixa e sem pausa), só dígitos */
    private function numerosAcompanhados(Tenant $tenant): array
    {
        return $this->numerosCnj($this->processoRepository->listarNumerosAcompanhadosDoTenant($tenant));
    }

    /**
     * @param list<string> $numeros
     *
     * @return list<string> só os que têm os 20 dígitos do CNJ, sem a máscara
     */
    private function numerosCnj(array $numeros): array
    {
        $validos = [];
        foreach ($numeros as $numeroCadastrado) {
            $numero = preg_replace('/\D/', '', $numeroCadastrado) ?? '';
            if (\strlen($numero) === self::DIGITOS_NUMERO_CNJ) {
                $validos[] = $numero;
            }
        }

        return $validos;
    }

    /**
     * A última verificação dos números consultados com sucesso (spec push-painel-monitoramento §1).
     * Só DEPOIS do flush das publicações (`concluir`): se gravar o que o DJEN trouxe falhar, o
     * processo não pode aparecer como verificado. O --dry-run não grava nada.
     */
    private function registrarVerificacoes(Tenant $tenant, bool $dryRun, ResultadoSincronizacao $resultado): void
    {
        if (!$dryRun) {
            $this->processoRepository->registrarVerificacaoDosNumeros($tenant, $resultado->getNumerosConsultados(), new \DateTimeImmutable());
        }
    }

    private function concluir(Tenant $tenant, bool $dryRun, ResultadoSincronizacao $resultado): void
    {
        if (!$dryRun && $resultado->getNovas() > 0) {
            $this->publicacaoRepository->flush();
            $resultado->definirNotificados($this->notificador->notificar($tenant, $resultado->getNovas()));
        }
    }

    /**
     * Uma consulta por processo cadastrado (sem baixa), pelo número CNJ, do mais recente ao mais
     * antigo.
     *
     * - `$aguardarNoLimite = true` (comando): no 429 espera a janela e repete o mesmo número, até
     *   `MAX_ESPERAS_SEGUIDAS` vezes seguidas; outra falha de um número não derruba os demais.
     * - `$aguardarNoLimite = false` (request): no 429 para e conta o que ficou para a captação
     *   diária (não é falha: é o desenho). Qualquer outra falha também para, registrada uma vez só:
     *   com timeout de 30s por chamada, insistir derrubaria o request no limite do nginx.
     *
     * Falta de configuração para nos dois modos: as consultas seguintes falhariam igual.
     *
     * @param list<string>        $numeros        números CNJ só com dígitos, na ordem de consulta
     * @param array<string, true> $vistosNesteRun djenId já criados nesta execução
     */
    private function consultarProcessos(
        Tenant $tenant,
        array $numeros,
        PeriodoConsulta $periodo,
        bool $dryRun,
        array &$vistosNesteRun,
        ResultadoSincronizacao $resultado,
        bool $aguardarNoLimite,
    ): void {
        $indice = 0;
        $esperasSeguidas = 0;
        $total = \count($numeros);

        while ($indice < $total) {
            $numero = $numeros[$indice];

            try {
                $itens = $this->consultarTodosItens(static fn (int $pagina): ConsultaComunicacoesQuery => new ConsultaComunicacoesQuery(
                    dataDisponibilizacaoInicio: $periodo->inicio,
                    dataDisponibilizacaoFim: $periodo->fim,
                    pagina: $pagina,
                    itensPorPagina: self::ITENS_POR_PAGINA,
                    numeroProcesso: $numero,
                ));
            } catch (ConsultaDjenException $e) {
                $motivo = $e->getMotivo();

                if ($motivo === MotivoFalhaDjen::NaoConfigurado) {
                    $resultado->registrarFalha(self::ROTULO_PROCESSOS, $motivo);

                    return;
                }

                if ($motivo === MotivoFalhaDjen::LimiteExcedido && $aguardarNoLimite) {
                    if ($esperasSeguidas >= self::MAX_ESPERAS_SEGUIDAS) {
                        $resultado->registrarFalha(self::ROTULO_PROCESSOS, $motivo);
                        $resultado->registrarProcessosAdiados($total - $indice);

                        return;
                    }

                    ++$esperasSeguidas;
                    $this->espera->aguardar(self::SEGUNDOS_JANELA_LIMITE);

                    continue; // repete o MESMO número
                }

                if ($motivo === MotivoFalhaDjen::LimiteExcedido) {
                    $resultado->registrarProcessosAdiados($total - $indice);

                    return;
                }

                if (!$aguardarNoLimite) {
                    $resultado->registrarFalha(self::ROTULO_PROCESSOS, $motivo);
                    $resultado->registrarProcessosAdiados($total - $indice);

                    return;
                }

                $resultado->registrarFalha('Processo ' . $numero, $motivo);
                ++$indice;
                $esperasSeguidas = 0;

                continue;
            }

            $esperasSeguidas = 0;
            ++$indice;
            $resultado->registrarProcessoConsultado($numero);
            $resultado->registrarRecebidas(\count($itens));
            $this->processarItens(null, $tenant, $itens, $dryRun, $vistosNesteRun, $resultado);
        }
    }

    /**
     * @param list<array<string, mixed>> $itens
     * @param array<string, true>        $vistosNesteRun djenId já criados nesta execução (dedupe entre
     *                                                   OABs e entre OAB e número de processo)
     */
    private function processarItens(
        ?OabMonitorada $oab,
        Tenant $tenant,
        array $itens,
        bool $dryRun,
        array &$vistosNesteRun,
        ResultadoSincronizacao $resultado,
    ): void {
        // Dedupe intra-lote e contra o que já foi criado nesta execução (evita violar o unique).
        $itensPorId = [];
        foreach ($itens as $item) {
            $djenId = DjenPublicacaoMapper::djenIdDoItem($item);
            if ($djenId === null || isset($vistosNesteRun[$djenId])) {
                continue;
            }

            $itensPorId[$djenId] = $item;
        }

        if ($itensPorId === []) {
            return;
        }

        // Dedupe contra o banco (uma query) — escopado por tenant.
        $existentes = array_flip(
            $this->publicacaoRepository->filtrarDjenIdsExistentesDoTenant(array_keys($itensPorId), $tenant)
        );

        foreach ($itensPorId as $djenId => $item) {
            if (isset($existentes[$djenId])) {
                continue;
            }

            $vistosNesteRun[$djenId] = true;

            $publicacao = new PublicacaoDjen();
            $this->mapper->mapearItem($publicacao, $item);
            $publicacao->setTenant($tenant);
            $publicacao->setOabMonitorada($oab);
            $vinculada = $this->vincularProcesso($publicacao, $tenant);

            if (!$dryRun) {
                $this->publicacaoRepository->salvar($publicacao);
            }

            $resultado->registrarNova($vinculada, (string) $djenId);
        }
    }

    /**
     * Busca todas as páginas de uma consulta (até o teto), acumulando os itens brutos.
     *
     * @param \Closure(int): ConsultaComunicacoesQuery $consultaDaPagina monta a consulta de cada página
     *
     * @return list<array<string, mixed>>
     */
    private function consultarTodosItens(\Closure $consultaDaPagina): array
    {
        $todos = [];

        for ($pagina = 1; $pagina <= self::MAX_PAGINAS; $pagina++) {
            $resposta = $this->djenClient->consultarComunicacoes($consultaDaPagina($pagina));

            foreach ($resposta->items as $item) {
                $todos[] = $item;
            }

            if ($resposta->quantidadeNaPagina() < self::ITENS_POR_PAGINA || \count($todos) >= $resposta->count) {
                break;
            }
        }

        return $todos;
    }

    /**
     * Vincula ao Processo do escritório quando o número CNJ casa. Sempre escopa pelo tenant
     * explícito (CLI roda com o TenantFilter desligado — homônimo de outro escritório NÃO pode casar).
     */
    private function vincularProcesso(PublicacaoDjen $publicacao, Tenant $tenant): bool
    {
        $numero = $publicacao->getNumeroProcesso();
        if ($numero === '') {
            return false;
        }

        $processo = $this->processoRepository->findByNumeroProcessoDoTenant($numero, $tenant);
        if ($processo === null) {
            return false;
        }

        $publicacao->setProcesso($processo);

        return true;
    }
}
