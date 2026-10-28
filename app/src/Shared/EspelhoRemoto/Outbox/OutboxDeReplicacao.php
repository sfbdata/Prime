<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Outbox;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * A outbox da réplica — tabela `armazenamento_replicacao` (spec P2 §3.2, §14). Ela É a fila.
 *
 * ## Escrita na mesma conexão do domínio (DP-10 refinada)
 *
 * Cada registro abre `beginTransaction()` na conexão compartilhada. Com transação do domínio aberta,
 * isso é um **savepoint** (DBAL 4): o domínio desfeito leva a linha junto, e a falha da própria
 * outbox volta só o savepoint. Sem transação aberta, a linha é confirmada na hora.
 *
 * ## Reivindicação
 *
 * Sob `pg_advisory_xact_lock` (curta: só a escolha da linha), `FOR UPDATE SKIP LOCKED`, e com duas
 * regras de ordem por chave remota:
 *  - não entrega linha cuja chave tenha OUTRA em `processando` com posse válida;
 *  - linha com outra MAIS NOVA da mesma chave (envio ou exclusão) vira `superado` e não sai.
 * Posse vencida (worker morto) volta a ser reivindicável — mas só até o teto de tentativas: a linha que
 * derruba o worker toda vez vira `falhou` em vez de "veneno" eterno no topo da fila.
 *
 * ## Posse com token
 *
 * Cada reivindicação grava um token aleatório em `posse`. Concluir, reagendar e renovar só valem com
 * ele: um worker que perdeu a posse (demorou além do prazo) não reescreve o estado de quem a pegou
 * depois, e fica sabendo (o método devolve `false`). O worker RENOVA a posse imediatamente antes do
 * PUT e não envia se não conseguir — com o teto de duração do HTTP menor que a posse, um envio velho
 * não termina depois de outro worker ter enviado a versão nova.
 */
final class OutboxDeReplicacao
{
    public const ESTADO_PENDENTE    = 'pendente';
    public const ESTADO_PROCESSANDO = 'processando';
    public const ESTADO_VERIFICADO  = 'verificado';
    public const ESTADO_FALHOU      = 'falhou';
    public const ESTADO_SUPERADO    = 'superado';
    public const ESTADO_DESCARTADO  = 'descartado';
    public const ESTADO_REGISTRADO  = 'registrado';

    public const OPERACAO_ENVIAR   = 'enviar';
    public const OPERACAO_EXCLUSAO = 'exclusao_registrada';

    private const TABELA = 'armazenamento_replicacao';

    /** Os estados finais de um envio: só eles saem pela retenção. */
    public const ESTADOS_CONCLUIDOS = [self::ESTADO_VERIFICADO, self::ESTADO_SUPERADO, self::ESTADO_DESCARTADO];

    private const CONCLUIDAS_ANTES_DE = 'operacao = :enviar AND estado IN (:estados) AND atualizado_em < :limite';

    private const TIPOS_DA_RETENCAO = ['estados' => ArrayParameterType::STRING];

    /** Identificador da trava consultiva da reivindicação ("replica" em decimal, sem colisão prática). */
    private const TRAVA_DA_REIVINDICACAO = 7_202_610_150;

    public function __construct(
        private readonly Connection $conexao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function registrarEnvio(ChaveDeArquivo $chave, string $sha256, int $tamanhoBytes, string $mimeType): int
    {
        return $this->registrar($chave, self::OPERACAO_ENVIAR, self::ESTADO_PENDENTE, $sha256, $tamanhoBytes, $mimeType);
    }

    /** A exclusão vira evento — **nunca** chamada ao R2 (regra 0 da P2). */
    public function registrarExclusao(ChaveDeArquivo $chave): int
    {
        return $this->registrar($chave, self::OPERACAO_EXCLUSAO, self::ESTADO_REGISTRADO, null, null, null);
    }

    /**
     * `$categorias`: só entrega linhas destas categorias (as ligadas na política). A linha de categoria
     * desligada fica `pendente`, sem subir ao espelho, e volta a andar quando a categoria religar.
     * Null = qualquer categoria (só os testes da fila usam).
     *
     * @param list<string>|null $categorias
     */
    public function reivindicarProxima(int $posseEmSegundos, int $maximoDeTentativas, ?array $categorias = null): ?ItemDeReplicacao
    {
        if ($categorias === []) {
            return null;
        }

        return $this->conexao->transactional(function (Connection $c) use ($posseEmSegundos, $maximoDeTentativas, $categorias): ?ItemDeReplicacao {
            $c->executeStatement('SELECT pg_advisory_xact_lock(?)', [self::TRAVA_DA_REIVINDICACAO]);
            $agora = $this->agora();

            while (true) {
                $linha = $this->proximaCandidata($c, $agora, $categorias);
                if ($linha === false) {
                    return null;
                }

                $chave = $this->chaveSeAindaServe($c, $linha, $maximoDeTentativas);
                if ($chave !== null) {
                    return $this->tomarPosse($c, $linha, $chave, $posseEmSegundos, $agora);
                }
            }
        });
    }

    /**
     * A próxima linha de envio vencida (pendente na hora, ou em processamento com a posse vencida),
     * sem outra em processamento válido para a mesma chave remota, de uma das categorias (null = todas).
     *
     * @param list<string>|null $categorias
     *
     * @return array<string, mixed>|false
     */
    private function proximaCandidata(Connection $c, string $agora, ?array $categorias): array|false
    {
        $soAsLigadas = $categorias === null ? '' : ' AND r.categoria IN (:categorias)';

        return $c->fetchAssociative(
            'SELECT * FROM ' . self::TABELA . ' r
              WHERE r.operacao = :enviar
                AND ((r.estado = :pendente AND r.proxima_tentativa_em <= :agora)
                     OR (r.estado = :processando AND r.processando_ate < :agora))' . $soAsLigadas . '
                AND NOT EXISTS (
                    SELECT 1 FROM ' . self::TABELA . ' o
                     WHERE o.chave_remota = r.chave_remota AND o.id <> r.id
                       AND o.estado = :processando AND o.processando_ate >= :agora)
              ORDER BY r.id
              LIMIT 1
              FOR UPDATE SKIP LOCKED',
            ['enviar' => self::OPERACAO_ENVIAR, 'pendente' => self::ESTADO_PENDENTE, 'processando' => self::ESTADO_PROCESSANDO, 'agora' => $agora]
                + ($categorias === null ? [] : ['categorias' => $categorias]),
            ['categorias' => ArrayParameterType::STRING],
        );
    }

    /**
     * A chave da linha, se ela ainda deve ser enviada; senão a linha muda de estado (superada por uma
     * mais nova, falhou pelo teto de tentativas ou por chave inválida) e volta null.
     *
     * @param array<string, mixed> $linha
     */
    private function chaveSeAindaServe(Connection $c, array $linha, int $maximoDeTentativas): ?ChaveDeArquivo
    {
        $id           = (int) $linha['id'];
        $novaMaisNova = $c->fetchOne(
            'SELECT id FROM ' . self::TABELA . ' WHERE chave_remota = :chave AND id > :id ORDER BY id DESC LIMIT 1',
            ['chave' => $linha['chave_remota'], 'id' => $id],
        );
        if ($novaMaisNova !== false) {
            $this->mudar($c, $id, self::ESTADO_SUPERADO, sprintf('há registro mais novo da mesma chave (#%d)', (int) $novaMaisNova));

            return null;
        }

        if ((int) $linha['tentativas'] >= $maximoDeTentativas) {
            $this->mudar($c, $id, self::ESTADO_FALHOU, sprintf('posse vencida depois de %d tentativas (o worker caiu ou o item derruba o worker)', (int) $linha['tentativas']));

            return null;
        }

        try {
            return self::chaveDaLinha($linha);
        } catch (ChaveDeArquivoInvalida $e) {
            // A mensagem da chave traz o nome cru; aqui vai só a classe.
            $this->mudar($c, $id, self::ESTADO_FALHOU, 'linha sem chave válida (' . $e::class . ')');

            return null;
        }
    }

    /** @param array<string, mixed> $linha */
    private function tomarPosse(Connection $c, array $linha, ChaveDeArquivo $chave, int $posseEmSegundos, string $agora): ItemDeReplicacao
    {
        $id    = (int) $linha['id'];
        $posse = bin2hex(random_bytes(16));
        $c->executeStatement(
            'UPDATE ' . self::TABELA . ' SET estado = :processando, processando_ate = :ate, posse = :posse, tentativas = tentativas + 1, atualizado_em = :agora WHERE id = :id',
            ['processando' => self::ESTADO_PROCESSANDO, 'ate' => $this->agora($posseEmSegundos), 'posse' => $posse, 'agora' => $agora, 'id' => $id],
        );

        return new ItemDeReplicacao(
            $id,
            $chave,
            (string) $linha['chave_remota'],
            (string) $linha['sha256'],
            (int) $linha['tamanho_bytes'],
            (string) ($linha['mime_type'] ?? 'application/octet-stream'),
            (int) $linha['tentativas'] + 1,
            $posse,
        );
    }

    /** Cada `marcar*`/`reagendar`/`renovarPosse` devolve false quando a posse já não era deste item. */
    public function marcarVerificado(ItemDeReplicacao $item): bool
    {
        return $this->concluir($item, self::ESTADO_VERIFICADO, null);
    }

    public function marcarSuperado(ItemDeReplicacao $item, string $motivo): bool
    {
        return $this->concluir($item, self::ESTADO_SUPERADO, $motivo);
    }

    public function marcarDescartado(ItemDeReplicacao $item, string $motivo): bool
    {
        return $this->concluir($item, self::ESTADO_DESCARTADO, $motivo);
    }

    public function marcarFalhou(ItemDeReplicacao $item, string $erroSemDadosPessoais): bool
    {
        return $this->concluir($item, self::ESTADO_FALHOU, $erroSemDadosPessoais);
    }

    public function reagendar(ItemDeReplicacao $item, string $erroSemDadosPessoais, int $emSegundos): bool
    {
        return $this->conexao->executeStatement(
            'UPDATE ' . self::TABELA . ' SET estado = :pendente, processando_ate = NULL, posse = NULL, proxima_tentativa_em = :quando, ultimo_erro = :erro, atualizado_em = :agora
              WHERE id = :id AND estado = :processando AND posse = :posse',
            ['pendente' => self::ESTADO_PENDENTE, 'quando' => $this->agora($emSegundos), 'erro' => $erroSemDadosPessoais, 'agora' => $this->agora(), 'id' => $item->id, 'processando' => self::ESTADO_PROCESSANDO, 'posse' => $item->posse],
        ) === 1;
    }

    /**
     * Estende a posse deste item por mais N segundos a partir de agora — só se ela ainda for dele e
     * ainda não tiver vencido. O worker chama logo antes do PUT; `false` = não envie.
     */
    public function renovarPosse(ItemDeReplicacao $item, int $porSegundos): bool
    {
        return $this->conexao->executeStatement(
            'UPDATE ' . self::TABELA . ' SET processando_ate = :ate, atualizado_em = :agora
              WHERE id = :id AND estado = :processando AND posse = :posse AND processando_ate >= :agora',
            ['ate' => $this->agora($porSegundos), 'agora' => $this->agora(), 'id' => $item->id, 'processando' => self::ESTADO_PROCESSANDO, 'posse' => $item->posse],
        ) === 1;
    }

    /** Existe registro (envio ou exclusão) mais novo que este para a mesma chave remota? */
    public function existeRegistroMaisNovo(ItemDeReplicacao $item): bool
    {
        return $this->conexao->fetchOne(
            'SELECT 1 FROM ' . self::TABELA . ' WHERE chave_remota = ? AND id > ? LIMIT 1',
            [$item->chaveRemota, $item->id],
        ) !== false;
    }

    /**
     * Retenção (decisão do dono de 23/10, I4 da P2.3, LGPD): quantas linhas de envio CONCLUÍDAS
     * (verificado, superado, descartado) estão paradas há mais de `$dias` dias, por estado. É o que
     * `apagarConcluidasHaMaisDe()` apagaria. Pendente, processando, falhou e as exclusões
     * (registrado, a fila da futura lixeira do espelho) nunca entram.
     *
     * @return array<string, int> estado => quantidade
     */
    public function contarConcluidasHaMaisDe(int $dias): array
    {
        $contagem = [];
        foreach ($this->conexao->fetchAllAssociative(
            'SELECT estado, count(*) AS n FROM ' . self::TABELA . ' WHERE ' . self::CONCLUIDAS_ANTES_DE . ' GROUP BY estado ORDER BY estado',
            $this->parametrosDaRetencao($dias),
            self::TIPOS_DA_RETENCAO,
        ) as $linha) {
            $contagem[(string) $linha['estado']] = (int) $linha['n'];
        }

        return $contagem;
    }

    /**
     * Apaga as linhas que `contarConcluidasHaMaisDe()` conta e devolve quantas saíram. Exclusão de
     * LINHA LOCAL da fila, nunca de objeto no espelho.
     */
    public function apagarConcluidasHaMaisDe(int $dias): int
    {
        return (int) $this->conexao->executeStatement(
            'DELETE FROM ' . self::TABELA . ' WHERE ' . self::CONCLUIDAS_ANTES_DE,
            $this->parametrosDaRetencao($dias),
            self::TIPOS_DA_RETENCAO,
        );
    }

    /** @return array<string, mixed> */
    private function parametrosDaRetencao(int $dias): array
    {
        if ($dias < 1) {
            throw new \InvalidArgumentException('A retenção precisa ser de pelo menos 1 dia.');
        }

        return [
            'enviar'  => self::OPERACAO_ENVIAR,
            'estados' => self::ESTADOS_CONCLUIDOS,
            'limite'  => $this->agora(-$dias * 86_400),
        ];
    }

    /** @return array<string, int> estado => quantidade, das linhas de envio */
    public function contarPorEstado(): array
    {
        $contagem = [];
        foreach ($this->conexao->fetchAllAssociative('SELECT estado, count(*) AS n FROM ' . self::TABELA . ' GROUP BY estado ORDER BY estado') as $linha) {
            $contagem[(string) $linha['estado']] = (int) $linha['n'];
        }

        return $contagem;
    }

    /**
     * A tabela existe neste banco? O modo agendado (`--se-ligado`) da retenção pula, em silêncio, um
     * banco que ainda não recebeu a migration da outbox.
     */
    public function tabelaExiste(): bool
    {
        return $this->conexao->createSchemaManager()->tablesExist([self::TABELA]);
    }

    /**
     * Linhas de envio em `falhou`, por categoria (alerta da reconciliação, P2.7). Só contagem: nome e
     * chave remota não saem daqui. `$soCategorias`: só estas (as ligadas na política); null = todas.
     *
     * @param list<string>|null $soCategorias
     *
     * @return array<string, int> categoria => quantidade
     */
    public function contarFalhasPorCategoria(?array $soCategorias = null): array
    {
        if ($soCategorias === []) {
            return [];
        }

        $contagem = [];
        foreach ($this->conexao->fetchAllAssociative(
            'SELECT categoria, count(*) AS n FROM ' . self::TABELA . ' WHERE operacao = :enviar AND estado = :falhou'
                . ($soCategorias === null ? '' : ' AND categoria IN (:categorias)') . ' GROUP BY categoria ORDER BY categoria',
            ['enviar' => self::OPERACAO_ENVIAR, 'falhou' => self::ESTADO_FALHOU] + ($soCategorias === null ? [] : ['categorias' => $soCategorias]),
            ['categorias' => ArrayParameterType::STRING],
        ) as $linha) {
            $contagem[(string) $linha['categoria']] = (int) $linha['n'];
        }

        return $contagem;
    }

    /**
     * Volta as linhas de envio em `falhou` das categorias dadas (as ligadas, ou uma delas) para
     * `pendente`, com tentativas zeradas e próxima tentativa agora. Devolve quantas voltaram. O worker
     * trata cada uma como envio novo: se já houver linha mais nova da mesma chave, ela vira `superado`
     * na reivindicação.
     *
     * @param list<string> $categorias
     */
    public function reenfileirarFalhas(array $categorias): int
    {
        if ($categorias === []) {
            return 0;
        }
        $agora = $this->agora();

        return (int) $this->conexao->executeStatement(
            'UPDATE ' . self::TABELA . ' SET estado = :pendente, tentativas = 0, proxima_tentativa_em = :agora, processando_ate = NULL, posse = NULL, atualizado_em = :agora
              WHERE operacao = :enviar AND estado = :falhou AND categoria IN (:categorias)',
            ['pendente' => self::ESTADO_PENDENTE, 'agora' => $agora, 'enviar' => self::OPERACAO_ENVIAR, 'falhou' => self::ESTADO_FALHOU, 'categorias' => $categorias],
            ['categorias' => ArrayParameterType::STRING],
        );
    }

    /** Há quantos segundos espera o envio pendente mais antigo, ou null se não há pendente. */
    public function idadeDaPendenteMaisAntigaEmSegundos(): ?int
    {
        $maisAntiga = $this->conexao->fetchOne(
            'SELECT min(criado_em) FROM ' . self::TABELA . ' WHERE operacao = ? AND estado = ?',
            [self::OPERACAO_ENVIAR, self::ESTADO_PENDENTE],
        );
        if ($maisAntiga === false || $maisAntiga === null) {
            return null;
        }

        $utc   = new \DateTimeZone('UTC');
        $desde = new \DateTimeImmutable((string) $maisAntiga, $utc);

        return max(0, $this->relogio->now()->setTimezone($utc)->getTimestamp() - $desde->getTimestamp());
    }

    /** O estado da linha mais recente de uma chave remota, ou null se a chave nunca passou pela outbox. */
    public function ultimoEstadoDe(string $chaveRemota): ?string
    {
        $estado = $this->conexao->fetchOne(
            'SELECT estado FROM ' . self::TABELA . ' WHERE chave_remota = ? ORDER BY id DESC LIMIT 1',
            [$chaveRemota],
        );

        return $estado === false ? null : (string) $estado;
    }

    /**
     * O envio `verificado` da chave, quando ele é a linha MAIS NOVA dela; senão null.
     *
     * Uma exclusão registrada, um envio ainda pendente (regravação em curso), um envio que falhou ou
     * foi descartado depois do último verificado tiram a prova: o que está no R2 pode não ser mais o
     * conteúdo atual da chave. Linha verificada sem SHA ou tamanho válido também não prova nada.
     */
    public function envioVerificadoDe(string $chaveRemota): ?EnvioVerificado
    {
        $linha = $this->conexao->fetchAssociative(
            'SELECT operacao, estado, sha256, tamanho_bytes, mime_type, criado_em FROM ' . self::TABELA . ' WHERE chave_remota = ? ORDER BY id DESC LIMIT 1',
            [$chaveRemota],
        );

        if ($linha === false || $linha['operacao'] !== self::OPERACAO_ENVIAR || $linha['estado'] !== self::ESTADO_VERIFICADO) {
            return null;
        }

        $sha256  = (string) $linha['sha256'];
        $tamanho = $linha['tamanho_bytes'];
        if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1 || $tamanho === null || (int) $tamanho < 0) {
            return null;
        }

        $mime = (string) ($linha['mime_type'] ?? '');

        return new EnvioVerificado(
            $chaveRemota,
            $sha256,
            (int) $tamanho,
            $mime !== '' ? $mime : 'application/octet-stream',
            new \DateTimeImmutable((string) $linha['criado_em'], new \DateTimeZone('UTC')),
        );
    }

    private function registrar(ChaveDeArquivo $chave, string $operacao, string $estado, ?string $sha256, ?int $tamanho, ?string $mime): int
    {
        $chaveRemota = LayoutDeChaveRemota::chaveDe($chave);
        $agora       = $this->agora();

        $this->conexao->beginTransaction();
        try {
            $id = $this->conexao->fetchOne(
                'INSERT INTO ' . self::TABELA . ' (operacao, escopo_tenant_id, categoria, nome, chave_remota, sha256, tamanho_bytes, mime_type, estado, tentativas, proxima_tentativa_em, criado_em, atualizado_em)
                 VALUES (:operacao, :tenant, :categoria, :nome, :chave, :sha, :tamanho, :mime, :estado, 0, :agora, :agora, :agora)
                 RETURNING id',
                [
                    'operacao' => $operacao, 'tenant' => $chave->escopo->tenantIdOuNull(), 'categoria' => $chave->categoria->value,
                    'nome' => $chave->nome, 'chave' => $chaveRemota, 'sha' => $sha256, 'tamanho' => $tamanho, 'mime' => $mime,
                    'estado' => $estado, 'agora' => $agora,
                ],
            );
            $this->conexao->commit();
        } catch (\Throwable $e) {
            $this->conexao->rollBack();

            throw $e;
        }

        return (int) $id;
    }

    private function concluir(ItemDeReplicacao $item, string $estado, ?string $motivo): bool
    {
        return $this->conexao->executeStatement(
            'UPDATE ' . self::TABELA . ' SET estado = :estado, processando_ate = NULL, posse = NULL, ultimo_erro = :motivo, atualizado_em = :agora
              WHERE id = :id AND estado = :processando AND posse = :posse',
            ['estado' => $estado, 'motivo' => $motivo, 'agora' => $this->agora(), 'id' => $item->id, 'processando' => self::ESTADO_PROCESSANDO, 'posse' => $item->posse],
        ) === 1;
    }

    private function mudar(Connection $c, int $id, string $estado, string $motivo): void
    {
        $c->executeStatement(
            'UPDATE ' . self::TABELA . ' SET estado = :estado, processando_ate = NULL, posse = NULL, ultimo_erro = :motivo, atualizado_em = :agora WHERE id = :id',
            ['estado' => $estado, 'motivo' => $motivo, 'agora' => $this->agora(), 'id' => $id],
        );
    }

    /** @param array<string, mixed> $linha */
    private static function chaveDaLinha(array $linha): ChaveDeArquivo
    {
        $categoria = CategoriaDeArquivo::tryFrom((string) $linha['categoria'])
            ?? throw new ChaveDeArquivoInvalida('categoria desconhecida');
        $escopo = $linha['escopo_tenant_id'] === null
            ? EscopoDeArquivo::global()
            : EscopoDeArquivo::deTenant((int) $linha['escopo_tenant_id']);

        return new ChaveDeArquivo($escopo, $categoria, (string) $linha['nome']);
    }

    /**
     * Sempre em UTC: as colunas são `TIMESTAMP WITHOUT TIME ZONE`, e só este relógio as escreve e
     * compara. Um worker rodando com outro fuso configurado não pode deslocar posse e espera.
     */
    private function agora(int $maisSegundos = 0): string
    {
        $agora = $this->relogio->now()->setTimezone(new \DateTimeZone('UTC'));
        if ($maisSegundos !== 0) {
            $agora = $agora->modify(sprintf('%+d seconds', $maisSegundos));
        }

        return $agora->format('Y-m-d H:i:s');
    }
}
